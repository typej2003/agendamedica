<?php

namespace App\Clinica\Vistas;

use App\Clinica\Agenda\CitaDeAgenda;
use App\Clinica\Agenda\LecturaDeAgenda;
use App\Clinica\Agenda\Vistas\FilaDeCitaVista;
use App\Notificaciones\Canal;
use App\Support\FechaClinica;

/**
 * **El inicio del médico: las consultas de hoy** (A-12, decisión del usuario). `/clinica` ya no es una pantalla
 * de accesos —las tarjetas «Agenda» y «Pacientes» se fueron porque el menú lateral ya lleva a las dos—: es la
 * lista de los pacientes del día del médico del contexto, **en la sede del contexto**, para que al entrar se vea
 * de un vistazo quién viene y qué falta.
 *
 * Lo que **no** tiene, a propósito: navegación entre días, vista de semana o mes, buscador de pacientes ni
 * filtros. No es otra agenda: es la lista del día. Lo que sí tiene son **la acción primaria del encabezado** —
 * «Agregar paciente»: el alta del que llega sin cita para atenderlo hoy (O-34)— y **las mismas acciones de la fila
 * de la agenda** («Confirmar», «Atender»/«Editar consulta» y el menú con el recordatorio, el cobro y las citas del
 * paciente), porque la fila es el parcial `clinica.agenda._fila` y el armador es el mismo `LectorDeAgenda`.
 *
 * **Orden de atención**: las citas ya atendidas van primero (marcadas como en la agenda) y después las demás, y
 * dentro de cada grupo se mantiene el orden por hora. Es la lectura del día del médico, no la cola de una sede:
 * por eso se aplana la jornada y se ordena acá, en el ViewModel, y no se reusa `_jornada` (que ordena por
 * `numorden`/hora dentro de una sede y no conoce el grupo «atendidas»).
 *
 * Se prueba sin base de datos (`tests/Unit/Clinica/`), como todos los ViewModels: lo que necesita llega por el
 * constructor ya leído.
 */
final class InicioVista
{
    /** @var array<int,CitaDeAgenda>|null */
    private ?array $citas = null;

    /**
     * @param  array  $contexto  el `contexto_clinico` de la petición (médico, especialidad y sede)
     * @param  int  $cumpleanosDeHoy  cuántos pacientes del médico cumplen años hoy (WEB-2.9)
     * @param  array<int,int>  $citasConDocumentos  ids de cita atendida con documentos para imprimir
     */
    public function __construct(
        private LecturaDeAgenda $lectura,
        private array $contexto,
        private int $cumpleanosDeHoy = 0,
        private bool $puedeAbrirLaConsulta = true,
        private array $citasConDocumentos = [],
    ) {
    }

    public function titulo(): string
    {
        return 'Pacientes de hoy';
    }

    /** "Viernes 17/10/2026 · Carlos Mendoza", con la sede del contexto cuando el médico eligió una. */
    public function subtitulo(): string
    {
        $medico = $this->contexto['medico'];
        $nombre = trim(($medico->prefix ?? '') . ' ' . ($medico->name ?? '') . ' ' . ($medico->lastname ?? ''));
        $sede = $this->contexto['office'] ?? null;

        return collect([
            ucfirst(FechaClinica::tituloDia($this->lectura->parametros->fecha)),
            $nombre,
            $sede ? ($sede->office_number ?? ($sede->medicalCenter->name ?? null)) : null,
            $this->resumen(),
        ])->filter()->implode(' · ');
    }

    /** "3 de 8 atendidos", o `null` si no hay pacientes — el estado del día en una frase corta. */
    public function resumen(): ?string
    {
        $citas = $this->citas();

        if ($citas === []) {
            return null;
        }

        $atendidas = count(array_filter($citas, fn (CitaDeAgenda $cita) => $cita->atendida()));

        return $atendidas . ' de ' . count($citas) . ' atendidos';
    }

    /**
     * **La lista del día**, ya ordenada: primero las atendidas, después el resto y las que el escritorio movió al
     * final (como en la agenda); dentro de cada grupo, por hora.
     *
     * @return array<int,array{clave:string, fila:FilaDeCitaVista}>
     */
    public function filas(): array
    {
        return array_map(fn (CitaDeAgenda $cita) => [
            'clave' => (string) $cita->id,
            'fila' => new FilaDeCitaVista(
                $cita,
                // La posición (`#7`) es la de la jornada de la agenda: el inicio no renumera la cola.
                $this->lectura->posiciones[$cita->id] ?? null,
                false,
                $this->lectura->ahora,
                $this->lectura->mensajes[$cita->id] ?? '',
                $this->puedeAbrirLaConsulta,
                isset($this->citasConDocumentos[$cita->id]),
            ),
        ], $this->citas());
    }

