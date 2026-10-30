<?php

namespace Tests\Feature;

use App\Clinica\ContextoTrabajo;
use App\Http\Livewire\Admin\Especialidades;
use App\Models\Medico;
use App\Models\Specialty;
use App\Models\User;
use App\Services\CuentaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sección "Especialidades" del panel (`/admin/especialidades`, componente `Admin\Especialidades`): con qué
 * especialidades trabaja cada médico.
 *
 * Lo que fijan estos tests: sólo entran los administradores; la pantalla deja al médico con **exactamente**
 * las especialidades tildadas (y avisa cuando queda sin ninguna activa, porque su consultorio no muestra
 * módulos); el catálogo **no** se crea ni se edita desde acá; y lo que se asigna es lo mismo que después lee
 * el contexto de la web clínica (`ContextoTrabajo`).
 */
class EspecialidadesDelMedicoTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function admin(): User
    {
        $user = app(CuentaService::class)->crearAdministrador('Admin Esp', 'admin-esp-' . substr(uniqid(), -6) . '@example.com')['user'];
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }

    /**
     * **Ningún médico nace sin especialidad** (2026-10-30): el alta desde el panel le pone
     * `medicina-general`, que es el piso seguro (el núcleo de la consulta, sin ecos ni embarazo). Antes
     * quedaba sin ninguna y en silencio: menú vacío, y hasta el fix del alcance además veía el contenido
     * ginecológico que no le tocaba.
     */
    public function test_el_alta_de_un_medico_le_asigna_medicina_general(): void
    {
        $this->seed(\Database\Seeders\EspecialidadesYModulosSeeder::class);

        $sufijo = uniqid();
        $r = app(CuentaService::class)->crearMedico([
            'name' => 'Nuevo', 'lastname' => 'Sin pedir especialidad',
            'email' => "alta-{$sufijo}@example.com", 'reg_medico' => "test-alta-{$sufijo}",
        ]);

        $this->assertSame(['medicina-general'], $r['medico']->specialties()->pluck('slug')->all());

        // Y si se le pide otra, esa manda.
        $sufijo = uniqid();
        $otra = $this->especialidad('Cardiología');
        $r = app(CuentaService::class)->crearMedico([
            'name' => 'Nuevo', 'lastname' => 'Con pedido', 'specialty_id' => $otra->id,
            'email' => "alta2-{$sufijo}@example.com", 'reg_medico' => "test-alta2-{$sufijo}",
        ]);

        $this->assertSame([$otra->id], $r['medico']->specialties()->pluck('specialties.id')->all());
    }

    /** @return array{user: User, medico: Medico} */
    private function medico(string $nombre = 'Doctor'): array
    {
        $sufijo = uniqid();
        $r = app(CuentaService::class)->crearMedico([
            'name' => $nombre, 'lastname' => 'Especialidad', 'email' => "esp-{$sufijo}@example.com", 'reg_medico' => "test-esp-{$sufijo}",
        ]);
        $r['user']->forceFill(['must_change_password' => false])->save();

        // **El alta ya no deja al médico sin especialidad** (2026-10-30): `crearMedico` le pone
        // `medicina-general` si no se le pide otra. Esta pantalla se prueba justamente con médicos **sin
        // ninguna**, así que se le quita la del alta a propósito.
        $r['medico']->specialties()->detach();

        return $r;
    }

    /** Una especialidad del catálogo de prueba (el real lo siembran los manifiestos). */
    private function especialidad(string $nombre, bool $activo = true): Specialty
    {
        return Specialty::create([
            'name'   => $nombre,
            'slug'   => 'prueba-' . substr(uniqid(), -6),
            'codigo' => 'P' . random_int(10, 99),
            'activo' => $activo,
        ]);
    }

    /* ------------------------------ acceso ------------------------------ */

    public function test_acceso_solo_para_administradores_y_enlace_en_el_panel(): void
    {
        $this->get('/admin/especialidades')->assertRedirect('/login');

        $this->actingAs($this->medico('Comun')['user'])->get('/admin/especialidades')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin());
        $this->get('/admin/especialidades')->assertOk()->assertSee('Especialidades');
        Livewire::test('layouts.aside')->assertSee(route('admin.especialidades'));
    }

    /* ------------------------------ pantalla ---------------------------- */

    public function test_el_panel_asigna_varias_especialidades_y_quita_las_que_sobran(): void
    {
        $r = $this->medico('Titular');
        $clinica = $this->especialidad('Clínica Médica');
        $eco = $this->especialidad('Ecografía');
        $neuro = $this->especialidad('Neurología');
        $this->actingAs($this->admin());

        $panel = Livewire::test(Especialidades::class)
            ->call('abrirAsignar', $r['medico']->id)
            ->assertSet('modal', 'asignar')
            ->assertSee('Clínica Médica')
            ->set('seleccionadas', [(string) $clinica->id, (string) $eco->id])
            ->call('guardarAsignacion')
            ->assertHasNoErrors()
            ->assertSet('modal', null);

        $this->assertSame([$clinica->id, $eco->id], $this->idsDelPivote($r['medico']));

        // Al reabrir, vienen tildadas las que tiene (sin importar el orden con que se guardaron).
        $abierto = $panel->call('abrirAsignar', $r['medico']->id);
        $this->assertEqualsCanonicalizing([(string) $clinica->id, (string) $eco->id], $abierto->get('seleccionadas'));

        // Ahora se suelta la clínica y se suma neurología: queda EXACTAMENTE eso.
        $abierto->set('seleccionadas', [(string) $eco->id, (string) $neuro->id])
            ->call('guardarAsignacion')
            ->assertHasNoErrors();

        $this->assertSame([$eco->id, $neuro->id], $this->idsDelPivote($r['medico']));
        // El catálogo no se tocó desde la pantalla.
        $this->assertSame(3, Specialty::whereIn('id', [$clinica->id, $eco->id, $neuro->id])->count());
    }

    public function test_el_panel_avisa_cuando_el_medico_queda_sin_especialidad(): void
    {
        $r = $this->medico('SinNada');
        $esp = $this->especialidad('Dermatología');
        $this->actingAs($this->admin());

        Livewire::test(Especialidades::class)
            ->call('abrirAsignar', $r['medico']->id)
            ->set('seleccionadas', [(string) $esp->id])
            ->call('guardarAsignacion')
            ->assertSet('aviso', null);

        Livewire::test(Especialidades::class)
            ->call('abrirAsignar', $r['medico']->id)
            ->set('seleccionadas', [])
            ->call('guardarAsignacion')
            ->assertHasNoErrors()   // se guarda: no es un error, pero hay que decirlo
            ->assertSee('quedó sin ninguna especialidad');

        $this->assertSame([], $this->idsDelPivote($r['medico']));
    }

    public function test_una_especialidad_inactiva_se_puede_dejar_asignada_pero_no_da_modulos(): void
    {
        $r = $this->medico('Dormido');
        $dormida = $this->especialidad('Especialidad apagada', activo: false);
        $this->actingAs($this->admin());

        Livewire::test(Especialidades::class)
            ->call('abrirAsignar', $r['medico']->id)
            ->assertSee('Especialidad apagada')
            ->assertSee('Inactiva')
            ->set('seleccionadas', [(string) $dormida->id])
            ->call('guardarAsignacion')
            ->assertHasNoErrors()
            ->assertSee('están inactivas');

        // La fila queda asignada (no se borra) y la pantalla lo marca.
        $this->assertSame([$dormida->id], $this->idsDelPivote($r['medico']));
        Livewire::test(Especialidades::class)->set('search', $r['medico']->reg_medico)->assertSee('inactiva');
    }

    public function test_los_filtros_y_el_buscador_acotan_el_listado(): void
    {
        $con = $this->medico('ConAlguna');
        $sin = $this->medico('SinAlguna');
        $esp = $this->especialidad('Oftalmología');
        app(CuentaService::class)->asignarEspecialidades($con['medico'], [$esp->id]);
        $this->actingAs($this->admin());

        // El buscador acota a los dos médicos de esta prueba, así las pastillas no dependen de la paginación.
        $panel = Livewire::test(Especialidades::class)->set('search', 'Especialidad');

        $panel->call('filtrar', 'sin')->assertSee('SinAlguna Especialidad')->assertDontSee('ConAlguna Especialidad');
        $panel->call('filtrar', 'con')->assertSee('ConAlguna Especialidad')->assertDontSee('SinAlguna Especialidad');
        // La fila sin especialidad dice qué le pasa al consultorio, no queda en blanco.
        $panel->call('filtrar', 'sin')->assertSee('Sin especialidad')->assertSee('Su consultorio no muestra ningún módulo');
        $panel->call('filtrar', 'todos')->assertSee('ConAlguna Especialidad')->assertSee('SinAlguna Especialidad');
        // Un filtro inventado no rompe: vuelve a "todos".
        $panel->call('filtrar', 'cualquiera')->assertSet('filtro', 'todos');

        Livewire::test(Especialidades::class)
            ->set('search', $sin['medico']->reg_medico)
            ->assertSee('SinAlguna Especialidad')
            ->assertDontSee('ConAlguna Especialidad');
    }

    /* ------------------------------- la regla --------------------------- */

    public function test_la_regla_rechaza_una_especialidad_inexistente_y_no_duplica(): void
    {
        $r = $this->medico('Reglas');
        $esp = $this->especialidad('Traumatología');
        $cuentas = app(CuentaService::class);

        // Repetida en el formulario: una sola fila en el pivote.
        $cambios = $cuentas->asignarEspecialidades($r['medico'], [(string) $esp->id, (string) $esp->id, $esp->id]);
        // `catalogoInicial` es la copia automática del catálogo inicial (WEB-12.9): la cubre CatalogoInicialPorEspecialidadTest.
        $this->assertSame(['asignadas' => 1, 'quitadas' => 0, 'postgradosQuitados' => 0], Arr::except($cambios, 'catalogoInicial'));
        $this->assertSame(1, $r['medico']->specialties()->count());

        // Volver a guardar lo mismo no cambia nada (y la pantalla lo dice).
        $this->assertSame(['asignadas' => 0, 'quitadas' => 0, 'postgradosQuitados' => 0, 'catalogoInicial' => null], $cuentas->asignarEspecialidades($r['medico'], [$esp->id]));

        $this->actingAs($this->admin());
        Livewire::test(Especialidades::class)
            ->call('abrirAsignar', $r['medico']->id)
            ->set('seleccionadas', [$esp->id])
            ->call('guardarAsignacion')
            ->assertSee('Sin cambios:');

        try {
            $cuentas->asignarEspecialidades($r['medico'], [$esp->id, 99999]);
            $this->fail('Una especialidad que no existe debía rechazarse.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('especialidades', $e->errors());
        }

        $this->assertSame(1, $r['medico']->specialties()->count());
    }

    public function test_lo_que_asigna_el_panel_es_lo_que_lee_el_consultorio(): void
    {
        $r = $this->medico('Contexto');
        $activa = $this->especialidad('Cardiología');
        app(CuentaService::class)->asignarEspecialidades($r['medico'], [$activa->id]);

        $contexto = app(ContextoTrabajo::class)->disponibles($r['user']);
        $this->assertSame([$activa->id], $contexto[$r['medico']->reg_medico]['especialidades']->pluck('id')->all());

        // Una especialidad inactiva no llega al consultorio: es la misma vara de la web clínica.
        $activa->forceFill(['activo' => false])->save();
        $contexto = app(ContextoTrabajo::class)->disponibles($r['user']);
        $this->assertSame([], $contexto[$r['medico']->reg_medico]['especialidades']->pluck('id')->all());
    }

    public function test_las_acciones_vuelven_a_comprobar_el_permiso(): void
    {
        $r = $this->medico('Permiso');
        $panel = Livewire::actingAs($this->admin())->test(Especialidades::class);

        // Pierde el acceso a mitad de camino (lo bloquean): la siguiente acción ya no pasa.
        auth()->user()->forceFill(['is_active' => false])->save();
        $panel->call('abrirAsignar', $r['medico']->id)->assertForbidden();
    }

    /* --------------------------- postgrados (2026-10-19) ----------------- */

    /** Un catálogo mínimo con su postgrado, como lo siembra el manifiesto de pediatría. */
    private function postgrado(Specialty $especialidad, string $nombre): \App\Models\Subspecialty
    {
        return \App\Models\Subspecialty::create([
            'specialty_id' => $especialidad->id,
            'slug'         => 'prueba-' . substr(uniqid(), -8),
            'nombre'       => $nombre,
            'orden'        => 10,
            'activo'       => true,
        ]);
    }

    public function test_el_panel_asigna_y_quita_los_postgrados_de_las_especialidades_del_medico(): void
    {
        $r = $this->medico('Postgrados');
        $pediatria = $this->especialidad('Pediatría');
        $nefro = $this->postgrado($pediatria, 'Nefrología pediátrica');
        $neumo = $this->postgrado($pediatria, 'Neumología pediátrica');

        app(CuentaService::class)->asignarEspecialidades($r['medico'], [$pediatria->id]);
        $this->actingAs($this->admin());

        Livewire::test(Especialidades::class)
            ->call('abrirAsignar', $r['medico']->id)
            ->assertSee('Nefrología pediátrica')
            ->set('postgrados', [(string) $nefro->id, (string) $neumo->id])
            ->call('guardarAsignacion')
            ->assertHasNoErrors();

        $this->assertEqualsCanonicalizing([$nefro->id, $neumo->id], $this->idsDePostgrados($r['medico']));

        // Al reabrir vienen tildados y se puede dejar uno solo.
        $abierto = Livewire::test(Especialidades::class)->call('abrirAsignar', $r['medico']->id);
        $this->assertEqualsCanonicalizing([(string) $nefro->id, (string) $neumo->id], $abierto->get('postgrados'));

        $abierto->set('postgrados', [(string) $nefro->id])->call('guardarAsignacion')->assertHasNoErrors();
        $this->assertSame([$nefro->id], $this->idsDePostgrados($r['medico']));
    }

    public function test_un_postgrado_de_una_especialidad_que_el_medico_no_tiene_no_se_guarda(): void
    {
        $r = $this->medico('Ajeno');
        $pediatria = $this->especialidad('Pediatría');
        $cardiologia = $this->especialidad('Cardiología');
        $nefro = $this->postgrado($pediatria, 'Nefrología pediátrica');
        $ecoCardio = $this->postgrado($cardiologia, 'Ecocardiografía');

        app(CuentaService::class)->asignarEspecialidades($r['medico'], [$pediatria->id]);

        // El servicio ignora el que no cuelga de una especialidad del médico (el formulario manda todo junto).
        app(CuentaService::class)->asignarSubespecialidades($r['medico'], [$nefro->id, $ecoCardio->id]);

        $this->assertSame([$nefro->id], $this->idsDePostgrados($r['medico']));
    }

    public function test_quitar_la_especialidad_quita_sus_postgrados_y_lo_dice(): void
    {
        $r = $this->medico('Huerfano');
        $pediatria = $this->especialidad('Pediatría');
        $otra = $this->especialidad('Dermatología');
        $nefro = $this->postgrado($pediatria, 'Nefrología pediátrica');
        $cuentas = app(CuentaService::class);

        $cuentas->asignarEspecialidades($r['medico'], [$pediatria->id]);
        $cuentas->asignarSubespecialidades($r['medico'], [$nefro->id]);
        $this->assertSame([$nefro->id], $this->idsDePostgrados($r['medico']));

        $cambios = $cuentas->asignarEspecialidades($r['medico'], [$otra->id]);

        $this->assertSame(1, $cambios['postgradosQuitados']);
        $this->assertSame([], $this->idsDePostgrados($r['medico']));
    }

    /* ------------------------------------------------------------------ */

    /** Los ids del pivote del médico, como enteros y ordenados. */
    private function idsDelPivote(Medico $medico): array
    {
        return $medico->specialties()->pluck('specialties.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    /** Los ids de los postgrados del médico, como enteros y ordenados. */
    private function idsDePostgrados(Medico $medico): array
    {
        return $medico->subespecialidades()->pluck('subespecialidades.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }
}
