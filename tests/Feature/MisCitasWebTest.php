<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Models\Paciente;
use App\Models\User;
use App\Paciente\PuedeGestionarSuCita;
use App\Reservas\DisponibilidadPublica;
use App\Reservas\OpcionDeReserva;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **«Mis citas»** (2026-10-19): el paciente ve sus citas, cancela y reprograma con las reglas de
 * `PuedeGestionarSuCita` (24 h, futura, sin atender, sin pago). El listado cruza los vínculos
 * `medico_pacientes` con la agenda real, y las acciones responden **404** si la cita no es suya.
 */
class MisCitasWebTest extends TestCase
{
    use DatabaseTransactions;

    private User $usuario;

    private Paciente $paciente;

    private Medico $medico;

    private MedicalCenter $centro;

    private Office $office;

    private Cola $futura;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Lunes 19 de octubre de 2026, 07:00 UTC = 03:00 en Caracas.
        Carbon::setTestNow(Carbon::create(2026, 10, 19, 7, 0, 0));

        $this->usuario = User::create([
            'name' => 'Ana Prueba',
            'email' => 'mis-citas-' . uniqid() . '@example.com',
            'password' => Hash::make('Secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $this->usuario->assignRole('Paciente');

        $this->paciente = Paciente::create([
            'nombres' => 'Ana',
            'apellidos' => 'Prueba',
            'cedula' => '9' . random_int(1000000, 9999999),
            'telefono' => '0414-5556677',
            'email' => $this->usuario->email,
            'user_id' => $this->usuario->id,
        ]);

        $this->centro = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id' => DB::table('estados')->value('id'),
            'city_id' => DB::table('cities')->value('id'),
            'name' => 'Sede de mis citas',
            'address' => 'Calle de prueba',
        ]);

        $this->medico = Medico::create([
            'name' => 'Carlos',
            'lastname' => 'MisCitas',
            'email' => 'medico-mis-citas-' . uniqid() . '@example.com',
            'reg_medico' => 'test-mis-citas-' . uniqid(),
        ]);

        $this->office = Office::create([
            'medical_center_id' => $this->centro->id,
            'medico_id' => $this->medico->id,
            'reg_medico' => $this->medico->reg_medico,
            'office_number' => '1',
            'modalidad' => Office::MODALIDAD_HORA,
            'duracion_cita' => 30,
            'activo' => true,
        ]);

        foreach ([1, 2, 3, 4, 5, 6] as $dia) {
            OfficeSchedule::create([
                'office_id' => $this->office->id,
                'reg_medico' => $this->medico->reg_medico,
                'dia_semana' => $dia,
                'hora_inicio' => '08:00',
                'hora_fin' => '18:00',
                'cupo' => 50,
            ]);
        }

        MedicoPaciente::create([
            'medico_id' => $this->medico->id,
            'paciente_id' => $this->paciente->id,
            'numhistoria' => '1001',
            'reg_medico' => $this->medico->reg_medico,
        ]);

        $this->futura = Cola::create([
            'reg_medico' => $this->medico->reg_medico,
            'fecha' => '2026-10-22',
            'hora_ini' => '09:00',
            'numhistoria' => '1001',
            'numorden' => 1,
            'atendido' => 0,
            'estado' => Cola::ESTADO_CONFIRMADA_PACIENTE,
            'medical_center_id' => $this->centro->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cita(int $numhistoria, string $fecha, string $hora, array $extra = []): Cola
    {
        return Cola::create(array_merge([
            'reg_medico' => $this->medico->reg_medico,
            'fecha' => $fecha,
            'hora_ini' => $hora,
            'numhistoria' => (string) $numhistoria,
            'numorden' => 1,
            'atendido' => 0,
            'estado' => Cola::ESTADO_CONFIRMADA,
            'medical_center_id' => $this->centro->id,
        ], $extra));
    }

    public function test_el_invitado_va_al_login_y_el_medico_no_entra(): void
    {
        $this->get('/mis-citas')->assertRedirect(route('login'));

        $medicoUser = User::create([
            'name' => 'Doctor',
            'email' => 'medico-user-' . uniqid() . '@example.com',
            'password' => Hash::make('Secreto123'),
        ]);
        $medicoUser->assignRole('Medico');

        $this->actingAs($medicoUser)->get('/mis-citas')->assertForbidden();
    }

    public function test_lista_las_citas_proximas_y_el_historial(): void
    {
        $this->cita(1001, '2026-10-10', '10:00', [
            'atendido' => 1,
            'estado' => Cola::ESTADO_CONFIRMADA,
        ]);

        $this->actingAs($this->usuario)
            ->get(route('paciente.citas'))
            ->assertOk()
            ->assertSee('Próximas')
            ->assertSee('Historial')
            ->assertSee($this->medico->nombre_mostrar)
            ->assertSee('Sede de mis citas')
            ->assertSee('Reprogramar')
            ->assertSee('Cancelar')
            ->assertSee('Atendida')
            ->assertSee('Confirmada por ti');
    }

    public function test_no_puede_tocar_la_cita_de_otro_paciente(): void
    {
        $otro = Paciente::create([
            'nombres' => 'Otra',
            'apellidos' => 'Persona',
            'cedula' => '8' . random_int(1000000, 9999999),
        ]);

        $ajena = Cola::create([
            'reg_medico' => $this->medico->reg_medico,
            'fecha' => '2026-10-23',
            'hora_ini' => '09:00',
            'paciente_sinhistoria_id' => $otro->id,
            'numorden' => 1,
            'atendido' => 0,
            'estado' => 2,
            'medical_center_id' => $this->centro->id,
        ]);

        $this->actingAs($this->usuario);

        $this->get(route('paciente.citas.cancelar', $ajena))->assertNotFound();
        $this->post(route('paciente.citas.cancelar.guardar', $ajena))->assertNotFound();
        $this->get(route('paciente.citas.reprogramar', $ajena))->assertNotFound();
        $this->post(route('paciente.citas.reprogramar.guardar', $ajena), ['horario' => '2026-10-22|09:00|' . $this->office->id])
            ->assertNotFound();
    }

    public function test_cancela_una_cita_futura_y_deja_su_rastro(): void
    {
        $id = $this->futura->id;

        $this->actingAs($this->usuario)
            ->post(route('paciente.citas.cancelar.guardar', $this->futura))
            ->assertRedirect(route('paciente.citas'));

        $this->assertNull(Cola::find($id));
        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'cola',
            'record_id' => $id,
            'operation' => 'deleted',
        ]);
    }

    public function test_no_cancela_con_menos_de_24_horas(): void
    {
        $cercana = $this->cita(1001, '2026-10-19', '18:00');

        $this->actingAs($this->usuario)
            ->post(route('paciente.citas.cancelar.guardar', $cercana))
            ->assertRedirect(route('paciente.citas'))
            ->assertSessionHas('error');

        $this->assertNotNull($cercana->fresh());
    }

    public function test_no_cancela_una_cita_atendida_ni_con_pago(): void
    {
        $atendida = $this->cita(1001, '2026-10-10', '10:00', ['atendido' => 1]);
        $pagada = $this->cita(1001, '2026-10-23', '10:00', ['monto_pagado' => 15]);

        $this->actingAs($this->usuario);

        $this->post(route('paciente.citas.cancelar.guardar', $atendida))
            ->assertRedirect(route('paciente.citas'))->assertSessionHas('error');
        $this->post(route('paciente.citas.cancelar.guardar', $pagada))
            ->assertRedirect(route('paciente.citas'))->assertSessionHas('error');

        $this->assertNotNull($atendida->fresh());
        $this->assertNotNull($pagada->fresh());
    }

    public function test_reprograma_la_misma_cita_y_la_deja_confirmada(): void
    {
        $opcion = $this->opcionNueva();

        $this->actingAs($this->usuario)
            ->get(route('paciente.citas.reprogramar', $this->futura))
            ->assertOk()
            ->assertSee('Reprogramar la cita');

        $this->post(route('paciente.citas.reprogramar.guardar', $this->futura), [
            'horario' => $opcion->clave(),
        ])->assertRedirect(route('paciente.citas'));

        $cita = $this->futura->fresh();

        $this->assertSame($opcion->fecha, Carbon::parse($cita->fecha)->toDateString());
        $this->assertSame($opcion->hora, substr((string) $cita->hora_ini, 0, 5));
        $this->assertSame(Cola::ESTADO_CONFIRMADA_PACIENTE, (int) $cita->estado);
        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'cola',
            'record_id' => $cita->id,
            'operation' => 'updated',
        ]);
    }

    public function test_no_reprograma_a_un_horario_con_menos_de_24_horas(): void
    {
        $this->actingAs($this->usuario)
            ->post(route('paciente.citas.reprogramar.guardar', $this->futura), [
                'horario' => '2026-10-19|18:00|' . $this->office->id,
            ])
            ->assertRedirect(route('paciente.citas.reprogramar', $this->futura))
            ->assertSessionHas('error');

        $this->assertSame('2026-10-22', Carbon::parse($this->futura->fresh()->fecha)->toDateString());
    }

    public function test_no_reprograma_a_un_horario_que_no_es_el_primero_disponible(): void
    {
        $this->actingAs($this->usuario)
            ->post(route('paciente.citas.reprogramar.guardar', $this->futura), [
                'horario' => '2026-10-20|12:00|' . $this->office->id,
            ])
            ->assertRedirect(route('paciente.citas.reprogramar', $this->futura))
            ->assertSessionHas('error');

        $this->assertSame('09:00', substr((string) $this->futura->fresh()->hora_ini, 0, 5));
    }

    public function test_un_horario_inexistente_se_rechaza_sin_tocar_la_cita(): void
    {
        $this->actingAs($this->usuario)
            ->from(route('paciente.citas.reprogramar', $this->futura))
            ->post(route('paciente.citas.reprogramar.guardar', $this->futura), [
                'horario' => '2026-02-31|10:00|' . $this->office->id,
            ])
            ->assertRedirect(route('paciente.citas.reprogramar', $this->futura))
            ->assertSessionHasErrors('horario');

        $this->assertSame('2026-10-22', Carbon::parse($this->futura->fresh()->fecha)->toDateString());
    }

    private function opcionNueva(): OpcionDeReserva
    {
        $opciones = app(DisponibilidadPublica::class)->opciones($this->medico, 8);

        $candidatas = collect($opciones)->filter(function (OpcionDeReserva $opcion) {
            return PuedeGestionarSuCita::opcionPermitida($opcion)
                && ! ($opcion->fecha === '2026-10-22' && $opcion->hora === '09:00');
        })->values();

        $this->assertNotEmpty($candidatas, 'La disponibilidad del médico tiene que ofrecer algún horario nuevo.');

        return $candidatas->first();
    }
}
