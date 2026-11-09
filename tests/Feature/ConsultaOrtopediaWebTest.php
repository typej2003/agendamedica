<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **La pestaña Ortopedia de la consulta** (Traumatología, 2026-11-09): la plantilla ortopédica del escritorio dentro de
 * *Solicitudes*, con sus casillas y sus textos.
 *
 * Lo que fijan estos casos:
 *
 *  - Se ofrece **sólo en traumatología**: en otra especialidad la pestaña no está ni se abre por URL (cae en la de
 *    siempre).
 *  - La plantilla se **carga y se guarda** con el botón, y las casillas viajan como `1`/`0`, igual que en la tabla.
 *  - Sin nada cargado **no se crea la fila** (la regla del escritorio).
 */
class ConsultaOrtopediaWebTest extends TestCase
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

    private function seccion(int $consultaId): string
    {
        return route('clinica.consulta', [$consultaId, 'seccion' => 'solicitudes', 'solicitud' => 'ortopedia']);
    }

    public function test_la_pestana_ortopedia_no_se_ofrece_fuera_de_traumatologia(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->membrete($medico);
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);

        $this->conContexto($user, $medico, $gineco)
            ->actingAs($user)
            ->get($this->seccion($consulta->id))
            ->assertOk()
            ->assertDontSee('Botas ortopédicas')
            ->assertSee('Constancia');
    }

    public function test_traumatologia_carga_la_plantilla_y_la_guarda(): void
    {
        [$user, $medico, $trauma] = $this->medicoConAcceso('Medico', 'traumatologia');
        $this->membrete($medico);
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);

        $this->conContexto($user, $medico, $trauma)
            ->actingAs($user)
            ->get($this->seccion($consulta->id))
            ->assertOk()
            ->assertSee('Botas ortopédicas')
            ->assertSee('Calzados ortopédicos')
            ->assertSee('Observación');

        $this->conContexto($user, $medico, $trauma)
            ->actingAs($user)
            ->post(route('clinica.consulta.ortopedia.guardar', $consulta->id), [
                'botas' => '1',
                'zapatos' => '0',
                'normal' => '0',
                'soporte' => '3 mm',
                'thomas' => '',
                'plantilla' => '1',
                'tacon_int' => '5',
                'tacon_ext' => '',
                'suela_int' => '',
                'suela_ext' => '',
                'borde_int_rect' => '0',
                'borde_int_abdu' => '1',
                'suela_normal' => '0',
                'suela_antirres' => '0',
                'aparatos' => '',
                'observacion' => 'Control en un mes.',
            ])
            ->assertRedirect($this->seccion($consulta->id));

        $this->assertDatabaseHas('ortopedia', [
            'reg_medico' => $medico->reg_medico,
            'nrohistoria' => 1001,
            'nroconsulta' => 1,
            'botas' => '1',
            'plantilla' => '1',
            'borde_int_abdu' => '1',
            'soporte' => '3 mm',
        ]);

        // Se relee lo guardado en la misma pantalla.
        $this->conContexto($user, $medico, $trauma)
            ->actingAs($user)
            ->get($this->seccion($consulta->id))
            ->assertOk()
            ->assertSee('Con plantilla')
            ->assertSee('Control en un mes.');
    }

    public function test_sin_nada_cargado_no_crea_la_fila(): void
    {
        [$user, $medico, $trauma] = $this->medicoConAcceso('Medico', 'traumatologia');
        $this->membrete($medico);
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);

        $vacio = array_fill_keys(
            ['botas', 'zapatos', 'normal', 'soporte', 'thomas', 'plantilla', 'tacon_int', 'tacon_ext',
                'suela_int', 'suela_ext', 'borde_int_rect', 'borde_int_abdu', 'suela_normal', 'suela_antirres',
                'aparatos', 'observacion'],
            '0',
        );

        $this->conContexto($user, $medico, $trauma)
            ->actingAs($user)
            ->post(route('clinica.consulta.ortopedia.guardar', $consulta->id), $vacio)
            ->assertRedirect();

        $this->assertDatabaseMissing('ortopedia', [
            'reg_medico' => $medico->reg_medico,
            'nrohistoria' => 1001,
            'nroconsulta' => 1,
        ]);
    }
}
