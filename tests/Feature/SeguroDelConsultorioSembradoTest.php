<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **Una base de desarrollo nueva nace con seguros** (O-60, 2026-11-09): la base de la suite se arma con
 * `migrate:fresh --seed` (`tests/bootstrap.php`), así que este test mira el resultado del `DatabaseSeeder`
 * completo y comprueba que `MedicalDataSeeder` deja el «Personal» en cada consultorio que siembra, igual que la
 * migración al desplegar y el alta del médico al crear la cuenta.
 *
 * Antes de esto había que hacer un `INSERT` a mano: la base nueva quedaba sin seguros y el alta de un paciente
 * se encontraba «— Sin seguro —» con el aviso de que el consultorio todavía no tenía seguros cargados.
 */
class SeguroDelConsultorioSembradoTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_base_sembrada_deja_el_seguro_personal_en_cada_consultorio(): void
    {
        $registros = DB::table('medicos')->pluck('reg_medico');

        $this->assertNotEmpty($registros, 'el seeder no dejó médicos');

        foreach ($registros as $registro) {
            $this->assertSame(
                1,
                DB::table('seg_emp')->where('reg_medico', $registro)->count(),
                "el consultorio {$registro} quedó sin seguros",
            );
        }

        $this->assertSame(
            ['PERSONAL'],
            DB::table('seg_emp')->distinct()->pluck('nombre')->all(),
            'todos los consultorios estrenan el mismo «Personal»',
        );
    }

    /**
     * El seeder pasa dos veces por el mismo consultorio (lo crea y después lo usa): el seguro tiene que quedar
     * **una sola vez**, porque la Action es idempotente y el `reg_medico` se repite en el alta del médico.
     */
    public function test_el_seguro_no_se_duplica_si_el_consultorio_se_repite(): void
    {
        $this->assertSame(
            DB::table('medicos')->distinct()->count('reg_medico'),
            DB::table('seg_emp')->count(),
            'hay tantos seguros como consultorios, sin repetir',
        );
    }
}
