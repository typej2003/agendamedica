<?php

namespace App\Clinica\Agenda;

use Carbon\Carbon;

/**
 * Lee la agenda de un rango para la web clínica: las citas, las jornadas con su posición calculada, los
 * días no laborables y el texto del recordatorio de cada cita.
 *
 * Nada de esto se recalcula en la vista: la jornada, la posición, la modalidad y el cupo los resuelve
 * `ArmadorDeAgenda` (portado del móvil); acá solo se decide qué entra según la sede y la razón pedidas.
 */
class LectorDeAgenda
{
    public function __construct(
        private ArmadorDeAgenda $armador,
        private DiasNoLaborables $diasNoLaborables,
        private MotivosDeCita $motivos,
        private RecordatoriosDeAgenda $recordatorios,
    ) {
    }

    public function leer(ParametrosDeAgenda $parametros, array $contexto): LecturaDeAgenda
    {
        $regMedico = $contexto['reg_medico'];
        [$desde, $hasta] = $parametros->rango();

        $sedes = $this->armador->sedes($regMedico, $contexto['medico']->id);
        $centroId = $parametros->sedeId ? $sedes->firstWhere('id', $parametros->sedeId)?->medical_center_id : null;

        // La posición del paciente se calcula sobre **todas** las citas del rango, no sobre las que
        // quedan visibles al filtrar: si la secretaria filtra, el paciente 7 sigue siendo el 7.
        $delRango = $this->armador->citas($regMedico, $desde, $hasta);
        $posiciones = $this->armador->posiciones($this->armador->jornadas($delRango, $sedes));

        $visibles = $centroId === null
            ? $delRango
            : $delRango->where('centroId', $centroId)->values();

        // Filtro por razón (WEB-2.14): el escritorio filtra la pantalla del día por el motivo de la
        // cita (`ddlb_1` de `w_hacer_cita` → `cola_tipo like '%XX%'`, con `TODOS`). Acá el código se
        // compara **exacto** en las dos columnas donde el legado lo guarda —`tipo`, que escriben el
        // escritorio y la web, y `motivo`, que escribe el móvil—, que es la misma regla con la que
        // cada fila muestra su razón (`CitaDeAgenda::razon`). Se aplica **después** de calcular las
        // posiciones: filtrar no renumera la cola (el paciente 7 sigue siendo el 7).
        $razones = $this->motivos->de($regMedico);
        $razon = $this->motivos->vigente($parametros->razonPedida, $razones);

        if ($razon !== null) {
            $visibles = $visibles
                ->filter(fn (CitaDeAgenda $cita) => trim((string) $cita->tipo) === $razon
                    || trim((string) $cita->motivo) === $razon)
                ->values();
        }

        return new LecturaDeAgenda(
            parametros: $parametros,
            sedes: $sedes,
            razones: $razones,
            razon: $razon,
            jornadas: $this->armador->jornadas($visibles, $sedes),
            posiciones: $posiciones,
            conteosPorDia: $visibles->groupBy('fecha')->map->count(),
            // Días no laborables (WEB-2.8): el aviso del día que se está mirando y, en la vista de mes,
            // la marca de cada día del rango para no ver solo el conteo de citas.
            noLaborables: $this->diasNoLaborables->entre($regMedico, $desde->copy(), $hasta->copy()),
            avisoNoLaborable: $this->diasNoLaborables->avisoDelDia($contexto, $parametros->fecha),
            totalRango: $visibles->count(),
            // Recordatorio por cita (WEB-2.6): el texto del **médico de esa cita** ya armado, para que
            // el diálogo de cada fila lo ofrezca sin una consulta por fila.
            mensajes: $this->recordatorios->mensajesPara($regMedico, $visibles, $sedes),
            ahora: Carbon::now(),
        );
    }
}
