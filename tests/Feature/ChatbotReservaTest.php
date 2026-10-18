<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Models\Paciente;
use App\Services\PacienteAtencionContextService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La reserva del chatbot de WhatsApp después de la auditoría del 2026-10-17 (§6, «agendar por `CrearCita`
 * o no agendar»).
 *
 * Lo que fija: la cita nace por la **Action compartida** —con su rastro en `sync_changes` y
 * `source = whatsapp`— y **dónde cae** lo dice `office_schedules` + `ArmadorDeAgenda`, no una hora
 * inventada: la sede del bloque, el número de la jornada y la hora del bloque (o el primer hueco, en una
 * sede con hora de cita).
 */
class ChatbotReservaTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $fecha;

    protected function setUp(): void
    {
        parent::setUp();

        // Un día de atención: el domingo la agenda no abre y el bot ya lo rechazaba.
        $this->fecha = Carbon::today()->isSunday() ? Carbon::tomorrow() : Carbon::today();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_la_cita_nace_por_crear_cita_en_el_bloque_del_dia_con_su_sede(): void
    {
        $medico = $this->medico();
        $sede = $this->sede($medico, Office::MODALIDAD_ORDEN, 'C-ORDEN');
        $this->bloque($sede, '08:00', '12:00');
        $paciente = $this->paciente('584165800403');

        // Una cita ya tomada: la nueva tiene que quedar detrás del mismo bloque.
        Cola::create([
            'reg_medico' => $medico->reg_medico,
            'fecha' => $this->fecha->toDateString(),
            'hora_ini' => '08:00',
            'numorden' => 1,
            'atendido' => 0,
            'estado' => 0,
            'medical_center_id' => $sede->medical_center_id,
        ]);

        $resultado = app(PacienteAtencionContextService::class)
            ->reservarCitaEnCola($paciente->telefono, $medico->reg_medico, $this->fecha->toDateString());

        $this->assertTrue($resultado['exito'], $resultado['mensaje'] ?? $resultado['error'] ?? '');

        $cita = Cola::where('reg_medico', $medico->reg_medico)
            ->where('paciente_sinhistoria_id', $paciente->id)
            ->firstOrFail();

        $this->assertSame($sede->medical_center_id, (int) $cita->medical_center_id, 'La cita nace con la sede del bloque.');
        $this->assertSame($this->fecha->toDateString(), Carbon::parse($cita->fecha)->toDateString());
        $this->assertSame('08:00', substr((string) $cita->hora_ini, 0, 5), 'Por orden de llegada la hora es la apertura del bloque.');
        $this->assertSame(2, (int) $cita->numorden, 'El número sigue a la jornada, no al día.');

        // El rastro de la Action compartida, con el origen del bot.
        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'cola',
            'record_id' => $cita->id,
            'operation' => 'created',
            'source' => 'whatsapp',
        ]);
    }

    public function test_en_una_sede_con_hora_de_cita_toma_el_primer_hueco_del_bloque(): void
    {
        $medico = $this->medico();
        $sede = $this->sede($medico, Office::MODALIDAD_HORA, 'C-HORA');
        $this->bloque($sede, '14:00', '18:00');
        $paciente = $this->paciente('584165800404');

        Cola::create([
            'reg_medico' => $medico->reg_medico,
            'fecha' => $this->fecha->toDateString(),
            'hora_ini' => '14:00',
            'numorden' => 1,
            'atendido' => 0,
            'estado' => 0,
            'medical_center_id' => $sede->medical_center_id,
        ]);

        $resultado = app(PacienteAtencionContextService::class)
            ->reservarCitaEnCola($paciente->telefono, $medico->reg_medico, $this->fecha->toDateString());

        $this->assertTrue($resultado['exito'], $resultado['mensaje'] ?? $resultado['error'] ?? '');

        $cita = Cola::where('reg_medico', $medico->reg_medico)
            ->where('paciente_sinhistoria_id', $paciente->id)
            ->firstOrFail();

        // La sede dura 30 minutos (el valor de la ayuda): el segundo paciente entra a las 14:30.
        $this->assertSame('14:30', substr((string) $cita->hora_ini, 0, 5));
        $this->assertSame(2, (int) $cita->numorden);
    }

    /** Un médico sin bloques configurados (el estado del legado) conserva la cuenta de respaldo. */
    public function test_sin_bloques_configurados_la_cita_nace_con_la_hora_de_respaldo_y_sin_sede(): void
    {
        $medico = $this->medico();
        $paciente = $this->paciente('584165800405');

        $resultado = app(PacienteAtencionContextService::class)
            ->reservarCitaEnCola($paciente->telefono, $medico->reg_medico, $this->fecha->toDateString());

        $this->assertTrue($resultado['exito'], $resultado['mensaje'] ?? $resultado['error'] ?? '');

        $cita = Cola::where('reg_medico', $medico->reg_medico)
            ->where('paciente_sinhistoria_id', $paciente->id)
            ->firstOrFail();

        $this->assertNull($cita->medical_center_id);
        $this->assertSame('08:00', substr((string) $cita->hora_ini, 0, 5));
        $this->assertSame(1, (int) $cita->numorden);
    }

    /** El bloque lleno no es un tope: se busca el siguiente bloque o el día siguiente. */
    public function test_un_bloque_lleno_manda_la_cita_al_bloque_siguiente(): void
    {
        $medico = $this->medico();
        $sede = $this->sede($medico, Office::MODALIDAD_ORDEN, 'C-LLENO');
        $this->bloque($sede, '08:00', '10:00', 1);
        $this->bloque($sede, '14:00', '16:00');
        $paciente = $this->paciente('584165800406');

        Cola::create([
            'reg_medico' => $medico->reg_medico,
            'fecha' => $this->fecha->toDateString(),
            'hora_ini' => '08:00',
            'numorden' => 1,
            'atendido' => 0,
            'estado' => 0,
            'medical_center_id' => $sede->medical_center_id,
        ]);

        $resultado = app(PacienteAtencionContextService::class)
            ->reservarCitaEnCola($paciente->telefono, $medico->reg_medico, $this->fecha->toDateString());

        $this->assertTrue($resultado['exito'], $resultado['mensaje'] ?? $resultado['error'] ?? '');

        $cita = Cola::where('reg_medico', $medico->reg_medico)
            ->where('paciente_sinhistoria_id', $paciente->id)
            ->firstOrFail();

        $this->assertSame('14:00', substr((string) $cita->hora_ini, 0, 5));
        $this->assertSame(1, (int) $cita->numorden, 'Cada jornada arranca en 1.');
    }

    public function test_no_agenda_un_domingo(): void
    {
        $medico = $this->medico();
        $this->sede($medico, Office::MODALIDAD_ORDEN, 'C-DOM');
        $paciente = $this->paciente('584165800407');

        $resultado = app(PacienteAtencionContextService::class)
            ->reservarCitaEnCola($paciente->telefono, $medico->reg_medico, Carbon::today()->next(Carbon::SUNDAY)->toDateString());

        $this->assertFalse($resultado['exito']);
        $this->assertStringContainsString('domingos', $resultado['mensaje']);
    }

    // ----------------------------------------------------------------------------------- Ayudas

    private function medico(): Medico
    {
        $regMedico = 'bot-test-' . uniqid();

        $medico = Medico::create([
            'name' => 'Dr. de prueba',
            'lastname' => 'Bot',
            'reg_medico' => $regMedico,
        ]);

        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return $medico;
    }

    private function sede(Medico $medico, string $modalidad, string $numero): Office
    {
        $centro = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id' => DB::table('estados')->value('id'),
            'city_id' => DB::table('cities')->value('id'),
            'name' => 'Centro ' . uniqid(),
            'address' => 'Dirección de prueba',
        ]);

        return Office::create([
            'medical_center_id' => $centro->id,
            'medico_id' => $medico->id,
            'reg_medico' => $medico->reg_medico,
            'office_number' => $numero,
            'modalidad' => $modalidad,
            'duracion_cita' => 30,
            'activo' => true,
        ]);
    }

    private function bloque(Office $sede, string $inicio, string $fin, ?int $cupo = null): OfficeSchedule
    {
        return OfficeSchedule::create([
            'office_id' => $sede->id,
            'reg_medico' => $sede->reg_medico,
            'dia_semana' => $this->fecha->dayOfWeekIso,
            'hora_inicio' => $inicio,
            'hora_fin' => $fin,
            'cupo' => $cupo,
        ]);
    }

    private function paciente(string $telefono): Paciente
    {
        return Paciente::create([
            'nombres' => 'Ana',
            'apellidos' => 'Pérez',
            'cedula' => (string) random_int(10000000, 30000000),
            'telefono' => $telefono,
            'sexo' => 'F',
            'fnacimiento' => '1990-05-04',
        ]);
    }
}
