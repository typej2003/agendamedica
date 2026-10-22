<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **El menú lateral del panel con submenús**: Usuarios, Catálogo inicial, Planes y servicios y Métodos de pago
 * son grupos desplegables; cada pestaña de antes es un submenú con su URL, y el grupo nace abierto y resaltado
 * cuando la ruta activa es de un hijo. Escritorio, Especialidades, API Keys, Pagos, Solicitudes y Ayúdanos a
 * mejorar siguen siendo ítems sueltos.
 */
class MenuLateralAdminTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function admin(): User
    {
        $user = User::create([
            'name' => 'Admin del menú',
            'email' => 'menu-admin-' . substr(uniqid(), -8) . '@example.com',
            'password' => Hash::make('secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('Root');

        return $user;
    }

    public function test_cada_pestana_de_antes_tiene_su_url_y_abre_la_pestana_correcta(): void
    {
        $this->actingAs($this->admin());

        $this->get(route('admin.cuentas'))->assertOk()->assertSee('Usuarios · Médicos');
        $this->get(route('admin.cuentas.administradores'))->assertOk()->assertSee('Usuarios · Administradores');
        $this->get(route('admin.servicios'))->assertOk()->assertSee('Servicios por médico');
        $this->get(route('admin.servicios.planes'))->assertOk()->assertSee('Nuevo plan');
        $this->get(route('admin.metodos-pago'))->assertOk()->assertSee('Nuevo método');
        $this->get(route('admin.metodos-pago.tasas'))->assertOk()->assertSee('Tasa');
    }

    public function test_los_grupos_listan_sus_submenus_y_los_items_sueltos_siguen_sueltos(): void
    {
        $this->actingAs($this->admin());
        $html = $this->get(route('admin.cuentas'))->assertOk()->getContent();

        foreach (['usuarios', 'catalogo-inicial', 'planes-y-servicios', 'metodos-de-pago'] as $clave) {
            $this->assertStringContainsString('data-menu="' . $clave . '"', $html);
        }
        foreach ([route('admin.cuentas.administradores'), route('admin.servicios.planes'), route('admin.metodos-pago.tasas'),
                  route('admin.catalogo-inicial.vacunas'), route('admin.catalogo-inicial.percentiles')] as $url) {
            $this->assertStringContainsString('href="' . $url . '"', $html);
        }
        // Sin submenú: un enlace directo, no un grupo.
        foreach (['especialidades', 'api-keys', 'pagos', 'solicitudes-medicos', 'comentarios'] as $ruta) {
            $this->assertStringContainsString('href="' . route('admin.' . $ruta) . '"', $html);
            $this->assertStringNotContainsString('data-menu="' . $ruta . '"', $html);
        }
    }

    public function test_el_grupo_de_la_ruta_activa_nace_abierto_y_resaltado_y_los_demas_cerrados(): void
    {
        $this->actingAs($this->admin());

        $html = $this->get(route('admin.servicios.planes'))->getContent();
        $this->assertMatchesRegularExpression('/<details class="nav-grupo" data-menu="planes-y-servicios" open>/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-menu="usuarios" open/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-menu="metodos-de-pago" open/', $html);
        $this->assertStringContainsString('aria-expanded="true"', $html);
        $this->assertStringContainsString('activo-padre', $html);
        // El hijo activo lleva aria-current.
        $this->assertMatchesRegularExpression('/href="' . preg_quote(route('admin.servicios.planes'), '/') . '"\s+aria-current="page"/', $html);

        $html = $this->get(route('admin.catalogo-inicial.catalogo', 'diagnosticos'))->getContent();
        $this->assertMatchesRegularExpression('/data-menu="catalogo-inicial" open/', $html);
        $this->assertMatchesRegularExpression('/href="' . preg_quote(route('admin.catalogo-inicial.catalogo', 'diagnosticos'), '/') . '"\s+aria-current="page"/', $html);
    }

    public function test_un_catalogo_inicial_aparece_como_submenu_por_cada_catalogo_del_registro(): void
    {
        $this->actingAs($this->admin());
        $html = $this->get(route('admin.cuentas'))->getContent();

        foreach (\App\Clinica\Catalogos\Iniciales\CatalogosIniciales::todos() as $slug => $def) {
            $this->assertStringContainsString('href="' . route('admin.catalogo-inicial.catalogo', $slug) . '"', $html);
        }
    }
}
