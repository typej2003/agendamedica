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
 * **Imprimir la plantilla ortopédica** (Traumatología, 2026-11-09): la hoja «Calzados Ortopédicos» del escritorio, con
 * las casillas marcadas, los textos que tienen algo y la observación.
 *
 * Lo que fijan estos casos: la hoja sale con su título y sus rótulos, la secretaría la ve en la lista de la cita sin el
 * contenido clínico, y fuera de traumatología no se ofrece.
 */
class ImpresionDeOrtopediaWebTest extends TestCase
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
    private function escenario(string $especialidad = 'traumatologia'): array
    {
        [$user, $medico, $trauma] = $this->medicoConAcceso('Medico', $especialidad);
        $this->membrete($medico);
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);

        return [$user, $medico, $trauma, $consulta];
    }

    private function plantilla(Medico $medico, array $extra = []): void
    {
        DB::table('ortopedia')->insert(array_merge([
            'reg_medico' => $medico->reg_medico,
            'nrohistoria' => 1001,
            'nroconsulta' => 1,
            'botas' => '1',
            'soporte' => '3 mm',
            'observacion' => 'Control en un mes.',
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }

    private function hoja(Consulta $consulta): string
    {
        return route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'ortopedia']);
    }

    public function test_la_hoja_sale_con_su_titulo_las_casillas_marcadas_y_la_observacion(): void
    {
        [$user, $medico, $trauma, $consulta] = $this->escenario();
        $this->plantilla($medico);

        $this->conContexto($user, $medico, $trauma)
            ->actingAs($user)
            ->get($this->hoja($consulta))
            ->assertOk()
            ->assertSee('CALZADOS ORTOPÉDICOS')
            ->assertSee('BOTAS ORTOPÉDICAS:')
            ->assertSee('SOPORTE PLANTAR:')
            ->assertSee('3 mm')
            ->assertSee('OBSERVACIÓN:')
            ->assertSee('Control en un mes.');
    }

    /**
     * Sin plantilla cargada no se imprime una hoja clínica vacía: sale el aviso de «todavía no está» con la vuelta a la
     * consulta (O-16), que es lo mismo que hacen las demás solicitudes.
     */
    public function test_sin_plantilla_cargada_avisa_que_falta_el_documento(): void
    {
        [$user, $medico, $trauma, $consulta] = $this->escenario();

        $this->conContexto($user, $medico, $trauma)
            ->actingAs($user)
            ->get($this->hoja($consulta))
            ->assertOk()
            ->assertSee('Volver a la consulta')
            ->assertDontSee('BOTAS ORTOPÉDICAS:');
    }
}
