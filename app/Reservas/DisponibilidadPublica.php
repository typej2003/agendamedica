<?php

namespace App\Reservas;

use App\Clinica\Agenda\ArmadorDeAgenda;
use App\Clinica\FechaDelConsultorio;
use App\Models\Medico;
use App\Models\Office;
use App\Services\PacienteAtencionContextService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * **La disponibilidad que ve el paciente**, sin autenticarse.
 *
 * No inventa una agenda paralela: usa `PacienteAtencionContextService::primerLugarDisponible`, que es el
 * mismo camino con el que el chatbot reserva y que a su vez se apoya en `ArmadorDeAgenda`/`Jornada`
 * (bloques, cupo, modalidad, sede y número). Lo único que agrega acá es el recorte a **un horario por
 * día** —lo que el portal ofrece para que reservar sea rápido— y el objeto `OpcionDeReserva` que la
 * vista puede pintar y el formulario puede volver a comprobar.
 */
final class DisponibilidadPublica
{
    /** Las sedes de cada médico, para no repetir la consulta en el catálogo y en su ficha. */
    private array $sedesPorMedico = [];

    public function __construct(
        private PacienteAtencionContextService $atencion,
        private ArmadorDeAgenda $armador,
    ) {
    }

    /**
     * Las próximas `$cantidad` ofertas, **una por bloque de horario** (si el médico atiende mañana y tarde,
     * salen ambas). Las horas y la anticipación de 24 h salen de `PacienteAtencionContextService::lugaresDelDia`,
     * el mismo cálculo del chatbot. Si no hay agenda configurada devuelve vacío.
     *
     * @return array<int,OpcionDeReserva>
     */
    public function opciones(Medico $medico, int $cantidad = 5, int $diasMaximos = 30): array
    {
        $regMedico = $medico->regMedicoPrincipal();

        if ($regMedico === null) {
            return [];
        }

        $opciones = [];
        $hoy = FechaDelConsultorio::hoyComoFecha();

        for ($i = 0; $i < $diasMaximos && count($opciones) < $cantidad; $i++) {
            foreach ($this->lugaresDelDia($regMedico, $medico, $hoy->copy()->addDays($i)) as $opcion) {
                if (count($opciones) >= $cantidad) {
                    break;
                }

                $opciones[] = $opcion;
            }
        }

        return $opciones;
    }

    /**
     * **La comprobación del horario elegido**: ¿sigue ofreciéndose ese bloque (hora y sede) con la agenda de
     * este momento y con la anticipación mínima? Si no (se llenó, se movió, quedó a menos de 24 h), la
     * reserva no se crea.
     */
    public function opcionEn(Medico $medico, string $fecha, string $hora, int $officeId): ?OpcionDeReserva
    {
        $regMedico = $medico->regMedicoPrincipal();

        if ($regMedico === null) {
            return null;
        }

        $dia = Carbon::createFromFormat('!Y-m-d', $fecha);
        $hora = substr($hora, 0, 5);

        foreach ($this->lugaresDelDia($regMedico, $medico, $dia) as $opcion) {
            if ($opcion->hora === $hora && $opcion->officeId === $officeId) {
                return $opcion;
            }
        }

        return null;
    }

    /** @return array<int,OpcionDeReserva> */
    private function lugaresDelDia(string $regMedico, Medico $medico, Carbon $dia): array
    {
        $opciones = [];

        foreach ($this->atencion->lugaresDelDia($regMedico, $medico, $dia) as $lugar) {
            if (! empty($lugar['sede'])) {
                $opciones[] = $this->opcion($dia, $lugar);
            }
        }

        return $opciones;
    }

    /** Las sedes donde el médico atiende y tiene bloques cargados (lo que el catálogo ofrece). */
    public function sedes(Medico $medico): Collection
    {
        if (array_key_exists((int) $medico->id, $this->sedesPorMedico)) {
            return $this->sedesPorMedico[(int) $medico->id];
        }

        $regMedico = $medico->regMedicoPrincipal();

        if ($regMedico === null) {
            return $this->sedesPorMedico[(int) $medico->id] = collect();
        }

        return $this->sedesPorMedico[(int) $medico->id] = $this->armador->sedes($regMedico, $medico->id)
            ->filter(fn (Office $office) => $office->schedules->isNotEmpty())
            ->values();
    }

    public function tieneAgenda(Medico $medico): bool
    {
        return $this->sedes($medico)->isNotEmpty();
    }

    /** @param array{disponible: bool, fecha: string, hora: string, numorden: int, centro_id: ?int, sede: Office} $lugar */
    private function opcion(Carbon $fecha, array $lugar): OpcionDeReserva
    {
        /** @var Office $sede */
        $sede = $lugar['sede'];

        return new OpcionDeReserva(
            fecha: $fecha->toDateString(),
            hora: substr((string) $lugar['hora'], 0, 5),
            officeId: (int) $sede->id,
            centroId: $lugar['centro_id'] !== null ? (int) $lugar['centro_id'] : null,
            sede: $sede->medicalCenter->name ?? 'Consultorio',
            direccion: $sede->medicalCenter->address ?? '',
            numorden: (int) ($lugar['numorden'] ?? 1),
        );
    }
}
