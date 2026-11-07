<?php

namespace App\Clinica\Vacunas\Vistas;

use App\Clinica\Pacientes\CamposDePaciente;
use App\Clinica\Vacunas\CalendarioDeVacunas;
use App\Clinica\Vacunas\ListaDeVacunasDelPaciente;
use Carbon\Carbon;
use Closure;

/**
 * **La pantalla de vacunas de un niño** (WEB-7.4.4a; O-68/O-69): la **lista de sus filas** (lo aplicado y lo programado, como la
 * solapa *Inmunizaciones* del escritorio) y, plegado, el **esquema del catálogo** —las dosis que el catálogo del médico propone,
 * con su «le toca» y su estado— del que se puede **programar** una dosis en la lista.
 *
 * El esquema conserva el segmentado que deja ver **todas** las dosis o sólo las **pendientes** (atrasadas y próximas). Los
 * estados del esquema (`aplicada` · `atrasada` · `próxima` · `pendiente` · `sin fecha`) son **sólo** del panel: una fila de la
 * lista está *aplicada* o *programada* y nada más.
 *
 * No consulta nada: recibe lo que armaron `ListaDeVacunasDelPaciente` y `CalendarioDeVacunas`, el catálogo para el diálogo de
 * «Agregar una vacuna» y una función que arma el enlace de cada filtro (el controlador pone las URLs).
 */
final class CalendarioDeVacunasVista
{
    public const TODAS = 'todas';
    public const PENDIENTES = 'pendientes';

    private const TONOS = [
        CalendarioDeVacunas::APLICADA => 'exito',
        CalendarioDeVacunas::ATRASADA => 'peligro',
        CalendarioDeVacunas::PROXIMA => 'aviso',
        CalendarioDeVacunas::PENDIENTE => 'neutro',
        CalendarioDeVacunas::SIN_FECHA => 'neutro',
        CalendarioDeVacunas::VENCIDA => 'neutro',
    ];

    private const ICONOS = [
        CalendarioDeVacunas::APLICADA => 'bi-check-circle',
        CalendarioDeVacunas::ATRASADA => 'bi-exclamation-triangle',
        CalendarioDeVacunas::PROXIMA => 'bi-clock',
        CalendarioDeVacunas::PENDIENTE => 'bi-hourglass-split',
        CalendarioDeVacunas::SIN_FECHA => 'bi-question-circle',
        CalendarioDeVacunas::VENCIDA => 'bi-calendar-x',
    ];

    /**
     * @param  object  $paciente  `nombres`, `apellidos`, `sexo`, `fnacimiento` y `numhistoria`
     * @param  array{vacunas:array<int,array<string,mixed>>, otras:array<int,array<string,mixed>>, resumen:array<string,int>}  $calendario
     * @param  Closure(string):string  $url  arma el enlace de un filtro (`todas` | `pendientes`)
     * @param  array{filas:array<int,array<string,mixed>>, resumen:array<aplicadas:int, programadas:int>}  $lista  las filas del niño
     * @param  array<int,array{codigo:string, nombre:string, tipo:string, dosis:array<int,array{numero:int, rotulo:string}>}>  $catalogo
     *         lo que ofrece el catálogo del médico para el diálogo «Agregar una vacuna»
     */
    public function __construct(
        private object $paciente,
        private array $calendario,
        private int $vacunasDelMedico,
        private string $filtro,
        private Closure $url,
        private string $urlFicha,
        // A dónde se postea el registro de una dosis (WEB-7.4.4b); `null` = la pantalla es de sólo lectura.
        private ?string $urlRegistrar = null,
        private ?string $hoy = null,
        // La hoja del carnet de vacunación (WEB-7.4.4c).
        private ?string $urlCarnet = null,
        // La **consulta** desde la que se está mirando el calendario (O-63), o `null` si es la pantalla del módulo: es lo que
        // hace que registrar una dosis vuelva a la sección «Inmunizaciones» y no al módulo del niño.
        private ?int $consulta = null,
        // **La lista del paciente** (O-68/O-69): sus filas ya armadas y su resumen.
        private array $lista = ['filas' => [], 'resumen' => ['aplicadas' => 0, 'programadas' => 0]],
        // A dónde se postea la programación de una dosis; `null` = no se ofrece programar.
        private ?string $urlProgramar = null,
        // El catálogo del médico (vacunas y dosis) que llena el diálogo de «Agregar una vacuna».
        private array $catalogo = [],
    ) {
    }

    /** La consulta abierta cuando el calendario es una **sección** de la consulta; `null` en la pantalla del módulo. */
    public function consulta(): ?int
    {
        return $this->consulta;
    }

    public function urlCarnet(): ?string
    {
        return $this->urlCarnet;
    }

    public function puedeRegistrar(): bool
    {
        return $this->urlRegistrar !== null;
    }

    public function urlRegistrar(): ?string
    {
        return $this->urlRegistrar;
    }

