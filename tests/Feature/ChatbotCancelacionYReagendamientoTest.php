<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Models\Paciente;
use App\Models\SyncChange;
use App\Services\GeminiService;
use App\Services\PacienteAtencionContextService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ChatbotCancelacionYReagendamientoTest extends TestCase
{
    use DatabaseTransactions;

    protected PacienteAtencionContextService $contextService;
    protected GeminiService $geminiService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contextService = app(PacienteAtencionContextService::class);
        $this->geminiService = app(GeminiService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_cancelacion_de_cita_en_dos_pasos_solicita_confirmacion_y_luego_elimina(): void
    {
        $tel = '584149998877';

        $paciente = Paciente::create([
            'nombres' => 'Carlos',
            'apellidos' => 'Mendoza',
            'telefono' => $tel,
            'cedula' => 'V-99887766',
        ]);

        $medico = Medico::create([
            'name' => 'Roberto',
            'lastname' => 'Canales',
            'prefix' => 'Dr.',
            'reg_medico' => 'MED-CANC-01',
            'is_active' => true,
        ]);

        $fechaFutura = Carbon::now()->addDays(3);
        if ($fechaFutura->isSunday()) {
            $fechaFutura->addDay();
        }

        $cita = Cola::create([
            'reg_medico' => 'MED-CANC-01',
            'fecha' => $fechaFutura->toDateString(),
            'paciente_sinhistoria_id' => $paciente->id,
            'numorden' => 2,
            'atendido' => 0,
            'estado' => Cola::ESTADO_CONFIRMADA_PACIENTE,
            'turno' => 'M',
            'hora_ini' => '09:00',
            'medico' => $medico->id,
        ]);

        // Paso 1: Paciente solicita cancelar su cita
        $respuestaPaso1 = $this->geminiService->generarRespuesta('Quiero cancelar mi cita con el Dr. Roberto Canales', $tel);

        $this->assertStringContainsString('CONFIRMACIÓN DE CANCELACIÓN', $respuestaPaso1);
        $this->assertStringContainsString('Roberto Canales', $respuestaPaso1);
        $this->assertStringContainsString('responde *SÍ* o *CONFIRMAR*', $respuestaPaso1);

        // La cita todavía debe existir en la base de datos (no se borra en paso 1)
        $this->assertDatabaseHas('cola', ['id' => $cita->id]);

        // Verificamos que se guardó en cache la intención de cancelación
        $cleanTel = preg_replace('/\D/', '', $tel);
        $this->assertTrue(Cache::has("wa_pendiente_cancelar_{$tel}") || Cache::has("wa_pendiente_cancelar_{$cleanTel}"));

        // Paso 2: Paciente confirma con "Sí"
        $respuestaPaso2 = $this->geminiService->generarRespuesta('Sí, confirmo la cancelación', $tel);

        $this->assertStringContainsString('CITA CANCELADA CON ÉXITO', $respuestaPaso2);
        $this->assertStringContainsString('Roberto Canales', $respuestaPaso2);
        $this->assertStringContainsString('cupo ha sido liberado', $respuestaPaso2);

        // La cita ahora debe haber sido eliminada de cola
        $this->assertDatabaseMissing('cola', ['id' => $cita->id]);

        // sync_changes debe registrar la eliminación
        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'cola',
            'record_id' => $cita->id,
            'operation' => 'deleted',
        ]);

        // El cache debe haberse limpiado
        $this->assertFalse(Cache::has("wa_pendiente_cancelar_{$tel}"));
        $this->assertFalse(Cache::has("wa_pendiente_cancelar_{$cleanTel}"));
    }

    public function test_cancelacion_de_cita_se_aborta_si_el_paciente_rechaza_en_el_paso_dos(): void
    {
        $tel = '584149998878';

        $paciente = Paciente::create([
            'nombres' => 'Elena',
            'apellidos' => 'Padrón',
            'telefono' => $tel,
            'cedula' => 'V-99887767',
        ]);

        $medico = Medico::create([
            'name' => 'Valeria',
            'lastname' => 'Morales',
            'prefix' => 'Dra.',
            'reg_medico' => 'MED-CANC-02',
            'is_active' => true,
        ]);

        $fechaFutura = Carbon::now()->addDays(2);
        if ($fechaFutura->isSunday()) {
            $fechaFutura->addDay();
        }

        $cita = Cola::create([
            'reg_medico' => 'MED-CANC-02',
            'fecha' => $fechaFutura->toDateString(),
            'paciente_sinhistoria_id' => $paciente->id,
            'numorden' => 1,
            'atendido' => 0,
            'estado' => Cola::ESTADO_CONFIRMADA_PACIENTE,
            'turno' => 'M',
            'hora_ini' => '08:30',
            'medico' => $medico->id,
        ]);

        // Paso 1: Solicita cancelar
        $this->geminiService->generarRespuesta('Por favor elimina mi cita', $tel);

        // Paso 2: Paciente desiste diciendo "No"
        $respuestaRechazo = $this->geminiService->generarRespuesta('No, no la canceles, me equivoqué', $tel);

        $this->assertStringContainsString('no hemos cancelado tu cita', $respuestaRechazo);
        $this->assertStringContainsString('Valeria Morales', $respuestaRechazo);

        // La cita debe permanecer activa
        $this->assertDatabaseHas('cola', ['id' => $cita->id]);

        $cleanTel = preg_replace('/\D/', '', $tel);
        $this->assertFalse(Cache::has("wa_pendiente_cancelar_{$tel}"));
        $this->assertFalse(Cache::has("wa_pendiente_cancelar_{$cleanTel}"));
    }

    public function test_cita_con_pago_registrado_no_se_cancela_y_se_orienta_a_gestion_manual(): void
    {
        $tel = '584149998879';

        $paciente = Paciente::create([
            'nombres' => 'Gabriel',
            'apellidos' => 'Ríos',
            'telefono' => $tel,
            'cedula' => 'V-99887768',
        ]);

        $medico = Medico::create([
            'name' => 'Daniel',
            'lastname' => 'Vargas',
            'prefix' => 'Dr.',
            'reg_medico' => 'MED-PAGO-01',
            'phone' => '0414-7778899',
            'is_active' => true,
        ]);

        $fechaFutura = Carbon::now()->addDays(2);
        if ($fechaFutura->isSunday()) {
            $fechaFutura->addDay();
        }

        // Cita con pago registrado
        $cita = Cola::create([
            'reg_medico' => 'MED-PAGO-01',
            'fecha' => $fechaFutura->toDateString(),
            'paciente_sinhistoria_id' => $paciente->id,
            'numorden' => 1,
            'atendido' => 0,
            'monto_pagado' => 45.00,
            'estado' => Cola::ESTADO_CONFIRMADA_PACIENTE,
            'turno' => 'M',
            'hora_ini' => '08:00',
            'medico' => $medico->id,
        ]);

        $respuesta = $this->geminiService->generarRespuesta('Quiero cancelar mi cita con el Dr. Daniel Vargas', $tel);

        $this->assertStringContainsString('CITA CON PAGO REGISTRADO', $respuesta);
        $this->assertStringContainsString('cuenta con un pago registrado', $respuesta);
        $this->assertStringContainsString('45,00', $respuesta);
        $this->assertStringContainsString('comunícate directamente con la secretaría o el consultorio', $respuesta);
        $this->assertStringContainsString('0414-7778899', $respuesta);

        // La cita NO debe ser eliminada
        $this->assertDatabaseHas('cola', ['id' => $cita->id]);

        // Tampoco debe quedar pendiente de confirmación en cache
        $cleanTel = preg_replace('/\D/', '', $tel);
        $this->assertFalse(Cache::has("wa_pendiente_cancelar_{$tel}"));
        $this->assertFalse(Cache::has("wa_pendiente_cancelar_{$cleanTel}"));
    }

    public function test_cita_del_mismo_dia_despues_de_hora_de_cierre_se_detecta_como_perdida_y_permite_reagendar(): void
    {
        $tel = '584149998880';

        $paciente = Paciente::create([
            'nombres' => 'Patricia',
            'apellidos' => 'Salazar',
            'telefono' => $tel,
            'cedula' => 'V-99887769',
        ]);

        $centro = MedicalCenter::create([
            'country_id' => 1,
            'state_id' => 1,
            'city_id' => 1,
            'name' => 'Clínica San Lucas',
            'address' => 'Av. Principal',
            'activo' => true,
        ]);

        $medico = Medico::create([
            'name' => 'Andrés',
            'lastname' => 'Cárdenas',
            'prefix' => 'Dr.',
            'reg_medico' => 'MED-HOY-01',
            'is_active' => true,
        ]);

        $office = Office::create([
            'medical_center_id' => $centro->id,
            'medico_id' => $medico->id,
            'reg_medico' => 'MED-HOY-01',
            'office_number' => 'Cons. 5',
            'modalidad' => Office::MODALIDAD_ORDEN,
            'duracion_cita' => 20,
            'activo' => true,
        ]);

        $zona = \App\Clinica\FechaDelConsultorio::zona();
        // Simulamos un día a las 15:00 en el consultorio (después del horario de 12:00)
        Carbon::setTestNow(Carbon::parse('2026-11-02 15:00:00', $zona));
        $hoy = \App\Clinica\FechaDelConsultorio::hoyComoFecha();

        // Horario para hoy: 08:00 a 12:00
        OfficeSchedule::create([
            'office_id' => $office->id,
            'reg_medico' => 'MED-HOY-01',
            'dia_semana' => $hoy->dayOfWeekIso,
            'hora_inicio' => '08:00',
            'hora_fin' => '12:00',
            'cupo' => 10,
        ]);

        // Cita para hoy a las 08:00 que no fue atendida
        $citaHoy = Cola::create([
            'reg_medico' => 'MED-HOY-01',
            'fecha' => $hoy->toDateString(),
            'paciente_sinhistoria_id' => $paciente->id,
            'numorden' => 1,
            'atendido' => 0,
            'estado' => Cola::ESTADO_CONFIRMADA_PACIENTE,
            'turno' => 'M',
            'hora_ini' => '08:00',
            'medical_center_id' => $centro->id,
            'medico' => $medico->id,
        ]);

        $citaData = $this->contextService->obtenerCitaPendienteConMedicoPorTelefono($tel, 'MED-HOY-01');

        $this->assertNotNull($citaData);
        $this->assertTrue($citaData['cita_perdida'], 'La cita de hoy debió ser marcada como cita perdida porque ya concluyó el horario');
        $this->assertStringContainsString('CITA PREVIA NO ATENDIDA', $citaData['mensaje']);
        $this->assertStringContainsString('cita perdida', $citaData['mensaje']);
    }
}
