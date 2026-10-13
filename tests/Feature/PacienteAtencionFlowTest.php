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
    public function test_saludo_paciente_no_registrado_da_bienvenida_y_pregunta_especialidad()
    {
        $mockContext = $this->mock(PacienteAtencionContextService::class);
        $mockContext->shouldReceive('obtenerContextoPaciente')
            ->once()
            ->with('584129998877')
            ->andReturn([
                'existe' => false,
                'nombre' => null,
                'numhistoria' => null,
                'medicos_asociados' => [],
                'tiene_medicos' => false,
                'especialidades' => ['Medicina General', 'Cardiología', 'Pediatría'],
            ]);

        $mockContext->shouldReceive('generarMensajePrimerContacto')
            ->once()
            ->andReturn("¡Hola! 👋 Te damos la bienvenida a *Doctorisimo*.\n\n¿A cuál especialidad médica deseas buscar un médico para agendar tu cita?\n\n1️⃣ Medicina General\n2️⃣ Cardiología\n3️⃣ Pediatría");

        $gemini = app(GeminiService::class);
        $respuesta = $gemini->generarRespuesta('Hola', '584129998877');

        $this->assertStringContainsString('Doctorisimo', $respuesta);
        $this->assertStringContainsString('especialidad médica', $respuesta);
        $this->assertStringContainsString('Cardiología', $respuesta);
    }

    public function test_saludo_paciente_registrado_con_multiples_medicos_pregunta_a_cual_referenciar()
    {
        $mockContext = $this->mock(PacienteAtencionContextService::class);
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
