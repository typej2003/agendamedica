<?php

namespace Tests\Feature;

use App\Events\ActividadWebRegistrada;
use App\Listeners\GuardarUltimaActividadWeb;
use App\Services\CuentaService;
use App\Services\SyncResumenService;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Última actividad web de una cuenta (columna nueva de "API Keys", 2026-10-20): el request sólo dispara el
 * evento —limitado por cuenta— y la escritura la hace la cola.
 */
class ActividadWebTest extends TestCase
{
    use DatabaseTransactions;

    private function nuevoMedico(): array
    {
        $sufijo = substr(uniqid(), -6);

        return app(CuentaService::class)->crearMedico([
            'name' => 'Actividad', 'lastname' => 'Web',
            'email' => "actividad-{$sufijo}@example.com", 'reg_medico' => "t-act-{$sufijo}",
        ]);
    }

    private function actividadDe(int $userId): ?string
    {
        return DB::table('users')->where('id', $userId)->value('ultima_actividad_web_at');
    }

    public function test_el_request_dispara_el_evento_una_vez_por_intervalo_y_no_escribe(): void
    {
        Cache::flush();
        Event::fake([ActividadWebRegistrada::class]);
        $user = $this->nuevoMedico()['user'];

        $this->actingAs($user)->get('/cambiar-password');
        $this->actingAs($user)->get('/cambiar-password');

        Event::assertDispatchedTimes(ActividadWebRegistrada::class, 1);
        Event::assertDispatched(ActividadWebRegistrada::class, fn ($e) => $e->userId === $user->id);
        $this->assertNull($this->actividadDe($user->id));
    }

    public function test_sin_sesion_no_se_registra_nada(): void
    {
        Event::fake([ActividadWebRegistrada::class]);

        $this->get('/login');

        Event::assertNotDispatched(ActividadWebRegistrada::class);
    }

    public function test_el_latido_de_la_sesion_no_es_actividad(): void
    {
        Cache::flush();
        Event::fake([ActividadWebRegistrada::class]);
        $user = $this->nuevoMedico()['user'];

        $this->actingAs($user)->get(route('clinica.sesion'));

        Event::assertNotDispatched(ActividadWebRegistrada::class);
    }

    public function test_el_listener_va_a_la_cola(): void
    {
        Queue::fake();

        ActividadWebRegistrada::dispatch(1, '2026-10-20 10:00:00');

        Queue::assertPushed(CallQueuedListener::class, fn ($job) => $job->class === GuardarUltimaActividadWeb::class);
    }

    public function test_el_listener_solo_avanza_la_fecha(): void
    {
        $user = $this->nuevoMedico()['user'];
        $listener = new GuardarUltimaActividadWeb();

        $listener->handle(new ActividadWebRegistrada($user->id, '2026-10-20 10:00:00'));
        $this->assertSame('2026-10-20 10:00:00', $this->actividadDe($user->id));

        // Un job viejo procesado tarde no pisa la fecha más nueva.
        $listener->handle(new ActividadWebRegistrada($user->id, '2026-10-20 09:00:00'));
        $this->assertSame('2026-10-20 10:00:00', $this->actividadDe($user->id));

        $listener->handle(new ActividadWebRegistrada($user->id, '2026-10-20 11:30:00'));
        $this->assertSame('2026-10-20 11:30:00', $this->actividadDe($user->id));
    }

    public function test_el_resumen_de_api_keys_trae_la_actividad_web(): void
    {
        $r = $this->nuevoMedico();
        $resumen = app(SyncResumenService::class);

        $this->assertNull($resumen->resumir(collect([$r['medico']]))[$r['medico']->id]['ultima_web']);

        (new GuardarUltimaActividadWeb())->handle(new ActividadWebRegistrada($r['user']->id, '2026-10-20 08:15:00'));

        $x = $resumen->resumir(collect([$r['medico']->fresh()]))[$r['medico']->id];
        $this->assertSame('2026-10-20 08:15:00', $x['ultima_web']->toDateTimeString());
    }
}
