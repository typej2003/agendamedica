<?php

namespace App\Clinica\Agenda\Vistas;

use App\Clinica\Agenda\LecturaDeAgenda;
use App\Clinica\FechaDelConsultorio;
use App\Notificaciones\Canal;
use App\Support\FechaClinica;
use Carbon\Carbon;

/**
 * La pantalla de la agenda (`/clinica/agenda`): el encabezado, la navegación entre días, los filtros y el
 * cuerpo según la vista (día, semana o mes). Todo link conserva la vista, la sede y la razón elegidas:
 * solo cambia lo que toca.
 */
final class AgendaVista
{
    private const ETIQUETAS_DE_VISTA = ['dia' => 'Día', 'semana' => 'Semana', 'mes' => 'Mes'];

    /** @param  array<string,mixed>  $query  los parámetros de la URL actual (se conservan en cada link) */
    public function __construct(
        private LecturaDeAgenda $lectura,
        private array $query,
        /**
         * Si el usuario que mira la agenda puede entrar a la consulta (WEB-4.1: la consulta es sólo del
         * médico). Lo resuelve el controlador con `User::trabajaLaConsulta()` y baja hasta cada fila.
         */
        private bool $puedeAbrirLaConsulta = true,
    ) {
    }

    private function vista(): string
    {
        return $this->lectura->parametros->vista;
    }

    private function fecha(): Carbon
    {
        return $this->lectura->parametros->fecha;
    }

    private function sedeId(): ?int
    {
        return $this->lectura->parametros->sedeId;
    }

    public function esDia(): bool
    {
        return $this->vista() === 'dia';
    }

    /** "Lunes 05/10/2026", "Semana del 05/10/2026" u "octubre 2026". */
    public function titulo(): string
    {
        $titulo = match ($this->vista()) {
            'semana' => 'Semana del ' . $this->fecha()->copy()->startOfWeek(Carbon::MONDAY)->format('d/m/Y'),
            'mes'    => FechaClinica::tituloMes($this->fecha()),
            default  => FechaClinica::tituloDia($this->fecha()),
        };

        return ucfirst($titulo);
    }

    /** "3 citas": lo que hay en el rango que se está mirando (el título va en la barra de navegación). */
    public function subtitulo(): string
    {
        $total = $this->lectura->totalRango;

        return $total . ($total === 1 ? ' cita' : ' citas') . match ($this->vista()) {
            'semana' => ' esta semana',
            'mes'    => ' este mes',
            default  => ' este día',
        };
    }

    // ------------------------------------------------------------------------------------ Navegación

    /** Un link de la agenda que conserva lo que se está mirando y cambia solo `$extra`. */
    private function url(array $extra): string
    {
        return route('clinica.agenda', array_merge($this->query, $extra));
    }

    /** @return array<int,array{clave:string, etiqueta:string, url:string, activa:bool}> */
    public function vistas(): array
    {
        $vistas = [];

        foreach (self::ETIQUETAS_DE_VISTA as $clave => $etiqueta) {
            $vistas[] = [
                'clave'    => $clave,
                'etiqueta' => $etiqueta,
                'url'      => $this->url(['vista' => $clave, 'fecha' => $this->fecha()->toDateString()]),
                'activa'   => $this->vista() === $clave,
            ];
        }

        return $vistas;
    }

    private function desplazada(int $pasos): Carbon
    {
        $fecha = $this->fecha()->copy();

        return match ($this->vista()) {
            'semana' => $fecha->addWeeks($pasos),
            'mes'    => $fecha->addMonthsNoOverflow($pasos),
            default  => $fecha->addDays($pasos),
        };
    }

    public function urlAnterior(): string
    {
        return $this->url(['fecha' => $this->desplazada(-1)->toDateString()]);
    }

    public function urlSiguiente(): string
    {
        return $this->url(['fecha' => $this->desplazada(1)->toDateString()]);
    }

    public function urlHoy(): string
    {
        return $this->url(['fecha' => FechaDelConsultorio::hoyComoFecha()->toDateString()]);
    }

    public function etiquetaAnterior(): string
    {
        return match ($this->vista()) {
            'semana' => 'Semana anterior',
            'mes'    => 'Mes anterior',
            default  => 'Día anterior',
        };
    }

    public function etiquetaSiguiente(): string
    {
        return match ($this->vista()) {
            'semana' => 'Semana siguiente',
            'mes'    => 'Mes siguiente',
            default  => 'Día siguiente',
        };
    }

    // ---------------------------------------------------------------------------------------- Filtros

    /** Lo que el formulario de filtros conserva sin mostrar: la vista y la fecha. */
    public function ocultos(): array
    {
        return ['vista' => $this->vista(), 'fecha' => $this->fecha()->toDateString()];
    }

    /** @return array<int,array{valor:string, texto:string, elegida:bool}> */
    public function opcionesDeSede(): array
    {
        $opciones = [['valor' => 'todas', 'texto' => 'Todas las sedes', 'elegida' => $this->sedeId() === null]];

        foreach ($this->lectura->sedes as $sede) {
            $texto = ($sede->medicalCenter->name ?? 'Sede') . ($sede->office_number ? ' — ' . $sede->office_number : '');
            $opciones[] = ['valor' => (string) $sede->id, 'texto' => $texto, 'elegida' => $this->sedeId() === $sede->id];
        }

        return $opciones;
    }

