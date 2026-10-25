<?php

namespace Tests\Feature;

use App\Actions\Catalogos\SembrarReferenciaDePediatria;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **La referencia de pediatría** (WEB-12.0, base vacía de Alexander del 2026-10-25): las curvas de crecimiento y el catálogo inicial de vacunas con sus dosis y
 * tipos, desde `database/seeders/data/pediatria/` (`pediatria:sembrar-referencia`).
 */
class ReferenciaDePediatriaTest extends TestCase
{
    use DatabaseTransactions;

    private const TABLAS = ['percentil_nino', 'percentil_nina', 'tipo_vacuna', 'vacunas', 'vacunas_dosis'];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::TABLAS as $tabla) {
            DB::table($tabla)->whereNull('reg_medico')->delete();
        }
    }

    private function iniciales(string $tabla): int
    {
        return DB::table($tabla)->whereNull('reg_medico')->count();
    }

    public function test_carga_todo_en_vacio_sin_el_stock_ni_el_precio_de_las_vacunas(): void
    {
        $informe = app(SembrarReferenciaDePediatria::class)->ejecutar();

        $this->assertSame(25, $this->iniciales('percentil_nino'));
        $this->assertSame(25, $this->iniciales('percentil_nina'));
        $this->assertSame(3, $this->iniciales('tipo_vacuna'));
        $this->assertSame(30, $this->iniciales('vacunas'));
        $this->assertSame(84, $this->iniciales('vacunas_dosis'));
        $this->assertSame(30, $informe['vacunas']['cargadas']);

        $this->assertSame('BCG', DB::table('vacunas')->whereNull('reg_medico')->where('codi_vacuna', '01')->value('vacuna'));
        $this->assertSame(0, DB::table('vacunas')->whereNull('reg_medico')->where(fn ($q) => $q->whereNotNull('cantidad')->orWhereNotNull('preciov'))->count());
        $this->assertSame(25, DB::table('percentil_nino')->whereNull('reg_medico')->whereNotNull('imc_m')->count());

        // Cada dosis cita una vacuna que está.
        $codigos = DB::table('vacunas')->whereNull('reg_medico')->pluck('codi_vacuna')->map(fn ($c) => trim((string) $c))->all();
        $this->assertSame([], DB::table('vacunas_dosis')->whereNull('reg_medico')->whereNotIn('codi_vacuna', $codigos)->pluck('codi_vacuna')->all());
    }

    public function test_un_catalogo_de_vacunas_que_ya_existe_solo_se_cambia_con_reemplazar(): void
    {
        DB::table('vacunas')->insert(['reg_medico' => null, 'codi_vacuna' => '01', 'vacuna' => 'HEPATITIS A', 'edad_inicial_meses' => 12]);
        // Lo que el médico copió no cambia nunca.
        DB::table('vacunas')->insert(['reg_medico' => 'ped-ref', 'codi_vacuna' => '01', 'vacuna' => 'HEPATITIS A']);

        $sembrar = app(SembrarReferenciaDePediatria::class);

        $informe = $sembrar->ejecutar();
        $this->assertTrue($informe['vacunas']['saltada']);
        $this->assertSame(1, $this->iniciales('vacunas'));
        $this->assertSame(0, $this->iniciales('vacunas_dosis'));

        // Simular no escribe.
        $sembrar->ejecutar(true, true);
        $this->assertSame('HEPATITIS A', DB::table('vacunas')->whereNull('reg_medico')->value('vacuna'));

        $informe = $sembrar->ejecutar(true);
        $this->assertSame(1, $informe['vacunas']['quitadas']);
        $this->assertSame(30, $this->iniciales('vacunas'));
        $this->assertSame(84, $this->iniciales('vacunas_dosis'));
        $this->assertSame('BCG', DB::table('vacunas')->whereNull('reg_medico')->where('codi_vacuna', '01')->value('vacuna'));
        $this->assertSame('HEPATITIS A', DB::table('vacunas')->where('reg_medico', 'ped-ref')->value('vacuna'));
    }

    public function test_con_las_curvas_ya_cargadas_solo_completa_el_imc_vacio(): void
    {
        $sembrar = app(SembrarReferenciaDePediatria::class);
        $sembrar->ejecutar();

        DB::table('percentil_nino')->whereNull('reg_medico')->update(['imc_b' => null, 'imc_m' => null, 'imc_a' => null]);
        $editada = DB::table('percentil_nino')->whereNull('reg_medico')->orderBy('id')->first();
        DB::table('percentil_nino')->where('id', $editada->id)->update(['imc_m' => 99.5]);

        $informe = $sembrar->ejecutar();

        $this->assertSame(24, $informe['percentil_nino']['completadas']);
        $this->assertSame(0, $informe['percentil_nino']['cargadas']);
        $this->assertSame(25, $this->iniciales('percentil_nino'));
        $this->assertSame(99.5, (float) DB::table('percentil_nino')->where('id', $editada->id)->value('imc_m'));
        $this->assertSame(0, $informe['percentil_nina']['completadas']);
    }

    public function test_el_comando_informa(): void
    {
        $this->artisan('pediatria:sembrar-referencia', ['--simular' => true])->assertExitCode(0);
        $this->assertSame(0, $this->iniciales('vacunas'));

        $this->artisan('pediatria:sembrar-referencia')->assertExitCode(0);
        $this->assertSame(30, $this->iniciales('vacunas'));
    }
}
