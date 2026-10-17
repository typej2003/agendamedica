<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\Evolucion;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\Paciente;
use App\Models\Specialty;
use App\Services\GeminiService;
use App\Services\PacienteAtencionContextService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ChatbotInformationTest extends TestCase
{
    use DatabaseTransactions;

    protected PacienteAtencionContextService $contextService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contextService = app(PacienteAtencionContextService::class);
    }

    public function test_identifica_paciente_por_numero_de_telefono_en_varios_formatos()
    {
        $paciente = Paciente::create([
            'nombres' => 'Carlos',
            'apellidos' => 'Pérez',
            'telefono' => '0414-1234567',
            'cedula' => 'V-12345678',
        ]);

        Historia::create([
            'paciente_id' => $paciente->id,
            'numhistoria' => 99991,
            'reg_medico' => 'TEST-REG-01',
        ]);

        // Formato internacional sin +
        $hallado1 = $this->contextService->buscarPacientePorTelefono('584141234567');
        $this->assertNotNull($hallado1);
        $this->assertEquals($paciente->id, $hallado1->id);

        // Formato internacional con +
        $hallado2 = $this->contextService->buscarPacientePorTelefono('+584141234567');
        $this->assertNotNull($hallado2);
        $this->assertEquals($paciente->id, $hallado2->id);

        // Formato nacional sin guión
        $hallado3 = $this->contextService->buscarPacientePorTelefono('04141234567');
        $this->assertNotNull($hallado3);
        $this->assertEquals($paciente->id, $hallado3->id);

        // Formato por últimos 7 dígitos
        $hallado4 = $this->contextService->buscarPacientePorTelefono('1234567');
        $this->assertNotNull($hallado4);
        $this->assertEquals($paciente->id, $hallado4->id);

        // Contexto completo
        $contexto = $this->contextService->obtenerContextoPaciente('584141234567');
        $this->assertTrue($contexto['existe']);
        $this->assertEquals('Carlos Pérez', $contexto['nombre']);
        $this->assertEquals(99991, $contexto['numhistoria']);
    }

    public function test_identifica_paciente_con_medicos_asociados()
    {
        $medico = Medico::create([
            'name' => 'Alejandro',
            'lastname' => 'Gómez',
            'prefix' => 'Dr.',
            'reg_medico' => 'MED-TEST-99',
            'is_active' => true,
        ]);

        $paciente = Paciente::create([
            'nombres' => 'María',
            'apellidos' => 'Rodríguez',
            'telefono' => '04129876543',
        ]);

        Historia::create([
            'paciente_id' => $paciente->id,
            'numhistoria' => 99992,
            'reg_medico' => 'MED-TEST-99',
        ]);

        MedicoPaciente::create([
            'medico_id' => $medico->id,
            'paciente_id' => $paciente->id,
            'reg_medico' => 'MED-TEST-99',
            'numhistoria' => 99992,
        ]);

        $contexto = $this->contextService->obtenerContextoPaciente('584129876543');
        $this->assertTrue($contexto['existe']);
        $this->assertTrue($contexto['tiene_medicos']);
        $this->assertCount(1, $contexto['medicos_asociados']);
        $this->assertEquals('MED-TEST-99', $contexto['medicos_asociados'][0]['reg_medico']);
    }

    public function test_obtiene_direccion_y_datos_del_consultorio_desde_tabla_evolucion()
    {
        $medico = Medico::create([
            'name' => 'Roberto',
            'lastname' => 'Fernández',
            'prefix' => 'Dr.',
            'reg_medico' => 'MED-TEST-88',
            'email' => 'roberto@medico.test',
            'phone' => '0212-9991122',
            'is_active' => true,
        ]);

        Evolucion::create([
            'reg_medico' => 'MED-TEST-88',
            'clave' => 1,
            'especialidad' => 'Cardiología',
            'ciudad' => 'Caracas',
            'consultorio' => 'Consultorio 4-B',
            'linea_1' => 'Centro Médico Las Mercedes',
            'linea_2' => 'Piso 4, Consultorio 4-B',
            'linea_3' => 'Av. Principal con Calle París',
            'telefono' => '0212-5554433',
            'correo_med' => 'roberto@medico.test',
        ]);

        // Por reg_medico
        $datos = $this->contextService->obtenerDatosConsultorio('MED-TEST-88');
        $this->assertTrue($datos['encontrado']);
        $this->assertEquals('Consultorio 4-B', $datos['consultorio']);
        $this->assertEquals('Caracas', $datos['ciudad']);
        $this->assertStringContainsString('Centro Médico Las Mercedes', $datos['direccion']);
        $this->assertStringContainsString('Piso 4', $datos['direccion']);
        $this->assertEquals('0212-5554433', $datos['telefono']);

        // Por nombre del doctor
        $datosPorNombre = $this->contextService->obtenerDatosConsultorio('Roberto Fernández');
        $this->assertTrue($datosPorNombre['encontrado']);
        $this->assertEquals('MED-TEST-88', $datosPorNombre['reg_medico']);
    }

    public function test_obtiene_horarios_de_atencion_desde_tabla_evolucion()
    {
        $medico = Medico::create([
            'name' => 'Elena',
            'lastname' => 'Morales',
            'prefix' => 'Dra.',
            'reg_medico' => 'MED-TEST-77',
            'is_active' => true,
        ]);

        Evolucion::create([
            'reg_medico' => 'MED-TEST-77',
            'clave' => 1,
            'especialidad' => 'Pediatría',
            'lunes' => '1',
            'lunes_i' => '08:00 AM',
            'lunes_f' => '12:00 PM',
            'miercoles' => '1',
            'miercoles_i' => '02:00 PM',
            'miercoles_f' => '06:00 PM',
            'viernes' => '1',
            'vienes_i' => '08:00 AM',
            'viernes_f' => '01:00 PM',
            'cita_previa' => '1',
            'tiempo_paci' => 20,
            'cantidad_paciente' => 12,
        ]);

        $horarios = $this->contextService->obtenerHorariosAtencion('MED-TEST-77');
        $this->assertTrue($horarios['encontrado']);
        $this->assertEquals('Por cita previa', $horarios['modalidad']);
        $this->assertEquals(12, $horarios['cupos_maximos_diarios']);
        $this->assertNotEmpty($horarios['dias_atencion']);
        $this->assertStringContainsString('Lunes', implode(' | ', $horarios['dias_atencion']));
        $this->assertStringContainsString('Miércoles', implode(' | ', $horarios['dias_atencion']));
        $this->assertStringContainsString('Viernes', implode(' | ', $horarios['dias_atencion']));
    }

    public function test_consultar_proximo_cupo_disponible_utiliza_limite_de_evolucion()
    {
        $medico = Medico::create([
            'name' => 'David',
            'lastname' => 'Suárez',
            'prefix' => 'Dr.',
            'reg_medico' => 'MED-TEST-66',
            'is_active' => true,
        ]);

        Evolucion::create([
            'reg_medico' => 'MED-TEST-66',
            'clave' => 1,
            'cantidad_paciente' => 5,
        ]);

        $cupo = $this->contextService->consultarProximoDiaDisponible('MED-TEST-66');
        $this->assertTrue($cupo['disponible']);
        $this->assertEquals(5, $cupo['cupos_disponibles']);
    }

    public function test_buscar_medicos_por_especialidad()
    {
        $specialty = Specialty::firstOrCreate(['name' => 'Dermatología Especial']);
        $medico = Medico::create([
            'name' => 'Sofía',
            'lastname' => 'Castro',
            'prefix' => 'Dra.',
            'reg_medico' => 'MED-TEST-55',
            'is_active' => true,
        ]);
        $medico->specialties()->sync([$specialty->id]);

        $resultado = $this->contextService->buscarMedicosPorEspecialidad('Dermatología Especial');
        $this->assertEquals('Dermatología Especial', $resultado['especialidad']);
        $this->assertNotEmpty($resultado['medicos']);
        $this->assertTrue(collect($resultado['medicos'])->pluck('reg_medico')->contains('MED-TEST-55'));
    }

    public function test_obtener_informacion_integral_de_un_medico()
    {
        $medico = Medico::create([
            'name' => 'Valeria',
            'lastname' => 'Mendoza',
            'prefix' => 'Dra.',
            'reg_medico' => 'MED-TEST-44',
            'is_active' => true,
        ]);

        Evolucion::create([
            'reg_medico' => 'MED-TEST-44',
            'clave' => 1,
            'especialidad' => 'Oftalmología',
            'ciudad' => 'Valencia',
            'consultorio' => 'Consultorio 12',
            'linea_1' => 'Torre Médica Norte',
            'lunes' => '1',
            'lunes_i' => '09:00 AM',
            'lunes_f' => '01:00 PM',
        ]);

        $info = $this->contextService->obtenerInformacionMedico('MED-TEST-44');
        $this->assertEquals('Oftalmología', $info['especialidad']);
        $this->assertEquals('Valencia', $info['consultorio']['ciudad']);
        $this->assertNotEmpty($info['horarios']['dias_atencion']);
    }

    public function test_gemini_service_ejecuta_tool_de_datos_consultorio()
    {
        config([
            'services.gemini.api_key' => 'fake_gemini_key',
            'services.gemini.model' => 'gemini-3.8-flash',
        ]);

        $medico = Medico::create([
            'name' => 'Arturo',
            'lastname' => 'Uslar',
            'prefix' => 'Dr.',
            'reg_medico' => 'MED-TEST-33',
            'is_active' => true,
        ]);

        Evolucion::create([
            'reg_medico' => 'MED-TEST-33',
            'clave' => 1,
            'especialidad' => 'Neurología',
            'ciudad' => 'Maracaibo',
            'consultorio' => 'Consultorio 5',
            'linea_1' => 'Policlínica Maracaibo',
            'telefono' => '0261-7000000',
        ]);

        // Simular primer turno con Function Calling y segundo turno con respuesta final
        \Illuminate\Support\Facades\Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent*' => \Illuminate\Support\Facades\Http::sequence()
                ->push([
                    'candidates' => [
                        [
                            'content' => [
                                'parts' => [
                                    [
                                        'functionCall' => [
                                            'name' => 'consultar_datos_consultorio',
                                            'args' => ['reg_medico' => 'MED-TEST-33'],
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ], 200)
                ->push([
                    'candidates' => [
                        [
                            'content' => [
                                'parts' => [
                                    [
                                        'text' => 'El Dr. Arturo Uslar atiende en Policlínica Maracaibo, Consultorio 5.'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ], 200),
        ]);

        $gemini = app(GeminiService::class);
        $respuesta = $gemini->generarRespuesta('¿Dónde queda el consultorio del Dr. Arturo Uslar?', '584140001122');

        $this->assertStringContainsString('Policlínica Maracaibo', $respuesta);
    }

    public function test_reservar_cita_en_cola_inserta_registro_y_genera_mensaje_oficial()
    {
        $medico = Medico::create([
            'name' => 'Gabriel',
            'lastname' => 'García',
            'prefix' => 'Dr.',
            'reg_medico' => 'MED-TEST-22',
            'is_active' => true,
        ]);

        Evolucion::create([
            'reg_medico' => 'MED-TEST-22',
            'clave' => 1,
            'especialidad' => 'Traumatología',
            'ciudad' => 'Caracas',
            'consultorio' => 'Consultorio 10-A',
            'linea_1' => 'Hospital de Clínicas Caracas',
        ]);

        $paciente = Paciente::create([
            'nombres' => 'Andrés',
            'apellidos' => 'Bello',
            'telefono' => '04147778899',
        ]);

        $fechaPrueba = Carbon::tomorrow();
        if ($fechaPrueba->isSunday()) {
            $fechaPrueba->addDay();
        }

        $res = $this->contextService->reservarCitaEnCola(
            '584147778899',
            'MED-TEST-22',
            $fechaPrueba->toDateString(),
            'Dolor en rodilla derecha'
        );

        $this->assertTrue($res['exito']);
        $this->assertDatabaseHas('cola', [
            'id' => $res['datos']['cita_id'],
            'reg_medico' => 'MED-TEST-22',
            'numorden' => 1,
            'atendido' => 0,
        ]);

        $this->assertStringContainsString('¡CITA REGISTRADA CON ÉXITO!', $res['mensaje_confirmacion']);
        $this->assertStringContainsString('Andrés Bello', $res['mensaje_confirmacion']);
        $this->assertStringContainsString('Dr. Gabriel García', $res['mensaje_confirmacion']);
        $this->assertStringContainsString('Hospital de Clínicas Caracas', $res['mensaje_confirmacion']);
        $this->assertStringContainsString('Turno asignado:* #1', $res['mensaje_confirmacion']);
    }

    public function test_confirmacion_directa_de_cita_por_mensaje_si()
    {
        $medico = Medico::create([
            'name' => 'Beatriz',
            'lastname' => 'Paredes',
            'prefix' => 'Dra.',
            'reg_medico' => 'MED-TEST-11',
            'is_active' => true,
        ]);

        Evolucion::create([
            'reg_medico' => 'MED-TEST-11',
            'clave' => 1,
            'especialidad' => 'Ginecología',
            'ciudad' => 'Barquisimeto',
            'consultorio' => 'Consultorio 2',
            'linea_1' => 'Centro Médico del Este',
        ]);

        $paciente = Paciente::create([
            'nombres' => 'Laura',
            'apellidos' => 'Guzmán',
            'telefono' => '04161112233',
        ]);

        // Simular que previamente se ofreció disponibilidad y quedó guardado en Cache
        $fechaCita = Carbon::tomorrow();
        if ($fechaCita->isSunday()) {
            $fechaCita->addDay();
        }

        \Illuminate\Support\Facades\Cache::put('wa_pendiente_584161112233', [
            'reg_medico' => 'MED-TEST-11',
            'fecha' => $fechaCita->toDateString(),
        ], now()->addMinutes(30));

        $gemini = app(GeminiService::class);
        $respuesta = $gemini->generarRespuesta('Sí, resérvala por favor', '584161112233');

        $this->assertStringContainsString('¡CITA REGISTRADA CON ÉXITO!', $respuesta);
        $this->assertStringContainsString('Laura Guzmán', $respuesta);
        $this->assertStringContainsString('Dra. Beatriz Paredes', $respuesta);

        // Verificar inserción en la tabla cola
        $this->assertDatabaseHas('cola', [
            'reg_medico' => 'MED-TEST-11',
            'numorden' => 1,
        ]);
    }
}