    /**
     * Una opción por motivo del catálogo (`motivo_cita` del médico): el `value` es el `codigo` —lo que
     * está guardado en `cola.tipo` y, en el móvil, en `cola.motivo`— y el texto el `tipo_atencion`.
     *
     * @return array<int,array{valor:string, texto:string, elegida:bool}>
     */
    public function opcionesDeRazon(): array
    {
        $opciones = [['valor' => '', 'texto' => 'Todas las razones', 'elegida' => $this->lectura->razon === null]];

        foreach ($this->lectura->razones as $motivo) {
            $opciones[] = [
                'valor'   => (string) $motivo->codigo,
                'texto'   => $motivo->tipo_atencion ?: (string) $motivo->codigo,
                'elegida' => $this->lectura->razon === (string) $motivo->codigo,
            ];
        }

        return $opciones;
    }

    /**
     * La razón activa se ve como chip, no solo por el `<select>`: así queda claro por qué el listado tiene
     * menos renglones. La × quita `razon` del query (sin dejar `?razon=` en la URL) y conserva el resto.
     *
     * @return array{texto:string, urlQuitar:string}|null
     */
    public function razonActiva(): ?array
    {
        if ($this->lectura->razon === null) {
            return null;
        }

        $motivo = $this->lectura->razones->firstWhere('codigo', $this->lectura->razon);

        return [
            'texto'     => $motivo?->tipo_atencion ?: $this->lectura->razon,
            'urlQuitar' => route('clinica.agenda', array_diff_key($this->query, ['razon' => true])),
        ];
    }

    // --------------------------------------------------------------------------------------- Avisos

    /** El día no laborable avisa **antes** de agendar: es el texto del `messagebox` del escritorio. */
    public function avisoNoLaborable(): ?string
    {
        return $this->lectura->avisoNoLaborable;
    }

    public function urlNoLaborables(): string
    {
        return route('clinica.agenda.no-laborables', ['mes' => $this->fecha()->format('Y-m')]);
    }

    /** Los motivos de cita y su marca de "varias citas" (WEB-2.17): la excepción a la cita única. */
    public function urlMotivos(): string
    {
        return route('clinica.agenda.motivos');
    }

    /**
     * Los cumpleaños del día (WEB-2.9). La pantalla no es de la agenda —el paciente que cumple años hoy
     * entra aunque no tenga ninguna cita—, pero se llega desde acá, que es donde la secretaria ya está
     * todos los días. Se le pasa la fecha que se está mirando para que el atajo no obligue a cambiarla
     * después.
     */
    public function urlCumpleanos(): string
    {
        return route('clinica.cumpleanos', ['fecha' => $this->fecha()->toDateString()]);
    }

    // -------------------------------------------------------------------------------------- Acciones

    public function urlNueva(): string
    {
        return route('clinica.agenda.nueva', ['fecha' => $this->fecha()->toDateString(), 'sede' => $this->sedeId()]);
    }

    public function urlEnvio(): string
    {
        return route('clinica.agenda.envio', ['fecha' => $this->fecha()->toDateString(), 'sede' => $this->sedeId() ?? 'todas']);
    }

    public function urlImprimir(): string
    {
        return route('clinica.agenda.imprimir', ['fecha' => $this->fecha()->toDateString(), 'sede' => $this->sedeId() ?? 'todas']);
    }

    /** @return array<string,string> clave => etiqueta de los canales del recordatorio */
    public function canales(): array
    {
        return Canal::etiquetas();
    }

    // ----------------------------------------------------------------------------------------- Cuerpo

    /** La vista Blade del cuerpo según la vista pedida. */
    public function plantilla(): string
    {
        return 'clinica.agenda.' . $this->vista();
    }

    /** El ViewModel del cuerpo: `DiaVista`, `SemanaVista` o `MesVista`. */
    public function cuerpo(): DiaVista|SemanaVista|MesVista
    {
        return match ($this->vista()) {
            'semana' => $this->semana(),
            'mes'    => $this->mes(),
            default  => $this->dia(),
        };
    }

    private function dia(): DiaVista
    {
        return new DiaVista($this->lectura, $this->jornadas(true));
    }

    private function semana(): SemanaVista
    {
        return new SemanaVista($this->lectura, $this->jornadas(false));
    }

    private function mes(): MesVista
    {
        return new MesVista($this->lectura, $this->query);
    }

    /** @return array<int,JornadaVista> */
    private function jornadas(bool $conArrastre): array
    {
        return $this->lectura->jornadas
            ->map(fn ($jornada) => new JornadaVista(
                $jornada,
                $this->lectura->posiciones,
                $this->lectura->mensajes,
                $this->lectura->ahora,
                $conArrastre,
                $this->puedeAbrirLaConsulta,
            ))
            ->all();
    }
}
