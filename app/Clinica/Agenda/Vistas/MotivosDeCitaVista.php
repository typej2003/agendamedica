<?php

namespace App\Clinica\Agenda\Vistas;

use App\Models\Medico;
use App\Models\MotivoCita;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * La pantalla **"Motivos de cita"** (`/clinica/agenda/motivos`, WEB-2.17): el catálogo de razones del
 * médico del contexto, con la marca de **admite varias citas pendientes** que usa la regla de la cita
 * única (`AgendamientoDeCitas`).
 *
 * Es un adelanto parcial del ABM de catálogos (WEB-8.1): acá solo se marca y se desmarca, que es lo único
 * que la excepción necesita. El código, la razón, el precio y la sede los sigue cargando el escritorio
 * —que es de donde llegan—, y por eso la pantalla no los edita: dar de alta un motivo sin `reg_medico`
 * del contexto o pisarle el precio a la sede es justo lo que WEB-8.1 tiene que decidir.
 */
final class MotivosDeCitaVista
{
    /** @param  Collection<int,MotivoCita>  $motivos  los del médico del contexto, con su sede cargada */
    public function __construct(
        private Collection $motivos,
        private Medico $medico,
    ) {
    }

    public function titulo(): string
    {
        return 'Motivos de cita';
    }

    public function subtitulo(): string
    {
        return 'Las razones con las que se agenda para ' . $this->medico->nombreMostrar
            . '. Un paciente tiene una sola cita pendiente por consultorio: los motivos marcados —masajistas, terapias— admiten varias.';
    }

    public function conteo(): string
    {
        $total = $this->motivos->count();

        return $total . ($total === 1 ? ' motivo' : ' motivos');
    }

    public function urlVolver(): string
    {
        return route('clinica.agenda', ['vista' => 'dia', 'fecha' => Carbon::today()->toDateString()]);
    }

    /** La acción de cada fila: la misma ruta para marcar y para desmarcar (alterna). */
    public function urlAlternar(MotivoCita $motivo): string
    {
        return route('clinica.agenda.motivos.alternar', $motivo->id);
    }

    public function estaVacio(): bool
    {
        return $this->motivos->isEmpty();
    }

    public function textoVacio(): string
    {
        return 'Todavía no hay motivos de cita.';
    }

    public function ayudaVacio(): string
    {
        return 'Los trae la sincronización del escritorio, que es donde se cargan las razones de la cita.';
    }

    /**
     * Una fila por motivo del catálogo, con lo que la tabla muestra ya resuelto: la razón, de qué sede es
     * (los que no tienen sede se ofrecen en todas), su precio y el estado de la marca con la acción que lo
     * cambia.
     *
     * @return array<int,array<string,mixed>>
     */
    public function filas(): array
    {
        return $this->motivos->map(function (MotivoCita $motivo) {
            $marcado = (bool) $motivo->admite_varias_citas_pendientes;

            return [
                'codigo' => (string) $motivo->codigo,
                'razon'  => $motivo->tipo_atencion ?: (string) $motivo->codigo,
                // Un motivo sin sede se ofrece en cualquier sede (el escritorio no conoce el concepto).
                'sede'   => $motivo->office_id === null
                    ? 'Todas las sedes'
                    : ($motivo->office?->medicalCenter?->name ?? '—'),
                'precio' => $motivo->precio === null
                    ? null
                    : number_format((float) $motivo->precio, 2, ',', '.'),
                'marca'  => $marcado
                    ? ['tono' => 'info', 'icono' => 'bi-people', 'texto' => 'Varias citas']
                    : ['tono' => 'neutro', 'icono' => 'bi-person', 'texto' => 'Una sola cita'],
                'accion' => $marcado
                    ? [
                        'texto'   => 'Volver a una sola',
                        'icono'   => 'bi-person',
                        'title'   => 'Volver a preguntar cuando el paciente ya tenga una cita pendiente',
                        'etiqueta' => 'Volver a una sola cita el motivo ' . ($motivo->tipo_atencion ?: $motivo->codigo),
                    ]
                    : [
                        'texto'   => 'Admitir varias',
                        'icono'   => 'bi-people',
                        'title'   => 'No preguntar por la cita pendiente con este motivo',
                        'etiqueta' => 'Admitir varias citas pendientes en el motivo ' . ($motivo->tipo_atencion ?: $motivo->codigo),
                    ],
                'urlAlternar' => $this->urlAlternar($motivo),
            ];
        })->all();
    }
}