    // ------------------------------------------------------------------------------------------ La lista del paciente (O-68/O-69)

    /** ¿Se puede escribir en la lista (aplicar una dosis o programarla)? Sin historia no se puede: la clave la exige. */
    public function puedeEscribir(): bool
    {
        return $this->urlRegistrar !== null || $this->urlProgramar !== null;
    }

    public function puedeProgramar(): bool
    {
        return $this->urlProgramar !== null;
    }

    public function urlProgramar(): ?string
    {
        return $this->urlProgramar;
    }

    /** Las filas de `vacuna_paciente` del niño, ya armadas por `ListaDeVacunasDelPaciente`. @return array<int,array<string,mixed>> */
    public function filas(): array
    {
        return $this->lista['filas'];
    }

    public function hayFilas(): bool
    {
        return $this->lista['filas'] !== [];
    }

    /**
     * Los contadores de la lista: cuántas dosis tiene aplicadas y cuántas programadas. No hay «atrasadas»: eso es del esquema.
     *
     * @return array<int,array{texto:string, tono:string, icono:string}>
     */
    public function resumenDeLaLista(): array
    {
        $filas = [];

        foreach ($this->lista['resumen'] as $estado => $cantidad) {
            if ($cantidad <= 0) {
                continue;
            }

            $filas[] = [
                'texto' => $cantidad . ' ' . ($estado === ListaDeVacunasDelPaciente::APLICADA
                    ? ($cantidad === 1 ? 'aplicada' : 'aplicadas')
                    : ($cantidad === 1 ? 'programada' : 'programadas')),
                'tono' => $estado === ListaDeVacunasDelPaciente::APLICADA ? 'exito' : 'aviso',
                'icono' => $estado === ListaDeVacunasDelPaciente::APLICADA ? 'bi-check-circle' : 'bi-calendar-event',
            ];
        }

        return $filas;
    }

    /** @return array{titulo:string, texto:string} */
    public function vacioDeLaLista(): array
    {
        return [
            'titulo' => 'Todavía no tiene vacunas cargadas',
            'texto' => $this->puedeEscribir()
                ? 'Agrega la vacuna que se le aplicó con «Agregar una vacuna», o abre «Esquema del catálogo» para programar una dosis de las que propone tu catálogo.'
                : 'Cuando el niño tenga historia y su médico abra esta pantalla, acá aparecen las dosis aplicadas y las programadas.',
        ];
    }

    /** ¿No hay ninguna vacuna que ofrecer en el diálogo de «Agregar una vacuna»? (catálogo vacío o sin dosis para este niño). */
    public function sinCatalogoParaAgregar(): bool
    {
        return $this->catalogo === [];
    }

    /**
     * El catálogo del médico para llenar los dos desplegables del diálogo («vacuna» y «dosis»), con lo que el esquema le
     * ofrecería a este niño.
     *
     * @return array<int,array{codigo:string, nombre:string, tipo:string, dosis:array<int,array{numero:int, rotulo:string}>}>
     */
    public function catalogoParaAgregar(): array
    {
        return $this->catalogo;
    }

    /** La fecha más tardía que acepta el formulario (hoy en el consultorio). */
    public function maxima(): string
    {
        return $this->hoy ?? \App\Clinica\FechaDelConsultorio::hoyComoTexto();
    }

    /** La fecha de nacimiento (`AAAA-MM-DD`) o vacío: la más temprana que acepta el formulario. */
    public function minima(): string
    {
        return empty($this->paciente->fnacimiento) ? '' : Carbon::parse($this->paciente->fnacimiento)->toDateString();
    }

    public static function normalizarFiltro(?string $filtro): string
    {
        return $filtro === self::PENDIENTES ? self::PENDIENTES : self::TODAS;
    }

    public function nombre(): string
    {
        return CamposDePaciente::nombre($this->paciente->apellidos ?? null, $this->paciente->nombres ?? null);
    }

    public function subtitulo(): string
    {
        $partes = ['Historia H. ' . ($this->paciente->numhistoria ?? '—')];

        if (! empty($this->paciente->fnacimiento)) {
            $partes[] = 'Nació el ' . Carbon::parse($this->paciente->fnacimiento)->format(\App\Support\Regionalizacion::formatoActual());
        }

        return implode(' · ', $partes);
    }

    public function urlFicha(): string
    {
        return $this->urlFicha;
    }

    /** ¿El médico no tiene ninguna vacuna en su catálogo? Entonces no hay calendario que armar. */
    public function sinCatalogo(): bool
    {
        return $this->vacunasDelMedico === 0;
    }

    /** @return array{titulo:string, texto:string} */
    public function vacioSinCatalogo(): array
    {
        return [
            'titulo' => 'Este consultorio todavía no tiene vacunas',
            'texto' => 'El calendario se arma con el catálogo de vacunas del médico. Cuando cargue el catálogo inicial (o sus propias vacunas) en Catálogos, '
                . 'aquí aparecen las dosis que le tocan a cada niño.',
        ];
    }

