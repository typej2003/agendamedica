<?php

namespace Tests\Feature;

use App\Actions\Recipes\ObtenerVerificacionDeRecipe;
use App\Clinica\Verificacion\GeneradorDeQr;
use App\Clinica\Verificacion\UrlDeVerificacion;
use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Medico;
use App\Models\RecipeVerificacion;
use App\Models\Specialty;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **El QR de verificación del récipe** (WEB-5.6): lo que imprime la hoja y la página pública a la que lleva.
 *
 * Lo que estos casos fijan:
 *
 *  - **El token** se crea al imprimir, es **idempotente** (reimprimir da el mismo QR, aunque el récipe se edite), aguanta dos
 *    impresiones simultáneas y no se loguea ni entra en `sync_changes`.
 *  - **La hoja** trae el QR en las dos mitades —del médico, de la pareja y de la secretaría— con la dirección y el código; la
 *    hoja de prueba trae uno de **ejemplo**.
 *  - **La página pública** (sin login ni cookies, `noindex`, con límite de peticiones) en cada estado: vigente, vencido, anulado,
 *    eliminado y no encontrado; refleja las ediciones; **nunca** muestra al paciente ni las indicaciones; y un récipe de otro
 *    médico no se mezcla.
 *  - **Anular y reactivar** (sólo el médico del contexto).
 */
