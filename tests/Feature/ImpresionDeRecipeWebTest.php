<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\RecipeFormato;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **Imprimir el récipe desde la consulta** (WEB-5.2): la vista previa de la hoja A4 horizontal que imprime el
 * navegador, vista como el médico.
 *
 * Lo que estos casos fijan:
 *
 *  - **El contenido de la hoja**: el membrete del médico (con su formato y sus defaults), los datos del paciente
 *    —la edad a la **fecha del récipe**—, los medicamentos con el nombre comercial o genérico, la firma, el pie y
 *    **la próxima cita** con la regla del escritorio (`fecha > hoy`, sólo de ese médico).
 *  - **Los dos modos** (Rp. + Indicaciones y sólo Indicaciones) y que **sin fila de formato** se imprime con los
 *    defaults **sin crear la fila**.
 *  - **La segunda hoja** (12 medicamentos) y **el récipe de la pareja** (sus datos, sin los del paciente).
 *  - **Quién entra**: el médico del contexto; la consulta de otro médico da 404, la secretaría 403 y el invitado va al
 *    login.
 *  - **Las opciones guardadas**: son del médico (la fila de `recipe_formatos`), se comparten con su asistente y **no
 *    mueven `updated_at`** de una fila que ya existe (el móvil lo usa como marca de versión de su firma y sello).
 *  - **El enlace desde la historia** del paciente y los botones de la sección de récipes.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class ImpresionDeRecipeWebTest extends TestCase
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

    /** Un médico con vademécum, paciente, consulta de hoy y un récipe 1 de dos medicamentos, listo para imprimir. */
    private function escenario(array $extraPaciente = []): array
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        $this->membrete($medico);
        [$pacienteId, $consulta] = $this->pacienteYConsulta($medico, 1001, 1, array_merge(['fnacimiento' => '1990-10-06'], $extraPaciente));
        $this->recipe($medico, 1001, 1, 1, [['0001', 2, 'Una cápsula cada 6 horas.'], ['0003', 1, 'Una al día.']], '2026-10-05');

        return [$user, $medico, $gineco, $pacienteId, $consulta];
    }

    // ------------------------------------------------------------------------------------ El contenido

    public function test_el_medico_imprime_el_recipe_con_el_membrete_el_paciente_y_los_medicamentos(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlRecipe($consulta));

        $respuesta->assertOk();
        // El membrete del médico.
        $respuesta->assertSee('Dra. Ana Imprime');
        $respuesta->assertSee('Ginecología y Obstetricia');
        $respuesta->assertSee('J-12345678-9');
        // El paciente: nombre, cédula con nacionalidad, edad **a la fecha del récipe** (35, no los 36 de hoy) y fecha.
        $respuesta->assertSee('Ana Alvarez');
        $respuesta->assertSee('V-14555666');
        $respuesta->assertSee('35 años');
        $respuesta->assertDontSee('36 años');
        $respuesta->assertSee('05/10/2026');
        // Las dos mitades; la dirección sólo en la de Rp.
        $respuesta->assertSee('Rp.');
        $respuesta->assertSee('Indicaciones:');
        $this->assertSame(1, substr_count($respuesta->getContent(), 'Av. Bolívar, edificio Sol, Barquisimeto'));
        // Los medicamentos en mayúsculas con su presentación, la cantidad y la indicación.
        $respuesta->assertSee('KEFORAL / CAP. 500 MG');
        $respuesta->assertSee('ÁCIDO FÓLICO / TABLETAS 5 MG');
        $respuesta->assertSee('Una cápsula cada 6 horas.');
        // La firma con el nombre del médico y el pie.
        $respuesta->assertSee('firma-nombre', false);
        $respuesta->assertSee('Av. Principal, Torre Salud, piso 3');
        $respuesta->assertSee('0251-5551234');
        $respuesta->assertSee('consultorio@example.com');
        // El lugar del QR ya no está vacío (WEB-5.6): trae el QR de verificación (el detalle, en RecipeVerificacionQrTest).
        $respuesta->assertDontSee('recipe-qr-reservado', false);
        $respuesta->assertSee('recipe-qr-codigo', false);
    }

    public function test_es_una_pagina_de_impresion_aparte_con_la_barra_que_no_se_imprime(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlRecipe($consulta));

        // Sin el armazón de la web, con su hoja de estilos de la impresión y su propio script (R6: imprime el navegador).
        $respuesta->assertDontSee('app-sidebar', false);
        $respuesta->assertSee('css/clinica-recipe.css', false);
        $respuesta->assertSee('js/clinica/imprimir-recipe.js', false);
        $respuesta->assertSee('data-imprimir', false);
        $respuesta->assertSee('barra-impresion', false);
        // Nada de estilos ni de JS metidos en la vista.
        $this->assertStringNotContainsString('style="', $respuesta->getContent());
        $this->assertStringNotContainsString('onclick=', $respuesta->getContent());
        $this->assertStringNotContainsString('<style', $respuesta->getContent());
    }

    public function test_el_modo_solo_indicaciones_imprime_una_mitad_y_el_nombre_generico_cambia_los_medicamentos(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $this->membrete($medico, [], null);
        DB::table('recipe_formatos')->insert([
            'reg_medico' => $medico->reg_medico, 'modo_impresion' => 'solo_indicaciones', 'nombre_medicamento' => 'generico',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlRecipe($consulta));

        $respuesta->assertOk();
        $respuesta->assertSee('Indicaciones:');
        $respuesta->assertDontSee('recipe-mitad-rp', false);
        $respuesta->assertSee('recipe-mitad-vacia', false);
        // El genérico, y la dirección (sólo de la mitad Rp.) no sale.
        $respuesta->assertSee('CEFALEXINA / CAP. 500 MG');
        $respuesta->assertDontSee('KEFORAL / CAP. 500 MG');
        $respuesta->assertDontSee('Av. Bolívar, edificio Sol');
    }

    public function test_sin_fila_de_formato_se_imprime_con_los_defaults_y_no_se_crea_la_fila(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $this->assertSame(0, DB::table('recipe_formatos')->where('reg_medico', $medico->reg_medico)->count());

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlRecipe($consulta));

        $respuesta->assertOk();
        // Por defecto: Rp. + Indicaciones y el nombre comercial; el membrete con el aspecto del legado.
        $respuesta->assertSee('recipe-mitad-rp', false);
        $respuesta->assertSee('recipe-mitad-indicaciones', false);
        $respuesta->assertSee('KEFORAL / CAP. 500 MG');
        $respuesta->assertSee('linea-negro', false);
        $respuesta->assertSee('fuente-arimo', false);
        $respuesta->assertSee('Rp. + Indicaciones · nombre comercial');
        // Mirar no crea nada.
        $this->assertSame(0, DB::table('recipe_formatos')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_el_formato_del_medico_gobierna_el_membrete(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/logo.png', 'png');
        Storage::disk('public')->put('recipe/firmas/firma.png', 'png');
        Storage::disk('public')->put('recipe/sellos/sello.png', 'png');

        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $this->membrete($medico, ['logo' => 'logos/logo.png'], null);
        DB::table('recipe_formatos')->insert([
            'reg_medico'     => $medico->reg_medico,
            'elementos'      => json_encode(['logo' => ['alineacion' => 'derecha', 'tamano' => 'grande'], 'medico' => ['alineacion' => 'izquierda', 'fuente' => 'tinos', 'negrita' => true]]),
            'color_linea'    => 'azul',
            'firma'          => 'recipe/firmas/firma.png',
            'sello'          => 'recipe/sellos/sello.png',
            'sello_posicion' => 'izquierda',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlRecipe($consulta));

        $respuesta->assertOk();
        $respuesta->assertSee('logo-derecha', false);
        $respuesta->assertSee('logo-grande', false);
        $respuesta->assertSee('fuente-tinos', false);
        $respuesta->assertSee('linea-azul', false);
        $respuesta->assertSee('sello-izquierda', false);
        $respuesta->assertSee('/storage/logos/logo.png', false);
        $respuesta->assertSee('/storage/recipe/firmas/firma.png', false);
        $respuesta->assertSee('/storage/recipe/sellos/sello.png', false);
    }

    public function test_el_logo_la_firma_y_el_sello_solo_se_pintan_si_el_archivo_existe(): void
    {
        Storage::fake('public');

        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $this->membrete($medico, ['logo' => 'logos/borrado.png'], null);
        DB::table('recipe_formatos')->insert([
            'reg_medico' => $medico->reg_medico, 'firma' => 'recipe/firmas/borrada.png', 'sello' => 'recipe/sellos/borrado.png',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlRecipe($consulta));

        $respuesta->assertOk();
        $respuesta->assertDontSee('/storage/', false);
        $respuesta->assertDontSee('membrete-logo', false);
        $respuesta->assertDontSee('firma-imagen', false);
        $respuesta->assertDontSee('class="sello', false);
        $respuesta->assertSee('sin-logo', false);
    }

    public function test_el_membrete_del_medico_se_busca_bajo_su_registro_principal(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        // El formato y los datos viven bajo el registro **principal**: el que el API del móvil escribe.
        $this->membrete($medico, ['especialidad' => 'Medicina Materno Fetal'], ['color_linea' => 'rojo']);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlRecipe($consulta));

        $respuesta->assertSee('Medicina Materno Fetal');
        $respuesta->assertSee('linea-rojo', false);
    }

    // ----------------------------------------------------------------------------------- La próxima cita

    public function test_la_proxima_cita_es_la_primera_posterior_a_hoy_y_no_la_de_hoy(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $this->cita($medico, 1001, '2026-10-07', ['atendido' => 1]);          // hoy: la que se atiende, no cuenta
        $this->cita($medico, 1001, '2026-10-20', ['hora_ini' => '15:00:00']); // la segunda
        $this->cita($medico, 1001, '2026-10-14');                              // la primera de las futuras

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlRecipe($consulta));

        $respuesta->assertSee('Próxima cita: 14/10/2026');
        $respuesta->assertDontSee('20/10/2026');
        $respuesta->assertDontSee('Próxima cita: 07/10/2026');
        // En las dos mitades.
        $this->assertSame(2, substr_count($respuesta->getContent(), 'Próxima cita: 14/10/2026'));
    }

    public function test_sin_cita_futura_la_hoja_sale_sin_la_linea_de_proxima_cita(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $this->cita($medico, 1001, '2026-10-07', ['atendido' => 1]);
        $this->cita($medico, 1001, '2026-09-01');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlRecipe($consulta));

        $respuesta->assertOk();
        $respuesta->assertDontSee('Próxima cita');
    }

    public function test_la_proxima_cita_es_solo_de_ese_medico_y_no_cuenta_la_que_el_escritorio_movio(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();

        // Otro médico del mismo consultorio con una cita del mismo número de historia, y una cita movida.
        $this->cita($medico, 1001, '2026-10-10', ['reg_medico' => 'otro-medico-' . substr(uniqid(), -6)]);
        $this->cita($medico, 1001, '2026-10-11', ['movida_escritorio' => 1]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlRecipe($consulta));

        $respuesta->assertDontSee('Próxima cita');
    }

    public function test_la_hora_de_la_proxima_cita_solo_sale_si_la_sede_agenda_por_hora(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $porHora = $this->sede($medico, 'hora_cita');
        $porOrden = $this->sede($medico, 'orden_llegada');

        $this->cita($medico, 1001, '2026-10-14', ['medical_center_id' => $porHora->medical_center_id, 'hora_ini' => '10:30:00']);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlRecipe($consulta))
            ->assertSee('Próxima cita: 14/10/2026  10:30');

        // La misma cita en una sede por orden de llegada: la hora de la fila es la del bloque y no se promete.
        Cola::where('reg_medico', $medico->reg_medico)->update(['medical_center_id' => $porOrden->medical_center_id]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlRecipe($consulta));
        $respuesta->assertSee('Próxima cita: 14/10/2026');
        $respuesta->assertDontSee('10:30');
    }

    // ------------------------------------------------------------------------------------ La segunda hoja

    public function test_doce_medicamentos_salen_en_dos_hojas_y_cada_hoja_repite_el_membrete_y_la_firma(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();

        $codigos = [];
        for ($i = 1; $i <= 12; $i++) {
            $codigo = sprintf('9%03d', $i);
            $this->medicamento($medico, $codigo, 'MEDICAMENTO ' . $i, 'GENERICO ' . $i, 'Tabletas', 'Una tableta cada ' . $i . ' horas.');
            $codigos[] = [$codigo, $i, 'Una tableta cada ' . $i . ' horas.'];
        }
        $this->recipe($medico, 1001, 1, 2, $codigos, '2026-10-05');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlRecipe($consulta, 2));

        $respuesta->assertOk();
        $this->assertSame(2, $this->hojasDe($respuesta->getContent()));
        $respuesta->assertSee('Hoja 1 de 2');
        $respuesta->assertSee('Hoja 2 de 2');
        $respuesta->assertSee('Un récipe · 12 medicamentos · 2 hojas');
        // Cada medicamento una sola vez en cada mitad, y ninguno partido: los 12 están.
        for ($i = 1; $i <= 12; $i++) {
            $this->assertSame(2, substr_count($respuesta->getContent(), 'MEDICAMENTO ' . $i . ' / TABLETAS'), "El medicamento $i");
        }
        // Cuatro mitades con membrete, firma y pie: dos hojas de dos mitades.
        $this->assertSame(4, substr_count($respuesta->getContent(), 'class="membrete '));
        $this->assertSame(4, substr_count($respuesta->getContent(), 'class="firma"'));
    }

    // --------------------------------------------------------------------------- El récipe de la pareja

    public function test_el_recipe_de_la_pareja_se_imprime_con_los_datos_de_la_pareja_y_no_los_del_paciente(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $this->pareja($medico, 1001, 1, ['0002', '0001'], '9876543', 'Mariana Rojas');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlPareja($consulta));

        $respuesta->assertOk();
        // La pareja en el lugar del paciente.
        $respuesta->assertSee('Mariana Rojas');
        $respuesta->assertSee('9876543');
        // Sin el nombre ni la edad ni la dirección del paciente principal.
        $respuesta->assertDontSee('Ana Alvarez');
        $respuesta->assertDontSee('V-14555666');
        $respuesta->assertDontSee('EDAD:');
        $respuesta->assertDontSee('DIRECCIÓN:');
        $respuesta->assertDontSee('Av. Bolívar');
        // Sus medicamentos, no los del récipe del paciente.
        $respuesta->assertSee('DIFLUCAN G / CAP. 150 MG');
        $respuesta->assertDontSee('ÁCIDO FÓLICO');
        // La fecha es la de la consulta (la pareja no tiene cabecera).
        $respuesta->assertSee('07/10/2026');
        $respuesta->assertSee('Récipe de la pareja · Mariana Rojas');
    }

    public function test_el_recipe_de_la_pareja_usa_las_mismas_opciones_del_medico(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $this->pareja($medico, 1001, 1, ['0002']);
        DB::table('recipe_formatos')->insert([
            'reg_medico' => $medico->reg_medico, 'modo_impresion' => 'solo_indicaciones', 'nombre_medicamento' => 'generico',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlPareja($consulta));

        $respuesta->assertSee('FLUCONAZOL / CAP. 150 MG');
        $respuesta->assertDontSee('recipe-mitad-rp', false);
    }

    public function test_sin_recipe_de_la_pareja_da_404(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlPareja($consulta))->assertNotFound();
    }

    // -------------------------------------------------------------------------------------- Quién entra

    public function test_un_recipe_que_no_existe_o_no_tiene_medicamentos_da_404(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        // Una cabecera sin filas es un récipe vacío: no hay qué imprimir.
        DB::table('recipe_detalle')->insert([
            'reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'recipe' => 3,
            'fe_emision' => '2026-10-05', 'fe_vence' => 30, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlRecipe($consulta, 9))->assertNotFound();
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlRecipe($consulta, 3))->assertNotFound();
    }

    public function test_la_consulta_de_otro_medico_da_404_y_la_secretaria_recibe_403(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        [$otroUser, $otroMedico, $otraGineco] = $this->medicoConAcceso();

        // Otro médico con su propio contexto no puede imprimir el récipe de esta consulta.
        $this->conContexto($otroUser, $otroMedico, $otraGineco)->actingAs($otroUser)->get($this->urlRecipe($consulta))->assertNotFound();
        $this->conContexto($otroUser, $otroMedico, $otraGineco)->actingAs($otroUser)->get($this->urlPareja($consulta))->assertNotFound();

        // La secretaría no entra por la consulta: ni a la consulta ni a su hoja impresa.
        $secretaria = $this->secretariaConAcceso($medico);
        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)->get($this->urlRecipe($consulta))->assertForbidden();
        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)->get($this->urlPareja($consulta))->assertForbidden();
        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)->get(route('clinica.consulta', $consulta->id))->assertForbidden();
    }

    public function test_un_invitado_va_al_login(): void
    {
        [, , , , $consulta] = $this->escenario();

        $this->get($this->urlRecipe($consulta))->assertRedirect(route('login'));
        $this->get($this->urlPareja($consulta))->assertRedirect(route('login'));
        $this->post(route('clinica.recipes.opciones'), [])->assertRedirect(route('login'));
    }

    // ----------------------------------------------------------------------------- Las opciones guardadas

    private function opciones(User $user, $medico, $gineco, array $datos)
    {
        return $this->conContexto($user, $medico, $gineco)->actingAs($user)->post(route('clinica.recipes.opciones'), $datos);
    }

    public function test_guardar_las_opciones_crea_la_fila_del_medico_y_sigue_a_la_vista_previa(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $siguiente = '/clinica/consulta/' . $consulta->id . '/recipes/1/imprimir';

        $this->opciones($user, $medico, $gineco, ['modo' => 'solo_indicaciones', 'nombre' => 'generico', 'siguiente' => $siguiente])
            ->assertRedirect($siguiente);

        $fila = RecipeFormato::where('reg_medico', $medico->reg_medico)->first();
        $this->assertNotNull($fila);
        $this->assertSame('solo_indicaciones', $fila->modo_impresion);
        $this->assertSame('generico', $fila->nombre_medicamento);

        // Y la siguiente impresión —de quien sea— sale así.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($siguiente)
            ->assertSee('CEFALEXINA / CAP. 500 MG')
            ->assertSee('Solo indicaciones · nombre genérico');
    }

    public function test_la_elegida_por_la_asistente_la_ve_el_medico_y_no_mueve_el_updated_at_de_una_fila_existente(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $this->membrete($medico, [], ['color_linea' => 'verde', 'firma' => 'recipe/firmas/f.png']);
        $siguiente = '/clinica/consulta/' . $consulta->id . '/recipes/1/imprimir';

        $secretaria = $this->secretariaConAcceso($medico);
        $this->opciones($secretaria, $medico, $gineco, ['modo' => 'solo_indicaciones', 'nombre' => 'generico', 'siguiente' => $siguiente])
            ->assertRedirect($siguiente);

        $fila = RecipeFormato::where('reg_medico', $medico->reg_medico)->first();
        $this->assertSame('solo_indicaciones', $fila->modo_impresion);
        // Lo que el móvil lee de la fila no se movió: ni la versión de sus imágenes ni lo demás.
        $this->assertSame('2026-09-01 00:00:00', $fila->getRawOriginal('updated_at'));
        $this->assertSame('verde', $fila->color_linea);
        $this->assertSame('recipe/firmas/f.png', $fila->firma);
        $this->assertSame(1, RecipeFormato::where('reg_medico', $medico->reg_medico)->count());

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($siguiente)->assertSee('Solo indicaciones · nombre genérico');
    }

    public function test_el_siguiente_puede_ser_la_url_absoluta_de_este_mismo_sitio_y_no_la_de_otro(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $ruta = '/clinica/consulta/' . $consulta->id . '/recipes/1/imprimir';

        // `route()` arma URLs absolutas: así llega desde los botones.
        $this->opciones($user, $medico, $gineco, ['modo' => 'solo_indicaciones', 'nombre' => 'comercial', 'siguiente' => url($ruta)])
            ->assertRedirect($ruta)
            ->assertSessionHasNoErrors();

        $this->opciones($user, $medico, $gineco, ['modo' => 'rp_indicaciones', 'nombre' => 'comercial', 'siguiente' => 'http://malo.example.com' . $ruta])
            ->assertSessionHasErrors('siguiente');
        $this->opciones($user, $medico, $gineco, ['modo' => 'rp_indicaciones', 'nombre' => 'comercial', 'siguiente' => 'http://usuario:clave@' . request()->getHost() . '.malo.com' . $ruta])
            ->assertSessionHasErrors('siguiente');

        $this->assertSame('solo_indicaciones', RecipeFormato::where('reg_medico', $medico->reg_medico)->value('modo_impresion'));
    }

    public function test_las_opciones_son_validadas_y_el_siguiente_solo_puede_ser_una_vista_previa(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $bueno = '/clinica/consulta/' . $consulta->id . '/recipes/1/imprimir';

        foreach ([
            ['modo' => 'apaisado', 'nombre' => 'comercial', 'siguiente' => $bueno],
            ['modo' => 'rp_indicaciones', 'nombre' => 'raro', 'siguiente' => $bueno],
            ['modo' => '', 'nombre' => 'comercial', 'siguiente' => $bueno],
            ['modo' => 'rp_indicaciones', 'nombre' => 'comercial', 'siguiente' => 'https://malo.example.com/clinica/x/imprimir'],
            ['modo' => 'rp_indicaciones', 'nombre' => 'comercial', 'siguiente' => '//malo.example.com/clinica/x/imprimir'],
            ['modo' => 'rp_indicaciones', 'nombre' => 'comercial', 'siguiente' => '/clinica/pacientes'],
            ['modo' => 'rp_indicaciones', 'nombre' => 'comercial', 'siguiente' => '/clinica/consulta/1/../../admin/imprimir x'],
            ['modo' => 'rp_indicaciones', 'nombre' => 'comercial'],
        ] as $datos) {
            $this->opciones($user, $medico, $gineco, $datos)->assertSessionHasErrors();
        }

        $this->assertSame(0, RecipeFormato::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_los_avisos_de_las_opciones_estan_en_espanol(): void
    {
        [$user, $medico, $gineco] = $this->escenario();

        $respuesta = $this->opciones($user, $medico, $gineco, []);
        $errores = collect(session('errors')->all());

        $this->assertNotEmpty($errores);
        foreach ($errores as $error) {
            $this->assertDoesNotMatchRegularExpression('/\b(field|required|must|invalid)\b/i', $error, $error);
        }
    }

    // -------------------------------------------------------------------- La sección y la historia

    public function test_la_seccion_de_recipes_ofrece_imprimir_cada_recipe_y_la_proxima_cita_con_hacer_cita(): void
    {
        [$user, $medico, $gineco, $pacienteId, $consulta] = $this->escenario();
        $this->recipe($medico, 1001, 1, 2, ['0004'], '2026-10-05');
        DB::table('recipe_detalle')->insert([
            'reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'recipe' => 3,
            'fe_emision' => '2026-10-05', 'fe_vence' => 30, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->cita($medico, 1001, '2026-10-14');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta', [$consulta->id, 'seccion' => 'recipes', 'lista' => 1]));

        $respuesta->assertOk();
        // Un botón de imprimir por récipe con medicamentos (el 3, vacío, no lo tiene).
        $respuesta->assertSee('data-siguiente="' . $this->urlRecipe($consulta, 1) . '"', false);
        $respuesta->assertSee('data-siguiente="' . $this->urlRecipe($consulta, 2) . '"', false);
        $respuesta->assertDontSee('data-siguiente="' . $this->urlRecipe($consulta, 3) . '"', false);
        // El diálogo con las opciones y la elegida marcada, y su script.
        $respuesta->assertSee('id="dialogo-imprimir-recipe"', false);
        $this->assertMatchesRegularExpression('/name="modo" value="rp_indicaciones"\s+checked/', $respuesta->getContent());
        $this->assertMatchesRegularExpression('/name="nombre" value="comercial"\s+checked/', $respuesta->getContent());
        $respuesta->assertSee('js/clinica/imprimir-recipe.js', false);
        // Lo que sale al pie de la hoja y el atajo para hacer la cita, con el paciente elegido y el retorno.
        $respuesta->assertSee('Próxima cita: 14/10/2026');
        $respuesta->assertSee('Hacer cita');
        $respuesta->assertSee('paciente_id=' . $pacienteId, false);
        $respuesta->assertSee('volver_consulta=' . $consulta->id, false);
    }

    public function test_sin_proxima_cita_la_seccion_lo_dice_y_ofrece_hacer_la_cita(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta', [$consulta->id, 'seccion' => 'recipes', 'lista' => 1]));

        $respuesta->assertSee('no tiene próxima cita agendada');
        $respuesta->assertSee('Hacer cita');
    }

    public function test_la_pestana_de_la_pareja_ofrece_imprimir_solo_si_ya_hay_recipe_de_la_pareja(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $url = route('clinica.consulta', [$consulta->id, 'seccion' => 'recipes', 'recipe' => 'pareja']);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($url)
            ->assertDontSee('data-siguiente="' . $this->urlPareja($consulta) . '"', false);

        $this->pareja($medico, 1001, 1, ['0002']);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($url)
            ->assertSee('data-siguiente="' . $this->urlPareja($consulta) . '"', false)
            ->assertSee('Hacer cita');
    }

    public function test_la_historia_del_paciente_enlaza_la_hoja_impresa_de_cada_recipe_para_el_medico(): void
    {
        [$user, $medico, $gineco, $pacienteId, $consulta] = $this->escenario();
        $this->recipe($medico, 1001, 1, 2, ['0004'], '2026-10-05');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get(route('clinica.pacientes.historia', $pacienteId));

        $respuesta->assertOk();
        $respuesta->assertSee('href="' . $this->urlRecipe($consulta, 1) . '"', false);
        $respuesta->assertSee('href="' . $this->urlRecipe($consulta, 2) . '"', false);
    }

    public function test_la_historia_no_enlaza_la_hoja_a_la_secretaria_que_no_puede_abrirla(): void
    {
        [, $medico, $gineco, $pacienteId, $consulta] = $this->escenario();
        $secretaria = $this->secretariaConAcceso($medico);

        $respuesta = $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)->get(route('clinica.pacientes.historia', $pacienteId));

        $respuesta->assertOk();
        $respuesta->assertDontSee($this->urlRecipe($consulta, 1), false);
    }
}
