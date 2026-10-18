<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\Paciente;
use App\Models\Specialty;
use App\Services\GeminiService;
use App\Services\PacienteAtencionContextService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PacienteAtencionFlowTest extends TestCase
{
    public function test_saludo_paciente_no_registrado_inicia_flujo_de_registro()
    {
        $mockContext = $this->mock(PacienteAtencionContextService::class);
        $mockContext->shouldReceive('gestionarRegistroPaciente')
            ->once()
            ->with('584129998877', 'Hola')
            ->andReturn("¡Hola! Te damos la bienvenida a *Doctorisimo*. 👋\n\nNotamos que tu número de teléfono no se encuentra registrado en nuestro sistema. Para poder brindarte atención médica y agendar tus citas, te ofrecemos registrarte.\n\nPara completar tu registro, te solicitaremos los siguientes datos:\n1️⃣ Tu número de cédula de identidad\n2️⃣ Tus apellidos\n3️⃣ Tus nombres\n\nPara comenzar, por favor indícanos tu número de *cédula de identidad* (ejemplo: V-12345678):");

        $gemini = app(GeminiService::class);
        $respuesta = $gemini->generarRespuesta('Hola', '584129998877');

        $this->assertStringContainsString('Doctorisimo', $respuesta);
        $this->assertStringContainsString('cédula de identidad', $respuesta);
        $this->assertStringContainsString('apellidos', $respuesta);
    }

    public function test_saludo_paciente_registrado_con_multiples_medicos_pregunta_a_cual_referenciar()
    {
        $mockContext = $this->mock(PacienteAtencionContextService::class);
        $mockContext->shouldReceive('gestionarRegistroPaciente')
            ->once()
            ->with('584165800403', 'Hola buenas tardes')
            ->andReturn(null);

        $mockContext->shouldReceive('obtenerContextoPaciente')
            ->once()
            ->with('584165800403')
            ->andReturn([
                'existe' => true,
                'nombre' => 'José Rosales',
                'numhistoria' => 101,
                'medicos_asociados' => [
                    [
                        'id' => 1,
                        'reg_medico' => 'MED-001',
                        'nombre' => 'Dr(a). Carlos Mendoza',
                        'especialidad' => 'Cardiología',
                    ],
                    [
                        'id' => 2,
                        'reg_medico' => 'MED-002',
                        'nombre' => 'Dr(a). María Colmenares',
                        'especialidad' => 'Ginecología',
                    ],
                ],
                'tiene_medicos' => true,
                'especialidades' => ['Cardiología', 'Ginecología'],
            ]);

        $mockContext->shouldReceive('generarMensajePrimerContacto')
            ->once()
            ->andReturn("¡Hola, *José Rosales*! 👋 Bienvenido a *Doctorisimo*.\n\nVemos que en nuestro consultorio te has atendido anteriormente con los siguientes médicos:\n\n1️⃣ *Dr(a). Carlos Mendoza* (Cardiología)\n2️⃣ *Dr(a). María Colmenares* (Ginecología)\n\n¿A qué médico deseas referenciar para agendar tu cita?");

        $gemini = app(GeminiService::class);
        $respuesta = $gemini->generarRespuesta('Hola buenas tardes', '584165800403');

        $this->assertStringContainsString('José Rosales', $respuesta);
        $this->assertStringContainsString('Carlos Mendoza', $respuesta);
        $this->assertStringContainsString('referenciar', $respuesta);
    }

    public function test_saludo_paciente_registrado_con_un_solo_medico()
    {
        $contextService = new PacienteAtencionContextService();
        $contexto = [
            'existe' => true,
            'nombre' => 'José Rosales',
            'numhistoria' => 101,
            'medicos_asociados' => [
                [
                    'id' => 1,
                    'reg_medico' => 'MED-001',
                    'nombre' => 'Dr. Carlos Mendoza',
                    'especialidad' => 'Cardiología',
                ],
            ],
            'tiene_medicos' => true,
            'especialidades' => ['Cardiología'],
        ];

        $mensaje = $contextService->generarMensajePrimerContacto($contexto);

        $this->assertStringContainsString('José Rosales', $mensaje);
        $this->assertStringContainsString('Dr. Carlos Mendoza', $mensaje);
        $this->assertStringContainsString('médico tratante registrado', $mensaje);
        $this->assertStringContainsString('doctor habitual', $mensaje);
    }

    public function test_saludo_paciente_registrado_sin_medicos_en_medicopaciente()
    {
        $contextService = new PacienteAtencionContextService();
        $contexto = [
            'existe' => true,
            'nombre' => 'José Rosales',
            'numhistoria' => 101,
            'medicos_asociados' => [],
            'tiene_medicos' => false,
            'especialidades' => ['Medicina General', 'Pediatría'],
        ];

        $mensaje = $contextService->generarMensajePrimerContacto($contexto);

        $this->assertStringContainsString('José Rosales', $mensaje);
        $this->assertStringContainsString('¿A cuál especialidad médica deseas buscar un médico', $mensaje);
        $this->assertStringContainsString('Medicina General', $mensaje);
    }

    public function test_seleccion_de_medico_consulta_cola_y_devuelve_proximo_cupo()
    {
        $mockContext = $this->mock(PacienteAtencionContextService::class);
        $mockContext->shouldReceive('gestionarRegistroPaciente')
            ->once()
            ->with('584165800403', '1')
            ->andReturn(null);

        $mockContext->shouldReceive('obtenerContextoPaciente')
            ->once()
            ->with('584165800403')
            ->andReturn([
                'existe' => true,
                'nombre' => 'José Rosales',
                'numhistoria' => 101,
                'medicos_asociados' => [
                    [
                        'id' => 1,
                        'reg_medico' => 'MED-001',
                        'nombre' => 'Dr(a). Carlos Mendoza',
                        'especialidad' => 'Cardiología',
                    ],
                ],
                'tiene_medicos' => true,
                'especialidades' => ['Cardiología'],
            ]);

        $mockContext->shouldReceive('consultarProximoDiaDisponible')
            ->once()
            ->with('MED-001')
            ->andReturn([
                'disponible' => true,
                'reg_medico' => 'MED-001',
                'medico' => 'Dr(a). Carlos Mendoza',
                'especialidad' => 'Cardiología',
                'fecha' => '2026-10-08',
                'fecha_formateada' => 'Jueves 8 de octubre de 2026',
                'cupos_disponibles' => 5,
            ]);

        $gemini = app(GeminiService::class);
        $respuesta = $gemini->generarRespuesta('1', '584165800403');

        $this->assertStringContainsString('Carlos Mendoza', $respuesta);
        $this->assertStringContainsString('Jueves 8 de octubre de 2026', $respuesta);
        $this->assertStringContainsString('5', $respuesta);
    }
}
