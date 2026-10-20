<?php

namespace App\Console\Commands;

use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Reservas\DisponibilidadPublica;
use Illuminate\Console\Command;

/**
 * **Por qué el portal `/reservar` no ofrece a un médico**: recorre los médicos activos y dice, por cada uno,
 * qué le falta de lo que `CatalogoDeMedicos` exige (activo, especialidad activa, `reg_medico`, consultorio
 * activo en una sede activa y al menos un bloque de horario).
 *
 * `--demo` arma una agenda de prueba (lunes a viernes, 08:00-12:00 y 14:00-17:00) a los que no la tienen, en
 * la primera sede activa. **No corre en producción**: ahí el médico carga su consultorio y su horario en
 * `/clinica/consultorios`.
 */
class ReservasDiagnosticoCommand extends Command
{
    protected $signature = 'reservas:diagnostico
        {--demo : crea consultorio y horario de prueba a los médicos que no tienen agenda (sólo fuera de producción)}';

    protected $description = 'Dice qué le falta a cada médico para aparecer en el portal público de reservas';

    public function handle(DisponibilidadPublica $disponibilidad): int
    {
        $demo = (bool) $this->option('demo');

        if ($demo && app()->environment('production')) {
            $this->error('--demo no se permite en producción: el médico carga su agenda en /clinica/consultorios.');

            return self::FAILURE;
        }

        $centro = MedicalCenter::activos()->orderBy('id')->first();
        $filas = [];
        $visibles = 0;

        foreach (Medico::query()->with('specialties')->orderBy('id')->get() as $medico) {
            $motivo = $this->motivo($medico, $disponibilidad);

            if ($motivo !== null && $demo && $this->armarAgendaDeDemo($medico, $centro)) {
                $motivo = $this->motivo($medico, $disponibilidad, true);
                $motivo = $motivo === null ? 'agenda de prueba creada' : $motivo;
            }

            $motivo === null ? $visibles++ : null;
            $filas[] = [$medico->id, trim($medico->name . ' ' . $medico->lastname), $motivo ?? 'visible'];
        }

        $this->table(['id', 'médico', 'estado'], $filas);
        $this->line(sprintf('Visibles en el portal: <info>%d</info> de %d.', $visibles, count($filas)));

        if ($centro === null) {
            $this->warn('No hay ninguna sede activa (medical_centers.activo): sin ella ningún consultorio es vigente.');
        }

        return self::SUCCESS;
    }

    private function motivo(Medico $medico, DisponibilidadPublica $disponibilidad, bool $recalcular = false): ?string
    {
        if ($recalcular) {
            $medico = Medico::with('specialties')->find($medico->id);
            $disponibilidad = app(DisponibilidadPublica::class);
        }

        if (! $medico->is_active) {
            return 'inactivo (medicos.is_active)';
        }

        if (! $medico->specialties->where('activo', true)->count()) {
            return 'sin especialidad activa (medico_specialty)';
        }

        if ($medico->regMedicoPrincipal() === null) {
            return 'sin reg_medico';
        }

        if (! $disponibilidad->tieneAgenda($medico)) {
            return 'sin consultorio activo con horario en una sede activa';
        }

        return null;
    }

    private function armarAgendaDeDemo(Medico $medico, ?MedicalCenter $centro): bool
    {
        $reg = $medico->regMedicoPrincipal();

        if ($centro === null || $reg === null || ! $medico->is_active) {
            return false;
        }

        $office = Office::updateOrCreate(
            ['medico_id' => $medico->id, 'medical_center_id' => $centro->id],
            [
                'reg_medico' => $reg,
                'office_number' => Office::where('medico_id', $medico->id)
                    ->where('medical_center_id', $centro->id)->value('office_number') ?? 'Consultorio 1',
                'modalidad' => Office::MODALIDAD_HORA,
                'duracion_cita' => Office::DURACION_CITA_POR_DEFECTO,
                'activo' => true,
            ],
        );

        if ($office->schedules()->exists()) {
            return true;
        }

        foreach (range(1, 5) as $dia) {
            foreach ([['08:00', '12:00'], ['14:00', '17:00']] as [$inicio, $fin]) {
                OfficeSchedule::create([
                    'office_id' => $office->id,
                    'reg_medico' => $reg,
                    'dia_semana' => $dia,
                    'hora_inicio' => $inicio,
                    'hora_fin' => $fin,
                    'cupo' => null,
                ]);
            }
        }

        return true;
    }
}
