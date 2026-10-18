<?php

namespace Tests\Feature;

use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\PestanasDeSolicitudes;
use App\Clinica\Consulta\Vistas\TiposDeEstudio;
use App\Clinica\Impresion\DocumentoImpreso;
use App\Models\Consulta;
use App\Models\Medico;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **Guardar y después imprimir** (O-16): el «Imprimir» de una sección de la consulta guarda primero lo escrito y recién
 * entonces arma la hoja, así que ya no termina en la página «No encontramos esa página» cuando había cambios sin guardar.
 *
 * Lo que fijan estos casos:
 *
 *  - el `POST` de una sección con `imprimir=<documento>` **deja la fila guardada** y **redirige a la hoja**, que responde
 *    200 con su título (el caso del eco obstétrico y el del reposo: los documentos de las dos familias, media hoja y hoja
 *    completa);
 *  - con un documento **vacío** no se crea ninguna fila y la pantalla dice «Guarda los datos del estudio para poder
 *    imprimir» (en vez del 404);
 *  - el botón es un `submit` del formulario de la sección y lleva la clave del documento.
 */
class ImpresionGuardandoWebTest extends TestCase
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
    private function escenario(): array
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->membrete($medico);
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);

        return [$user, $medico, $gineco, $consulta];
    }

    private function como(User $user, Medico $medico, Specialty $gineco): self
    {
        return $this->conContexto($user, $medico, $gineco)->actingAs($user);
    }

    private function hojaDe(Consulta $consulta, string $documento): string
    {
        return route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => $documento]);
    }

    // ====================================================================== El eco: guarda e imprime

    public function test_imprimir_un_eco_con_datos_sin_guardar_guarda_la_fila_y_despues_abre_la_hoja(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $respuesta = $this->como($user, $medico, $gineco)->post(
            route('clinica.consulta.estudio.guardar', [$consulta->id, TiposDeEstudio::OBSTETRICO]),
            [
                'fur' => '09/05/2026',
                'gesta' => '2',
                'lcc' => '25',
                'imprimir' => DocumentoImpreso::ECO_OBSTETRICO,
            ],
        );

        // Lo que apretó «Imprimir» quedó guardado y la redirección va a la hoja del documento, no a la sección.
        $respuesta->assertRedirect($this->hojaDe($consulta, DocumentoImpreso::ECO_OBSTETRICO));
        $this->assertSame(1, DB::table('eco_obstetrico')->where('consulta', 1)->count());

        $this->como($user, $medico, $gineco)->get($this->hojaDe($consulta, DocumentoImpreso::ECO_OBSTETRICO))
            ->assertOk()
            ->assertSee('ECOSONOGRAFÍA OBSTÉTRICA')
            ->assertSee('PRIMER TRIMESTRE')
            ->assertSee('25');
    }

    public function test_imprimir_un_eco_vacio_no_crea_la_fila_y_dice_que_hay_que_guardar_los_datos(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $respuesta = $this->como($user, $medico, $gineco)->post(
            route('clinica.consulta.estudio.guardar', [$consulta->id, TiposDeEstudio::OBSTETRICO]),
            ['imprimir' => DocumentoImpreso::ECO_OBSTETRICO],
        );

        // Vuelve a la sección (a cargar el estudio) y lo dice con el texto de O-16.
        $respuesta->assertRedirect(route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_ESTUDIO,
            'tipo' => TiposDeEstudio::OBSTETRICO,
        ]));
        $respuesta->assertSessionHas('error', fn ($mensaje) => str_contains((string) $mensaje, 'Guarda los datos del estudio para poder imprimir.'));

        $this->assertSame(0, DB::table('eco_obstetrico')->count());
    }

    // ================================================================= Los demás documentos (media hoja)

    public function test_imprimir_el_reposo_con_datos_sin_guardar_guarda_la_fila_y_despues_abre_la_hoja(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $respuesta = $this->como($user, $medico, $gineco)->post(
            route('clinica.consulta.reposo.guardar', $consulta->id),
            [
                'dias' => 8,
                'desde' => '10/10/2026',
                'observacion' => 'Reposo por infección.',
                'imprimir' => DocumentoImpreso::REPOSO,
            ],
        );

        $respuesta->assertRedirect($this->hojaDe($consulta, DocumentoImpreso::REPOSO));
        $this->assertSame(1, DB::table('reposo_paciente')->count());

        $this->como($user, $medico, $gineco)->get($this->hojaDe($consulta, DocumentoImpreso::REPOSO))
            ->assertOk()
            ->assertSee('REPOSO MÉDICO')
            ->assertSee('Reposo por infección.');
    }

    public function test_un_documento_que_falla_la_validacion_no_se_imprime_y_se_queda_con_su_error(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $respuesta = $this->como($user, $medico, $gineco)->post(
            route('clinica.consulta.reposo.guardar', $consulta->id),
            ['dias' => 0, 'desde' => '10/10/2026', 'imprimir' => DocumentoImpreso::REPOSO],
        );

        // La validación manda: no hay hoja y la sección marca el campo.
        $respuesta->assertSessionHasErrors('dias');
        $this->assertSame(0, DB::table('reposo_paciente')->count());
    }

    // ======================================================================================= El botón

    public function test_el_boton_imprimir_del_estudio_es_un_envio_del_formulario_con_la_clave_del_documento(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        DB::table('eco_obstetrico')->insert([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'consulta' => 1,
            'fecha' => '2026-06-01', 'fur' => '2026-05-09', 'lcc' => '25',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $seccion = $this->como($user, $medico, $gineco)->get(route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_ESTUDIO,
            'tipo' => TiposDeEstudio::OBSTETRICO,
        ]));

        $seccion->assertOk()
            ->assertSee('name="imprimir"', false)
            ->assertSee('value="' . DocumentoImpreso::ECO_OBSTETRICO . '"', false)
            ->assertSee('form="estudio-obstetrico-form"', false);
    }

    public function test_el_boton_imprimir_de_una_solicitud_tambien_guarda_antes(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $seccion = $this->como($user, $medico, $gineco)->get(route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_SOLICITUDES,
            'solicitud' => PestanasDeSolicitudes::CONSTANCIA,
        ]));

        $seccion->assertOk()
            ->assertSee('name="imprimir"', false)
            ->assertSee('value="' . DocumentoImpreso::CONSTANCIA . '"', false)
            ->assertSee('form="constancia-form"', false);
    }
}
