<?php

namespace Tests\Feature;

use App\Actions\Recipes\GuardarOpcionesDeImpresion;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\RecipeFormato;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * **Las opciones de impresión del récipe conviven con el API del móvil** (WEB-5.2).
 *
 * `recipe_formatos` es una tabla de AppDDR que la web lee y escribe desde WEB-5.2 (dos columnas nullable nuevas:
 * `modo_impresion` y `nombre_medicamento`) **y que ya leen y escriben `RecipeFormatoController` y
 * `RecipeFormatoResource`** —éste viaja en `sync-app-data`—. Lo que estos casos fijan es que **el móvil no se entera**:
 *
 *  - el `actualizar` del API **no borra ni pisa** las columnas nuevas;
 *  - el JSON del Resource **no cambia de forma** (no hay campos nuevos que el teléfono no espera);
 *  - guardar las opciones desde la web **no mueve** lo que el teléfono lee (`updated_at`, `elementos`, firma y sello);
 *  - y la Action que las escribe valida lo que acepta y no crea nada de más.
 */
class RecipeFormatoOpcionesDeImpresionTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = '/api/app/configuracion/formato-recipe';

    /** Las claves de siempre del JSON que recibe el teléfono: ni una más. */
    private const CLAVES_DEL_JSON = ['color_linea', 'elementos', 'firma_url', 'sello_posicion', 'sello_url', 'updated_at'];

    private function medicoAutenticado(): Medico
    {
        $regMedico = 'test-opciones' . substr(uniqid(), -6);
        $user = User::create([
            'name' => 'Doctora de prueba',
            'email' => 'doctora-opcio' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'),
        ]);

        $medico = Medico::create([
            'user_id' => $user->id,
            'name' => 'Doctora',
            'lastname' => 'De Prueba',
            'email' => $user->email,
            'password' => $user->password,
            'reg_medico' => $regMedico,
        ]);

        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        Sanctum::actingAs($user, ['*'], 'api');

        return $medico;
    }

    private function conOpciones(Medico $medico, string $modo = 'solo_indicaciones', string $nombre = 'generico'): void
    {
        DB::table('recipe_formatos')->insert([
            'reg_medico' => $medico->reg_medico,
            'elementos' => json_encode(['medico' => ['alineacion' => 'derecha']]),
            'color_linea' => 'verde',
            'modo_impresion' => $modo,
            'nombre_medicamento' => $nombre,
            'created_at' => '2026-09-01 00:00:00',
            'updated_at' => '2026-09-01 00:00:00',
        ]);
    }

    private function opciones(Medico $medico): array
    {
        $fila = DB::table('recipe_formatos')->where('reg_medico', $medico->reg_medico)->first();

        return [$fila->modo_impresion, $fila->nombre_medicamento];
    }

    public function test_el_actualizar_del_movil_no_borra_las_opciones_de_impresion(): void
    {
        $medico = $this->medicoAutenticado();
        $this->conOpciones($medico);

        $this->postJson(self::URL, [
            'elementos' => ['rif' => ['negrita' => false], 'medico' => ['fuente' => 'tinos']],
            'color_linea' => 'azul',
            'sello_posicion' => 'derecha',
        ])->assertOk();

        $this->assertSame(['solo_indicaciones', 'generico'], $this->opciones($medico));
        // Y lo que el móvil sí guardó, quedó.
        $fila = RecipeFormato::where('reg_medico', $medico->reg_medico)->first();
        $this->assertSame('azul', $fila->color_linea);
        $this->assertSame('derecha', $fila->sello_posicion);
        $this->assertSame('tinos', $fila->elementos['medico']['fuente']);
    }

    public function test_el_movil_que_crea_el_formato_deja_las_opciones_sin_valor(): void
    {
        $medico = $this->medicoAutenticado();

        $this->postJson(self::URL, ['color_linea' => 'rojo'])->assertCreated();

        $this->assertSame([null, null], $this->opciones($medico));
    }

    public function test_el_json_del_api_no_cambia_de_forma_ni_trae_las_opciones_nuevas(): void
    {
        $medico = $this->medicoAutenticado();
        $this->conOpciones($medico);

        $actualizar = $this->postJson(self::URL, ['color_linea' => 'rojo']);
        $actualizar->assertOk();
        $claves = array_keys($actualizar->json('data'));
        sort($claves);
        $this->assertSame(self::CLAVES_DEL_JSON, $claves);

        $sync = $this->postJson('/api/app/sync-app-data', []);
        $sync->assertOk();
        $clavesDelSync = array_keys($sync->json('formato_recipe'));
        sort($clavesDelSync);
        $this->assertSame(self::CLAVES_DEL_JSON, $clavesDelSync);

        foreach ([$actualizar, $sync] as $respuesta) {
            $this->assertStringNotContainsString('modo_impresion', $respuesta->getContent());
            $this->assertStringNotContainsString('nombre_medicamento', $respuesta->getContent());
        }
    }

    public function test_guardar_las_opciones_desde_la_web_no_mueve_lo_que_el_movil_lee(): void
    {
        $medico = $this->medicoAutenticado();
        $this->conOpciones($medico, 'rp_indicaciones', 'comercial');

        $antes = $this->postJson('/api/app/sync-app-data', [])->json('formato_recipe');

        $this->app->make(GuardarOpcionesDeImpresion::class)->ejecutar($medico, 'solo_indicaciones', 'generico');

        $this->assertSame(['solo_indicaciones', 'generico'], $this->opciones($medico));
        $despues = $this->postJson('/api/app/sync-app-data', [])->json('formato_recipe');

        // El teléfono ve **exactamente** lo mismo: la marca de versión de sus imágenes no se movió.
        $this->assertSame($antes, $despues);
        $this->assertSame('2026-09-01T00:00:00+00:00', $despues['updated_at']);
    }

    public function test_la_action_crea_la_fila_la_primera_vez_y_despues_la_reusa(): void
    {
        $medico = $this->medicoAutenticado();
        $guardar = $this->app->make(GuardarOpcionesDeImpresion::class);

        $this->assertSame(0, RecipeFormato::where('reg_medico', $medico->reg_medico)->count());

        $guardar->ejecutar($medico, 'rp_indicaciones', 'generico');
        $guardar->ejecutar($medico, 'solo_indicaciones', 'comercial');

        $this->assertSame(1, RecipeFormato::where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(['solo_indicaciones', 'comercial'], $this->opciones($medico));
        // Una fila creada así no inventa configuración del membrete: el móvil la ve con sus defaults.
        $sync = $this->postJson('/api/app/sync-app-data', [])->json('formato_recipe');
        $this->assertSame('negro', $sync['color_linea']);
        $this->assertSame('izquierda', $sync['elementos']['logo']['alineacion']);
    }

    public function test_la_action_rechaza_valores_desconocidos_y_no_escribe_nada(): void
    {
        $medico = $this->medicoAutenticado();
        $guardar = $this->app->make(GuardarOpcionesDeImpresion::class);

        foreach ([['apaisado', 'comercial'], ['rp_indicaciones', 'raro'], ['', '']] as [$modo, $nombre]) {
            try {
                $guardar->ejecutar($medico, $modo, $nombre);
                $this->fail('Tenía que rechazar ' . $modo . '/' . $nombre);
            } catch (InvalidArgumentException $excepcion) {
                $this->assertNotSame('', $excepcion->getMessage());
            }
        }

        $this->assertSame(0, RecipeFormato::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_la_action_guarda_bajo_el_registro_principal_del_medico(): void
    {
        $medico = $this->medicoAutenticado();
        $segundo = 'test-opciones' . substr(uniqid(), -6);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $segundo]);

        $this->app->make(GuardarOpcionesDeImpresion::class)->ejecutar($medico->fresh(), 'solo_indicaciones', 'generico');

        // El principal es el que `regMedicoPrincipal()` devuelve —el mismo bajo el que escribe el API del móvil—.
        $principal = $medico->fresh()->regMedicoPrincipal();
        $this->assertSame(1, DB::table('recipe_formatos')->where('reg_medico', $principal)->count());
        $this->assertSame(1, DB::table('recipe_formatos')->whereIn('reg_medico', [$medico->reg_medico, $segundo])->count());
    }
}
