<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\SyncCarga;
use App\Models\SyncChange;
use App\Services\CuentaService;
use App\Services\SyncCredencialService;
use App\Services\SyncResumenService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Resumen de sincronización por médico (sección "API Keys" del panel): última sync del escritorio y del app,
 * carga inicial, API keys activas y conteos.
 *
 * `DatabaseTransactions` (ver MedicAPI/AGENTS.md). Los conteos se comparan contra la propia base en vez de
 * contra números fijos, porque la base de desarrollo trae datos reales.
 */
class SyncResumenTest extends TestCase
{
    use DatabaseTransactions;

    private function nuevoMedico(): array
    {
        $sufijo = uniqid();

        return app(CuentaService::class)->crearMedico([
            'name' => 'Resumen', 'lastname' => 'Prueba',
            'email' => "resumen-{$sufijo}@example.com", 'reg_medico' => "test-resumen-{$sufijo}",
        ]);
    }

    private function cambio(string $reg, string $source, string $creado): void
    {
        $c = SyncChange::create([
            'reg_medico' => $reg, 'table_name' => 'pacientes', 'record_id' => 1, 'operation' => 'created',
            'occurred_at' => $creado, 'source' => $source,
        ]);
        DB::table('sync_changes')->where('id', $c->id)->update(['created_at' => $creado]);
    }

    public function test_un_medico_sin_actividad_se_ve_vacio(): void
    {
        $r = $this->nuevoMedico();

        $x = app(SyncResumenService::class)->resumir(collect([$r['medico']]))[$r['medico']->id];

        $this->assertNull($x['ultima_escritorio']);
        $this->assertNull($x['ultima_app']);
        $this->assertNull($x['carga']);
        $this->assertSame(0, $x['activas']);
        $this->assertSame(0, $x['pacientes']);
        $this->assertSame(0, $x['historias']);
        $this->assertContains($r['medico']->reg_medico, $x['regs']);
    }

    public function test_la_ultima_sync_es_la_mas_reciente_de_cada_origen(): void
    {
        $r = $this->nuevoMedico();
        $reg = $r['medico']->reg_medico;

        // Escritorio: credencial (enero), carga inicial (febrero) y cambios subidos (marzo) -> manda marzo.
        $emitida = app(SyncCredencialService::class)->emitir($reg, 'EQUIPO-RESUMEN');
        $emitida['credencial']->forceFill(['last_used_at' => '2026-01-10 08:00:00'])->save();
        SyncCarga::create([
            'reg_medico' => $reg, 'medico_id' => $r['medico']->id, 'estado' => SyncCarga::COMPLETA,
            'iniciada_at' => '2026-02-01 09:00:00', 'finalizada_at' => '2026-02-01 09:30:00',
        ]);
        $this->cambio($reg, 'escritorio', '2026-03-05 10:00:00');
        // Un cambio del móvil NO cuenta como actividad del escritorio.
        $this->cambio($reg, 'mobile', '2026-06-01 10:00:00');

        // App: sesión (abril) y cambios del móvil (junio) -> manda junio.
        $r['user']->createToken('t')->accessToken->forceFill(['last_used_at' => '2026-04-02 12:00:00'])->save();

        $x = app(SyncResumenService::class)->resumir(collect([$r['medico']->fresh()]))[$r['medico']->id];

        $this->assertSame('2026-03-05 10:00:00', $x['ultima_escritorio']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-01 10:00:00', $x['ultima_app']->format('Y-m-d H:i:s'));
        $this->assertSame(SyncCarga::COMPLETA, $x['carga']->estado);
        $this->assertSame(1, $x['activas']);
    }

    public function test_una_credencial_revocada_no_cuenta_como_activa_pero_se_lista(): void
    {
        $r = $this->nuevoMedico();
        $servicio = app(SyncCredencialService::class);
        $emitida = $servicio->emitir($r['medico']->reg_medico, 'EQUIPO-A');
        $servicio->emitir($r['medico']->reg_medico, 'EQUIPO-B');
        $servicio->revocar($emitida['credencial']);

        $x = app(SyncResumenService::class)->resumir(collect([$r['medico']]))[$r['medico']->id];

        $this->assertCount(2, $x['credenciales']);
        $this->assertSame(1, $x['activas']);
    }

    public function test_los_conteos_coinciden_con_la_base(): void
    {
        $medicos = Medico::whereNotNull('reg_medico')->orderBy('id')->limit(6)->get();
        $this->assertNotEmpty($medicos);

        $resumen = app(SyncResumenService::class)->resumir($medicos);

        foreach ($medicos as $m) {
            $regs = $resumen[$m->id]['regs'];
            $this->assertSame(
                DB::table('medico_pacientes')->where('medico_id', $m->id)->count(),
                $resumen[$m->id]['pacientes'],
                "Pacientes de {$m->reg_medico}"
            );
            $this->assertSame(
                DB::table('historias')->whereIn('reg_medico', $regs)->count(),
                $resumen[$m->id]['historias'],
                "Historias de {$m->reg_medico}"
            );
        }
    }

    public function test_varios_medicos_a_la_vez_no_se_mezclan(): void
    {
        $a = $this->nuevoMedico();
        $b = $this->nuevoMedico();
        app(SyncCredencialService::class)->emitir($a['medico']->reg_medico, 'SOLO-A');
        $this->cambio($b['medico']->reg_medico, 'escritorio', '2026-03-05 10:00:00');

        $res = app(SyncResumenService::class)->resumir(collect([$a['medico'], $b['medico']]));

        $this->assertSame(1, $res[$a['medico']->id]['activas']);
        $this->assertSame(0, $res[$b['medico']->id]['activas']);
        $this->assertNull($res[$a['medico']->id]['ultima_escritorio']);
        $this->assertInstanceOf(Carbon::class, $res[$b['medico']->id]['ultima_escritorio']);
    }
}
