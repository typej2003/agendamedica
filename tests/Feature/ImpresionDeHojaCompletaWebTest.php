<?php

namespace Tests\Feature;

use App\Clinica\Impresion\DocumentoImpreso;
use App\Clinica\Impresion\MedidasDeLaMitad;
use App\Clinica\Impresion\ModoDeLaHoja;
use App\Models\Consulta;
use App\Models\Medico;
use App\Models\PreNatalObservacion;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **Los dos modos de hoja del motor** (O-26): media hoja horizontal (dos documentos por A4) y **hoja completa vertical**
 * (uno por A4). El modo lo declara cada documento en `ModoDeLaHoja` —la única tabla de esa regla, al lado de las medidas— y
 * el motor lo respeta; acá se fija que:
 *
 *  - el **informe del control de embarazo** salga en hoja completa (una página vertical, el ancho entero) y ya no en la
 *    mitad izquierda de un A4 horizontal;
 *  - la **constancia** (y las demás solicitudes) siga en media hoja;
 *  - un **lote mixto** —un récipe, un informe de eco y un control de embarazo— traiga las tres clases de hoja en la misma
 *    corrida, cada una con la suya;
 *  - los **formatos en blanco** de los mismos documentos sigan el mismo modo que la hoja real.
 */
class ImpresionDeHojaCompletaWebTest extends TestCase
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

    private function hoja(Consulta $consulta, string $documento): string
    {
        return route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => $documento]);
    }

    private function embarazo(Medico $medico): void
    {
        PreNatalObservacion::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'gesta_clave' => 1,
            'fur' => '2026-05-20', 'menarquia' => '12 años', 'gestas' => 2, 'partos' => 1,
            'cesarea' => 0, 'abortos' => 0, 'tipiaje' => 'O+', 'tipiaje_cony' => 'A+', 'multiple' => 'N',
            'observaciones' => 'Embarazo en curso.',
        ]);
    }

    private function constancia(Medico $medico): void
    {
        DB::table('constancia_obs')->insert([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'numconsulta' => 1,
            'observacion01' => 'Acudió a control.',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ============================================================================== El motor

    public function test_el_modo_de_la_hoja_lo_declara_cada_documento_en_un_solo_lugar(): void
    {
        // Hoja completa: el control de embarazo y los siete ecos.
        foreach ([
            DocumentoImpreso::CONTROL_DE_EMBARAZO,
            DocumentoImpreso::ECO_PELVICO,
            DocumentoImpreso::ECO_OBSTETRICO,
            DocumentoImpreso::ECO_OBSTETRICO_SEGUNDO,
            DocumentoImpreso::ECO_OBSTETRICO_TERCERO,
            DocumentoImpreso::ECO_DOPPLER,
            DocumentoImpreso::ECO_CARDIOGRAMA_FETAL,
            DocumentoImpreso::ECO_MAMARIO,
            DocumentoImpreso::ECO_ABDOMINAL,
        ] as $documento) {
            $this->assertSame(ModoDeLaHoja::COMPLETA, ModoDeLaHoja::de($documento), $documento);
            $this->assertSame(1, ModoDeLaHoja::porPagina(ModoDeLaHoja::COMPLETA));
        }

        // La mitad superior de un A4 vertical: los dos informes del examen, que llevan la imagen en la misma página y dejan
        // el resto de la hoja en blanco como el escritorio (2026-10-18, Alexander).
        foreach ([DocumentoImpreso::COLPOSCOPIA, DocumentoImpreso::HISTEROSCOPIA] as $documento) {
            $this->assertSame(ModoDeLaHoja::MITAD_SUPERIOR, ModoDeLaHoja::de($documento), $documento);
            $this->assertTrue(ModoDeLaHoja::esMitadSuperior($documento), $documento);
            $this->assertSame(1, ModoDeLaHoja::porPagina(ModoDeLaHoja::MITAD_SUPERIOR));
        }

        // El informe médico y el reposo: A4 vertical, grande por defecto y pequeña (mitad superior) a elección; nunca media
        // hoja horizontal (2026-10-21).
        foreach ([DocumentoImpreso::INFORME, DocumentoImpreso::REPOSO] as $documento) {
            $this->assertTrue(ModoDeLaHoja::seEligeLaPagina($documento), $documento);
            $this->assertSame(ModoDeLaHoja::COMPLETA, ModoDeLaHoja::de($documento), $documento);
            $this->assertSame(ModoDeLaHoja::COMPLETA, ModoDeLaHoja::de($documento, ModoDeLaHoja::PAGINA_GRANDE), $documento);
            $this->assertSame(ModoDeLaHoja::COMPLETA, ModoDeLaHoja::de($documento, 'cualquiera'), $documento);
            $this->assertSame(ModoDeLaHoja::MITAD_SUPERIOR, ModoDeLaHoja::de($documento, ModoDeLaHoja::PAGINA_PEQUENA), $documento);
        }
        $this->assertSame(ModoDeLaHoja::MITAD, ModoDeLaHoja::de(DocumentoImpreso::CONSTANCIA, ModoDeLaHoja::PAGINA_PEQUENA));

        // Media hoja: los récipes y las demás solicitudes.
        foreach ([
            DocumentoImpreso::CONSTANCIA,
            DocumentoImpreso::REFERENCIA,
            DocumentoImpreso::HOSPITALIZACION,
            DocumentoImpreso::DIETA,
            DocumentoImpreso::LABORATORIO,
            DocumentoImpreso::LABORATORIO_PAREJA,
            DocumentoImpreso::ESTUDIOS,
            DocumentoImpreso::RESUMEN_MEDICO,
        ] as $documento) {
            $this->assertSame(ModoDeLaHoja::MITAD, ModoDeLaHoja::de($documento), $documento);
            $this->assertSame(2, ModoDeLaHoja::porPagina(ModoDeLaHoja::MITAD));
        }

        // Las medidas de la hoja completa son las de un A4 vertical entero: el ancho del cuerpo es más del doble del de la
        // mitad, que es lo que hace que las hojas completas aprovechen el ancho.
        $completa = ModoDeLaHoja::medidas(ModoDeLaHoja::COMPLETA);
        $mitad = ModoDeLaHoja::medidas(ModoDeLaHoja::MITAD);

        $this->assertGreaterThan($mitad->anchoDelCuerpo() * 1.3, $completa->anchoDelCuerpo());

        // La mitad superior mide lo mismo de ancho que la hoja completa y la mitad de alto (menos el margen y el aire de la
        // media página), que es lo que hace que el informe entre en la mitad de arriba del A4.
        $superior = ModoDeLaHoja::medidas(ModoDeLaHoja::MITAD_SUPERIOR);

        $this->assertSame($completa->anchoDelCuerpo(), $superior->anchoDelCuerpo());
        $this->assertSame(
            MedidasDeLaMitad::ALTO_UTIL_DE_LA_HOJA_COMPLETA_MM / 2 - 5.5,
            MedidasDeLaMitad::ALTO_UTIL_DE_LA_MITAD_SUPERIOR_MM,
            'la mitad superior es media página menos 9 de margen arriba y 5 de aire abajo',
        );
    }

    // =========================================================== El informe del control de embarazo

    public function test_el_informe_del_control_de_embarazo_sale_en_hoja_completa_vertical(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->embarazo($medico);

        $hoja = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, DocumentoImpreso::CONTROL_DE_EMBARAZO));

        $hoja->assertOk()
            ->assertSee('CONTROL DE EMBARAZO')
            ->assertSee('20/05/2026')
            // Una página vertical entera para el documento; no la mitad izquierda del A4 horizontal.
            ->assertSee('recipe-hoja-completa', false)
            ->assertDontSee('recipe-mitad-vacia', false);
    }

    public function test_la_constancia_sigue_en_media_hoja(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->constancia($medico);

        $hoja = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, DocumentoImpreso::CONSTANCIA));

        $hoja->assertOk()
            ->assertSee('CONSTANCIA')
            ->assertDontSee('recipe-hoja-completa', false)
            // La mitad derecha va vacía: dos documentos por A4 horizontal.
            ->assertSee('recipe-mitad-vacia', false);
    }

    // ============================================================================ El lote mixto

    public function test_un_lote_mixto_trae_cada_hoja_en_su_modo_en_la_misma_corrida(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->vademecum($medico);
        $this->recipe($medico, 1001, 1, 1, ['0001'], '2026-10-06');
        $this->embarazo($medico);
        DB::table('eco_pelvico')->insert([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'nroconsulta' => 1,
            'fecha' => '2026-06-01', 'fur' => '2026-05-20', 'cuerpo' => 62.5,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $hojas = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'todos'));

        $hojas->assertOk()
            // El récipe, en las dos mitades del A4 horizontal.
            ->assertSee('class="recipe-hoja ', false)
            // El informe del eco, en su página vertical con nombre (`page: informe`).
            ->assertSee('class="informe-hoja"', false)
            // El control de embarazo, en la hoja completa del motor.
            ->assertSee('recipe-hoja-completa', false)
            ->assertSee('KEFORAL')
            ->assertSee('CONTROL DE EMBARAZO');

        // Una página por documento: las dos horizontales (el récipe y el control de embarazo) más la vertical del eco.
        $html = $hojas->getContent();
        $this->assertSame(2, substr_count($html, 'class="recipe-hoja '), 'las dos hojas horizontales y completas del motor');
        $this->assertSame(1, substr_count($html, 'class="informe-hoja"'), 'la hoja vertical del informe del eco');
    }

    // ======================================================================= Los formatos en blanco

    public function test_el_formato_en_blanco_sigue_el_modo_de_su_hoja_real(): void
    {
        [$user, $medico, $gineco] = $this->escenario();

        // La constancia, en media hoja (con su mitad derecha vacía); el control de embarazo, en hoja completa.
        $this->como($user, $medico, $gineco)->get(route('clinica.formato.en-blanco', DocumentoImpreso::CONSTANCIA))
            ->assertOk()
            ->assertDontSee('recipe-hoja-completa', false)
            ->assertSee('recipe-mitad-vacia', false);

        $this->como($user, $medico, $gineco)->get(route('clinica.formato.en-blanco', DocumentoImpreso::ECO_OBSTETRICO))
            ->assertOk()
            ->assertSee('recipe-hoja-completa', false)
            ->assertDontSee('recipe-mitad-vacia', false);
    }
}
