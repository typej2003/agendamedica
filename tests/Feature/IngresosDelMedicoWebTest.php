<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Pago;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **Mis ingresos** (O-53): los pagos de cita del médico con sus estados, sus totales y el filtro.
 *
 * Es la pantalla del médico, no la del administrador: sólo ve sus pagos y los de sus citas.
 */
class IngresosDelMedicoWebTest extends TestCase
{
    use DatabaseTransactions;

    private Medico $medico;

    private Specialty $gineco;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EspecialidadesYModulosSeeder::class);

        $reg = 'ingresos-test-' . substr(uniqid(), -6);

        $this->user = User::create([
            'name' => 'Doctora de ingresos', 'email' => "{$reg}@example.com",
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $this->user->assignRole('Medico');

        $this->medico = Medico::create([
            'user_id' => $this->user->id, 'name' => 'Doctora', 'lastname' => 'De Ingresos',
            'email' => "{$reg}@example.com", 'password' => $this->user->password, 'reg_medico' => $reg,
        ]);

        $this->gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $this->medico->specialties()->attach($this->gineco->id);
        MedicoRegistro::create(['medico_id' => $this->medico->id, 'reg_medico' => $reg]);
    }

    private function conContexto(): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $this->medico->reg_medico,
            'specialty_id' => $this->gineco->id,
            'office_id' => null,
        ]]);
    }

    private function cita(string $regMedico): Cola
    {
        return Cola::create([
            'reg_medico' => $regMedico,
            'fecha' => now()->toDateString(),
            'hora_ini' => '09:00:00',
            'numorden' => 1,
            'atendido' => 0,
            'estado' => 0,
            'monto' => 100,
        ]);
    }

    private function pago(Cola $cita, string $estado, int $montoE2, array $extra = []): Pago
    {
        return Pago::create(array_merge([
            'reg_medico' => $cita->reg_medico,
            'beneficiario_tipo' => Pago::BENEFICIARIO_MEDICO,
            'beneficiario_reg_medico' => $cita->reg_medico,
            'recaudador' => Pago::RECAUDADOR_MEDICO,
            'canal' => Pago::CANAL_MOSTRADOR,
            'pagable_tipo' => Pago::PAGABLE_CITA,
            'pagable_id' => $cita->id,
            'concepto' => 'Consulta de prueba',
            'moneda' => 'USD',
            'monto_e2' => $montoE2,
            'monto_usd_e2' => $montoE2,
            'neto_usd_e2' => $montoE2,
            'estado' => $estado,
        ], $extra));
    }

    public function test_los_ingresos_listan_los_pagos_de_citas_del_medico_con_sus_estados(): void
    {
        $cita = $this->cita($this->medico->reg_medico);

        $this->pago($cita, Pago::APROBADO, 10_000, ['medio_manual' => 'efectivo']);
        $this->pago($cita, Pago::EN_PROCESO, 5_000);
        $this->pago($cita, Pago::RECHAZADO, 3_000);

        // Un pago de plan no es un ingreso de la consulta y no debe aparecer.
        Pago::create([
            'reg_medico' => $this->medico->reg_medico,
            'beneficiario_tipo' => Pago::BENEFICIARIO_PLATAFORMA,
            'recaudador' => Pago::RECAUDADOR_PLATAFORMA,
            'canal' => Pago::CANAL_PLAN,
            'pagable_tipo' => Pago::PAGABLE_PLAN,
            'pagable_id' => 1,
            'concepto' => 'Plan mensual',
            'moneda' => 'USD',
            'monto_e2' => 2_000,
            'monto_usd_e2' => 2_000,
            'estado' => Pago::APROBADO,
        ]);

        $this->conContexto()->actingAs($this->user)
            ->get(route('clinica.ingresos'))
            ->assertOk()
            ->assertSee('Mis ingresos')
            ->assertSee('100,00')
            ->assertSee('50,00')
            ->assertSee('30,00')
            ->assertSee('Efectivo')
            ->assertSee('Cobrado')
            ->assertDontSee('Plan mensual');
    }

    public function test_el_filtro_por_estado_deja_solo_los_cobrados(): void
    {
        $cita = $this->cita($this->medico->reg_medico);

        $this->pago($cita, Pago::APROBADO, 10_000);
        $this->pago($cita, Pago::RECHAZADO, 3_000);

        $this->conContexto()->actingAs($this->user)
            ->get(route('clinica.ingresos', ['estado' => 'pagados']))
            ->assertOk()
            ->assertSee('100,00')
            ->assertDontSee('30,00');
    }

    public function test_no_muestra_los_pagos_de_otro_medico(): void
    {
        $ajena = $this->cita('otro-consultorio');
        $this->pago($ajena, Pago::APROBADO, 7_700);

        $this->conContexto()->actingAs($this->user)
            ->get(route('clinica.ingresos'))
            ->assertOk()
            ->assertDontSee('77,00')
            ->assertSee('No hay pagos de citas en este período');
    }
}
