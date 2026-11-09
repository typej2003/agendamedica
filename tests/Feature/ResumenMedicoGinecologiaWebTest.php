<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Medico;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **Los bloques de ginecología en el resumen médico** (F6, 2026-11-09): la colposcopía y la histeroscopía, que son texto
 * de dos solapas del examen (`consultas.observaciones` y `consultas.cuello`).
 *
 * La guarda es la especialidad: en medicina interna esas mismas columnas significan otra cosa, así que sus textos **no**
 * se imprimen como bloques ginecológicos.
 */
class ResumenMedicoGinecologiaWebTest extends TestCase
{
    use DatabaseTransactions;
    use PreparaRecipesParaImprimir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fijarHoy();
    }

    protected function tearDown(): void
    {
        $this->soltarHoy();
        parent::tearDown();
    }

    /** @return array{0:User, 1:Medico, 2:Specialty, 3:Consulta} */
    private function escenario(string $especialidad): array
    {
        [$user, $medico, $especialidadModelo] = $this->medicoConAcceso('Medico', $especialidad);
        $this->membrete($medico);
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);

        DB::table('consultas')->where('reg_medico', $medico->reg_medico)->where('numhistoria', 1001)->where('nroconsulta', 1)
            ->update([
                'enfermedadactual' => 'Control anual.',
                'observaciones' => 'Epitelio escamoso maduro, zona de transformación tipo 1.',
                'cuello' => 'Canal cervical permeable, cavidad regular.',
            ]);

        // Un eco de cada tipo con su conclusión: son los otros tres bloques de ginecología.
        DB::table('eco_pelvico')->insert([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'nroconsulta' => 1,
            'sugerencias' => 'Útero de tamaño normal, sin hallazgos.', 'fecha' => '2026-10-07', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('eco_obstetrico')->insert([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'consulta' => 1,
            'dianostico' => 'Embarazo de 12 semanas, único, vivo.', 'fecha' => '2026-10-07', 'eg' => '12', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('eco_doppler')->insert([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'consulta' => 1,
            'conclusion' => 'Flujo umbilical normal.', 'fecha' => '2026-10-07', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pre_natal_observaciones')->insert([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001,
            'observaciones' => 'Control mensual, sin complicaciones.', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$user, $medico, $especialidadModelo, $consulta];
    }

    private function hoja(Consulta $consulta): string
    {
        return route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'resumen-medico']);
    }

    public function test_la_ginecologa_ve_la_colposcopia_y_la_histeroscopia_en_el_resumen(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario('ginecologia-y-obstetricia');

        $this->conContexto($user, $medico, $gineco)
            ->actingAs($user)
            ->get($this->hoja($consulta))
            ->assertOk()
            ->assertSee('COLPOSCOPÍA:')
            ->assertSee('Epitelio escamoso maduro')
            ->assertSee('HISTEROSCOPÍA:')
            ->assertSee('Canal cervical permeable')
            ->assertSee('ECO GINECOLÓGICO:')
            ->assertSee('Útero de tamaño normal')
            ->assertSee('ECO OBSTÉTRICO:')
            ->assertSee('Embarazo de 12 semanas')
            ->assertSee('ECO DOPPLER:')
            ->assertSee('Flujo umbilical normal')
            ->assertSee('CONTROL PRENATAL:')
            ->assertSee('Control mensual, sin complicaciones.');
    }

    public function test_el_internista_no_ve_esos_bloques_aunque_las_columnas_tengan_texto(): void
    {
        [$user, $medico, $internista, $consulta] = $this->escenario('medicina-interna');

        $this->conContexto($user, $medico, $internista)
            ->actingAs($user)
            ->get($this->hoja($consulta))
            ->assertOk()
            ->assertDontSee('COLPOSCOPÍA:')
            ->assertDontSee('HISTEROSCOPÍA:')
            ->assertDontSee('Epitelio escamoso maduro')
            ->assertDontSee('ECO GINECOLÓGICO:')
            ->assertDontSee('ECO OBSTÉTRICO:')
            ->assertDontSee('ECO DOPPLER:')
            ->assertDontSee('Flujo umbilical normal')
            ->assertDontSee('CONTROL PRENATAL:');
    }
}
