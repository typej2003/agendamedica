<?php

namespace Tests\Feature;

use App\Actions\Ciudades\CrearCiudad;
use App\Models\City;
use App\Models\Estado;
use App\Models\User;
use App\Services\CuentaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `<x-ui.selector-buscable>` y su primer uso, las ciudades: el componente, la búsqueda (autenticada y pública), el
 * alta (sólo administradores) y las vistas que lo usan.
 */
class SelectorBuscableTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function admin(): User
    {
        $u = app(CuentaService::class)->crearAdministrador('Admin Selector', 'admin-sel-' . substr(uniqid(), -6) . '@example.com')['user'];
        $u->forceFill(['must_change_password' => false])->save();

        return $u;
    }

    private function medico(): User
    {
        $s = uniqid();
        $u = app(CuentaService::class)->crearMedico(['name' => 'Dr', 'lastname' => 'Sel', 'email' => "sel-{$s}@example.com", 'reg_medico' => "test-sel-{$s}"])['user'];
        $u->forceFill(['must_change_password' => false])->save();

        return $u;
    }

    private function secretaria(): User
    {
        $s = uniqid();
        $reg = 'test-sec-' . $s;
        app(CuentaService::class)->crearMedico(['name' => 'Dr', 'lastname' => 'Jefe', 'email' => "jefe-{$s}@example.com", 'reg_medico' => $reg]);
        $u = app(CuentaService::class)->crearSecretaria('Sec', "sec-{$s}@example.com", $reg)['user'];
        $u->forceFill(['must_change_password' => false])->save();

        return $u;
    }

    private function estado(): Estado
    {
        return Estado::orderBy('id')->firstOrFail();
    }

    // ------------------------------------------------------------------------------------------ Componente

    public function test_con_url_pide_al_servidor_y_marca_required_error_y_valor(): void
    {
        $html = Blade::render('<x-ui.selector-buscable name="city_id" label="Ciudad" required value="5" value-label="Maracaibo — Zulia" url="/buscar" error="Elige la ciudad." />');

        $this->assertStringContainsString('data-modo="remoto"', $html);
        $this->assertStringContainsString('data-url="/buscar"', $html);
        $this->assertStringContainsString('role="combobox"', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);
        $this->assertStringContainsString('role="listbox"', $html);
        $this->assertStringContainsString('data-requerido', $html);
        $this->assertStringContainsString('name="city_id" value="5"', $html);
        $this->assertStringContainsString('value="Maracaibo — Zulia"', $html);
        $this->assertStringContainsString('Elige la ciudad.', $html);
        $this->assertStringContainsString('tiene-error', $html);
        $this->assertMatchesRegularExpression('/<label[^>]+for="selector-city_id"/', $html);
        $this->assertStringNotContainsString('data-crear-url', $html);
    }

    public function test_con_pocas_opciones_se_incrustan_y_la_etiqueta_sale_de_ellas(): void
    {
        $html = Blade::render('<x-ui.selector-buscable name="x" value="2" :options="$o" url="/buscar" />', ['o' => [
            ['value' => 1, 'label' => 'Uno'], ['value' => 2, 'label' => 'Dos'],
        ]]);

        $this->assertStringContainsString('data-modo="local"', $html);
        $this->assertStringContainsString('data-opciones', $html);
        $this->assertStringNotContainsString('data-url=', $html);
        $this->assertStringContainsString('value="Dos"', $html);
    }

    public function test_sobre_el_umbral_pasa_a_modo_remoto(): void
    {
        $opciones = array_map(fn ($i) => ['value' => $i, 'label' => "Op $i"], range(1, 60));
        $html = Blade::render('<x-ui.selector-buscable name="x" :options="$o" url="/buscar" />', ['o' => $opciones]);

        $this->assertStringContainsString('data-modo="remoto"', $html);
        $this->assertStringNotContainsString('data-opciones', $html);
    }

    public function test_la_opcion_de_crear_solo_va_con_puede_crear_y_url(): void
    {
        $con = Blade::render('<x-ui.selector-buscable name="x" url="/b" puede-crear crear-url="/crear" />');
        $sin = Blade::render('<x-ui.selector-buscable name="x" url="/b" crear-url="/crear" />');
        $sinUrl = Blade::render('<x-ui.selector-buscable name="x" url="/b" puede-crear />');

        $this->assertStringContainsString('data-crear-url="/crear"', $con);
        $this->assertStringNotContainsString('data-crear-url', $sin);
        $this->assertStringNotContainsString('data-crear-url', $sinUrl);
    }

    public function test_livewire_manda_los_atributos_wire_al_input_oculto(): void
    {
        $html = Blade::render('<x-ui.selector-buscable name="city_id" url="/b" wire:model="city_id" />');

        $this->assertMatchesRegularExpression('/<input type="hidden" name="city_id"[^>]*wire:model="city_id"/', $html);
        $this->assertStringContainsString('wire:ignore', $html);
    }

    // ------------------------------------------------------------------------------------------ Búsqueda

    public function test_la_busqueda_ignora_tildes_y_mayusculas_y_ordena_las_que_empiezan_primero(): void
    {
        $estado = $this->estado();
        $a = City::create(['state_id' => $estado->id, 'name' => 'Zzáragoza Norte', 'capital' => false]);
        $b = City::create(['state_id' => $estado->id, 'name' => 'Norte de Zzaragoza', 'capital' => false]);

        $json = $this->actingAs($this->medico())->getJson('/clinica/ciudades/buscar?q=ZZARAGOZA')->assertOk()->json('resultados');

        $this->assertSame([$a->id, $b->id], array_column($json, 'value'));
        $this->assertSame($estado->name, $json[0]['detail']);
    }

    public function test_la_busqueda_respeta_el_limite_y_el_valor_nombre(): void
    {
        $user = $this->medico();

        $this->actingAs($user)->getJson('/clinica/ciudades/buscar?q=san&limite=3')->assertOk()->assertJsonCount(3, 'resultados');
        $this->actingAs($user)->getJson('/clinica/ciudades/buscar?q=san&limite=5000')->assertOk();
        $this->assertLessThanOrEqual(50, count($this->actingAs($user)->getJson('/clinica/ciudades/buscar?q=a&limite=5000')->json('resultados')));

        $nombre = $this->actingAs($user)->getJson('/clinica/ciudades/buscar?q=maracaibo&valor=nombre')->json('resultados.0.value');
        $this->assertSame('Maracaibo', $nombre);
    }

    public function test_sin_texto_no_devuelve_nada(): void
    {
        $this->actingAs($this->medico())->getJson('/clinica/ciudades/buscar?q=')->assertOk()->assertExactJson(['resultados' => []]);
    }

    public function test_la_busqueda_autenticada_pide_sesion(): void
    {
        $this->getJson('/clinica/ciudades/buscar?q=maracaibo')->assertUnauthorized();
    }

    public function test_la_busqueda_autenticada_sirve_a_medico_y_secretaria(): void
    {
        foreach ([$this->medico(), $this->secretaria()] as $user) {
            $this->actingAs($user)->getJson('/clinica/ciudades/buscar?q=maracaibo')->assertOk();
        }
    }

    public function test_el_administrador_busca_en_su_ruta_y_el_medico_no_entra_a_ella(): void
    {
        $this->actingAs($this->admin())->getJson('/admin/ciudades/buscar?q=maracaibo')->assertOk()->assertJsonPath('resultados.0.label', 'Maracaibo');
        $this->actingAs($this->medico())->getJson('/admin/ciudades/buscar?q=maracaibo')->assertForbidden();
    }

    public function test_la_busqueda_publica_del_registro_no_pide_sesion_y_no_da_ids(): void
    {
        $r = $this->getJson('/registro/medico/ciudades?q=maracaibo')->assertOk()->json('resultados');

        $this->assertSame('Maracaibo', $r[0]['value']);
        $this->assertSame(['detail', 'label', 'value'], collect($r[0])->keys()->sort()->values()->all());
    }

    public function test_la_busqueda_publica_tiene_limite_de_peticiones(): void
    {
        RateLimiter::clear('x');
        $ultimo = null;
        for ($i = 0; $i < 61; $i++) {
            $ultimo = $this->getJson('/registro/medico/ciudades?q=ma');
        }

        $ultimo->assertStatus(429);
    }

    // ------------------------------------------------------------------------------------------ Crear

    public function test_solo_el_administrador_crea_ciudades(): void
    {
        $datos = ['nombre' => 'Pueblo Nuevo de Prueba', 'state_id' => $this->estado()->id];

        $this->actingAs($this->medico())->postJson('/admin/ciudades', $datos)->assertForbidden();
        $this->actingAs($this->secretaria())->postJson('/admin/ciudades', $datos)->assertForbidden();
        $this->assertDatabaseMissing('cities', ['name' => 'Pueblo Nuevo de Prueba']);

        $r = $this->actingAs($this->admin())->postJson('/admin/ciudades', $datos)->assertCreated();
        $this->assertTrue($r->json('creada'));
        $this->assertStringContainsString('Pueblo Nuevo de Prueba', $r->json('label'));
        $this->assertDatabaseHas('cities', ['id' => $r->json('value'), 'state_id' => $datos['state_id']]);
    }

    public function test_un_duplicado_devuelve_la_existente(): void
    {
        $estado = $this->estado();
        $existente = City::create(['state_id' => $estado->id, 'name' => 'Villa Única', 'capital' => false]);
        $antes = City::count();

        $r = $this->actingAs($this->admin())->postJson('/admin/ciudades', ['nombre' => '  VILLA   unica ', 'state_id' => $estado->id])->assertOk();

        $this->assertFalse($r->json('creada'));
        $this->assertSame($existente->id, $r->json('value'));
        $this->assertSame($antes, City::count());
    }

    public function test_el_mismo_nombre_en_otro_estado_si_se_crea(): void
    {
        [$e1, $e2] = Estado::orderBy('id')->take(2)->get();
        $admin = $this->admin();

        $a = $this->actingAs($admin)->postJson('/admin/ciudades', ['nombre' => 'Villa Gemela Prueba', 'state_id' => $e1->id])->assertCreated();
        $b = $this->actingAs($admin)->postJson('/admin/ciudades', ['nombre' => 'Villa Gemela Prueba', 'state_id' => $e2->id])->assertCreated();

        $this->assertNotSame($a->json('value'), $b->json('value'));
    }

    public function test_crear_valida_el_nombre_y_el_estado(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/admin/ciudades', ['nombre' => '', 'state_id' => $this->estado()->id])->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->actingAs($admin)->postJson('/admin/ciudades', ['nombre' => '12345', 'state_id' => $this->estado()->id])->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->actingAs($admin)->postJson('/admin/ciudades', ['nombre' => 'Valida'])->assertStatus(422)->assertJsonValidationErrors('state_id');
        $this->actingAs($admin)->postJson('/admin/ciudades', ['nombre' => 'Valida', 'state_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('state_id');
    }

    public function test_la_accion_limpia_el_nombre(): void
    {
        [$ciudad] = app(CrearCiudad::class)->ejecutar('  san   josé del monte ', $this->estado()->id);

        $this->assertSame('San José del Monte', $ciudad->name);
    }

    public function test_la_accion_rechaza_un_nombre_invalido(): void
    {
        $this->expectException(ValidationException::class);
        app(CrearCiudad::class)->ejecutar('<b>x</b>', $this->estado()->id);
    }

    // ------------------------------------------------------------------------------------------ Vistas

    public function test_el_dialogo_del_admin_ofrece_crear_con_el_estado_y_precarga_la_ciudad_al_editar(): void
    {
        $admin = $this->admin();
        $s = uniqid();
        $medico = app(CuentaService::class)->crearMedico(['name' => 'Dr', 'lastname' => 'Dlg', 'email' => "dlg-{$s}@example.com", 'reg_medico' => "test-dlg-{$s}"])['medico'];
        $ciudad = City::with('state')->where('name', 'Maracaibo')->firstOrFail();

        $this->actingAs($admin);
        $componente = \Livewire\Livewire::test(\App\Http\Livewire\Admin\Sedes\SedesDelMedico::class, ['medico' => $medico])
            ->call('abrirAgregar')->set('modo', 'nueva')->set('city_id', (string) $ciudad->id);

        $componente->assertSeeHtml('data-crear-url="' . route('admin.ciudades.crear') . '"')
            ->assertSeeHtml('data-url="' . route('admin.ciudades.buscar') . '"')
            ->assertSeeHtml('value="Maracaibo — ' . $ciudad->state->name . '"')
            ->assertSeeHtml('wire:model="city_id"');
        $this->assertStringContainsString('&quot;label&quot;:&quot;Estado&quot;', $componente->html());
    }

    public function test_el_registro_publico_usa_el_selector_en_modo_libre_sin_crear(): void
    {
        $html = $this->get('/registro/medico')->assertOk()->getContent();

        $this->assertStringContainsString('data-selector-buscable', $html);
        $this->assertStringContainsString('data-libre="1"', $html);
        $this->assertStringContainsString('name="ciudad"', $html);
        $this->assertStringNotContainsString('data-crear-url', $html);
    }
}