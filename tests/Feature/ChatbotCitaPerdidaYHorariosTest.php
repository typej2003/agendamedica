<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\DiaNoLaborable;
use App\Models\Evolucion;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Models\Paciente;
use App\Models\Specialty;
use App\Services\GeminiService;
use App\Services\PacienteAtencionContextService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ChatbotCitaPerdidaYHorariosTest extends TestCase
{
    use DatabaseTransactions;

    protected PacienteAtencionContextService $contextService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contextService = app(PacienteAtencionContextService::class);
    }

    public function test_paciente_con_cita_perdida_pasada_puede_cambiar_el_dia_y_reprogramar()
    {
        $tel = '584141112233';

        $paciente = Paciente::create([
            'nombres' => 'Juan',
            'apellidos' => 'Barrios',
            'telefono' => $tel,
            'cedula' => 'V-11223344',
        ]);

        $medico = Medico::create([
            'name' => 'Luis',
            'lastname' => 'Herrera',
            'prefix' => 'Dr.',
            'reg_medico' => 'MED-REP-01',
            'is_active' => true,
        ]);

        Evolucion::create([
            'reg_medico' => 'MED-REP-01',
            'clave' => 1,
            'especialidad' => 'Traumatología',
            'consultorio' => 'Cons. 12',
            'linea_1' => 'Hospital Clínico',
            'cantidad_paciente' => 10,
        ]);

        // Cita en el pasado relativo al consultorio sin atender (cita perdida)
        $fechaPasada = \App\Clinica\FechaDelConsultorio::hoyComoFecha()->subDays(2);
        $citaPerdida = Cola::create([
            'reg_medico' => 'MED-REP-01',
            'fecha' => $fechaPasada->toDateString(),
            'paciente_sinhistoria_id' => $paciente->id,
            'numorden' => 1,
            'atendido' => 0,
            'estado' => Cola::ESTADO_CONFIRMADA_PACIENTE,
            'turno' => 'M',
            'motivo' => 'Dolor de rodilla',
            'hora_ini' => '08:00',
            'tipo' => 'CONSULTA',
            'medico' => $medico->id,
        ]);

        $citaData = $this->contextService->obtenerCitaPendienteConMedicoPorTelefono($tel, 'MED-REP-01');
        $this->assertNotNull($citaData);
        $this->assertTrue($citaData['cita_perdida']);
        $this->assertStringContainsString('CITA PREVIA NO ATENDIDA', $citaData['mensaje']);

        // Cambiar el día de la cita a una fecha futura (al menos 24 h)
        $proximaFecha = Carbon::now()->addDays(2);
        if ($proximaFecha->isSunday()) {
            $proximaFecha->addDay();
        }

        $resCambio = $this->contextService->reprogramarCitaEnCola(
            $tel,
            'MED-REP-01',
            $proximaFecha->toDateString(),
            'Reprogramación por cita perdida'
        );

        $this->assertTrue($resCambio['exito']);
        $this->assertEquals($citaPerdida->id, $resCambio['cita_id']);
        $this->assertStringContainsString('CITA CAMBIADA CON ÉXITO', $resCambio['mensaje_confirmacion']);
        $this->assertStringContainsString('Luis Herrera', $resCambio['mensaje_confirmacion']);

        // Verificar que la fila en Cola fue actualizada in-place
        $citaPerdida->refresh();
        $this->assertEquals($proximaFecha->toDateString(), $citaPerdida->fecha->toDateString());
        $this->assertEquals(0, $citaPerdida->atendido);
        $this->assertEquals('Reprogramación por cita perdida', $citaPerdida->motivo);
    }

    public function test_reprogramar_cita_respeta_modalidad_orden_de_llegada()
    {
        $tel = '584142223344';

        $paciente = Paciente::create([
            'nombres' => 'Ana',
            'apellidos' => 'Torres',
            'telefono' => $tel,
            'cedula' => 'V-22334455',
        ]);

        $centro = MedicalCenter::create([
            'country_id' => 1,
            'state_id' => 1,
            'city_id' => 1,
            'name' => 'Centro Médico del Este',
            'address' => 'Av. Francisco de Miranda',
            'activo' => true,
        ]);

        $medico = Medico::create([
            'name' => 'Rosa',
            'lastname' => 'Guerrero',
            'prefix' => 'Dra.',
            'reg_medico' => 'MED-ORD-01',
            'is_active' => true,
        ]);

        $office = Office::create([
            'medical_center_id' => $centro->id,
            'medico_id' => $medico->id,
            'reg_medico' => 'MED-ORD-01',
            'office_number' => 'Cons. 3A',
            'modalidad' => Office::MODALIDAD_ORDEN,
            'duracion_cita' => 20,
            'activo' => true,
        ]);

        $diaSemana = Carbon::now()->addDays(2);
        if ($diaSemana->isSunday()) {
            $diaSemana->addDay();
        }

        OfficeSchedule::create([
            'office_id' => $office->id,
            'reg_medico' => 'MED-ORD-01',
            'dia_semana' => $diaSemana->dayOfWeekIso,
            'hora_inicio' => '08:00',
            'hora_fin' => '12:00',
            'cupo' => 15,
        ]);

        // Cita previa en el pasado
        $citaVieja = Cola::create([
            'reg_medico' => 'MED-ORD-01',
            'fecha' => Carbon::now()->subDays(3)->toDateString(),
            'paciente_sinhistoria_id' => $paciente->id,
            'numorden' => 1,
            'atendido' => 0,
            'estado' => Cola::ESTADO_CONFIRMADA_PACIENTE,
            'turno' => 'M',
            'hora_ini' => '08:00',
            'medico' => $medico->id,
        ]);

        $res = $this->contextService->reprogramarCitaEnCola($tel, 'MED-ORD-01', $diaSemana->toDateString());
        $this->assertTrue($res['exito']);
        $this->assertTrue($res['datos']['es_orden_llegada']);
        $this->assertStringContainsString('Por orden de llegada', $res['mensaje_confirmacion']);
        $this->assertStringContainsString('8:00 AM', $res['mensaje_confirmacion']);
    }

    public function test_reprogramar_cita_respeta_modalidad_hora_cita()
    {
        $tel = '584143334455';

        $paciente = Paciente::create([
            'nombres' => 'Pedro',
            'apellidos' => 'Infante',
            'telefono' => $tel,
            'cedula' => 'V-33445566',
        ]);

        $centro = MedicalCenter::create([
            'country_id' => 1,
            'state_id' => 1,
            'city_id' => 1,
            'name' => 'Clínica Sanitas',
            'address' => 'Calle Los Samanes',
            'activo' => true,
        ]);

        $medico = Medico::create([
            'name' => 'Carmen',
            'lastname' => 'Salazar',
            'prefix' => 'Dra.',
            'reg_medico' => 'MED-HORA-01',
            'is_active' => true,
        ]);

        $office = Office::create([
            'medical_center_id' => $centro->id,
            'medico_id' => $medico->id,
            'reg_medico' => 'MED-HORA-01',
            'office_number' => 'Cons. 501',
            'modalidad' => Office::MODALIDAD_HORA,
            'duracion_cita' => 30,
            'activo' => true,
        ]);

        $diaSemana = Carbon::now()->addDays(2);
        if ($diaSemana->isSunday()) {
            $diaSemana->addDay();
        }

        OfficeSchedule::create([
            'office_id' => $office->id,
            'reg_medico' => 'MED-HORA-01',
            'dia_semana' => $diaSemana->dayOfWeekIso,
            'hora_inicio' => '09:00',
            'hora_fin' => '13:00',
            'cupo' => 8,
        ]);

        // Cita ocupada en el primer hueco (09:00)
        Cola::create([
            'reg_medico' => 'MED-HORA-01',
            'medical_center_id' => $centro->id,
            'fecha' => $diaSemana->toDateString(),
            'numorden' => 1,
            'atendido' => 0,
            'estado' => Cola::ESTADO_CONFIRMADA_PACIENTE,
            'turno' => 'M',
            'hora_ini' => '09:00',
            'medico' => $medico->id,
        ]);

        // Cita perdida del paciente
        $citaPerdida = Cola::create([
            'reg_medico' => 'MED-HORA-01',
            'fecha' => Carbon::now()->subDays(4)->toDateString(),
            'paciente_sinhistoria_id' => $paciente->id,
            'numorden' => 2,
            'atendido' => 0,
            'estado' => Cola::ESTADO_CONFIRMADA_PACIENTE,
            'turno' => 'M',
            'hora_ini' => '09:30',
            'medico' => $medico->id,
        ]);

        $res = $this->contextService->reprogramarCitaEnCola($tel, 'MED-HORA-01', $diaSemana->toDateString());
        $this->assertTrue($res['exito']);
        $this->assertFalse($res['datos']['es_orden_llegada']);
        $this->assertEquals(2, $res['datos']['turno']);
        // Segundo turno a 30 minutos de apertura: 9:30 AM
        $this->assertEquals('9:30 AM', $res['datos']['hora_estimada']);
        $this->assertStringContainsString('Hora de cita:* 9:30 AM', $res['mensaje_confirmacion']);
    }

    public function test_paciente_solicita_expresamente_cambiar_cita_perdida_por_mensaje()
    {
        $tel = '584144445566';

        $paciente = Paciente::create([
            'nombres' => 'Miguel',
            'apellidos' => 'Cabrera',
            'telefono' => $tel,
            'cedula' => 'V-44556677',
        ]);

        $medico = Medico::create([
            'name' => 'Félix',
            'lastname' => 'Hernández',
            'prefix' => 'Dr.',
            'reg_medico' => 'MED-CAMBIO-01',
            'is_active' => true,
        ]);

        Evolucion::create([
            'reg_medico' => 'MED-CAMBIO-01',
            'clave' => 1,
            'especialidad' => 'Medicina General',
            'consultorio' => 'Cons. 10',
            'cantidad_paciente' => 10,
        ]);

        // Cita perdida en el pasado
        Cola::create([
            'reg_medico' => 'MED-CAMBIO-01',
            'fecha' => Carbon::now()->subDays(2)->toDateString(),
            'paciente_sinhistoria_id' => $paciente->id,
            'numorden' => 1,
            'atendido' => 0,
            'estado' => Cola::ESTADO_CONFIRMADA_PACIENTE,
            'turno' => 'M',
            'hora_ini' => '08:00',
            'medico' => $medico->id,
        ]);

        $gemini = app(GeminiService::class);

        // Paciente escribe diciendo que perdió su cita y quiere cambiarla
        $respuesta = $gemini->generarRespuesta('Perdí mi cita con el Dr. Félix Hernández, quiero cambiar el día', $tel);

        $this->assertStringContainsString('Dr. Félix Hernández', $respuesta);
        $this->assertStringContainsString('cambiar el día de tu cita', $respuesta);

        // El paciente confirma diciendo "sí"
        $respuestaConfirmacion = $gemini->generarRespuesta('sí', $tel);

        $this->assertStringContainsString('CITA CAMBIADA CON ÉXITO', $respuestaConfirmacion);
        $this->assertStringContainsString('Félix Hernández', $respuestaConfirmacion);
    }

    public function test_consulta_horarios_de_atencion_muestra_horario_semanal_desde_office_schedules()
    {
        $centro = MedicalCenter::create([
            'country_id' => 1,
            'state_id' => 1,
            'city_id' => 1,
            'name' => 'Centro Médico Quirúrgico',
            'address' => 'Av. Principal 45',
            'activo' => true,
        ]);

        $medico = Medico::create([
            'name' => 'Gloria',
            'lastname' => 'Pacheco',
            'prefix' => 'Dra.',
            'reg_medico' => 'MED-HOR-01',
            'is_active' => true,
        ]);

        $office = Office::create([
            'medical_center_id' => $centro->id,
            'medico_id' => $medico->id,
            'reg_medico' => 'MED-HOR-01',
            'office_number' => 'Cons. 102',
            'modalidad' => Office::MODALIDAD_ORDEN,
            'duracion_cita' => 20,
            'activo' => true,
        ]);

        // Lunes (1) y Miércoles (3)
        OfficeSchedule::create([
            'office_id' => $office->id,
            'reg_medico' => 'MED-HOR-01',
            'dia_semana' => 1,
            'hora_inicio' => '08:00',
            'hora_fin' => '12:00',
            'cupo' => 10,
        ]);
        OfficeSchedule::create([
            'office_id' => $office->id,
            'reg_medico' => 'MED-HOR-01',
            'dia_semana' => 3,
            'hora_inicio' => '14:00',
            'hora_fin' => '18:00',
            'cupo' => 12,
        ]);

        $horarios = $this->contextService->obtenerHorariosAtencion('MED-HOR-01');

        $this->assertTrue($horarios['encontrado']);
        $this->assertCount(2, $horarios['dias_atencion']);
        $this->assertStringContainsString('Lunes:', $horarios['dias_atencion'][0]);
        $this->assertStringContainsString('8:00 AM a 12:00 PM', $horarios['dias_atencion'][0]);
        $this->assertStringContainsString('Miércoles:', $horarios['dias_atencion'][1]);
        $this->assertStringContainsString('2:00 PM a 6:00 PM', $horarios['dias_atencion'][1]);
        $this->assertStringContainsString('Horarios de atención de Dra. Gloria Pacheco', $horarios['mensaje']);
        $this->assertStringContainsString('Cons. 102', $horarios['mensaje']);
    }

    public function test_consulta_horarios_considera_dias_no_laborables_del_medico()
    {
        $medico = Medico::create([
            'name' => 'Armando',
            'lastname' => 'Molina',
            'prefix' => 'Dr.',
            'reg_medico' => 'MED-NOLAB-01',
            'is_active' => true,
        ]);

        Evolucion::create([
            'reg_medico' => 'MED-NOLAB-01',
            'clave' => 1,
            'especialidad' => 'Cardiología',
            'lunes' => '1',
            'lunes_i' => '08:00:00',
            'lunes_f' => '12:00:00',
            'martes' => '1',
            'martes_i' => '08:00:00',
            'martes_f' => '12:00:00',
            'cantidad_paciente' => 10,
        ]);

        // Registrar un día no laborable en un día próximo (ej. el próximo lunes)
        $diaBloqueado = Carbon::now()->addDays(3);
        if ($diaBloqueado->isSunday()) {
            $diaBloqueado->addDay();
        }

        DiaNoLaborable::create([
            'reg_medico' => 'MED-NOLAB-01',
            'medico' => $medico->id,
            'dia' => $diaBloqueado->toDateString(),
            'tipo' => DiaNoLaborable::FERIADO,
            'motivo' => 'Feriado Nacional',
        ]);

        // 1. Verificar que obtenerHorariosAtencion lista el día no laborable
        $horarios = $this->contextService->obtenerHorariosAtencion('MED-NOLAB-01');
        $this->assertTrue($horarios['encontrado']);
        $this->assertNotEmpty($horarios['dias_no_laborables']);
        $this->assertStringContainsString('Feriado Nacional', $horarios['dias_no_laborables'][0]);
        $this->assertStringContainsString('Días que no labora próximamente', $horarios['mensaje']);

        // 2. Verificar que lugaresDelDia no ofrece el día no laborable
        $lugares = $this->contextService->lugaresDelDia('MED-NOLAB-01', $medico, $diaBloqueado);
        $this->assertEmpty($lugares);
    }

    public function test_resolver_pregunta_informativa_responde_a_pregunta_de_dias_de_atencion()
    {
        $medico = Medico::create([
            'name' => 'Silvia',
            'lastname' => 'Navarro',
            'prefix' => 'Dra.',
            'reg_medico' => 'MED-INFO-01',
            'is_active' => true,
        ]);

        Evolucion::create([
            'reg_medico' => 'MED-INFO-01',
            'clave' => 1,
            'especialidad' => 'Ginecología',
            'martes' => '1',
            'martes_i' => '09:00:00',
            'martes_f' => '13:00:00',
            'jueves' => '1',
            'jueves_i' => '09:00:00',
            'jueves_f' => '13:00:00',
            'cantidad_paciente' => 8,
        ]);

        $respuesta = $this->contextService->resolverPreguntaInformativa(
            '¿Qué días tiene atención la Dra. Silvia Navarro?'
        );

        $this->assertNotNull($respuesta);
        $this->assertStringContainsString('Horarios de atención de Dra. Silvia Navarro', $respuesta);
        $this->assertStringContainsString('Martes:', $respuesta);
        $this->assertStringContainsString('Jueves:', $respuesta);
    }
}
