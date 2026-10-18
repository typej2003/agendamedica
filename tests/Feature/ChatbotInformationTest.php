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

        Paciente::create([
            'nombres' => 'Arturo',
            'apellidos' => 'Pérez',
            'telefono' => '584140001122',
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

    public function test_no_permite_registrar_otra_cita_si_ya_tiene_cita_con_atendido_cero_y_muestra_datos_de_cola()
    {
        $medico = Medico::create([
            'name' => 'Marcos',
            'lastname' => 'Pérez',
            'prefix' => 'Dr.',
            'reg_medico' => 'MED-TEST-99',
            'is_active' => true,
        ]);

        Evolucion::create([
            'reg_medico' => 'MED-TEST-99',
            'clave' => 1,
            'especialidad' => 'Cardiología',
            'ciudad' => 'Caracas',
            'consultorio' => 'Consultorio 402',
            'linea_1' => 'Centro Médico Caracas',
        ]);

        $paciente = Paciente::create([
            'nombres' => 'Fernando',
            'apellidos' => 'Sucre',
            'telefono' => '04123334455',
        ]);

        Historia::create([
            'paciente_id' => $paciente->id,
            'numhistoria' => 881122,
            'reg_medico' => 'MED-TEST-99',
        ]);

        $fechaCita = Carbon::tomorrow();
        if ($fechaCita->isSunday()) {
            $fechaCita->addDay();
        }

        // Crear una cita previa pendiente de atención (atendido = 0)
        $citaPrevia = Cola::create([
            'reg_medico' => 'MED-TEST-99',
            'fecha' => $fechaCita->toDateString(),
            'numhistoria' => 881122,
            'paciente_sinhistoria_id' => null,
            'numorden' => 3,
            'atendido' => 0,
            'estado' => Cola::ESTADO_CONFIRMADA_PACIENTE,
            'turno' => 'M',
            'motivo' => 'Control cardiovascular',
            'hora_ini' => '08:40',
            'hora_fin' => '09:00',
            'tiempo' => 20,
            'tipo' => 'CONSULTA',
            'medico' => $medico->id,
        ]);

        // Intentar registrar una nueva cita con el mismo médico
        $res = $this->contextService->reservarCitaEnCola(
            '584123334455',
            'MED-TEST-99',
            $fechaCita->copy()->addDays(2)->toDateString(),
            'Nueva cita'
        );

        $this->assertFalse($res['exito']);
        $this->assertTrue($res['ya_tiene_cita']);
        $this->assertStringContainsString('YA TIENES UNA CITA PROGRAMADA', $res['mensaje']);
        $this->assertStringContainsString('Fernando Sucre', $res['mensaje']);
        $this->assertStringContainsString('Dr. Marcos Pérez', $res['mensaje']);
        $this->assertStringContainsString('Turno asignado:* #3', $res['mensaje']);
        $this->assertStringContainsString('08:40 AM', $res['mensaje']);
        $this->assertStringContainsString('Control cardiovascular', $res['mensaje']);
        $this->assertStringContainsString('Consultorio 402', $res['mensaje']);
        $this->assertStringContainsString('Centro Médico Caracas', $res['mensaje']);

        // Asegurar que no se insertó ninguna cita adicional en cola
        $this->assertEquals(1, Cola::where('reg_medico', 'MED-TEST-99')->count());
    }

    public function test_resolver_opcion_directa_avisa_si_ya_tiene_cita_pendiente_en_cola()
    {
        $medico = Medico::create([
            'name' => 'Carlos',
            'lastname' => 'Mendoza',
            'prefix' => 'Dr.',
            'reg_medico' => 'MED-TEST-88',
            'is_active' => true,
        ]);

        Evolucion::create([
            'reg_medico' => 'MED-TEST-88',
            'clave' => 1,
            'especialidad' => 'Pediatría',
            'ciudad' => 'Caracas',
            'consultorio' => 'Consultorio 10',
            'linea_1' => 'Clínica Sanatrix',
        ]);

        $paciente = Paciente::create([
            'nombres' => 'José',
            'apellidos' => 'Rosales',
            'telefono' => '04165800403',
        ]);

        $medico->pacientes()->attach($paciente->id);

        $fechaCita = Carbon::tomorrow();
        if ($fechaCita->isSunday()) {
            $fechaCita->addDay();
        }

        // Crear la cita previa con atendido = 0
        Cola::create([
            'reg_medico' => 'MED-TEST-88',
            'fecha' => $fechaCita->toDateString(),
            'paciente_sinhistoria_id' => $paciente->id,
            'numorden' => 1,
            'atendido' => 0,
            'estado' => Cola::ESTADO_CONFIRMADA_PACIENTE,
            'turno' => 'M',
            'motivo' => 'Consulta general',
            'hora_ini' => '08:00',
            'hora_fin' => '08:20',
            'tiempo' => 20,
            'tipo' => 'CONSULTA',
            'medico' => $medico->id,
        ]);

        $gemini = app(GeminiService::class);
        // El paciente envía "1" para elegir al Dr. Carlos Mendoza
        $respuesta = $gemini->generarRespuesta('1', '584165800403');

        $this->assertStringContainsString('YA TIENES UNA CITA PROGRAMADA', $respuesta);
        $this->assertStringContainsString('José Rosales', $respuesta);
        $this->assertStringContainsString('Dr. Carlos Mendoza', $respuesta);
        $this->assertStringContainsString('Turno asignado:* #1', $respuesta);
        $this->assertStringContainsString('Clínica Sanatrix', $respuesta);
    }

    public function test_flujo_registro_paciente_nuevo_cuando_telefono_no_esta_en_paciente()
    {
        $tel = '584129990099';

        // Asegurar que el teléfono no existe previamente en la tabla pacientes
        $this->assertNull(Paciente::where('telefono', $tel)->first());

        $gemini = app(GeminiService::class);

        // Crear especialidad y médico para el flujo posterior
        $specialty = Specialty::firstOrCreate(['name' => 'Ginecología Láser Avanzada']);
        $medico = Medico::create([
            'name' => 'Marcos',
            'lastname' => 'Pérez',
            'prefix' => 'Dr.',
            'reg_medico' => 'MED-GIN-01',
            'is_active' => true,
        ]);
        $medico->specialties()->sync([$specialty->id]);

        Evolucion::create([
            'reg_medico' => 'MED-GIN-01',
            'clave' => 1,
            'especialidad' => 'Ginecología Láser Avanzada',
            'consultorio' => 'Consultorio 101',
            'linea_1' => 'Torre Médica Sur',
        ]);

        // PASO 1: Primer mensaje -> Debe preguntar la cédula
        $r1 = $gemini->generarRespuesta('Hola, buenas tardes', $tel);
        $this->assertStringContainsString('cédula de identidad', $r1);
        $this->assertNull(Paciente::where('telefono', $tel)->first());

        // PASO 2: Paciente responde su cédula -> Debe preguntar los apellidos
        $r2 = $gemini->generarRespuesta('V-24555666', $tel);
        $this->assertStringContainsString('indícanos tus *apellidos*', $r2);
        $this->assertNull(Paciente::where('telefono', $tel)->first());

        // PASO 3: Paciente responde sus apellidos -> Debe preguntar los nombres
        $r3 = $gemini->generarRespuesta('González Martínez', $tel);
        $this->assertStringContainsString('indícanos tus *nombres*', $r3);
        $this->assertNull(Paciente::where('telefono', $tel)->first());

        // PASO 4: Paciente responde sus nombres -> Debe almacenar los datos en Paciente y ofrecer especialidades
        $r4 = $gemini->generarRespuesta('Carlos Eduardo', $tel);

        $pacienteCreado = Paciente::where('cedula', '24555666')->first();
        $this->assertNotNull($pacienteCreado);
        $this->assertEquals('Carlos Eduardo', $pacienteCreado->nombres);
        $this->assertEquals('González Martínez', $pacienteCreado->apellidos);
        $this->assertEquals('V', $pacienteCreado->nac);
        $this->assertEquals($tel, $pacienteCreado->telefono);

        $this->assertStringContainsString('REGISTRADOS CON ÉXITO', $r4);
        $this->assertStringContainsString('Carlos Eduardo González Martínez', $r4);
        $this->assertStringContainsString('V-24555666', $r4);
        $this->assertStringContainsString('Ginecología Láser Avanzada', $r4);

        // PASO 5: Paciente selecciona la especialidad (por nombre o número)
        $r5 = $gemini->generarRespuesta('Ginecología Láser Avanzada', $tel);
        $this->assertStringContainsString('Médicos disponibles en Ginecología Láser Avanzada', $r5);
        $this->assertStringContainsString('Dr(a). Marcos Pérez', $r5);

        // PASO 6: Paciente selecciona al médico (opción 1)
        $r6 = $gemini->generarRespuesta('1', $tel);
        $this->assertStringContainsString('La agenda del *Dr(a). Marcos Pérez*', $r6);
        // Desde el 2026-10-18 se ofrecen varios días y **no** se dicen los cupos.
        $this->assertStringContainsString('disponibilidad', $r6);
        $this->assertStringNotContainsString('Cupos disponibles', $r6);

        // PASO 7: Paciente confirma ("Sí") -> Debe crear la cita en Cola
        $r7 = $gemini->generarRespuesta('Sí, confirmo', $tel);
        $this->assertStringContainsString('CITA REGISTRADA CON ÉXITO', $r7);
        $this->assertStringContainsString('Carlos Eduardo González Martínez', $r7);
        $this->assertStringContainsString('Dr. Marcos Pérez', $r7);

        $citaEnCola = Cola::where('reg_medico', 'MED-GIN-01')
            ->where('paciente_sinhistoria_id', $pacienteCreado->id)
            ->first();
        $this->assertNotNull($citaEnCola);
        $this->assertEquals(0, $citaEnCola->atendido);
    }

    public function test_paciente_registrado_solicita_medico_directamente()
    {
        $tel = '584145554433';

        $paciente = Paciente::create([
            'nombres' => 'Valeria',
            'apellidos' => 'Ríos',
            'cedula' => '23111222',
            'telefono' => $tel,
        ]);

        $medico = Medico::create([
            'name' => 'Luis',
            'lastname' => 'Herrera',
            'prefix' => 'Dr.',
            'reg_medico' => 'MED-DIR-01',
            'is_active' => true,
        ]);

        Evolucion::create([
            'reg_medico' => 'MED-DIR-01',
            'clave' => 1,
            'especialidad' => 'Traumatología',
            'consultorio' => 'Consultorio 204',
            'linea_1' => 'Centro Traumatológico',
        ]);

        $gemini = app(GeminiService::class);

        // Solicitar directamente al médico
        $respuesta = $gemini->generarRespuesta('Quiero cita con el doctor Luis Herrera por favor', $tel);

        $this->assertStringContainsString('Dr. Luis Herrera', $respuesta);
        $this->assertStringContainsString('disponibilidad', $respuesta);
        $this->assertStringNotContainsString('Cupos disponibles', $respuesta);

        // Confirmar cita
        $confirmacion = $gemini->generarRespuesta('Sí', $tel);
        $this->assertStringContainsString('CITA REGISTRADA CON ÉXITO', $confirmacion);
        $this->assertStringContainsString('Valeria Ríos', $confirmacion);
    }

    public function test_paciente_con_cedula_preexistente_actualiza_telefono_en_registro()
    {
        $tel = '584247778899';

        $pacienteExistente = Paciente::create([
            'nombres' => 'Manuel',
            'apellidos' => 'Blanco',
            'cedula' => '19555444',
            'nac' => 'V',
            'telefono' => null,
        ]);

        $gemini = app(GeminiService::class);

        // Flujo de registro: primer mensaje Hola -> pide cédula -> responde cédula preexistente -> vincula y saluda
        $r1 = $gemini->generarRespuesta('Hola', $tel);
        $this->assertStringContainsString('cédula de identidad', $r1);

        $r2 = $gemini->generarRespuesta('V-19555444', $tel);
        $this->assertStringContainsString('Manuel Blanco', $r2);
        $this->assertStringContainsString('Hemos vinculado tu número de WhatsApp', $r2);

        // Comprobar que no se creó duplicado y que se actualizó el teléfono
        $this->assertEquals(1, Paciente::where('cedula', '19555444')->count());
        $pacienteActualizado = Paciente::where('cedula', '19555444')->first();
        $this->assertEquals($tel, $pacienteActualizado->telefono);
    }

    public function test_paciente_solicita_medico_no_registrado_informa_adecuadamente()
    {
        $tel = '584161112233';

        Paciente::create([
            'nombres' => 'Patricia',
            'apellidos' => 'Silva',
            'cedula' => '21000999',
            'telefono' => $tel,
        ]);

        $gemini = app(GeminiService::class);

        // Solicitar a un médico que NO existe en la base de datos
        $respuesta = $gemini->generarRespuesta('Quiero una cita con el doctor Gregory House por favor', $tel);

        $this->assertStringContainsString('No encontramos registrado(a) en nuestra clínica al *Dr(a). Gregory House*', $respuesta);
        $this->assertStringContainsString('especialidades disponibles', $respuesta);
    }

    public function test_paciente_consulta_telefono_directo_del_medico()
    {
        $tel = '584149998877';

        Paciente::create([
            'nombres' => 'Gabriela',
            'apellidos' => 'Mendoza',
            'cedula' => '22333444',
            'telefono' => $tel,
        ]);

        Medico::create([
            'name' => 'Roberto',
            'lastname' => 'Paredes',
            'prefix' => 'Dr.',
            'phone' => '0414-5556677',
            'reg_medico' => 'MED-TEL-01',
            'is_active' => true,
        ]);

        $gemini = app(GeminiService::class);

        $respuesta = $gemini->generarRespuesta('¿Cuál es el teléfono del Dr. Roberto Paredes?', $tel);

        $this->assertStringContainsString('Dr. Roberto Paredes', $respuesta);
        $this->assertStringContainsString('0414-5556677', $respuesta);
    }

    public function test_paciente_consulta_donde_atiende_el_medico()
    {
        $tel = '584149998878';

        Paciente::create([
            'nombres' => 'Daniel',
            'apellidos' => 'Vargas',
            'cedula' => '22333445',
            'telefono' => $tel,
        ]);

        $medico = Medico::create([
            'name' => 'Carla',
            'lastname' => 'Morales',
            'prefix' => 'Dra.',
            'phone' => '0424-1112233',
            'reg_medico' => 'MED-SEDE-01',
            'is_active' => true,
        ]);

        $centro = \App\Models\MedicalCenter::create([
            'country_id' => 1,
            'state_id' => 1,
            'city_id' => 1,
            'name' => 'Centro Médico San José',
            'address' => 'Av. Bolívar, Torre Médica Piso 3',
            'phone' => '0212-9988776',
            'activo' => true,
        ]);

        $medico->medicalCenters()->attach($centro->id, ['reg_medico' => 'MED-SEDE-01']);

        $gemini = app(GeminiService::class);

        $respuesta = $gemini->generarRespuesta('¿Dónde atiende la Dra. Carla Morales?', $tel);

        $this->assertStringContainsString('Dra. Carla Morales', $respuesta);
        $this->assertStringContainsString('Centro Médico San José', $respuesta);
        $this->assertStringContainsString('Av. Bolívar, Torre Médica Piso 3', $respuesta);
        $this->assertStringContainsString('0212-9988776', $respuesta);
    }

    public function test_paciente_consulta_telefono_y_direccion_del_consultorio()
    {
        $tel = '584149998879';

        Paciente::create([
            'nombres' => 'Esteban',
            'apellidos' => 'Quintero',
            'cedula' => '22333446',
            'telefono' => $tel,
        ]);

        Medico::create([
            'name' => 'Fernando',
            'lastname' => 'Suárez',
            'prefix' => 'Dr.',
            'phone' => '0416-3334455',
            'reg_medico' => 'MED-CONS-01',
            'is_active' => true,
        ]);

        Evolucion::create([
            'reg_medico' => 'MED-CONS-01',
            'clave' => 1,
            'consultorio' => 'Consultorio 5B',
            'telefono' => '0241-8889900',
            'linea_1' => 'Policlínica La Fe',
            'linea_2' => 'Piso 5, ala norte',
            'ciudad' => 'Valencia',
        ]);

        $gemini = app(GeminiService::class);

        // Consultar dirección del consultorio
        $respuestaDir = $gemini->generarRespuesta('¿Cuál es la dirección del consultorio del Dr. Fernando Suárez?', $tel);
        $this->assertStringContainsString('Dr. Fernando Suárez', $respuestaDir);
        $this->assertStringContainsString('Consultorio 5B', $respuestaDir);
        $this->assertStringContainsString('Policlínica La Fe', $respuestaDir);
        $this->assertStringContainsString('Valencia', $respuestaDir);

        // Consultar teléfono del consultorio
        $respuestaTel = $gemini->generarRespuesta('¿Cuál es el teléfono del consultorio del Dr. Fernando Suárez?', $tel);
        $this->assertStringContainsString('Dr. Fernando Suárez', $respuestaTel);
        $this->assertStringContainsString('0241-8889900', $respuestaTel);
    }
}