    public function sinFechaDeNacimiento(): bool
    {
        return empty($this->paciente->fnacimiento);
    }

    /** @return array<int,array{texto:string, url:string, activa:bool}> */
    public function filtros(): array
    {
        return [
            ['texto' => 'Todas', 'url' => ($this->url)(self::TODAS), 'activa' => $this->filtro === self::TODAS],
            ['texto' => 'Pendientes', 'url' => ($this->url)(self::PENDIENTES), 'activa' => $this->filtro === self::PENDIENTES],
        ];
    }

    /** @return array<int,array{estado:string, texto:string, cantidad:int, tono:string, icono:string}> los contadores que no están en cero */
    public function resumen(): array
    {
        $filas = [];
        foreach ($this->calendario['resumen'] as $estado => $cantidad) {
            if ($cantidad > 0) {
                $filas[] = ['estado' => $estado, 'texto' => CalendarioDeVacunas::rotulo($estado), 'cantidad' => $cantidad,
                    'tono' => self::TONOS[$estado], 'icono' => self::ICONOS[$estado]];
            }
        }

        return $filas;
    }

    /**
     * Las vacunas con sus dosis ya listas para pintar. Con el filtro «pendientes» sólo quedan las dosis atrasadas y próximas.
     *
     * @return array<int,array{codigo:string, vacuna:string, tipo:string, cobertura:?string, sinEdad:bool, dosis:array<int,array<string,mixed>>}>
     */
    public function vacunas(): array
    {
        $salida = [];

        foreach ($this->calendario['vacunas'] as $vacuna) {
            $dosis = [];
            foreach ($vacuna['dosis'] as $d) {
                if ($this->filtro === self::PENDIENTES && ! in_array($d['estado'], [CalendarioDeVacunas::ATRASADA, CalendarioDeVacunas::PROXIMA], true)) {
                    continue;
                }

                $dosis[] = [
                    'codigo' => $vacuna['codigo'],
                    'nombre' => $vacuna['vacuna'],
                    'numero' => $d['numero'],
                    'planeadaIso' => $d['planeada'],
                    'registrable' => $this->urlRegistrar !== null && $d['numero'] !== null
                        && ! in_array($d['estado'], [CalendarioDeVacunas::APLICADA, CalendarioDeVacunas::VENCIDA], true),
                    // **Programar** (O-68/O-69): sólo lo que el catálogo propone, que todavía no está en la lista del paciente y
                    // cuya ventana sigue abierta. Es la única forma en que el esquema agrega algo: el médico aprieta y la fila
                    // aparece en la lista (nunca solo).
                    'programable' => $this->urlProgramar !== null && $d['numero'] !== null && $d['planeada'] !== null
                        && ! in_array($d['estado'], [CalendarioDeVacunas::APLICADA, CalendarioDeVacunas::VENCIDA], true) && ! $d['enLista'],
                    'enLista' => $d['enLista'],
                    'rotulo' => $d['rotulo'],
                    'planeada' => self::fecha($d['planeada']),
                    'vence' => self::fecha($d['vence'] ?? null),
                    'aplicada' => self::fecha($d['aplicada']),
                    'lote' => $d['lote'],
                    'estado' => CalendarioDeVacunas::rotulo($d['estado']),
                    'clave' => $d['estado'],
                    'tono' => self::TONOS[$d['estado']],
                    'icono' => self::ICONOS[$d['estado']],
                ];
            }

            if ($this->filtro === self::PENDIENTES && $dosis === []) {
                continue;
            }

            $salida[] = ['dosis' => $dosis] + array_diff_key($vacuna, ['dosis' => 1]);
        }

        return $salida;
    }

    /** Las aplicadas de vacunas que el médico ya no tiene en su catálogo. @return array<int,array<string,mixed>> */
    public function otras(): array
    {
        if ($this->filtro === self::PENDIENTES) {
            return [];
        }

        return array_map(fn ($o) => ['aplicada' => self::fecha($o['aplicada'])] + $o, $this->calendario['otras']);
    }

    public function hayAlgoQueMostrar(): bool
    {
        return $this->vacunas() !== [] || $this->otras() !== [];
    }

    /** @return array{titulo:string, texto:string} */
    public function vacioDelFiltro(): array
    {
        return $this->filtro === self::PENDIENTES
            ? ['titulo' => 'Nada pendiente', 'texto' => 'No hay dosis atrasadas ni próximas en los próximos ' . CalendarioDeVacunas::DIAS_PROXIMA . ' días.']
            : ['titulo' => 'Sin dosis que mostrar', 'texto' => 'Las vacunas del catálogo no tienen una edad ni dosis definidas para este niño.'];
    }

    private static function fecha(?string $fecha): ?string
    {
        return $fecha === null ? null : Carbon::parse($fecha)->format(\App\Support\Regionalizacion::formatoActual());
    }
}
