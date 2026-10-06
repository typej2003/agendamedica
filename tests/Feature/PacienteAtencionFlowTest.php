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
    public function test_contexto_paciente_no_registrado_retorna_especialidades()
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
                'especialidades' => ['Cardiología', 'Pediatría'],
            ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => '¡Hola! Bienvenido a Doctorisimo. ¿A cuál especialidad médica deseas acudir para agendar tu cita?']
                            ]
                        ]
                    ]
                ]
            ], 200),
        ]);

        $gemini = app(GeminiService::class);
        $respuesta = $gemini->generarRespuesta('Hola', '584129998877');

        $this->assertStringContainsString('Bienvenido a Doctorisimo', $respuesta);
        $this->assertStringContainsString('especialidad', $respuesta);
    }

    public function test_contexto_paciente_con_multiples_medicos_ofrece_lista()
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

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                ['text' => '¡Hola, José Rosales! Veo que te has atendido con el Dr. Carlos Mendoza y la Dra. María Colmenares. ¿Con cuál de ellos deseas agendar tu cita?']
                            ]
                        ]
                    ]
                ]
            ], 200),
        ]);

        $gemini = app(GeminiService::class);
        $respuesta = $gemini->generarRespuesta('Hola buenas tardes', '584165800403');

        $this->assertStringContainsString('José Rosales', $respuesta);
        $this->assertStringContainsString('Carlos Mendoza', $respuesta);
    }

    public function test_gemini_invoca_tool_consultar_proximo_cupo_en_cola()
    {
        $mockContext = $this->mock(PacienteAtencionContextService::class);
        $mockContext->shouldReceive('obtenerContextoPaciente')
            ->once()
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

        // Simular primer turno con FunctionCall y segundo turno con respuesta final
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::sequence()
                ->push([
                    'candidates' => [
                        [
                            'content' => [
                                'parts' => [
                                    [
                                        'functionCall' => [
                                            'name' => 'consultar_proximo_cupo_disponible',
                                            'args' => [
                                                'reg_medico' => 'MED-001',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200)
                ->push([
                    'candidates' => [
                        [
                            'content' => [
                                'parts' => [
                                    [
                                        'text' => 'El día más próximo disponible para el Dr. Carlos Mendoza es el Jueves 8 de octubre de 2026 y le quedan 5 cupos.',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200),
        ]);

        $gemini = app(GeminiService::class);
        $respuesta = $gemini->generarRespuesta('Quiero cita con el doctor Carlos Mendoza', '584165800403');

        $this->assertStringContainsString('Carlos Mendoza', $respuesta);
        $this->assertStringContainsString('Jueves 8 de octubre de 2026', $respuesta);
        $this->assertStringContainsString('5 cupos', $respuesta);
    }
}
