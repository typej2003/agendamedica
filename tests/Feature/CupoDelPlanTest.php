<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Plan;
use App\Models\RegMedicoServicio;
use App\Models\User;
use App\Services\CupoDelPlan;
use App\Services\ServicioService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Los cupos y los precios de un plan (2026-10-28): quién cuenta como médico, quién como secretaría,
 * cuándo se puede agregar, cuándo un plan no cubre al equipo y cuánto sale el plan por asiento.
 */
class CupoDelPlanTest extends TestCase
{
    use DatabaseTransactions;

    private function cupos(): CupoDelPlan
    {
        return app(CupoDelPlan::class);
    }

    private function doctor(string $reg, bool $centro = false): Medico
    {
        if ($centro) {
            return Medico::create([
                'name' => 'Centro', 'lastname' => 'Médico', 'reg_medico' => $reg, 'es_centro' => true,
            ]);
        }

        $user = User::create([
            'name' => 'Doctor ' . $reg, 'email' => $reg . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Doctor', 'lastname' => $reg,
            'email' => $user->email, 'password' => $user->password, 'reg_medico' => $reg,
        ]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        return $medico;
    }

    private function secretaria(string $reg, string $email): User
    {
        $user = User::create([
            'name' => 'Secretaria ' . $email, 'email' => $email,
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $user->id, 'reg_medico' => $reg]);

        return $user;
    }

    private function planDelEquipo(string $reg, string $codigo): void
    {
        app(ServicioService::class)->reemplazar($reg, Plan::porCodigo($codigo), 0, 'prueba');
    }

    public function test_el_anual_de_un_plan_fijo_y_de_uno_por_asiento_cobra_diez_meses(): void
    {
        $this->doctor('anual-a');
        $conteo = ['medicos' => 1, 'secretarias' => 0];

        $fijo = Plan::porCodigo('equipo-1');
        $this->assertSame(2000, $this->cupos()->precioUsdE2($fijo, $conteo, Plan::MENSUAL));
        $this->assertSame(20000, $this->cupos()->precioUsdE2($fijo, $conteo, Plan::ANUAL));
        $this->assertSame(4000, $this->cupos()->ahorroAnualUsdE2($fijo, $conteo));

        $asiento = Plan::porCodigo('equipo-por-asiento');
        $equipo = ['medicos' => 4, 'secretarias' => 1];
        $this->assertSame(4800, $this->cupos()->precioUsdE2($asiento, $equipo, Plan::MENSUAL));
        $this->assertSame(48000, $this->cupos()->precioUsdE2($asiento, $equipo, Plan::ANUAL));
        $this->assertSame(9600, $this->cupos()->ahorroAnualUsdE2($asiento, $equipo));
    }

    public function test_cuenta_la_ficha_duenia_y_los_vinculados_y_no_cuenta_los_centros(): void
    {
        $a = $this->doctor('equipo-a');
        $b = $this->doctor('equipo-b');
        $centro = $this->doctor('centro-c', true);

        MedicoRegistro::create(['medico_id' => $b->id, 'reg_medico' => 'equipo-a']);
        MedicoRegistro::create(['medico_id' => $b->id, 'reg_medico' => 'centro-c']);

        $this->assertSame(2, $this->cupos()->conteo('equipo-a')['medicos']);
        $this->assertSame(1, $this->cupos()->conteo('centro-c')['medicos'], 'El centro no cuenta como médico.');
        $this->assertSame(
            [$a->id, $b->id],
            $this->cupos()->medicosDelEquipo('equipo-a')->pluck('id')->sort()->values()->all(),
        );
    }

    public function test_la_secretaria_cuenta_solo_si_es_solo_secretaria(): void
    {
        $this->doctor('equipo-sec');
        $this->secretaria('equipo-sec', 'sec1@example.com');

        // Una cuenta con rol Secretaria pero con ficha de médico no cuenta como secretaría.
        $tambienMedico = User::create([
            'name' => 'También médico', 'email' => 'medica@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true,
        ]);
        $tambienMedico->assignRole('Secretaria');
        Medico::create([
            'user_id' => $tambienMedico->id, 'name' => 'Médica', 'lastname' => 'También',
            'email' => $tambienMedico->email, 'password' => $tambienMedico->password, 'reg_medico' => 'equipo-otra',
        ]);
        MedicoRegistro::create(['user_id' => $tambienMedico->id, 'reg_medico' => 'equipo-sec']);

        $this->assertSame(1, $this->cupos()->conteo('equipo-sec')['secretarias']);
    }

    public function test_con_el_plan_de_un_medico_no_se_puede_agregar_otro_y_con_el_de_dos_si(): void
    {
        $this->doctor('equipo-cupo');
        $this->planDelEquipo('equipo-cupo', 'equipo-1');

        $this->assertFalse($this->cupos()->puedeAgregarMedico('equipo-cupo'));
        $this->expectException(ValidationException::class);
        $this->cupos()->exigirCupoMedico('equipo-cupo');
    }

    public function test_el_plan_de_dos_medicos_deja_agregar_el_segundo(): void
    {
        $this->doctor('equipo-dos');
        $this->planDelEquipo('equipo-dos', 'equipo-2');

        $this->assertTrue($this->cupos()->puedeAgregarMedico('equipo-dos'));
        $this->cupos()->exigirCupoMedico('equipo-dos');
    }

    public function test_sin_servicio_no_hay_cupo_que_aplicar(): void
    {
        $this->doctor('equipo-sin');
        $this->secretaria('equipo-sin', 'sin@example.com');
        RegMedicoServicio::where('reg_medico', 'equipo-sin')->update(['estado' => RegMedicoServicio::CANCELADO]);

        $this->assertTrue($this->cupos()->puedeAgregarMedico('equipo-sin'));
        $this->assertTrue($this->cupos()->puedeAgregarSecretaria('equipo-sin'));
    }

    public function test_el_mismo_plan_se_puede_renovar_aunque_el_equipo_este_por_encima(): void
    {
        $a = $this->doctor('equipo-baja');
        $b = $this->doctor('equipo-baja-b');
        MedicoRegistro::create(['medico_id' => $b->id, 'reg_medico' => 'equipo-baja']);
        $this->planDelEquipo('equipo-baja', 'equipo-1');

        $this->assertFalse($this->cupos()->cabe(Plan::porCodigo('equipo-1'), 'equipo-baja'));

        // El mismo plan se permite (no se corta el servicio); uno más chico distinto, no.
        $this->cupos()->exigirQueCubra(Plan::porCodigo('equipo-1'), 'equipo-baja');

        $mini = Plan::create([
            'codigo' => 'mini-cupo-t', 'nombre' => 'Mini', 'frecuencia' => 'mensual', 'precio_usd' => 5,
            'restricciones' => ['max_medicos' => 1, 'max_secretarias' => 1],
        ]);

        $this->expectException(ValidationException::class);
        $this->cupos()->exigirQueCubra($mini, 'equipo-baja');
    }

    public function test_el_plan_sugerido_es_el_primero_que_cubre_al_equipo_mas_uno(): void
    {
        $this->doctor('equipo-sug');
        $this->secretaria('equipo-sug', 'sug@example.com');
        $this->planDelEquipo('equipo-sug', 'equipo-1');

        $this->assertSame('equipo-2', $this->cupos()->planSugerido('equipo-sug', CupoDelPlan::MEDICO)?->codigo);
        $this->assertSame('equipo-por-asiento', $this->cupos()->planSugerido('equipo-sug', CupoDelPlan::SECRETARIA)?->codigo);
    }

    public function test_el_plan_por_asiento_cobra_el_minimo_y_las_secretarias_extra(): void
    {
        $plan = Plan::porCodigo('equipo-por-asiento');

        $this->assertSame(4800, $this->cupos()->precioDesdeUsdE2($plan));
        $this->assertSame(4800, $this->cupos()->precioUsdE2($plan, ['medicos' => 4, 'secretarias' => 1]));
        $this->assertSame(6000, $this->cupos()->precioUsdE2($plan, ['medicos' => 4, 'secretarias' => 2]));
        $this->assertStringContainsString('5 asientos', (string) $this->cupos()->desglose($plan, ['medicos' => 4, 'secretarias' => 2]));
    }
}
