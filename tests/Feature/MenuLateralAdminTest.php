<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **El menú lateral del panel**: ocho áreas y dos niveles. Usuarios, Catálogo inicial, Facturación y pagos y
 * Crecimiento y fidelización son grupos desplegables; cada pantalla es un submenú con su URL, y el grupo nace abierto
 * y resaltado cuando la ruta activa es de un hijo. Solicitudes de médicos, Especialidades, API Keys y Soporte y
 * sugerencias siguen siendo ítems sueltos.
 *
 * «Facturación y pagos» junta lo que antes eran los grupos «Planes y servicios» y «Métodos de pago» más «Pagos», y
 * «Crecimiento y fidelización» junta «Referidos» y «Latidos». **Un grupo no cuelga de otro**: el menú se queda en dos
 * niveles (decisión del 2026-11-12, tarjeta #111).
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

    /**
     * El HTML del panel, listo para consultar por XPath.
     */
    private function xpath(string $html): \DOMXPath
    {
        $documento = new \DOMDocument();
        $anterior = libxml_use_internal_errors(true);
        $documento->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        return new \DOMXPath($documento);
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
        $this->get(route('admin.pagos'))->assertOk()->assertSee('Pagos');
    }

    public function test_los_grupos_listan_sus_submenus_y_los_items_sueltos_siguen_sueltos(): void
    {
        $this->actingAs($this->admin());
        $html = $this->get(route('admin.cuentas'))->assertOk()->getContent();

        foreach (['usuarios', 'catalogo-inicial', 'facturacion-y-pagos', 'crecimiento-y-fidelizacion'] as $clave) {
            $this->assertStringContainsString('data-menu="' . $clave . '"', $html);
        }
        foreach ([route('admin.cuentas.administradores'), route('admin.cuentas.pacientes'), route('admin.servicios.planes'),
                  route('admin.metodos-pago.tasas'), route('admin.pagos'), route('admin.referidos'), route('admin.latidos'),
                  route('admin.catalogo-inicial.vacunas'), route('admin.catalogo-inicial.percentiles')] as $url) {
            $this->assertStringContainsString('href="' . $url . '"', $html);
        }
        // Sin submenú: un enlace directo, no un grupo.
        foreach (['especialidades', 'api-keys', 'solicitudes-medicos', 'comentarios'] as $ruta) {
            $this->assertStringContainsString('href="' . route('admin.' . $ruta) . '"', $html);
            $this->assertStringNotContainsString('data-menu="' . $ruta . '"', $html);
        }
        // «Pagos» dejó de ser suelto: ahora es un hijo de «Facturación y pagos».
        $this->assertStringNotContainsString('data-menu="pagos"', $html);
    }

    public function test_el_menu_no_pasa_de_dos_niveles(): void
    {
        $this->actingAs($this->admin());
        $html = $this->get(route('admin.cuentas'))->getContent();
        $xpath = $this->xpath($html);

        // Cuatro grupos y ningún grupo dentro de otro ni dentro de un submenú: grupo → enlace, y nada más.
        $this->assertSame(4, $xpath->query('//details[@class="nav-grupo"]')->length);
        $this->assertSame(0, $xpath->query('//details//details')->length);
        $this->assertSame(0, $xpath->query('//ul[contains(@class, "nav-submenu")]//details')->length);
        $this->assertSame(4, $xpath->query('//details[@class="nav-grupo"]/ul[contains(@class, "nav-submenu")]')->length);

        // Las cinco pantallas de facturación cuelgan del grupo, y «Pagos» es una de ellas.
        $this->assertSame(5, $xpath->query('//details[@data-menu="facturacion-y-pagos"]/ul/li')->length);
        $this->assertSame(1, $xpath->query('//details[@data-menu="facturacion-y-pagos"]/ul/li/a[@href="' . route('admin.pagos') . '"]')->length);
        $this->assertSame(2, $xpath->query('//details[@data-menu="crecimiento-y-fidelizacion"]/ul/li')->length);
    }

    public function test_el_grupo_de_la_ruta_activa_nace_abierto_y_resaltado_y_los_demas_cerrados(): void
    {
        $this->actingAs($this->admin());

        $html = $this->get(route('admin.servicios.planes'))->getContent();
        $this->assertMatchesRegularExpression('/<details class="nav-grupo" data-menu="facturacion-y-pagos" open>/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-menu="usuarios" open/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-menu="crecimiento-y-fidelizacion" open/', $html);
        $this->assertStringContainsString('aria-expanded="true"', $html);
        $this->assertStringContainsString('activo-padre', $html);
        // El hijo activo lleva aria-current.
        $this->assertMatchesRegularExpression('/href="' . preg_quote(route('admin.servicios.planes'), '/') . '"\s+aria-current="page"/', $html);

        // «Pagos» abre y resalta el mismo grupo.
        $html = $this->get(route('admin.pagos'))->getContent();
        $this->assertMatchesRegularExpression('/<details class="nav-grupo" data-menu="facturacion-y-pagos" open>/', $html);
        $this->assertMatchesRegularExpression('/href="' . preg_quote(route('admin.pagos'), '/') . '"\s+aria-current="page"/', $html);

        $html = $this->get(route('admin.latidos'))->getContent();
        $this->assertMatchesRegularExpression('/<details class="nav-grupo" data-menu="crecimiento-y-fidelizacion" open>/', $html);
        $this->assertMatchesRegularExpression('/href="' . preg_quote(route('admin.latidos'), '/') . '"\s+aria-current="page"/', $html);

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
