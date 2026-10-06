<?php

namespace Tests\Feature;

use App\Events\MedicoRegistrado;
use App\Listeners\OtorgarServicioDePrueba;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Plan;
use App\Models\RegMedicoServicio;
use App\Models\User;
use App\Services\ServicioService;
use App\Support\RestriccionesPlan;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Planes de servicio y vencimiento por `reg_medico`. Lo único que se bloquea al vencer es la sincronización
 * (402 `servicio_vencido`); después de la gracia. El escritorio sigue pudiendo preguntar el estado.
 */
class ServicioPlanesTest extends TestCase
{
    use DatabaseTransactions;

    private const API_KEY = 'MiClaveSecreta123!';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function servicio(): ServicioService
    {
        return app(ServicioService::class);
    }

    private function medico(string $reg, bool $autenticar = false): Medico
    {
        $user = User::create(['name' => 'Doc', 'email' => 'srv-' . uniqid() . '@example.com', 'password' => Hash::make('secreto123')]);
        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Doc', 'lastname' => 'Servicio', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $reg,
        ]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);
        if ($autenticar) {
            Sanctum::actingAs($user, ['*'], 'api');
        }

        return $medico;
    }

    /** Deja el servicio vigente de un reg con esa fecha de vencimiento. */
    private function vencerEl(string $reg, string $fecha): void
    {
        RegMedicoServicio::where('reg_medico', $reg)->update(['vence_el' => $fecha]);
    }

    private function escritorio(string $ruta, array $cuerpo)
    {
        return $this->postJson("/api/sync/{$ruta}", $cuerpo, ['X-API-KEY' => self::API_KEY]);
    }

    /* ----------------------------- catálogo ----------------------------- */

    public function test_la_migracion_deja_el_plan_de_prueba_como_default_y_el_de_powerbuilder(): void
    {
        $prueba = Plan::porDefecto();
        $this->assertSame('prueba', $prueba->codigo);
        $this->assertSame(0.0, (float) $prueba->precio_usd);
        $this->assertSame(1, $prueba->meses());

        $pb = Plan::porCodigo('powerbuilder');
        $this->assertSame(0.0, (float) $pb->precio_usd);
        $this->assertSame(12, $pb->meses());
        $this->assertFalse($pb->es_default);
    }

    public function test_solo_un_plan_es_el_default(): void
    {
        $nuevo = Plan::create(['codigo' => 'mensual-t', 'nombre' => 'Mensual', 'frecuencia' => 'mensual', 'precio_usd' => 19.99, 'es_default' => true]);

        $this->assertSame($nuevo->id, Plan::porDefecto()->id);
        $this->assertSame(1, Plan::where('es_default', true)->count());
        $this->assertFalse(Plan::porCodigo('prueba')->es_default);
    }

    public function test_el_ahorro_es_el_tachado_menos_el_precio(): void
    {
        $anual = new Plan(['frecuencia' => 'anual', 'precio_usd' => 199.99, 'precio_tachado_usd' => 239.98]);
        $this->assertSame(12, $anual->meses());
        $this->assertSame(39.99, $anual->ahorroUsd());

        $this->assertSame(0.0, (new Plan(['precio_usd' => 19.99]))->ahorroUsd());
        $this->assertSame(0.0, (new Plan(['precio_usd' => 50, 'precio_tachado_usd' => 40]))->ahorroUsd());
    }

    /* ---------------------- restricciones con defaults ------------------- */

    public function test_una_restriccion_que_falta_en_el_json_toma_su_valor_por_defecto(): void
    {
        $r = RestriccionesPlan::desde(['max_medicos' => 3]);
        $this->assertSame(3, $r->maxMedicos());
        $this->assertNull($r->maxHistoricoMeses()); // no estaba guardada: sin límite

        $this->assertNull(RestriccionesPlan::desde(null)->maxMedicos());
        $this->assertSame(['max_medicos' => null, 'max_historico_meses' => null], RestriccionesPlan::desde('')->toArray());
    }

    public function test_una_fila_vieja_sin_la_restriccion_nueva_sigue_funcionando(): void
    {
        // El JSON guardado solo trae una clave; la otra (agregada después a los defaults) se completa sola.
        DB::table('planes')->insert([
            'codigo' => 'viejo-t', 'nombre' => 'Viejo', 'frecuencia' => 'mensual', 'precio_usd' => 10,
            'restricciones' => json_encode(['max_medicos' => 2, 'algo_futuro' => true]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $plan = Plan::porCodigo('viejo-t');
        $this->assertSame(2, $plan->restricciones->maxMedicos());
        $this->assertNull($plan->restricciones->maxHistoricoMeses());
        $this->assertTrue($plan->restricciones->get('algo_futuro'));
    }

    public function test_una_columna_de_restricciones_vacia_se_lee_con_los_defaults(): void
    {
        $plan = Plan::create(['codigo' => 'sin-restr-t', 'nombre' => 'Sin', 'frecuencia' => 'mensual', 'precio_usd' => 5]);
        DB::table('planes')->where('id', $plan->id)->update(['restricciones' => null]);

        $this->assertNull(Plan::find($plan->id)->restricciones->maxMedicos());
    }

    /* ------------------- prueba gratis al registrarse -------------------- */

    public function test_un_medico_nuevo_recibe_el_plan_por_defecto_gratis_por_un_mes(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
        $reg = 'srv-nuevo-' . uniqid();
        $this->medico($reg);

        $s = RegMedicoServicio::where('reg_medico', $reg)->get();
        $this->assertCount(1, $s);
        $this->assertSame('registro', $s[0]->origen);
        $this->assertSame(0.0, (float) $s[0]->monto_usd);
        $this->assertSame('2026-10-05', $s[0]->inicia_el->toDateString());
        $this->assertSame('2026-11-05', $s[0]->vence_el->toDateString());
        $this->assertSame(Plan::porDefecto()->id, $s[0]->plan_id);
    }

    public function test_si_el_plan_por_defecto_es_de_pago_el_primer_mes_igual_es_gratis(): void
    {
        Plan::create(['codigo' => 'pago-t', 'nombre' => 'De pago', 'frecuencia' => 'anual', 'precio_usd' => 199.99, 'es_default' => true]);
        $reg = 'srv-pago-' . uniqid();
        $this->medico($reg);

        $s = RegMedicoServicio::where('reg_medico', $reg)->first();
        $this->assertSame('De pago', $s->plan_nombre);
        $this->assertSame(0.0, (float) $s->monto_usd);
        $this->assertSame(Carbon::today()->addMonthNoOverflow()->toDateString(), $s->vence_el->toDateString());
    }

    public function test_un_medico_con_reg_medico_vacio_no_recibe_nada_ni_falla(): void
    {
        $antes = RegMedicoServicio::count();

        // `medicos.reg_medico` es NOT NULL, pero el panel viejo puede dejarlo vacío.
        app(OtorgarServicioDePrueba::class)->handle(new MedicoRegistrado(new Medico(['reg_medico' => '  '])));

        $this->assertSame($antes, RegMedicoServicio::count());
    }

    public function test_el_servicio_copia_las_restricciones_del_plan_al_contratar(): void
    {
        $plan = Plan::create([
            'codigo' => 'limit-t', 'nombre' => 'Con límites', 'frecuencia' => 'mensual', 'precio_usd' => 19.99,
            'restricciones' => ['max_medicos' => 2, 'max_historico_meses' => 6],
        ]);
        $reg = 'srv-limit-' . uniqid();
        $s = $this->servicio()->renovar($reg, $plan);
        $plan->update(['restricciones' => ['max_medicos' => 10]]); // editar el plan no cambia lo ya contratado

        $this->assertSame(2, $s->fresh()->restricciones->maxMedicos());
        $this->assertSame(6, $s->fresh()->restricciones->maxHistoricoMeses());
        $this->assertSame(['max_medicos' => 2, 'max_historico_meses' => 6], $this->servicio()->estado($reg)['restricciones']);
    }

    /* ------------------------------ estados ------------------------------ */

    public function test_vigente_gracia_y_vencido_segun_la_fecha(): void
    {
        $reg = 'srv-est-' . uniqid();
        $this->medico($reg);
        $this->vencerEl($reg, '2026-10-10');

        $en = fn (string $dia) => $this->servicio()->estado($reg, Carbon::parse($dia));

        $this->assertSame('vigente', $en('2026-10-01')['estado']);
        $this->assertSame(9, $en('2026-10-01')['dias_restantes']);
        $this->assertSame('vigente', $en('2026-10-10')['estado']);   // el día del vencimiento todavía sirve
        $this->assertSame('gracia', $en('2026-10-11')['estado']);
        $this->assertTrue($en('2026-10-11')['permite_sync']);
        $this->assertSame('gracia', $en('2026-10-15')['estado']);    // 5 días de gracia
        $this->assertSame('2026-10-15', $en('2026-10-15')['gracia_hasta']);
        $this->assertSame('vencido', $en('2026-10-16')['estado']);
        $this->assertFalse($en('2026-10-16')['permite_sync']);
    }

    public function test_sin_ninguna_fila_es_sin_servicio_y_no_sincroniza(): void
    {
        $e = $this->servicio()->estado('srv-nadie-' . uniqid());

        $this->assertSame('sin_servicio', $e['estado']);
        $this->assertFalse($e['permite_sync']);
    }

    public function test_renovar_sigue_donde_termina_el_servicio_si_aun_no_vencio(): void
    {
        Carbon::setTestNow('2026-10-05');
        $reg = 'srv-ren-' . uniqid();
        $this->medico($reg); // prueba: vence 2026-11-05
        $anual = Plan::create(['codigo' => 'anual-t', 'nombre' => 'Anual', 'frecuencia' => 'anual', 'precio_usd' => 199.99, 'precio_tachado_usd' => 239.98]);

        $nuevo = $this->servicio()->renovar($reg, $anual);

        $this->assertSame('2026-11-05', $nuevo->inicia_el->toDateString());
        $this->assertSame('2027-11-05', $nuevo->vence_el->toDateString());
        $this->assertSame(199.99, (float) $nuevo->monto_usd);
        $this->assertSame('compra', $nuevo->origen);
        $this->assertSame('2027-11-05', $this->servicio()->estado($reg)['vence_el']);
    }

    public function test_renovar_un_servicio_ya_vencido_empieza_hoy(): void
    {
        Carbon::setTestNow('2026-10-05');
        $reg = 'srv-renv-' . uniqid();
        $this->medico($reg);
        $this->vencerEl($reg, '2026-08-01');
        $mensual = Plan::create(['codigo' => 'mens-t', 'nombre' => 'Mensual', 'frecuencia' => 'mensual', 'precio_usd' => 19.99]);

        $nuevo = $this->servicio()->renovar($reg, $mensual);

        $this->assertSame('2026-10-05', $nuevo->inicia_el->toDateString());
        $this->assertSame('2026-11-05', $nuevo->vence_el->toDateString());
        $this->assertSame('vigente', $this->servicio()->estado($reg)['estado']);
    }

    public function test_un_servicio_cancelado_no_cuenta(): void
    {
        $reg = 'srv-canc-' . uniqid();
        $this->medico($reg);
        RegMedicoServicio::where('reg_medico', $reg)->update(['estado' => 'cancelado']);

        $this->assertSame('sin_servicio', $this->servicio()->estado($reg)['estado']);
    }

    /* ------------------- año de cortesía de PowerBuilder ----------------- */

    public function test_la_primera_vez_que_el_escritorio_habla_recibe_un_anio_gratis_una_sola_vez(): void
    {
        Carbon::setTestNow('2026-10-05');
        $reg = 'srv-pb-' . uniqid();

        $r = $this->escritorio('cambios/estado', ['reg_medico' => $reg])->assertOk();
        $this->assertSame('vigente', $r->json('servicio_estado'));
        $this->assertSame('2027-10-05', $r->json('servicio_vence'));

        $this->escritorio('cambios/estado', ['reg_medico' => $reg])->assertOk();
        $filas = RegMedicoServicio::where('reg_medico', $reg)->get();
        $this->assertCount(1, $filas);
        $this->assertSame('powerbuilder', $filas[0]->origen);
        $this->assertSame(0.0, (float) $filas[0]->monto_usd);
    }

    public function test_un_medico_con_su_mes_de_prueba_tambien_recibe_el_anio_al_sincronizar_desde_el_escritorio(): void
    {
        Carbon::setTestNow('2026-10-05');
        $reg = 'srv-prpb-' . uniqid();
        $this->medico($reg); // prueba hasta 2026-11-05

        $r = $this->escritorio('cambios/estado', ['reg_medico' => $reg])->assertOk();

        $this->assertSame('2027-10-05', $r->json('servicio_vence'));
        $this->assertSame(2, RegMedicoServicio::where('reg_medico', $reg)->count());
    }

    public function test_el_anio_de_cortesia_no_se_vuelve_a_regalar_cuando_vence(): void
    {
        Carbon::setTestNow('2026-10-05');
        $reg = 'srv-pb2-' . uniqid();
        $this->escritorio('cambios/estado', ['reg_medico' => $reg])->assertOk();
        $this->vencerEl($reg, '2026-09-01');

        $r = $this->escritorio('cambios/estado', ['reg_medico' => $reg])->assertOk();

        $this->assertSame('vencido', $r->json('servicio_estado'));
        $this->assertSame(1, RegMedicoServicio::where('reg_medico', $reg)->count());
    }

    /* ------------------- bloqueo de la sincronización -------------------- */

    private function escritorioVencido(): string
    {
        $reg = 'srv-venc-' . uniqid();
        $this->escritorio('cambios/estado', ['reg_medico' => $reg])->assertOk(); // año de cortesía
        $this->vencerEl($reg, Carbon::today()->subDays(30)->toDateString());

        return $reg;
    }

    public function test_el_escritorio_con_servicio_vencido_no_puede_subir_cambios_pero_si_preguntar_el_estado(): void
    {
        $reg = $this->escritorioVencido();

        $this->escritorio('cambios/subir', ['reg_medico' => $reg, 'cambios' => []])
            ->assertStatus(402)
            ->assertJson(['ok' => false, 'error' => 'servicio_vencido', 'code' => 'servicio_vencido']);

        $this->escritorio('cambios/estado', ['reg_medico' => $reg])
            ->assertOk()
            ->assertJson(['servicio_estado' => 'vencido', 'reg_medico' => $reg]);
    }

    public function test_el_escritorio_con_servicio_vencido_no_puede_iniciar_ni_seguir_una_carga(): void
    {
        $reg = $this->escritorioVencido();

        $this->escritorio('carga-inicial/iniciar', ['reg_medico' => $reg, 'tablas' => ['pacientes' => 1]])
            ->assertStatus(402)->assertJson(['error' => 'servicio_vencido']);
    }

    public function test_en_gracia_el_escritorio_todavia_sincroniza(): void
    {
        $reg = $this->escritorioVencido();
        $this->vencerEl($reg, Carbon::today()->subDays(3)->toDateString());

        // 402 sería el bloqueo; sin carga inicial completa lo que contesta es el 409 de siempre.
        $this->escritorio('cambios/subir', ['reg_medico' => $reg, 'cambios' => []])
            ->assertStatus(409)->assertJson(['error' => 'sin_carga_inicial']);
    }

    public function test_el_app_con_servicio_vencido_no_sincroniza_y_el_aviso_dice_cuando_vencio(): void
    {
        $reg = 'srv-app-' . uniqid();
        $this->medico($reg, autenticar: true);
        $this->vencerEl($reg, Carbon::today()->subDays(30)->toDateString());

        $this->postJson('/api/app/sync-app-data', [])
            ->assertStatus(402)
            ->assertJson(['code' => 'servicio_vencido', 'vence_el' => Carbon::today()->subDays(30)->toDateString()]);
    }

    public function test_el_app_con_servicio_vigente_sincroniza_y_recibe_el_estado_del_servicio(): void
    {
        $reg = 'srv-app2-' . uniqid();
        $this->medico($reg, autenticar: true);

        $r = $this->postJson('/api/app/sync-app-data', [])->assertOk();

        $this->assertSame('vigente', $r->json('servicio.estado'));
        $this->assertSame(Carbon::today()->addMonthNoOverflow()->toDateString(), $r->json('servicio.vence_el'));
        $this->assertArrayHasKey('max_medicos', $r->json('servicio.restricciones'));
    }

    public function test_un_medico_del_app_que_quedo_sin_servicio_recibe_su_prueba_en_vez_de_quedar_bloqueado(): void
    {
        $reg = 'srv-app3-' . uniqid();
        $this->medico($reg, autenticar: true);
        RegMedicoServicio::where('reg_medico', $reg)->delete();

        $r = $this->postJson('/api/app/sync-app-data', [])->assertOk();

        $this->assertSame('vigente', $r->json('servicio.estado'));
        $this->assertSame(1, RegMedicoServicio::where('reg_medico', $reg)->count());
    }
}
