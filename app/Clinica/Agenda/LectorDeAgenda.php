<?php

namespace App\Clinica\Agenda;

use App\Clinica\FechaDelConsultorio;
use App\Models\Cola;
use App\Models\Office;
use Carbon\Carbon;
use Illuminate\Support\Collection;

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
            // "Ahora" **en la zona del consultorio**, no en UTC: es con lo que cada fila decide si
            // ofrece "Confirmar" (desde una hora antes) y "Atender" (solo el día de la cita). Con
            // `Carbon::now()` —UTC— un consultorio de Venezuela (UTC-4) apagaba esos botones a las
            // 20:00 locales, cuando en UTC ya es el día siguiente (`FechaDelConsultorio`, WEB-2.9).
            ahora: FechaDelConsultorio::ahora(),
            // El panel "Pacientes sin historia a atender" (WEB-2.11) es **del día que se está
            // mirando**, no del rango: en semana y mes no se muestra.
            pacientesSinHistoria: $parametros->vista === 'dia'
                ? $this->sinHistoria($regMedico, $parametros->fecha, $centroId)
                : collect(),
        );
    }

    /** @return Collection<int,Office> las sedes del médico del contexto */
    public function sedes(array $contexto): Collection
    {
        return $this->armador->sedes($contexto['reg_medico'], $contexto['medico']->id);
    }

    /**
     * Las citas del día y la sede pedidos, sin las que el escritorio movió (la fila vieja: el paciente ya
     * está en la fecha nueva). Es la base del listado que se imprime y del envío del día.
     *
     * @param  Collection<int,Office>  $sedes
     * @return Collection<int,CitaDeAgenda>
     */
    public function citasDelDia(array $contexto, Collection $sedes, Carbon $fecha, ?int $sedeId): Collection
    {
        $centroId = $sedeId ? $sedes->firstWhere('id', $sedeId)?->medical_center_id : null;

        return $this->armador->citas($contexto['reg_medico'], $fecha->copy(), $fecha->copy())
            ->when($centroId !== null, fn (Collection $citas) => $citas->where('centroId', $centroId))
            ->reject(fn (CitaDeAgenda $cita) => $cita->movida())
            ->values();
    }

    /**
     * Las citas de un día cuyo paciente **todavía no tiene número de historia** (panel "Pacientes sin
     * historia a atender", WEB-2.11).
     *
     * Cómo se reconoce: la cita está anclada al paciente por `cola.paciente_sinhistoria_id` y **no**
     * lleva `numhistoria`. Ese es el estado que deja el alta de la web cuando el paciente es nuevo
     * (WEB-2.2) y el que el propio "Pasar a cita formal" resuelve, así que la lista se vacía sola
     * después de asignar el número.
     *
     * No es "cualquier fila con `numhistoria` nulo": una cita del legado puede no tener ni número ni
     * ancla (sin paciente identificable) y esa **no** se ofrece para formalizar — no hay a quién
     * numerarle. Las condiciones del pivote son las mismas que muestra la fila de la agenda, así que
     * las citas de otro médico no entran ni por el pivote.
     *
     * @return Collection<int,CitaDeAgenda>
     */
    public function sinHistoria(string $regMedico, Carbon $fecha, ?int $centroId): Collection
    {
        $filas = Cola::where('reg_medico', $regMedico)
            ->whereDate('fecha', $fecha->toDateString())
            ->whereNull('numhistoria')
            ->whereNotNull('paciente_sinhistoria_id')
            ->whereIn('paciente_sinhistoria_id', function ($consulta) use ($regMedico) {
                $consulta->select('paciente_id')
                    ->from('medico_pacientes')
                    ->where('reg_medico', $regMedico)
                    ->where(function ($pivote) {
                        $pivote->whereNull('numhistoria')->orWhere('numhistoria', '');
                    });
            })
            ->orderBy('hora_ini')
            ->orderBy('numorden')
            ->get();

        return $this->armador->desdeFilas($regMedico, $filas)
            ->when($centroId !== null, fn (Collection $citas) => $citas->where('centroId', $centroId))
            ->values();
    }

    /**
     * Las citas del día a las que **se les puede recordar**: no las confirmadas ni las atendidas (misma
     * regla que el recordatorio de una cita). El listado que se imprime sí las lleva: ahí el estatus es
     * el dato.
     *
     * @param  Collection<int,Office>  $sedes
     * @return Collection<int,CitaDeAgenda>
     */
    public function citasParaRecordar(array $contexto, Collection $sedes, Carbon $fecha, ?int $sedeId): Collection
    {
        return $this->citasDelDia($contexto, $sedes, $fecha, $sedeId)
            ->filter(fn (CitaDeAgenda $cita) => $cita->puedeRecordar())
            ->values();
    }

    /**
     * El texto del recordatorio de cada cita, ya armado.
     *
     * @param  Collection<int,CitaDeAgenda>  $citas
     * @param  Collection<int,Office>  $sedes
     * @return array<int,string>  id de cita => mensaje
     */
    public function mensajesPara(array $contexto, Collection $citas, Collection $sedes): array
    {
        return $this->recordatorios->mensajesPara($contexto['reg_medico'], $citas, $sedes);
    }
}
