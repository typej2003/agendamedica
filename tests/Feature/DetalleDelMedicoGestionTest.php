<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\DetalleDelMedico;
use App\Models\CuentaBancaria;
use App\Models\Evolucion;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MetodoPago;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Models\Plan;
use App\Models\RegMedicoServicio;
use App\Models\Specialty;
use App\Models\User;
use App\Services\CuentaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **El detalle del médico administra, no sólo mira** (2026-11-03): roles, API keys, especialidades, plan,
 * secretarías, motivos de cita, cita online y métodos de pago, todo sin salir de la ficha.
 */
class DetalleDelMedicoGestionTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private Medico $medico;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->admin = app(CuentaService::class)->crearAdministrador('Admin Detalle', 'detalle-admin-' . substr(uniqid(), -6) . '@example.com')['user'];
        $this->admin->forceFill(['must_change_password' => false])->save();

        $this->medico = app(CuentaService::class)->crearMedico([
            'name' => 'Lucía',
            'lastname' => 'Gestión',
            'email' => 'detalle-medico-' . substr(uniqid(), -6) . '@example.com',
            'reg_medico' => 'test-gest-' . substr(uniqid(), -6),
        ])['medico'];
        $this->medico->forceFill(['license_number' => 'MPPS-9876', 'phone' => '04141112233'])->save();

        $this->actingAs($this->admin);
    }

    private function detalle()
    {
        return Livewire::test(DetalleDelMedico::class, ['medico' => $this->medico]);
    }

    public function test_los_roles_se_guardan_desde_la_ficha(): void
    {
        $this->detalle()
            ->call('irA', 'roles')
            ->assertSet('pestana', 'roles')
            ->assertSee('Roles')
            ->set('rolesSeleccionados', ['Medico', 'Administrador'])
            ->call('guardarRoles')
            ->assertHasNoErrors();

        $this->assertTrue($this->medico->user->fresh()->hasRole('Administrador'));
    }

    public function test_la_api_key_se_genera_y_se_ven_las_credenciales_desde_la_ficha(): void
    {
        $panel = $this->detalle()
            ->call('irA', 'api-keys')
            ->call('abrirEmitir', $this->medico->id)
            ->assertSet('modal', 'emitir')
            ->set('equipo', 'CONSULTORIO-DETALLE')
            ->call('emitir')
            ->assertHasNoErrors()
            ->assertSet('modal', null);

        $this->assertNotNull($panel->get('tokenGenerado'));
        $this->assertSame('CONSULTORIO-DETALLE', $panel->get('tokenGenerado')['equipo']);

        $panel->call('cerrarToken')
            ->assertSet('tokenGenerado', null)
            ->call('verCredencialesDeEsteMedico')
            ->assertSet('verCredenciales', true)
            ->assertSee('CONSULTORIO-DETALLE');
    }

    public function test_las_especialidades_se_asignan_desde_la_ficha(): void
    {
        $pediatria = Specialty::where('slug', 'pediatria')->firstOrFail();

        $this->detalle()
            ->call('irA', 'especialidades')
            ->set('seleccionadas', [(string) $pediatria->id])
            ->call('guardarEspecialidades')
            ->assertHasNoErrors();

        $this->assertEqualsCanonicalizing(
            [$pediatria->id],
            $this->medico->fresh()->specialties()->pluck('specialties.id')->all(),
        );
    }

    public function test_el_plan_se_renueva_desde_la_ficha(): void
    {
        $plan = Plan::create([
            'codigo' => 'detalle-' . substr(uniqid(), -6),
            'nombre' => 'Plan del detalle',
            'frecuencia' => Plan::MENSUAL,
            'precio_usd' => 19.99,
            'activo' => true,
            'visible' => true,
            'orden' => 99,
        ]);

        $this->detalle()
            ->call('irA', 'planes')
            ->call('abrirRenovarPlan')
            ->assertSet('planAccion', 'renovar')
            ->set('planElegido', $plan->id)
            ->set('meses', 2)
            ->set('monto', 39.98)
            ->call('renovarPlan')
            ->assertHasNoErrors();

        $servicio = RegMedicoServicio::where('reg_medico', $this->medico->reg_medico)
            ->where('plan_id', $plan->id)
            ->where('estado', RegMedicoServicio::ACTIVO)
            ->first();

        $this->assertNotNull($servicio, 'El plan elegido tiene que quedar activo.');
    }

    public function test_una_secretaria_se_crea_desde_la_ficha_y_muestra_su_clave_una_vez(): void
    {
        $correo = 'secretaria-detalle-' . substr(uniqid(), -6) . '@example.com';

        $panel = $this->detalle()
            ->call('irA', 'secretarias')
            ->call('abrirNuevaSecretaria')
            ->set('secretariaNombre', 'Marta de la ficha')
            ->set('secretariaEmail', $correo)
            ->call('crearSecretaria')
            ->assertHasNoErrors();

        $clave = $panel->get('claveSecretaria');
        $this->assertNotNull($clave);
        $this->assertSame($correo, $clave['correo']);
        $this->assertTrue(User::where('email', $correo)->exists());

        $panel->call('cerrarClaveSecretaria')->assertSet('claveSecretaria', null);
    }

    public function test_los_motivos_de_cita_se_crean_editan_y_quitan_desde_la_ficha(): void
    {
        $panel = $this->detalle()
            ->call('irA', 'motivos')
            ->call('abrirMotivoNuevo')
            ->assertSet('motivoForm', true)
            ->set('motivoRazon', 'Control de la ficha')
            ->set('motivoPrecio', '25.00')
            ->call('guardarMotivo')
            ->assertHasNoErrors();

        $motivo = \App\Models\MotivoCita::where('reg_medico', $this->medico->reg_medico)->firstOrFail();
        $this->assertSame('CONTROL DE LA FICHA', $motivo->tipo_atencion);
        $this->assertSame(25.0, (float) $motivo->precio);

        $panel->call('abrirMotivoEditar', $motivo->id)
            ->set('motivoPrecio', '30.00')
            ->call('guardarMotivo')
            ->assertHasNoErrors();

        $this->assertSame(30.0, (float) $motivo->fresh()->precio);

        $panel->call('quitarMotivo', $motivo->id);
        $this->assertNull(\App\Models\MotivoCita::find($motivo->id));
    }

    public function test_la_cita_online_se_configura_desde_la_ficha(): void
    {
        $this->detalle()
            ->call('irA', 'cita-online')
            ->set('citaPorcentaje', '30')
            ->set('citaPublicar', false)
            ->set('citaMinutosAntes', '15')
            ->set('citaIndicaciones', 'Trae tus exámenes.')
            ->call('guardarCitaOnline')
            ->assertHasNoErrors();

        $evolucion = Evolucion::where('reg_medico', $this->medico->reg_medico)->firstOrFail();
        $this->assertSame(3000, (int) $evolucion->porcentaje_cita_online_bp);
        $this->assertFalse((bool) $evolucion->publicar_agenda);
        $this->assertSame(15, (int) $evolucion->cita_linea_minutos_antes);
        $this->assertSame('Trae tus exámenes.', $evolucion->cita_linea_indicaciones);
    }

    public function test_las_cuentas_de_cobro_se_cargan_desde_la_ficha(): void
    {
        $this->detalle()
            ->call('irA', 'metodos-pago')
            ->call('abrirCuentaNueva')
            ->set('ctaBanco', '0102')
            ->set('ctaTelefono', '04141234567')
            ->set('ctaTitular', 'Lucía Gestión')
            ->set('ctaDocTipo', 'V')
            ->set('ctaDocNumero', '12345678')
            ->call('guardarCuenta')
            ->assertHasNoErrors();

        $cuenta = CuentaBancaria::where('reg_medico', $this->medico->reg_medico)
            ->where('propietario', CuentaBancaria::MEDICO)
            ->firstOrFail();

        $this->assertSame('0102', $cuenta->banco_codigo);
        $this->assertSame('04141234567', $cuenta->telefono);
        $this->assertTrue($cuenta->predeterminada);
        $this->assertTrue(
            MetodoPago::where('cuenta_bancaria_id', $cuenta->id)->where('pasarela', 'pago_movil')->exists(),
            'La cuenta tiene que dejar su método de pago.',
        );
    }

    public function test_los_horarios_del_consultorio_se_ven_en_la_ficha(): void
    {
        $centro = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id' => DB::table('estados')->value('id'),
            'city_id' => DB::table('cities')->value('id'),
            'name' => 'Sede del detalle',
            'address' => 'Av. Gestión',
            'medico_id' => $this->medico->id,
        ]);
        $consultorio = Office::create([
            'medical_center_id' => $centro->id,
            'medico_id' => $this->medico->id,
            'reg_medico' => $this->medico->reg_medico,
            'office_number' => '3',
            'modalidad' => Office::MODALIDAD_ORDEN,
            'duracion_cita' => 30,
            'activo' => true,
        ]);
        OfficeSchedule::create([
            'office_id' => $consultorio->id,
            'reg_medico' => $this->medico->reg_medico,
            'dia_semana' => 1,
            'hora_inicio' => '08:00',
            'hora_fin' => '12:00',
            'cupo' => 8,
        ]);

        $panel = $this->detalle()
            ->call('irA', 'horarios')
            ->assertSee('Sede del detalle')
            ->assertSee('Agregar bloque')
            ->assertSee('08:00')
            ->assertSee('cupo 8');

        // Agregar, editar y quitar un bloque, sin salir de la ficha.
        $panel->call('abrirHorarioNuevo', $consultorio->id)
            ->assertSet('horarioForm', true)
            ->set('horarioDia', '2')
            ->set('horarioInicio', '14:00')
            ->set('horarioFin', '18:00')
            ->set('horarioCupo', '12')
            ->call('guardarHorario')
            ->assertHasNoErrors()
            ->assertSet('horarioForm', false);

        $nuevo = OfficeSchedule::where('office_id', $consultorio->id)->where('dia_semana', 2)->firstOrFail();
        $this->assertSame('14:00', substr((string) $nuevo->hora_inicio, 0, 5));
        $this->assertSame(12, (int) $nuevo->cupo);

        $panel->call('abrirHorarioEditar', $consultorio->id, $nuevo->id)
            ->assertSet('horarioDia', '2')
            ->set('horarioCupo', '20')
            ->call('guardarHorario')
            ->assertHasNoErrors();

        $this->assertSame(20, (int) $nuevo->fresh()->cupo);

        $panel->call('quitarHorario', $consultorio->id, $nuevo->id);
        $this->assertNull(OfficeSchedule::find($nuevo->id));
    }
}
