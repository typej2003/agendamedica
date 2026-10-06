<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\Servicios;
use App\Models\Medico;
use App\Models\Plan;
use App\Models\RegMedicoServicio;
use App\Models\User;
use App\Services\CuentaService;
use App\Services\ServicioService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Sección "Planes y servicios" del panel (`/admin/servicios`, componente Livewire `Admin\Servicios`).
 * La lógica de fondo (estados, renovar, bloqueo de la sync) está en ServicioPlanesTest.
 */
class ServiciosPanelTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        $user = app(CuentaService::class)->crearAdministrador('Admin Serv', 'admin-serv-' . uniqid() . '@example.com')['user'];
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }

    /** Un médico con su mes de prueba (lo da el evento al crearse). */
    private function medico(string $nombre = 'Doctor'): Medico
    {
        $sufijo = uniqid();

        return app(CuentaService::class)->crearMedico([
            'name' => $nombre, 'lastname' => 'Servicio', 'email' => "serv-{$sufijo}@example.com", 'reg_medico' => "test-serv-{$sufijo}",
        ])['medico'];
    }

    private function vencerEl(Medico $medico, string $fecha): void
    {
        RegMedicoServicio::where('reg_medico', $medico->reg_medico)->update(['vence_el' => $fecha]);
    }

    private function plan(array $extra = []): Plan
    {
        return Plan::create($extra + ['codigo' => 'p-' . uniqid(), 'nombre' => 'Plan prueba', 'frecuencia' => 'mensual', 'precio_usd' => 19.99]);
    }

    /* ------------------------------ acceso ------------------------------ */

    public function test_acceso_solo_para_administradores_y_enlace_en_el_panel(): void
    {
        $this->get('/admin/servicios')->assertRedirect('/login');

        $comun = app(CuentaService::class)->crearMedico([
            'name' => 'Comun', 'lastname' => 'Doc', 'email' => 'comun-' . uniqid() . '@example.com', 'reg_medico' => 'test-c-' . uniqid(),
        ])['user'];
        $comun->forceFill(['must_change_password' => false])->save();
        $this->actingAs($comun)->get('/admin/servicios')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->admin());
        $this->get('/admin/servicios')->assertOk()->assertSee('Planes y servicios');
        Livewire::test('layouts.aside')->assertSee(route('admin.servicios'));
    }

    /* ------------------------ servicios por médico ----------------------- */

    public function test_lista_cada_medico_con_su_plan_vencimiento_y_estado(): void
    {
        Carbon::setTestNow('2026-10-05');
        $medico = $this->medico('Vigente');
        $this->actingAs($this->admin());

        Livewire::test(Servicios::class)
            ->set('search', $medico->reg_medico)
            ->assertSee('Vigente Servicio')
            ->assertSee('Prueba gratuita')
            ->assertSee('05/11/2026')
            ->assertSee('Vigente')
            ->assertDontSee('Vence en'); // faltan 31 días: todavía no es "por vencer"
    }

    public function test_los_estados_se_distinguen_y_los_contadores_filtran(): void
    {
        Carbon::setTestNow('2026-10-05');
        $porVencer = $this->medico('PorVencer');
        $this->vencerEl($porVencer, '2026-10-20');
        $gracia = $this->medico('EnGracia');
        $this->vencerEl($gracia, '2026-10-02');
        $vencido = $this->medico('Vencido');
        $this->vencerEl($vencido, '2026-08-01');
        $sin = $this->medico('SinServicio');
        RegMedicoServicio::where('reg_medico', $sin->reg_medico)->delete();
        $this->actingAs($this->admin());

        $panel = Livewire::test(Servicios::class)->set('search', 'Servicio');

        $panel->call('filtrar', 'por_vencer')->assertSee('PorVencer')->assertDontSee('EnGracia')->assertDontSee('Vencido Servicio')->assertSee('Vence en 15 días');
        $panel->call('filtrar', 'gracia')->assertSee('EnGracia')->assertSee('Sincroniza hasta el 07/10/2026')->assertDontSee('PorVencer');
        $panel->call('filtrar', 'vencido')->assertSee('Vencido Servicio')->assertSee('Sin sincronizar')->assertDontSee('EnGracia');
        $panel->call('filtrar', 'sin_servicio')->assertSee('SinServicio')->assertSee('Sin servicio')->assertDontSee('Vencido Servicio');
        $panel->call('filtrar', 'todos')->assertSee('PorVencer')->assertSee('SinServicio');
        $panel->call('filtrar', 'cualquier-cosa')->assertSet('filtro', 'todos');
    }

    public function test_renovar_sigue_donde_termina_el_actual_y_deja_el_historial(): void
    {
        Carbon::setTestNow('2026-10-05');
        $medico = $this->medico();
        $anual = $this->plan(['frecuencia' => 'anual', 'precio_usd' => 199.99, 'precio_tachado_usd' => 239.98, 'nombre' => 'Anual 12']);
        $this->actingAs($this->admin());

        Livewire::test(Servicios::class)
            ->call('abrirRenovar', $medico->id)
            ->assertSet('modal', 'renovar')
            ->assertSee('05/11/2026 (cuando termina el actual)')
            ->set('planElegido', $anual->id)
            ->assertSet('meses', 12)->assertSet('monto', 199.99) // el plan llena meses y monto
            ->set('meses', 14)                                    // paga 12, disfruta 14
            ->set('nota', 'Transferencia 1234')
            ->call('renovar')
            ->assertHasNoErrors()
            ->assertSet('modal', null);

        $nuevo = RegMedicoServicio::where('reg_medico', $medico->reg_medico)->orderByDesc('id')->first();
        $this->assertSame('compra', $nuevo->origen);
        $this->assertSame('Anual 12', $nuevo->plan_nombre);
        $this->assertSame('2026-11-05', $nuevo->inicia_el->toDateString());
        $this->assertSame('2028-01-05', $nuevo->vence_el->toDateString());
        $this->assertSame(199.99, (float) $nuevo->monto_usd);
        $this->assertSame('Transferencia 1234', $nuevo->nota);
        $this->assertSame('2028-01-05', app(ServicioService::class)->estado($medico->reg_medico)['vence_el']);

        Livewire::test(Servicios::class)->call('verHistorial', $medico->id)
            ->assertSee('Transferencia 1234')->assertSee('Compra')->assertSee('Prueba al registrarse');
    }

    public function test_renovar_con_monto_cero_es_cortesia_y_un_vencido_empieza_hoy(): void
    {
        Carbon::setTestNow('2026-10-05');
        $medico = $this->medico();
        $this->vencerEl($medico, '2026-08-01');
        $this->actingAs($this->admin());

        Livewire::test(Servicios::class)
            ->call('abrirRenovar', $medico->id)
            ->assertSee('El nuevo período empieza')->assertSee('hoy')
            ->set('monto', 0)->set('meses', 3)
            ->call('renovar')->assertHasNoErrors();

        $nuevo = RegMedicoServicio::where('reg_medico', $medico->reg_medico)->orderByDesc('id')->first();
        $this->assertSame('manual', $nuevo->origen);
        $this->assertSame('2026-10-05', $nuevo->inicia_el->toDateString());
        $this->assertSame('2027-01-05', $nuevo->vence_el->toDateString());
    }

    public function test_renovar_valida_los_datos(): void
    {
        $medico = $this->medico();
        $this->actingAs($this->admin());

        Livewire::test(Servicios::class)
            ->call('abrirRenovar', $medico->id)
            ->set('planElegido', 999999)->set('meses', 0)->set('monto', -5) // el plan primero: al elegirlo llena meses y monto
            ->call('renovar')
            ->assertHasErrors(['meses', 'monto', 'planElegido'])
            ->assertSet('modal', 'renovar');

        $this->assertSame(1, RegMedicoServicio::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_no_se_puede_renovar_con_un_plan_inactivo(): void
    {
        $medico = $this->medico();
        $inactivo = $this->plan(['activo' => false]);
        $this->actingAs($this->admin());

        Livewire::test(Servicios::class)
            ->call('abrirRenovar', $medico->id)
            ->set('planElegido', $inactivo->id)
            ->call('renovar')
            ->assertHasErrors(['planElegido']);
    }

    public function test_cancelar_un_periodo_lo_saca_del_vencimiento(): void
    {
        Carbon::setTestNow('2026-10-05');
        $medico = $this->medico();
        $extra = app(ServicioService::class)->renovar($medico->reg_medico, $this->plan(['frecuencia' => 'anual']));
        $this->assertSame('2027-11-05', app(ServicioService::class)->estado($medico->reg_medico)['vence_el']);
        $this->actingAs($this->admin());

        Livewire::test(Servicios::class)
            ->call('verHistorial', $medico->id)
            ->call('cancelarServicio', $extra->id);

        $this->assertSame('cancelado', $extra->fresh()->estado);
        $this->assertStringContainsString('Cancelado', $extra->fresh()->nota);
        $this->assertSame('2026-11-05', app(ServicioService::class)->estado($medico->reg_medico)['vence_el']);
    }

    public function test_no_se_cancela_el_servicio_de_otro_medico_desde_la_ventana(): void
    {
        $uno = $this->medico('Uno');
        $otro = $this->medico('Otro');
        $ajeno = RegMedicoServicio::where('reg_medico', $otro->reg_medico)->first();
        $this->actingAs($this->admin());

        Livewire::test(Servicios::class)->call('verHistorial', $uno->id)->call('cancelarServicio', $ajeno->id)->assertForbidden();

        $this->assertSame('activo', $ajeno->fresh()->estado);
    }

    /* ---------------- modificar: renovar / reemplazar / cancelar ---------------- */

    public function test_modificar_ofrece_renovar_reemplazar_y_quitar_plan_en_ese_orden_junto_al_historial(): void
    {
        $medico = $this->medico('ConMenu');
        $this->actingAs($this->admin());

        $panel = Livewire::test(Servicios::class)->set('search', $medico->reg_medico)->assertSee('Modificar')->assertSee('Historial');
        $html = $panel->payload['effects']['html'];

        $this->assertLessThan(strpos($html, 'Reemplazar'), strpos($html, 'Renovar'));
        $this->assertLessThan(strpos($html, 'Quitar plan'), strpos($html, 'Reemplazar'));
        $this->assertStringNotContainsString('Deshacer', $html);
    }

    public function test_sin_servicio_reemplazar_y_quitar_plan_aparecen_deshabilitados(): void
    {
        $medico = $this->medico('SinNada');
        RegMedicoServicio::where('reg_medico', $medico->reg_medico)->delete();
        $this->actingAs($this->admin());

        Livewire::test(Servicios::class)->set('search', $medico->reg_medico)
            ->assertSee('No tiene servicio que reemplazar')
            ->assertSee('Ya no tiene servicio');
    }

    public function test_reemplazar_empieza_hoy_sin_conservar_el_tiempo_y_deja_quien_cuando_y_por_que(): void
    {
        Carbon::setTestNow('2026-10-05');
        $medico = $this->medico();
        $viejo = RegMedicoServicio::where('reg_medico', $medico->reg_medico)->first(); // prueba: hasta 05/11
        $anual = $this->plan(['frecuencia' => 'anual', 'precio_usd' => 120, 'nombre' => 'Anual 12']);
        $admin = $this->admin();
        $this->actingAs($admin);

        Livewire::test(Servicios::class)
            ->call('abrirReemplazar', $medico->id)
            ->assertSet('modal', 'reemplazar')
            ->assertSee('no conserva el tiempo')
            ->set('planElegido', $anual->id)
            ->assertSet('meses', 12)->assertSet('monto', 120.0)
            ->set('motivo', 'Plan equivocado')
            ->call('reemplazar')
            ->assertHasNoErrors()
            ->assertSet('modal', null);

        $this->assertSame('cancelado', $viejo->fresh()->estado);
        $this->assertStringContainsString('Plan equivocado', $viejo->fresh()->nota);
        $this->assertStringContainsString('por Admin Serv', $viejo->fresh()->nota);
        $this->assertStringContainsString('05/10/2026', $viejo->fresh()->nota);

        $nuevo = app(ServicioService::class)->actual($medico->reg_medico);
        $this->assertSame('Anual 12', $nuevo->plan_nombre);
        $this->assertSame('compra', $nuevo->origen);
        $this->assertSame('2026-10-05', $nuevo->inicia_el->toDateString()); // hoy, no el 05/11
        $this->assertSame('2027-10-05', $nuevo->vence_el->toDateString()); // 12 meses desde hoy: el tiempo viejo no se suma
        $this->assertStringContainsString('Plan equivocado', $nuevo->nota);
        $this->assertSame(2, RegMedicoServicio::where('reg_medico', $medico->reg_medico)->count()); // nada se borra
    }

    public function test_reemplazar_cancela_todos_los_periodos_activos_no_solo_el_vigente(): void
    {
        Carbon::setTestNow('2026-10-05');
        $medico = $this->medico();
        app(ServicioService::class)->renovar($medico->reg_medico, $this->plan(['frecuencia' => 'anual'])); // renovó por adelantado
        $plan = $this->plan(['nombre' => 'Mensual nuevo']);
        $this->actingAs($this->admin());

        Livewire::test(Servicios::class)
            ->call('abrirReemplazar', $medico->id)
            ->set('planElegido', $plan->id)->set('motivo', 'Fraude')
            ->call('reemplazar')->assertHasNoErrors();

        $this->assertSame(2, RegMedicoServicio::where('reg_medico', $medico->reg_medico)->where('estado', 'cancelado')->count());
        $this->assertSame('2026-11-05', app(ServicioService::class)->estado($medico->reg_medico)['vence_el']);
    }

    public function test_reemplazar_con_monto_cero_es_cortesia_y_exige_motivo_y_datos_validos(): void
    {
        Carbon::setTestNow('2026-10-05');
        $medico = $this->medico();
        $plan = $this->plan();
        $this->actingAs($this->admin());

        $panel = Livewire::test(Servicios::class)->call('abrirReemplazar', $medico->id)->set('planElegido', $plan->id);

        $panel->set('motivo', '')->call('reemplazar')->assertHasErrors(['motivo'])->assertSet('modal', 'reemplazar');
        $panel->set('motivo', 'ab')->call('reemplazar')->assertHasErrors(['motivo']);
        $panel->set('motivo', 'Cortesía')->set('meses', 0)->set('monto', -1)->call('reemplazar')->assertHasErrors(['meses', 'monto']);
        $this->assertSame('activo', app(ServicioService::class)->actual($medico->reg_medico)->estado); // no cambió nada

        $panel->set('meses', 2)->set('monto', 0)->call('reemplazar')->assertHasNoErrors();
        $this->assertSame('manual', app(ServicioService::class)->actual($medico->reg_medico)->origen);
    }

    public function test_quitar_plan_quita_todo_el_servicio_y_deja_de_sincronizar_sin_regalar_otra_prueba(): void
    {
        Carbon::setTestNow('2026-10-05');
        $medico = $this->medico();
        $servicios = app(ServicioService::class);
        $servicios->renovar($medico->reg_medico, $this->plan(['frecuencia' => 'anual']));
        $this->actingAs($this->admin());

        Livewire::test(Servicios::class)
            ->call('abrirQuitar', $medico->id)
            ->assertSet('modal', 'quitar')
            ->assertSee('deja de sincronizar ahora mismo')
            ->set('motivo', 'Pago revertido')
            ->call('quitarPlan')
            ->assertHasNoErrors()
            ->assertSet('modal', null);

        $estado = $servicios->estado($medico->reg_medico);
        $this->assertSame('sin_servicio', $estado['estado']);
        $this->assertFalse($estado['permite_sync']);
        $this->assertSame(0, RegMedicoServicio::where('reg_medico', $medico->reg_medico)->where('estado', 'activo')->count());
        $this->assertSame(2, RegMedicoServicio::where('reg_medico', $medico->reg_medico)->count()); // el historial queda
        $this->assertNull($servicios->otorgarPrueba($medico->reg_medico)); // no se le vuelve a regalar un mes

        Livewire::test(Servicios::class)->call('verHistorial', $medico->id)
            ->assertSee('Pago revertido')->assertSee('por Admin Serv')->assertSee('Cancelado');
    }

    public function test_quitar_plan_exige_motivo_y_no_toca_a_otros_medicos(): void
    {
        $uno = $this->medico('Uno');
        $otro = $this->medico('Otro');
        $this->actingAs($this->admin());

        Livewire::test(Servicios::class)->call('abrirQuitar', $uno->id)->call('quitarPlan')->assertHasErrors(['motivo'])->assertSet('modal', 'quitar');
        $this->assertSame('activo', app(ServicioService::class)->actual($uno->reg_medico)->estado);

        Livewire::test(Servicios::class)->call('abrirQuitar', $uno->id)->set('motivo', 'Incidencia')->call('quitarPlan');
        $this->assertNotNull(app(ServicioService::class)->actual($otro->reg_medico));
    }

    public function test_reemplazar_o_quitar_plan_sin_servicio_avisa_y_no_abre_la_ventana(): void
    {
        $medico = $this->medico();
        RegMedicoServicio::where('reg_medico', $medico->reg_medico)->delete();
        $this->actingAs($this->admin());

        Livewire::test(Servicios::class)->call('abrirReemplazar', $medico->id)->assertSet('modal', null)->assertSee('usa Renovar');
        Livewire::test(Servicios::class)->call('abrirQuitar', $medico->id)->assertSet('modal', null)->assertSee('ya no tiene servicio');
    }

    public function test_las_acciones_nuevas_tambien_vuelven_a_comprobar_el_permiso(): void
    {
        $medico = $this->medico();
        $panel = Livewire::actingAs($this->admin())->test(Servicios::class);

        auth()->user()->forceFill(['is_active' => false])->save();
        $panel->call('abrirQuitar', $medico->id)->assertForbidden();
        $this->assertSame('activo', app(ServicioService::class)->actual($medico->reg_medico)->estado);
    }

    /* -------------------------------- planes ------------------------------ */

    public function test_crear_un_plan_con_tachado_muestra_el_ahorro(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Servicios::class)
            ->call('cambiarPestana', 'planes')
            ->call('nuevoPlan')
            ->set('nombre', 'Anual')->set('codigo', 'anual-12')->set('frecuencia', 'anual')
            ->set('precio_usd', '199.99')->set('precio_tachado_usd', '239.98')
            ->assertSee('ahorro de USD 39,99')
            ->call('guardarPlan')
            ->assertHasNoErrors()
            ->assertSet('modal', null)
            ->assertSee('Anual (12 meses)')->assertSee('199,99')->assertSee('239,98');

        $plan = Plan::porCodigo('anual-12');
        $this->assertSame('anual', $plan->frecuencia);
        $this->assertSame(39.99, $plan->ahorroUsd());
        $this->assertFalse($plan->es_default);
        // Sin restricciones editables todavía: quedan las de por defecto (sin límite).
        $this->assertNull($plan->restricciones->maxMedicos());
    }

    public function test_el_codigo_es_unico_y_sin_espacios_ni_mayusculas(): void
    {
        $this->plan(['codigo' => 'repetido']);
        $this->actingAs($this->admin());

        $panel = Livewire::test(Servicios::class)->call('cambiarPestana', 'planes')->call('nuevoPlan')
            ->set('nombre', 'X')->set('precio_usd', '1');

        $panel->set('codigo', 'repetido')->call('guardarPlan')->assertHasErrors(['codigo']);
        $panel->set('codigo', 'Con Espacio')->call('guardarPlan')->assertHasErrors(['codigo']);
        $panel->set('codigo', 'valido-1')->call('guardarPlan')->assertHasNoErrors();
    }

    public function test_un_plan_se_edita_sin_perder_su_codigo_unico(): void
    {
        $plan = $this->plan(['codigo' => 'editable', 'nombre' => 'Viejo']);
        $this->actingAs($this->admin());

        Livewire::test(Servicios::class)->call('cambiarPestana', 'planes')
            ->call('editarPlan', $plan->id)
            ->assertSet('nombre', 'Viejo')
            ->set('nombre', 'Nuevo')->set('precio_usd', '25.5')->set('precio_tachado_usd', '')
            ->call('guardarPlan')->assertHasNoErrors();

        $plan->refresh();
        $this->assertSame('Nuevo', $plan->nombre);
        $this->assertSame(25.5, (float) $plan->precio_usd);
        $this->assertNull($plan->precio_tachado_usd);
    }

    public function test_marcar_otro_plan_como_predeterminado_desmarca_al_anterior(): void
    {
        $anterior = Plan::porDefecto();
        $nuevo = $this->plan();
        $this->actingAs($this->admin());

        Livewire::test(Servicios::class)->call('cambiarPestana', 'planes')
            ->call('editarPlan', $nuevo->id)->set('es_default', true)
            ->call('guardarPlan')->assertHasNoErrors();

        $this->assertSame($nuevo->id, Plan::porDefecto()->id);
        $this->assertFalse($anterior->fresh()->es_default);
        $this->assertSame(1, Plan::where('es_default', true)->count());
    }

    public function test_el_plan_predeterminado_no_se_puede_desmarcar_ni_desactivar(): void
    {
        $default = Plan::porDefecto();
        $this->actingAs($this->admin());

        $panel = Livewire::test(Servicios::class)->call('cambiarPestana', 'planes')
            ->call('editarPlan', $default->id)->set('es_default', false)
            ->call('guardarPlan')->assertHasErrors(['es_default']);
        $this->assertTrue($default->fresh()->es_default);

        $panel->call('editarPlan', $default->id)->set('activo', false)->call('guardarPlan')->assertHasErrors();
        $panel->call('alternarActivo', $default->id);
        $this->assertTrue($default->fresh()->activo);
    }

    public function test_un_plan_nuevo_inactivo_no_puede_ser_el_predeterminado(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Servicios::class)->call('cambiarPestana', 'planes')->call('nuevoPlan')
            ->set('nombre', 'X')->set('codigo', 'x-inactivo')->set('precio_usd', '1')
            ->set('activo', false)->set('es_default', true)
            ->call('guardarPlan')->assertHasErrors(['activo']);

        $this->assertNull(Plan::porCodigo('x-inactivo'));
    }

    public function test_activar_y_desactivar_un_plan_y_los_contratos_que_tiene(): void
    {
        $plan = $this->plan(['nombre' => 'Alternable']);
        app(ServicioService::class)->renovar('test-alt-' . uniqid(), $plan);
        $this->actingAs($this->admin());

        $panel = Livewire::test(Servicios::class)->call('cambiarPestana', 'planes')->assertSee('Alternable');
        $panel->call('alternarActivo', $plan->id);
        $this->assertFalse($plan->fresh()->activo);
        $panel->call('alternarActivo', $plan->id);
        $this->assertTrue($plan->fresh()->activo);

        // El plan desactivado deja de ofrecerse al renovar, pero lo contratado sigue (el servicio copia lo suyo).
        $this->assertSame(1, RegMedicoServicio::where('plan_id', $plan->id)->count());
    }

    public function test_las_acciones_vuelven_a_comprobar_el_permiso(): void
    {
        $panel = Livewire::actingAs($this->admin())->test(Servicios::class);

        // Pierde el acceso a mitad de camino (lo bloquean): la siguiente acción ya no pasa.
        auth()->user()->forceFill(['is_active' => false])->save();
        $panel->call('nuevoPlan')->assertForbidden();
    }
}
