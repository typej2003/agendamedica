<?php

namespace Tests\Feature;

use App\Clinica\Impresion\DocumentoImpreso;
use App\Clinica\Impresion\FormatosEnBlanco;
use App\Models\Evolucion;
use App\Models\Medico;
use App\Models\RecipeFormato;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **Formatos en blanco** (WEB-5.5b): cada documento de la consulta sin datos de paciente, con el membrete, la firma, el sello y el pie del
 * médico, en `/clinica/formato/en-blanco/{formato}`.
 *
 * Lo que estos casos fijan:
 *
 *  - **Quién entra**: el médico; la secretaría recibe 403 y el invitado va al login; un formato inexistente es 404.
 *  - **Qué sale**: el formato guardado del médico (membrete, pie, color de la línea), el bloque del paciente con sus rótulos y **sin ningún
 *    dato de la base**, los datos cortos y los renglones propios de cada documento, y los títulos de la hoja real.
 *  - **Qué NO hace**: no escribe nada (ni filas, ni `sync_changes`, ni tokens de verificación) y no lleva QR.
 *  - **El récipe respeta el modo** guardado y deja elegir el otro sin guardarlo.
 *  - **Las hojas reales no cambian** (récipe y documentos).
 */
class FormatosEnBlancoWebTest extends TestCase
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
        Evolucion::flushEventListeners();
        RecipeFormato::flushEventListeners();
        $this->soltarHoy();

        parent::tearDown();
    }

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConFormato(): array
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->membrete($medico, ['especialidad' => 'Medicina Interna', 'rif' => 'J-55555555-5', 'linea_1' => 'Calle del Pie 123'], ['color_linea' => 'rojo']);

        return [$user, $medico, $gineco];
    }

    private function como(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->conContexto($user, $medico, $specialty)->actingAs($user);
    }

    private function url(string $formato, array $consulta = []): string
    {
        return route('clinica.formato.en-blanco', ['formato' => $formato] + $consulta);
    }

    /** Un conteo de todo lo que ver una hoja podría escribir. */
    private function huellaDeLaBase(): array
    {
        return [
            DB::table('sync_changes')->count(),
            DB::table('recipe_verificaciones')->count(),
            DB::table('recipes')->count(),
            DB::table('pacientes')->count(),
            DB::table('consultas')->count(),
            DB::table('evolucion')->count(),
            DB::table('recipe_formatos')->count(),
        ];
    }

    /** @return array<int,array{0:string}> */
    public function formatos(): array
    {
        return array_map(fn (string $clave) => [$clave], FormatosEnBlanco::claves());
    }

    // ------------------------------------------------------------------------------------ Quién entra

    /** @dataProvider formatos */
    public function test_cada_formato_responde_200_al_medico_con_su_membrete_y_sin_datos_de_paciente(string $formato): void
    {
        [$user, $medico, $gineco] = $this->medicoConFormato();
        $this->vademecum($medico);
        // Un paciente con récipe en la base: nada de eso puede aparecer en una hoja en blanco.
        $this->pacienteYConsulta($medico);
        $this->recipe($medico, 1001, 1, 1, [['0001', 2, 'Una cápsula cada 6 horas.']], '2026-10-05');

        $antes = $this->huellaDeLaBase();

        $html = $this->como($user, $medico, $gineco)->get($this->url($formato))->assertOk()->getContent();

        $this->assertSame($antes, $this->huellaDeLaBase(), 'Una hoja en blanco no escribe nada.');

        // El formato guardado del médico: membrete, pie y color de la línea.
        $this->assertStringContainsString('Dra. Ana Imprime', $html);
        $this->assertStringContainsString('Medicina Interna', $html);
        $this->assertStringContainsString('J-55555555-5', $html);
        $this->assertStringContainsString('Calle del Pie 123', $html);
        $this->assertStringContainsString('linea-rojo', $html);

        // El bloque del paciente: los rótulos de la hoja real, vacíos, con su línea.
        foreach (['PACIENTE:', 'C.I.:', 'EDAD:', 'FECHA:'] as $rotulo) {
            $this->assertStringContainsString($rotulo, $html);
        }
        $this->assertStringContainsString('dato-en-blanco', $html);

        // Nada del paciente de la base ni del récipe.
        $this->assertStringNotContainsString('14555666', $html);
        $this->assertStringNotContainsString('Alvarez', $html);
        $this->assertStringNotContainsString('Barquisimeto', $html);
        $this->assertStringNotContainsString('Una cápsula cada 6 horas', $html);

        // Renglones para escribir, una sola hoja, sin QR ni token.
        $this->assertStringContainsString('renglones-en-blanco', $html);
        $this->assertSame(1, substr_count($html, '<article class="recipe-hoja'));
        $this->assertStringNotContainsString('recipe-qr-codigo', $html);
        $this->assertStringNotContainsString('Continúa', $html);
        $this->assertStringContainsString('data-imprimir', $html);
        $this->assertStringContainsString('css/clinica-en-blanco.css', $html);
    }

    /** @dataProvider formatos */
    public function test_la_secretaria_recibe_403(string $formato): void
    {
        [, $medico, $gineco] = $this->medicoConFormato();
        $secretaria = $this->secretariaConAcceso($medico);

        $this->como($secretaria, $medico, $gineco)->get($this->url($formato))->assertForbidden();
    }

    public function test_el_invitado_va_al_login(): void
    {
        $this->get($this->url('recipe'))->assertRedirect('/login');
    }

    public function test_un_formato_que_no_existe_es_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConFormato();

        $this->como($user, $medico, $gineco)->get($this->url('ecografia'))->assertNotFound();
        $this->como($user, $medico, $gineco)->get(url('/clinica/formato/en-blanco/RECIPE'))->assertNotFound();
    }

    // ------------------------------------------------------------------------------ Lo propio de cada hoja

    public function test_los_titulos_son_los_de_la_hoja_real(): void
    {
        [$user, $medico, $gineco] = $this->medicoConFormato();
        $web = $this->como($user, $medico, $gineco);

        foreach (DocumentoImpreso::TITULOS as $clave => [$titulo]) {
            $html = $web->get($this->url($clave))->assertOk()->getContent();
            $this->assertStringContainsString('>' . $titulo . '<', $html, "El título de {$clave}.");
        }
    }

    public function test_el_reposo_trae_los_cuatro_datos_en_blanco(): void
    {
        [$user, $medico, $gineco] = $this->medicoConFormato();

        $html = $this->como($user, $medico, $gineco)->get($this->url('reposo'))->assertOk()->getContent();

        foreach (['REPOSO DURANTE:', 'A PARTIR DE LA FECHA:', 'HASTA EL DÍA:', 'REINTEGRO A SUS LABORES EL DÍA:'] as $rotulo) {
            $this->assertStringContainsString($rotulo, $html);
        }
        $this->assertSame(4, substr_count($html, 'documento-fijo documento-fijo-en-blanco'));
    }

    public function test_la_referencia_trae_el_medico_referido_y_el_motivo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConFormato();

        $html = $this->como($user, $medico, $gineco)->get($this->url('referencia'))->assertOk()->getContent();

        foreach (['REFERIDO A:', 'ESPECIALIDAD:', 'CLÍNICA:', 'TELÉFONO:', 'MOTIVO DE LA REFERENCIA:'] as $rotulo) {
            $this->assertStringContainsString($rotulo, $html);
        }
    }

    public function test_hospitalizacion_laboratorio_estudios_y_dieta_traen_sus_secciones(): void
    {
        [$user, $medico, $gineco] = $this->medicoConFormato();
        $web = $this->como($user, $medico, $gineco);

        $hospitalizacion = $web->get($this->url('hospitalizacion'))->getContent();
        foreach (['HOSPITALIZAR EN:', 'DIAGNÓSTICO PROVISIONAL:', 'RECOMENDACIONES:'] as $rotulo) {
            $this->assertStringContainsString($rotulo, $hospitalizacion);
        }

        $laboratorio = $web->get($this->url('laboratorio'))->getContent();
        $this->assertStringContainsString('EXÁMENES SOLICITADOS:', $laboratorio);
        $this->assertStringContainsString('RESUMEN CLÍNICO:', $laboratorio);

        $estudios = $web->get($this->url('estudios'))->getContent();
        $this->assertStringContainsString('ESTUDIOS SOLICITADOS:', $estudios);
        $this->assertStringContainsString('RESUMEN CLÍNICO:', $estudios);

        $this->assertStringContainsString('DIETA:', $web->get($this->url('dieta'))->getContent());
    }

    // ------------------------------------------------------------------------------------------ Récipe

    public function test_el_recipe_respeta_el_modo_guardado_y_deja_elegir_el_otro_sin_guardarlo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->membrete($medico, [], ['modo_impresion' => RecipeFormato::MODO_SOLO_INDICACIONES]);
        $web = $this->como($user, $medico, $gineco);

        $guardado = $web->get($this->url('recipe'))->assertOk()->getContent();
        $this->assertStringContainsString('recipe-mitad-indicaciones', $guardado);
        $this->assertStringNotContainsString('recipe-mitad-rp', $guardado);
        $this->assertStringContainsString('Indicaciones:', $guardado);
        $this->assertStringContainsString('recipe-mitad-vacia', $guardado, 'Media hoja: la otra mitad queda en blanco.');

        $antes = $this->huellaDeLaBase();
        $elegido = $web->get($this->url('recipe', ['modo' => RecipeFormato::MODO_RP_E_INDICACIONES]))->assertOk()->getContent();
        $this->assertStringContainsString('recipe-mitad-rp', $elegido);
        $this->assertStringContainsString('recipe-mitad-indicaciones', $elegido);
        $this->assertStringNotContainsString('recipe-mitad-vacia', $elegido);
        $this->assertSame($antes, $this->huellaDeLaBase(), 'Elegir el modo no lo guarda.');
        $this->assertSame(RecipeFormato::MODO_SOLO_INDICACIONES, RecipeFormato::where('reg_medico', $medico->reg_medico)->value('modo_impresion'));

        // Un modo inventado cae en el guardado.
        $this->assertStringNotContainsString('recipe-mitad-rp', $web->get($this->url('recipe', ['modo' => 'inventado']))->getContent());
    }

    public function test_el_recipe_por_defecto_trae_rp_e_indicaciones(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $html = $this->como($user, $medico, $gineco)->get($this->url('recipe'))->assertOk()->getContent();

        $this->assertStringContainsString('recipe-mitad-rp', $html);
        $this->assertStringContainsString('recipe-mitad-indicaciones', $html);
        $this->assertStringContainsString('aria-current="true"', $html);
    }

    // ------------------------------------------------------------------------------- El acceso y lo real

    public function test_la_pantalla_del_formato_ofrece_los_nueve_formatos_en_blanco(): void
    {
        [$user, $medico, $gineco] = $this->medicoConFormato();

        $html = $this->como($user, $medico, $gineco)->get(route('clinica.formato'))->assertOk()->getContent();

        $this->assertStringContainsString('Formatos en blanco', $html);
        foreach (FormatosEnBlanco::claves() as $clave) {
            $this->assertStringContainsString($this->url($clave), $html);
        }
        $this->assertCount(9, FormatosEnBlanco::catalogo());
    }

    public function test_las_hojas_reales_no_cambian(): void
    {
        [$user, $medico, $gineco] = $this->medicoConFormato();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->recipe($medico, 1001, 1, 1, [['0001', 2, 'Una cápsula cada 6 horas.']], '2026-10-05');

        $html = $this->como($user, $medico, $gineco)->get($this->urlRecipe($consulta))->assertOk()->getContent();

        $this->assertStringContainsString('Una cápsula cada 6 horas', $html);
        $this->assertStringNotContainsString('dato-en-blanco', $html);
        $this->assertStringNotContainsString('cuerpo-en-blanco', $html);
        $this->assertStringNotContainsString('renglones-en-blanco', $html);
    }
}