    public function estaVacio(): bool
    {
        return $this->citas() === [];
    }

    /** @return array{icono:string, titulo:string, texto:string} */
    public function vacio(): array
    {
        return [
            'icono' => 'bi-calendar-x',
            'titulo' => 'Hoy no hay pacientes',
            'texto' => 'No hay citas para hoy en este consultorio. Puedes ir a la agenda a agendar una, o agregar al '
                . 'paciente que llegó sin cita para atenderlo hoy.',
        ];
    }

    public function urlAgenda(): string
    {
        return route('clinica.agenda', [
            'vista' => 'dia',
            'fecha' => $this->lectura->parametros->fecha->toDateString(),
        ]);
    }

    /**
     * **«Agregar paciente»**, la acción primaria del inicio (O-34, 2026-10-18): el alta de un paciente que llega
     * **sin cita** a atenderse hoy. No es un acceso al módulo Pacientes —para eso está el menú lateral— ni otra
     * pantalla: es el botón que lleva al alta de siempre.
     *
     * El camino completo ya existe y no se reimplementa: la web **numera la historia en el mismo paso** (O-04,
     * `PacienteController::persistir`) y después la ficha **ofrece atenderlo** (O-05, `AtencionInmediata`), que le
     * crea la cita del día con la sede del contexto y abre la consulta. O sea que «agregar paciente» acá siempre
     * significa «para atenderlo hoy», que es lo que el médico viene a hacer a esta pantalla.
     */
    public function urlPacienteNuevo(): string
    {
        return route('clinica.pacientes.nuevo');
    }

    /** La ayuda del botón (viaja en su `title`): dice qué hace el alta y qué pasa después, sin prometer la cita. */
    public function ayudaAgregarPaciente(): string
    {
        return 'Registra al paciente que llegó sin cita: queda con su historia y te ofrece atenderlo ahora, '
            . 'con la cita de hoy.';
    }

    /**
     * El aviso de cumpleaños del día (WEB-2.9), ahora una línea arriba de la lista: la pantalla de la que cuelga la
     * acción no es un módulo del menú, así que el inicio es el camino por el que el médico se entera de que hoy hay
     * saludos para mandar.
     */
    public function hayCumpleanosHoy(): bool
    {
        return $this->cumpleanosDeHoy > 0;
    }

    public function textoCumpleanos(): string
    {
        if ($this->cumpleanosDeHoy === 1) {
            return 'Hoy cumple años 1 paciente.';
        }

        return 'Hoy cumplen años ' . $this->cumpleanosDeHoy . ' pacientes.';
    }

    public function urlCumpleanos(): string
    {
        return route('clinica.cumpleanos');
    }

    /** Si la fila ofrece «Atender»: sólo el médico (la consulta es suya). */
    public function puedeAtender(): bool
    {
        return $this->puedeAbrirLaConsulta;
    }

    /** @return array<string,string> clave => etiqueta de los canales del recordatorio */
    public function canales(): array
    {
        return Canal::etiquetas();
    }

    /**
     * Las citas del día aplanadas y ordenadas (ver el docblock de la clase). Una sola vez por instancia: la usan
     * `filas()`, `estaVacio()` y `resumen()`.
     *
     * @return array<int,CitaDeAgenda>
     */
    private function citas(): array
    {
        if ($this->citas !== null) {
            return $this->citas;
        }

        $this->citas = $this->lectura->jornadas
            ->flatMap(fn ($jornada) => $jornada->citas)
            ->sort(function (CitaDeAgenda $a, CitaDeAgenda $b) {
                // Las que el escritorio movió van al final: no son parte de la cola del día (como en la agenda).
                if ($a->movida() !== $b->movida()) {
                    return $a->movida() ? 1 : -1;
                }

                // Las ya atendidas, al inicio de su grupo.
                if ($a->atendida() !== $b->atendida()) {
                    return $a->atendida() ? -1 : 1;
                }

                // Y dentro del grupo, por hora (con el número de orden como desempate del legado).
                return strcmp((string) $a->horaIni, (string) $b->horaIni)
                    ?: (($a->numOrden ?? PHP_INT_MAX) <=> ($b->numOrden ?? PHP_INT_MAX));
            })
            ->values()
            ->all();

        return $this->citas;
    }
}
