<?php

namespace Tests\Feature;

use App\Clinica\Consulta\Traumatologia\MapaDeLaOrdenDeIngreso;
use App\Clinica\Consulta\Traumatologia\MapaDelImplante;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **La solicitud de implante y la orden de ingreso de la consulta** (Traumatología, T3, 2026-11-10): las dos pestañas
 * nuevas de *Solicitudes*, sus filas (`implante` y `orden_ingreso`, clave `historia` + `consulta`) y sus dos hojas.
 *
 * Lo que fijan estos casos:
 *
 *  - Las dos se ofrecen **sólo en traumatología**: en otra especialidad no están ni se abren por URL.
 *  - Se **cargan y se guardan** con su botón, y las casillas viajan como `1`/`0`, igual que en la tabla.
 *  - Sin nada cargado **no se crea la fila** (la regla de la plantilla ortopédica).
 *  - Cada una tiene su **hoja**, con lo cargado.
 */
class ConsultaTraumatologiaSolicitudesWebTest extends TestCase
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

    private function seccion(int $consultaId, string $pestana): string
    {
        return route('clinica.consulta', [$consultaId, 'seccion' => 'solicitudes', 'solicitud' => $pestana]);
    }

    public function test_las_dos_pestanas_no_se_ofrecen_fuera_de_traumatologia(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->membrete($medico);
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);

        $this->conContexto($user, $medico, $gineco)
            ->actingAs($user)
            ->get($this->seccion($consulta->id, 'implante'))
            ->assertOk()
            // La URL de una pestaña oculta cae en la de siempre (la constancia).
            ->assertSee('Constancia')
            ->assertDontSee('Plan operatorio')
            ->assertDontSee('Recomendaciones de la hoja');
    }

    public function test_traumatologia_carga_el_implante_y_lo_guarda(): void
    {
        [$user, $medico, $trauma] = $this->medicoConAcceso('Medico', 'traumatologia');
        $this->membrete($medico);
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);

        $this->conContexto($user, $medico, $trauma)
            ->actingAs($user)
            ->get($this->seccion($consulta->id, 'implante'))
            ->assertOk()
            ->assertSee('Plan operatorio')
            ->assertSee('Componente femoral')
            ->assertSee('Orthopack');

        $this->conContexto($user, $medico, $trauma)
            ->actingAs($user)
            ->post(route('clinica.consulta.implante.guardar', $consulta->id), [
                'fecha_reporte' => '2026-11-10',
                'fecha_opera' => '2026-11-20',
                'sistema' => 'NO CEMENTADO',
                'marca' => 'ZIMMER',
                'tipo' => 'PRIMARIO',
                'componente_femoral' => '1',
                'componente_tibial' => '3',
                'diagnostico' => 'COXARTROSIS',
                'plan_operatorio' => 'REEMPLAZO TOTAL DE CADERA',
            ])
            ->assertRedirect($this->seccion($consulta->id, 'implante'));

        $this->assertDatabaseHas('implante', [
            'reg_medico' => $medico->reg_medico,
            'historia' => 1001,
            'consulta' => 1,
            'sistema' => 'NO CEMENTADO',
            'marca' => 'ZIMMER',
            'componente_femoral' => '1',
            'diagnostico' => 'COXARTROSIS',
        ]);

        // Se relee lo guardado en la misma pantalla.
        $this->conContexto($user, $medico, $trauma)
            ->actingAs($user)
            ->get($this->seccion($consulta->id, 'implante'))
            ->assertOk()
            ->assertSee('Cargada')
            ->assertSee('NO CEMENTADO')
            ->assertSee('COXARTROSIS');

        // Y su hoja lleva lo cargado.
        $this->conContexto($user, $medico, $trauma)
            ->actingAs($user)
            ->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'implante']))
            ->assertOk()
            ->assertSee('SOLICITUD IMPLANTE')
            ->assertSee('ZIMMER')
            ->assertSee('REEMPLAZO TOTAL DE CADERA');
    }

    public function test_traumatologia_carga_la_orden_de_ingreso_y_la_guarda(): void
    {
        [$user, $medico, $trauma] = $this->medicoConAcceso('Medico', 'traumatologia');
        $this->membrete($medico);
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);

        $this->conContexto($user, $medico, $trauma)
            ->actingAs($user)
            ->get($this->seccion($consulta->id, 'orden-ingreso'))
            ->assertOk()
            ->assertSee('Recomendaciones de la hoja')
            ->assertSee('Clexane 20 mg SC OD');

        $this->conContexto($user, $medico, $trauma)
            ->actingAs($user)
            ->post(route('clinica.consulta.orden-ingreso.guardar', $consulta->id), [
                'fecha_operacion' => '2026-11-20',
                'idx' => 'FRACTURA DE CADERA',
                'texto' => 'INGRESO A LAS 6 AM',
                'reco_llevar' => '1',
                'reco_clexane' => '1',
                'detalle' => 'AVISAR AL ANESTESIOLOGO',
            ])
            ->assertRedirect($this->seccion($consulta->id, 'orden-ingreso'));

        $this->assertDatabaseHas('orden_ingreso', [
            'reg_medico' => $medico->reg_medico,
            'historia' => 1001,
            'consulta' => 1,
            'idx' => 'FRACTURA DE CADERA',
            'reco_llevar' => '1',
            'reco_clexane' => '1',
            'detalle' => 'AVISAR AL ANESTESIOLOGO',
        ]);

        $this->conContexto($user, $medico, $trauma)
            ->actingAs($user)
            ->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'orden-ingreso']))
            ->assertOk()
            ->assertSee('ORDEN DE INGRESO')
            ->assertSee('FRACTURA DE CADERA')
            ->assertSee('Clexane 20 mg SC OD')
            ->assertSee('AVISAR AL ANESTESIOLOGO');
    }

    public function test_sin_nada_cargado_no_crea_ninguna_de_las_dos_filas(): void
    {
        [$user, $medico, $trauma] = $this->medicoConAcceso('Medico', 'traumatologia');
        $this->membrete($medico);
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);

        $implanteVacio = [];

        foreach (MapaDelImplante::campos() as $campo) {
            $implanteVacio[$campo['clave']] = $campo['tipo'] === 'marca' ? '0' : '';
        }

        $ordenVacia = [];

        foreach (MapaDeLaOrdenDeIngreso::campos() as $campo) {
            $ordenVacia[$campo['clave']] = $campo['tipo'] === 'marca' ? '0' : '';
        }

        $this->conContexto($user, $medico, $trauma)
            ->actingAs($user)
            ->post(route('clinica.consulta.implante.guardar', $consulta->id), $implanteVacio)
            ->assertRedirect();

        $this->conContexto($user, $medico, $trauma)
            ->actingAs($user)
            ->post(route('clinica.consulta.orden-ingreso.guardar', $consulta->id), $ordenVacia)
            ->assertRedirect();

        $this->assertDatabaseMissing('implante', ['reg_medico' => $medico->reg_medico, 'historia' => 1001, 'consulta' => 1]);
        $this->assertDatabaseMissing('orden_ingreso', ['reg_medico' => $medico->reg_medico, 'historia' => 1001, 'consulta' => 1]);
    }
}