class RecipeVerificacionQrWebTest extends TestCase
{
    use DatabaseTransactions;
    use PreparaRecipesParaImprimir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fijarHoy();
        config(['clinica.verificacion.url' => 'https://doctorisimo.example']);
    }

    protected function tearDown(): void
    {
        RecipeVerificacion::flushEventListeners();
        $this->soltarHoy();
        parent::tearDown();
    }

    /** @return array{0:User, 1:Medico, 2:Specialty, 3:int, 4:Consulta} el médico con un récipe 1 (2 medicamentos) de hoy */
    private function escenario(): array
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        $this->membrete($medico);
        [$pacienteId, $consulta] = $this->pacienteYConsulta($medico, 1001, 1, ['fnacimiento' => '1990-10-06']);
        $this->recipe($medico, 1001, 1, 1, [['0001', 2, 'Una cápsula cada 6 horas.'], ['0003', 1, 'Una al día.']], '2026-10-05');

        return [$user, $medico, $gineco, $pacienteId, $consulta];
    }

    private function como(User $user, Medico $medico, Specialty $gineco): self
    {
        return $this->conContexto($user, $medico, $gineco)->actingAs($user);
    }

    /** Imprime el récipe 1 como el médico y devuelve su verificación (creada o la que ya había). */
    private function imprimir(User $user, Medico $medico, Specialty $gineco, Consulta $consulta, int $numero = 1): RecipeVerificacion
    {
        $this->como($user, $medico, $gineco)->get($this->urlRecipe($consulta, $numero))->assertOk();

        return RecipeVerificacion::where('reg_medico', $medico->reg_medico)->where('nroconsulta', $consulta->nroconsulta)
            ->where('tipo', 'paciente')->where('recipe', $numero)->firstOrFail();
    }

    private function publica(RecipeVerificacion $verificacion)
    {
        return $this->get('/verificar/' . $verificacion->token);
    }

    // ============================================================================================ El token

    public function test_imprimir_crea_la_verificacion_y_reimprimir_da_el_mismo_qr(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();

        $this->assertSame(0, RecipeVerificacion::count());

        $primera = $this->imprimir($user, $medico, $gineco, $consulta);
        $segunda = $this->imprimir($user, $medico, $gineco, $consulta);

        $this->assertSame(1, RecipeVerificacion::where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame($primera->token, $segunda->token);
        $this->assertSame($primera->codigo, $segunda->codigo);
        $this->assertSame(43, strlen($primera->token));
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $primera->token);
        $this->assertMatchesRegularExpression('/\A[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}\z/', $primera->codigo);
        $this->assertSame($medico->id, $primera->medico_id);
        $this->assertNull($primera->revocado_en);
    }

    public function test_el_token_sobrevive_a_las_ediciones_del_recipe(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();

        $antes = $this->imprimir($user, $medico, $gineco, $consulta);

        // El médico corrige el récipe: cambia la cantidad y quita un medicamento.
        DB::table('recipes')->where('reg_medico', $medico->reg_medico)->where('codemedicina', '0001')->update(['cantidad' => 5]);
        DB::table('recipes')->where('reg_medico', $medico->reg_medico)->where('codemedicina', '0003')->delete();

        $despues = $this->imprimir($user, $medico, $gineco, $consulta);

        $this->assertSame($antes->token, $despues->token);
        $this->assertSame(1, RecipeVerificacion::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_cada_recipe_tiene_su_propio_token_y_la_pareja_el_suyo(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $this->recipe($medico, 1001, 1, 2, ['0004'], '2026-10-05');
        $this->pareja($medico, 1001, 1, ['0002']);

        $uno = $this->imprimir($user, $medico, $gineco, $consulta, 1);
        $dos = $this->imprimir($user, $medico, $gineco, $consulta, 2);
        $this->como($user, $medico, $gineco)->get($this->urlPareja($consulta))->assertOk();
        $pareja = RecipeVerificacion::where('reg_medico', $medico->reg_medico)->where('tipo', 'pareja')->firstOrFail();

        $this->assertCount(3, array_unique([$uno->token, $dos->token, $pareja->token]));
        $this->assertCount(3, array_unique([$uno->codigo, $dos->codigo, $pareja->codigo]));
        $this->assertSame(1, (int) $pareja->recipe);
    }

    public function test_dos_impresiones_a_la_vez_terminan_con_una_sola_fila(): void
    {
        [, $medico] = $this->escenario();
        $rival = null;

        // Entre el «no hay» de la primera y su inserción, la otra impresión ya insertó la suya (la carrera).
        RecipeVerificacion::creating(function () use (&$rival, $medico) {
            if ($rival === null) {
                $rival = str_repeat('R', 43);
                DB::table('recipe_verificaciones')->insert([
                    'token' => $rival, 'codigo' => 'RIVAL222', 'reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001,
                    'nroconsulta' => 1, 'tipo' => 'paciente', 'recipe' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $resultado = app(ObtenerVerificacionDeRecipe::class)->ejecutar($medico->reg_medico, 1001, 1, 'paciente', 1, $medico->id);

        $this->assertSame($rival, $resultado->token, 'Gana la fila de la impresión que llegó primero y nadie ve un error.');
        $this->assertSame(1, RecipeVerificacion::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_el_indice_unico_impide_repetir_el_recipe_el_token_o_el_codigo(): void
    {
        [, $medico] = $this->escenario();
        $fila = fn (array $cambios) => array_merge([
            'token' => str_repeat('a', 43), 'codigo' => 'AAAAAAAA', 'reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001,
            'nroconsulta' => 1, 'tipo' => 'paciente', 'recipe' => 1, 'created_at' => now(), 'updated_at' => now(),
        ], $cambios);

        DB::table('recipe_verificaciones')->insert($fila([]));

        foreach ([
            'el mismo récipe' => ['token' => str_repeat('b', 43), 'codigo' => 'BBBBBBBB'],
            'el mismo token' => ['codigo' => 'CCCCCCCC', 'recipe' => 2],
            'el mismo código' => ['token' => str_repeat('d', 43), 'recipe' => 3],
        ] as $caso => $cambios) {
            try {
                DB::table('recipe_verificaciones')->insert($fila($cambios));
                $this->fail('El índice único debía rechazar ' . $caso . '.');
            } catch (QueryException $excepcion) {
                $this->assertMatchesRegularExpression('/unique|duplicate/i', $excepcion->getMessage(), $caso);
            }
        }
    }

    public function test_el_token_no_se_loguea_ni_entra_en_sync_changes(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $this->pareja($medico, 1001, 1, ['0002']);

        $log = storage_path('logs/laravel.log');
        $tamano = is_file($log) ? filesize($log) : 0;
        $cambios = DB::table('sync_changes')->count();

        $uno = $this->imprimir($user, $medico, $gineco, $consulta);
        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.recipes.verificacion.anular', [$consulta->id, 1]))->assertRedirect();
        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.recipes.verificacion.reactivar', [$consulta->id, 1]))->assertRedirect();
        $this->publica($uno)->assertOk();

        $this->assertSame($cambios, DB::table('sync_changes')->count(), 'La verificación no entra en el sync.');
        $this->assertArrayNotHasKey('recipe_verificaciones', config('sync_legado.tablas'));

        clearstatcache();
        $nuevo = is_file($log) && filesize($log) > $tamano ? substr((string) file_get_contents($log), $tamano) : '';
        $this->assertStringNotContainsString($uno->token, $nuevo);
        $this->assertStringNotContainsString($uno->codigo, $nuevo);
    }

    public function test_el_token_no_viaja_en_un_json_del_modelo(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $verificacion = $this->imprimir($user, $medico, $gineco, $consulta);

        $this->assertArrayNotHasKey('token', $verificacion->toArray());
    }

    // ============================================================================================ La hoja

    public function test_la_hoja_del_medico_trae_el_qr_en_las_dos_mitades_con_la_direccion_y_el_codigo(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();

        $respuesta = $this->como($user, $medico, $gineco)->get($this->urlRecipe($consulta));
        $verificacion = RecipeVerificacion::where('reg_medico', $medico->reg_medico)->firstOrFail();
        $html = $respuesta->getContent();

        $this->assertSame(2, substr_count($html, 'class="recipe-qr-codigo"'), 'Una mitad Rp. y otra de Indicaciones, cada una con su QR.');
        $this->assertSame(2, substr_count($html, 'Código <strong class="recipe-qr-numero">' . substr($verificacion->codigo, 0, 4) . '-' . substr($verificacion->codigo, 4) . '</strong>'));
        $this->assertSame(2, substr_count($html, 'Verifique en <strong>doctorisimo.example/verificar</strong>'));
        $this->assertStringNotContainsString('recipe-qr-reservado', $html);

        // El QR es el de esta dirección: el mismo dibujo que genera el servidor para ella, dos veces.
        $svg = (new GeneradorDeQr())->svg('https://doctorisimo.example/verificar/' . $verificacion->token);
        $this->assertSame(2, substr_count($html, $svg));
        // Y el token no está escrito en claro en la hoja (sólo dentro del dibujo).
        $this->assertStringNotContainsString($verificacion->token, $html);
    }

    public function test_el_modo_solo_indicaciones_lleva_el_qr_en_su_unica_mitad(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $this->membrete($medico, [], null);
        DB::table('recipe_formatos')->insert([
            'reg_medico' => $medico->reg_medico, 'modo_impresion' => 'solo_indicaciones', 'nombre_medicamento' => 'comercial',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->como($user, $medico, $gineco)->get($this->urlRecipe($consulta))->getContent();

        $this->assertSame(1, substr_count($html, 'class="recipe-qr-codigo"'));
    }

    public function test_la_segunda_hoja_de_un_recipe_largo_lleva_el_mismo_qr(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        foreach (range(1, 12) as $n) {
            $this->medicamento($medico, 'L' . str_pad((string) $n, 3, '0', STR_PAD_LEFT), 'LARGO ' . $n, null, 'Tab ' . $n);
        }
        $this->recipe($medico, 1001, 1, 2, array_map(fn (int $n) => ['L' . str_pad((string) $n, 3, '0', STR_PAD_LEFT), 1, 'Una al día.'], range(1, 12)), '2026-10-05');

        $respuesta = $this->como($user, $medico, $gineco)->get($this->urlRecipe($consulta, 2))->assertOk();
        $html = $respuesta->getContent();

        $this->assertSame(2, $this->hojasDe($html));
        $this->assertSame(4, substr_count($html, 'class="recipe-qr-codigo"'), 'Cada mitad de cada hoja lleva el QR.');
        $verificacion = RecipeVerificacion::where('reg_medico', $medico->reg_medico)->where('recipe', 2)->firstOrFail();
        $this->assertSame(4, substr_count($html, (new GeneradorDeQr())->svg(UrlDeVerificacion::deToken($verificacion->token))));
    }

    public function test_el_recipe_de_la_pareja_lleva_su_qr_y_la_hoja_no_nombra_a_la_pareja_en_el_qr(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $this->pareja($medico, 1001, 1, ['0002']);

        $html = $this->como($user, $medico, $gineco)->get($this->urlPareja($consulta))->assertOk()->getContent();
        $verificacion = RecipeVerificacion::where('reg_medico', $medico->reg_medico)->where('tipo', 'pareja')->firstOrFail();

        $this->assertSame(2, substr_count($html, 'class="recipe-qr-codigo"'));
        $this->assertSame(2, substr_count($html, (new GeneradorDeQr())->svg(UrlDeVerificacion::deToken($verificacion->token))));
    }

    public function test_la_secretaria_imprime_la_misma_hoja_con_el_mismo_qr(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $secretaria = $this->secretariaConAcceso($medico);
        $this->cita($medico, 1001, '2026-10-07', ['atendido' => 1, 'estado' => 1]);
        $cita = Cola::where('reg_medico', $medico->reg_medico)->firstOrFail();

        $delMedico = $this->imprimir($user, $medico, $gineco, $consulta);

        $html = $this->como($secretaria, $medico, $gineco)
            ->get(route('clinica.agenda.documentos.imprimir', [$cita->id, 'documento' => 'recipe-1']))
            ->assertOk()->getContent();

        $this->assertSame(1, RecipeVerificacion::where('reg_medico', $medico->reg_medico)->count(), 'No se crea otro: es el mismo récipe.');
        $this->assertSame(2, substr_count($html, 'class="recipe-qr-codigo"'));
        $this->assertSame(2, substr_count($html, (new GeneradorDeQr())->svg(UrlDeVerificacion::deToken($delMedico->token))));
    }

    public function test_la_secretaria_que_imprime_primero_crea_la_verificacion_del_medico(): void
    {
        [, $medico, $gineco] = $this->escenario();
        $secretaria = $this->secretariaConAcceso($medico);
        $this->cita($medico, 1001, '2026-10-07', ['atendido' => 1, 'estado' => 1]);
        $cita = Cola::where('reg_medico', $medico->reg_medico)->firstOrFail();

        $this->como($secretaria, $medico, $gineco)
            ->get(route('clinica.agenda.documentos.imprimir', [$cita->id, 'documento' => 'recipe-1']))->assertOk();

        $verificacion = RecipeVerificacion::where('reg_medico', $medico->reg_medico)->firstOrFail();
        $this->assertSame($medico->id, $verificacion->medico_id, 'El médico del contexto es quien avala el récipe.');
        $this->publica($verificacion)->assertOk()->assertSee('Dra. Ana Imprime');
    }

    public function test_la_hoja_de_prueba_trae_un_qr_de_ejemplo_que_no_crea_nada(): void
    {
        [$user, $medico, $gineco] = $this->escenario();

        $respuesta = $this->como($user, $medico, $gineco)->get(route('clinica.formato.vista-previa'))->assertOk();
        $html = $respuesta->getContent();

        $this->assertSame(0, RecipeVerificacion::count());
        $this->assertSame(2, substr_count($html, 'class="recipe-qr-codigo"'));
        $this->assertStringContainsString('recipe-qr-ejemplo', $html);
        $this->assertSame(2, substr_count($html, '<strong class="recipe-qr-numero">EJEMPLO</strong>'));
        $this->assertStringContainsString('no verifica ningún récipe', $html);
        $this->assertSame(2, substr_count($html, (new GeneradorDeQr())->svg('https://doctorisimo.example/verificar/ejemplo')));
    }

    // ================================================================================ La página pública

    public function test_la_pagina_publica_de_un_recipe_vigente_muestra_fecha_medico_y_medicamentos(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $medico->forceFill(['license_number' => 'MPPS 4455'])->save();
        $verificacion = $this->imprimir($user, $medico, $gineco, $consulta);

        $respuesta = $this->publica($verificacion)->assertOk();

        $respuesta->assertSee('Récipe auténtico y vigente');
        $respuesta->assertSee('data-estado="vigente"', false);
        $respuesta->assertSee('05/10/2026');           // emisión
        $respuesta->assertSee('30 días');
        $respuesta->assertSee('04/11/2026');           // vence
        $respuesta->assertSee('Récipe 1');
        $respuesta->assertSee('Dra. Ana Imprime');
        $respuesta->assertSee('Ginecología y Obstetricia');
        $respuesta->assertSee('J-12345678-9');
        $respuesta->assertSee('MPPS 4455');
        $respuesta->assertSee('Av. Principal, Torre Salud, piso 3');
        $respuesta->assertSee('KEFORAL / CAP. 500 MG');
        $respuesta->assertSee('ÁCIDO FÓLICO / TABLETAS 5 MG');
        // Y el contenido es el de la hoja: sin Bootstrap Icons, sin scripts, sin estilos en la página.
        $this->assertStringNotContainsString('<script', $respuesta->getContent());
        $this->assertStringNotContainsString('style="', $respuesta->getContent());
        $this->assertStringNotContainsString('<style', $respuesta->getContent());
    }

    public function test_la_pagina_publica_no_muestra_al_paciente_ni_las_indicaciones(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $this->pareja($medico, 1001, 1, ['0002']);
        $this->como($user, $medico, $gineco)->get($this->urlPareja($consulta))->assertOk();

        foreach (RecipeVerificacion::where('reg_medico', $medico->reg_medico)->get() as $verificacion) {
            $contenido = $this->publica($verificacion)->assertOk()->getContent();

            foreach (['Ana Alvarez', 'Alvarez', '14555666', 'V-14555666', 'Barquisimeto', 'Bolívar', 'años',
                      'Una cápsula cada 6 horas', 'Una al día', 'Indicación de', 'Pareja 0002', 'Mariana', 'Rojas', '9876543'] as $dato) {
                $this->assertStringNotContainsString($dato, $contenido, 'La página pública no puede mostrar «' . $dato . '».');
            }
        }
    }

    public function test_el_recipe_de_la_pareja_se_rotula_sin_identidad(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $this->pareja($medico, 1001, 1, ['0002']);
        $this->como($user, $medico, $gineco)->get($this->urlPareja($consulta))->assertOk();
        $verificacion = RecipeVerificacion::where('reg_medico', $medico->reg_medico)->where('tipo', 'pareja')->firstOrFail();

        $respuesta = $this->publica($verificacion)->assertOk();

        $respuesta->assertSee('Récipe de la pareja');
        $respuesta->assertSee('DIFLUCAN G / CAP. 150 MG');
        $respuesta->assertDontSee('Mariana Rojas');
        $respuesta->assertDontSee('9876543');
        // Sin cabecera, la vigencia es la estándar de 30 días sobre la fecha de la consulta (hoy: 07/10/2026).
        $respuesta->assertSee('07/10/2026');
        $respuesta->assertSee('06/11/2026');
    }

    public function test_siempre_se_ve_el_recipe_vivo_y_una_edicion_se_refleja(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $verificacion = $this->imprimir($user, $medico, $gineco, $consulta);

        $this->publica($verificacion)->assertSee('KEFORAL / CAP. 500 MG')->assertDontSee('LOSARTAN');

        // El médico cambia un medicamento por otro y la cantidad.
        DB::table('recipes')->where('reg_medico', $medico->reg_medico)->where('codemedicina', '0001')
            ->update(['codemedicina' => '0004', 'cantidad' => 7]);

        $respuesta = $this->publica($verificacion)->assertOk();
        $respuesta->assertSee('LOSARTAN / TABLETAS 50 MG');
        $respuesta->assertDontSee('KEFORAL');
        $respuesta->assertSee('<span class="verif-cantidad" title="Cantidad">7</span>', false);
    }

    public function test_el_nombre_del_medicamento_es_el_comercial_o_el_generico_de_la_hoja(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $verificacion = $this->imprimir($user, $medico, $gineco, $consulta);
        $this->membrete($medico, [], null);
        DB::table('recipe_formatos')->insert([
            'reg_medico' => $medico->reg_medico, 'modo_impresion' => 'rp_indicaciones', 'nombre_medicamento' => 'generico',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->publica($verificacion)->assertSee('CEFALEXINA / CAP. 500 MG')->assertDontSee('KEFORAL');
    }

    public function test_un_recipe_pasado_su_vigencia_esta_vencido_y_el_ultimo_dia_aun_es_vigente(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $verificacion = $this->imprimir($user, $medico, $gineco, $consulta);
        $emision = fn (string $fecha) => DB::table('recipe_detalle')->where('reg_medico', $medico->reg_medico)->update(['fe_emision' => $fecha]);

        // Hoy es el 07/10/2026 en el consultorio: emitido el 07/09 vence el 07/10 (último día, vigente) y el 06/09, el 06/10 (vencido).
        $emision('2026-09-07');
        $this->publica($verificacion)->assertSee('Récipe auténtico y vigente')->assertSee('07/10/2026');

        $emision('2026-09-06');
        $this->publica($verificacion)->assertOk()->assertSee('Récipe auténtico pero vencido')->assertSee('data-estado="vencido"', false)
            ->assertSee('06/10/2026')->assertSee('KEFORAL / CAP. 500 MG');

        // Con otra vigencia en la cabecera (15 días).
        DB::table('recipe_detalle')->where('reg_medico', $medico->reg_medico)->update(['fe_emision' => '2026-10-01', 'fe_vence' => 15]);
        $this->publica($verificacion)->assertSee('Récipe auténtico y vigente')->assertSee('15 días')->assertSee('16/10/2026');
    }

    public function test_un_recipe_del_escritorio_sin_cabecera_vale_30_dias_sobre_la_fecha_del_recipe(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        DB::table('recipe_detalle')->where('reg_medico', $medico->reg_medico)->delete();
        $verificacion = $this->imprimir($user, $medico, $gineco, $consulta);

        $this->publica($verificacion)->assertOk()->assertSee('Récipe auténtico y vigente')->assertSee('05/10/2026')->assertSee('04/11/2026');
    }

    public function test_el_recipe_anulado_por_el_medico_lo_dice_y_no_muestra_el_contenido(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $verificacion = $this->imprimir($user, $medico, $gineco, $consulta);

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.recipes.verificacion.anular', [$consulta->id, 1]))
            ->assertRedirect(route('clinica.consulta', [$consulta->id, 'seccion' => 'recipes']));

        $respuesta = $this->publica($verificacion->fresh())->assertOk();
        $respuesta->assertSee('Anulado por el médico');
        $respuesta->assertSee('data-estado="anulado"', false);
        $respuesta->assertSee('07/10/2026');
        $respuesta->assertDontSee('KEFORAL');
        $respuesta->assertSee('Dra. Ana Imprime');

        // Reactivarlo lo devuelve a la normalidad, con el mismo token.
        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.recipes.verificacion.reactivar', [$consulta->id, 1]))->assertRedirect();
        $this->publica($verificacion->fresh())->assertSee('Récipe auténtico y vigente')->assertSee('KEFORAL / CAP. 500 MG');
    }

    public function test_el_recipe_eliminado_dice_que_ya_no_existe(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $verificacion = $this->imprimir($user, $medico, $gineco, $consulta);

        $this->como($user, $medico, $gineco)->delete(route('clinica.consulta.recipes.eliminar', [$consulta->id, 1]))->assertRedirect();

        $respuesta = $this->publica($verificacion)->assertOk();
        $respuesta->assertSee('Este récipe ya no existe');
        $respuesta->assertSee('eliminado por el médico');
        $respuesta->assertSee('data-estado="eliminado"', false);
        $respuesta->assertDontSee('KEFORAL');
    }

    public function test_un_token_inexistente_o_mal_formado_da_la_misma_respuesta_y_404(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $real = $this->imprimir($user, $medico, $gineco, $consulta);

        $inexistente = $this->get('/verificar/' . str_repeat('x', 43));
        $malFormado = $this->get('/verificar/' . substr($real->token, 0, 20));
        $conSimbolos = $this->get('/verificar/' . str_repeat('x', 40) . '%3Cb%3E');
        $largo = $this->get('/verificar/' . $real->token . 'x');

        foreach ([$inexistente, $malFormado, $conSimbolos, $largo] as $respuesta) {
            $respuesta->assertNotFound();
            $respuesta->assertSee('No se encontró este récipe');
            $respuesta->assertSee('data-estado="no_encontrado"', false);
        }

        $this->assertSame($inexistente->getContent(), $malFormado->getContent(), 'El mismo cuerpo: no se distingue "no existe" de "mal escrito".');
        $this->assertSame($inexistente->getContent(), $largo->getContent());
    }

    public function test_el_token_de_ejemplo_no_resuelve_nada_real(): void
    {
        $this->escenario();

        $respuesta = $this->get('/verificar/ejemplo')->assertOk();

        $respuesta->assertSee('Código de ejemplo');
        $respuesta->assertSee('data-estado="ejemplo"', false);
        $respuesta->assertDontSee('Dra. Ana Imprime');
        $respuesta->assertDontSee('KEFORAL');
    }

    public function test_un_recipe_de_otro_medico_nunca_se_mezcla(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        [$otroUser, $otro, $otroGineco] = $this->medicoConAcceso();
        $otro->forceFill(['name' => 'Bruno', 'lastname' => 'Ajeno', 'prefix' => 'Dr'])->save();
        $this->membrete($otro, ['especialidad' => 'Cardiología', 'rif' => 'J-99999999-0', 'linea_1' => 'Otra ciudad'], null);
        $this->medicamento($otro, '0001', 'ATORVAX', 'ATORVASTATINA', 'Tab. 20 mg');
        [, $consultaAjena] = $this->pacienteYConsulta($otro, 1001, 1, ['cedula' => '777', 'nombres' => 'Carlos', 'apellidos' => 'Distinto']);
        $this->recipe($otro, 1001, 1, 1, [['0001', 3, 'Ajena']], '2026-10-06');

        $mio = $this->imprimir($user, $medico, $gineco, $consulta);
        $ajeno = $this->imprimir($otroUser, $otro, $otroGineco, $consultaAjena);

        $this->assertNotSame($mio->token, $ajeno->token);
        $this->publica($mio)->assertSee('Dra. Ana Imprime')->assertSee('KEFORAL')->assertDontSee('ATORVAX')->assertDontSee('Cardiología')->assertDontSee('Ajeno');
        $this->publica($ajeno)->assertSee('Dr. Bruno Ajeno')->assertSee('ATORVAX / TAB. 20 MG')->assertSee('Cardiología')
            ->assertDontSee('KEFORAL')->assertDontSee('Dra. Ana Imprime');
    }

    public function test_la_pagina_es_publica_sin_cookies_y_no_indexable(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $verificacion = $this->imprimir($user, $medico, $gineco, $consulta);
        // Sin sesión: el contexto y el usuario de arriba se tiran.
        $this->app['auth']->guard()->logout();
        $this->flushSession();

        foreach (['/verificar/' . $verificacion->token, '/verificar/' . str_repeat('z', 43), '/verificar', '/verificar?codigo=ZZZZZZZZ'] as $url) {
            $respuesta = $this->get($url);

            $this->assertFalse($respuesta->isRedirect() && str_contains((string) $respuesta->headers->get('Location'), '/login'), 'No pide login: ' . $url);
            $respuesta->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
            $respuesta->assertHeader('Referrer-Policy', 'no-referrer');
            $this->assertStringContainsString('no-store', (string) $respuesta->headers->get('Cache-Control'));
            $this->assertStringContainsString("default-src 'none'", (string) $respuesta->headers->get('Content-Security-Policy'));
            $this->assertCount(0, $respuesta->headers->getCookies(), 'Sin cookie de sesión ni de nada: ' . $url);
            $this->assertStringContainsString('<meta name="robots" content="noindex', $respuesta->getContent());
        }
    }

    public function test_el_limite_de_peticiones_responde_429_y_el_del_codigo_es_mas_estricto(): void
    {
        $url = '/verificar/' . str_repeat('q', 43);

        for ($i = 0; $i < 60; $i++) {
            $this->get($url)->assertNotFound();
        }
        $limitada = $this->get($url);
        $limitada->assertStatus(429);
        $limitada->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_el_limite_del_codigo_corto_es_de_diez_por_minuto(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->get('/verificar?codigo=ZZZZ-ZZZZ')->assertNotFound();
        }

        $this->get('/verificar?codigo=ZZZZ-ZZZZ')->assertStatus(429);
        // Cada ruta tiene su contador: la del token sigue disponible.
        $this->get('/verificar/' . str_repeat('q', 43))->assertNotFound();
    }

    // ====================================================================================== El código

    public function test_el_codigo_corto_lleva_a_la_pagina_del_recipe(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $verificacion = $this->imprimir($user, $medico, $gineco, $consulta);
        $legible = substr($verificacion->codigo, 0, 4) . '-' . substr($verificacion->codigo, 4);

        foreach ([$legible, strtolower($legible), str_replace('-', ' ', $legible), $verificacion->codigo] as $escrito) {
            $this->get('/verificar?codigo=' . rawurlencode($escrito))->assertRedirect('/verificar/' . $verificacion->token);
        }
    }

    public function test_un_codigo_que_no_existe_o_esta_mal_escrito_da_el_mismo_mensaje(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $this->imprimir($user, $medico, $gineco, $consulta);

        $inexistente = $this->get('/verificar?codigo=ZZZZ-ZZZZ');
        $corto = $this->get('/verificar?codigo=AB');
        $raro = $this->get('/verificar?codigo=' . rawurlencode('<script>'));

        foreach ([$inexistente, $corto] as $respuesta) {
            $respuesta->assertNotFound();
            $respuesta->assertSee('No encontramos ese código.');
        }
        $raro->assertNotFound();
        $raro->assertDontSee('<script>', false);

        $this->get('/verificar')->assertOk()->assertSee('Verificar un récipe')->assertSee('name="codigo"', false);
    }

    // ===================================================================== Anular y reactivar (permisos)

    public function test_la_seccion_de_recipes_muestra_el_estado_del_qr_y_sus_acciones(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        // El estado del QR se ve en la lista (O-28: el editor es la puerta de entrada de la sección).
        $seccion = fn () => $this->como($user, $medico, $gineco)->get(route('clinica.consulta', [$consulta->id, 'seccion' => 'recipes', 'lista' => 1]))->assertOk();

        $seccion()->assertSee('Aún sin QR')->assertDontSee('Anular QR');

        $this->imprimir($user, $medico, $gineco, $consulta);
        $seccion()->assertSee('Verificación activa')->assertSee('Anular QR')
            ->assertSee('action="' . route('clinica.consulta.recipes.verificacion.anular', [$consulta->id, 1]) . '"', false)
            ->assertDontSee('Reactivar QR');

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.recipes.verificacion.anular', [$consulta->id, 1]));
        $seccion()->assertSee('QR anulado')->assertSee('Reactivar QR')->assertDontSee('Anular QR');
    }

    public function test_la_pestana_de_la_pareja_muestra_el_estado_del_qr(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $this->pareja($medico, 1001, 1, ['0002']);
        $url = route('clinica.consulta', [$consulta->id, 'seccion' => 'recipes', 'recipe' => 'pareja']);

        $this->como($user, $medico, $gineco)->get($url)->assertSee('Aún sin QR');

        $this->como($user, $medico, $gineco)->get($this->urlPareja($consulta))->assertOk();
        $this->como($user, $medico, $gineco)->get($url)->assertSee('Verificación activa')->assertSee('Anular QR');

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.recipe-pareja.verificacion.anular', $consulta->id))->assertRedirect();
        $this->como($user, $medico, $gineco)->get($url)->assertSee('QR anulado')->assertSee('Reactivar QR');
        $this->assertTrue(RecipeVerificacion::where('tipo', 'pareja')->where('reg_medico', $medico->reg_medico)->firstOrFail()->estaAnulada());

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.recipe-pareja.verificacion.reactivar', $consulta->id))->assertRedirect();
        $this->assertFalse(RecipeVerificacion::where('tipo', 'pareja')->where('reg_medico', $medico->reg_medico)->firstOrFail()->estaAnulada());
    }

    public function test_la_secretaria_no_puede_anular_ni_reactivar(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $secretaria = $this->secretariaConAcceso($medico);
        $verificacion = $this->imprimir($user, $medico, $gineco, $consulta);

        $this->como($secretaria, $medico, $gineco)->post(route('clinica.consulta.recipes.verificacion.anular', [$consulta->id, 1]))->assertForbidden();
        $this->assertFalse($verificacion->fresh()->estaAnulada());

        $verificacion->forceFill(['revocado_en' => now()])->save();
        $this->como($secretaria, $medico, $gineco)->post(route('clinica.consulta.recipes.verificacion.reactivar', [$consulta->id, 1]))->assertForbidden();
        $this->assertTrue($verificacion->fresh()->estaAnulada());
    }

    public function test_el_medico_de_otro_consultorio_recibe_404_y_no_toca_nada(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        [$otroUser, $otro, $otroGineco] = $this->medicoConAcceso();
        $verificacion = $this->imprimir($user, $medico, $gineco, $consulta);

        $this->como($otroUser, $otro, $otroGineco)->post(route('clinica.consulta.recipes.verificacion.anular', [$consulta->id, 1]))->assertNotFound();
        $this->assertFalse($verificacion->fresh()->estaAnulada());
    }

    public function test_un_recipe_sin_qr_o_un_invitado_no_pueden_anular(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();

        // Nunca se imprimió: no hay QR que anular.
        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.recipes.verificacion.anular', [$consulta->id, 1]))->assertNotFound();
        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.recipe-pareja.verificacion.anular', $consulta->id))->assertNotFound();

        $this->flushSession();
        $this->app['auth']->guard()->logout();
        $this->post(route('clinica.consulta.recipes.verificacion.anular', [$consulta->id, 1]))->assertRedirect(route('login'));
    }

    public function test_anular_es_idempotente_y_conserva_la_primera_fecha(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario();
        $verificacion = $this->imprimir($user, $medico, $gineco, $consulta);
        $url = route('clinica.consulta.recipes.verificacion.anular', [$consulta->id, 1]);

        $this->como($user, $medico, $gineco)->post($url);
        $primera = $verificacion->fresh()->revocado_en;
        Carbon::setTestNow($this->hoy->copy()->addHour());
        $this->como($user, $medico, $gineco)->post($url);

        $this->assertTrue($primera->equalTo($verificacion->fresh()->revocado_en));
    }
}
