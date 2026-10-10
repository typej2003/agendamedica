<?php

namespace App\Clinica\Agenda\Vistas;

use App\Clinica\Agenda\CitaDeAgenda;
use App\Support\FechaClinica;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * El panel **"Pacientes sin historia a atender"** de la vista de día (WEB-2.11): las citas del día que
 * se están mirando cuyo paciente **todavía no tiene número de historia**, cada una con "Pasar a cita
 * formal".
 *
 * Es el `groupbox` + `dw_3` de `w_hacer_cita` (`:807-819`), que el escritorio pone al lado del listado
 * de la agenda. Lo que cambia es de dónde sale la lista: el escritorio la lee de `paciente_no_regi`
 * con `registrado = 'N'` y AppDDR **no usa esa tabla** (deuda del núcleo, anotada en
 * `TODO-WEB.md` WEB-2.11); acá la identidad "sin historia" es la cita anclada por
 * `cola.paciente_sinhistoria_id` sobre un vínculo `medico_pacientes` sin número.
 *
 * El panel es **informativo y no bloquea nada**: se ve siempre, aunque esté vacío (que es la buena
 * noticia del día), y una cita que no se pudo formalizar se avisa con `session('error')`, que el
 * layout ya muestra arriba.
 */
final class PacientesSinHistoriaVista
{
    /**
     * @param  Collection<int,CitaDeAgenda>  $citas  las del día que se está mirando, sin número de historia
     */
    public function __construct(
        private Collection $citas,
        private Carbon $fecha,
    ) {
    }

    public function titulo(): string
    {
        return 'Pacientes sin historia a atender';
    }

    /**
     * El subtítulo dice el día que se está mirando y para qué sirve el panel. También lleva el icono
     * del concepto: historia clínica (`bi-journal-medical`, guía de UI §6).
     */
    public function subtitulo(): string
    {
        return 'Citas del ' . FechaClinica::tituloDia($this->fecha)
            . ' de pacientes que todavía no tienen número de historia. Se lo asigna "Pasar a cita formal".';
    }

    /** Cuántas hay: el dato que la secretaría mira de reojo. */
    public function conteo(): string
    {
        $total = $this->citas->count();

        return $total === 0
            ? 'Ninguno'
            : $total . ($total === 1 ? ' paciente' : ' pacientes');
    }

    public function estaVacio(): bool
    {
        return $this->citas->isEmpty();
    }

    /** @return array{icono:string, titulo:string, texto:string} */
    public function vacio(): array
    {
        return [
            'icono'  => 'bi-journal-medical',
            'titulo' => 'Hoy no hay pacientes sin historia',
            'texto'  => 'Todos los pacientes de este día ya tienen su número de historia. Cuando la secretaría dé de alta una cita sin historia, aparece acá.',
        ];
    }

    /**
     * Una fila por paciente, con lo que se pinta ya resuelto. El orden es el del `hora_ini` de la
     * jornada (lo trae la lectura de la agenda), que es como el escritorio lista su panel.
     *
     * @return array<int,FilaSinHistoriaVista>
     */
    public function filas(): array
    {
        return $this->citas
            ->map(fn (CitaDeAgenda $cita) => new FilaSinHistoriaVista($cita))
            ->values()
            ->all();
    }
}
