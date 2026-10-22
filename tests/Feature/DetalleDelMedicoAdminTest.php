<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\Cuentas;
use App\Http\Livewire\Admin\DetalleDelMedico;
use App\Models\Comentario;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Office;
use App\Models\Pago;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **Detalle del médico** (Usuarios › Médicos › Ver): pantalla de sólo lectura con pestañas. Se fija que cada
 * pestaña se renderiza, que las condicionales (Pagos, Solicitud, Comentarios) sólo aparecen con datos, que
 * «Editar» vuelve a Médicos con el diálogo ya abierto y que sólo entra un administrador.
 */
class DetalleDelMedicoAdminTest extends TestCase
{
    use DatabaseTransactions;

    private Medico $medico;

    private User $cuentaDelMedico;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->cuentaDelMedico = $this->usuario('Medico', 'Dra. Detalle');
        $this->medico = Medico::create([
            'user_id' => $this->cuentaDelMedico->id,
            'name' => 'Lucía',
            'lastname' => 'Detalle',
            'prefix' => 'Dra.',
            'email' => $this->cuentaDelMedico->email,
            'phone' => '04141234567',
            'license_number' => 'MPPS-1234',
            'reg_medico' => 'test-det' . substr(uniqid(), -6),
        ]);
        MedicoRegistro::create(['medico_id' => $this->medico->id, 'reg_medico' => $this->medico->reg_medico]);
    }

    private function usuario(string $rol, string $nombre = 'Usuario'): User
    {
        $user = User::create([
            'name' => $nombre,
            'email' => 'det-' . substr(uniqid(), -8) . '@example.com',
            'password' => Hash::make('secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        return $user;
    }

    public function test_el_administrador_ve_el_detalle_con_los_datos_del_usuario(): void
    {
        $this->actingAs($this->usuario('Root'));

        $this->get(route('admin.cuentas.medico', $this->medico))
            ->assertOk()
            ->assertSee('Dra. Lucía Detalle')
            ->assertSee('MPPS-1234')
            ->assertSee($this->medico->reg_medico)
            ->assertSee('Medico')
            ->assertSee('Editar');
    }

    public function test_cada_pestana_se_renderiza_con_su_contenido(): void
    {
        $this->actingAs($this->usuario('Root'));

        // Datos de apoyo para que cada pestaña tenga algo que mostrar.
        $centro = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id' => DB::table('estados')->value('id'),
            'city_id' => DB::table('cities')->value('id'),
            'name' => 'Clínica del detalle',
            'address' => 'Av. Prueba',
        ]);
        Office::create([
            'medical_center_id' => $centro->id, 'medico_id' => $this->medico->id, 'reg_medico' => $this->medico->reg_medico,
            'office_number' => '7', 'modalidad' => Office::MODALIDAD_ORDEN, 'duracion_cita' => 30, 'activo' => true,
        ]);
        $this->medico->specialties()->attach(Specialty::firstOrFail());
        $secretaria = $this->usuario('Secretaria', 'Marta Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $this->medico->reg_medico]);

        $esperado = [
            'datos' => 'Registros de datos',
            'sedes' => 'Clínica del detalle',
            'roles' => 'Medico',
            'api-keys' => 'Credenciales activas',
            'especialidades' => Specialty::firstOrFail()->name,
            'planes' => 'Plan',
            'secretarias' => 'Marta Secretaria',
            'pacientes' => 'Pacientes del consultorio',
        ];

        foreach ($esperado as $pestana => $texto) {
            Livewire::test(DetalleDelMedico::class, ['medico' => $this->medico])
                ->call('irA', $pestana)
                ->assertSet('pestana', $pestana)
                ->assertSee($texto);
        }
    }

    public function test_las_pestanas_condicionales_solo_aparecen_con_datos_y_enlazan_a_su_pantalla(): void
    {
        $this->actingAs($this->usuario('Root'));

        Livewire::test(DetalleDelMedico::class, ['medico' => $this->medico])
            ->assertDontSee('Ayúdanos a mejorar')
            ->assertDontSee('Solicitud de registro')
            ->call('irA', 'pagos')->assertSet('pestana', 'datos');

        Pago::create([
            'reg_medico' => $this->medico->reg_medico, 'beneficiario_tipo' => Pago::BENEFICIARIO_PLATAFORMA,
            'recaudador' => Pago::RECAUDADOR_PLATAFORMA, 'canal' => Pago::CANAL_PLAN, 'pagable_tipo' => Pago::PAGABLE_PLAN,
            'concepto' => 'Plan del detalle', 'moneda' => 'VES', 'monto_e2' => 73000, 'monto_usd_e2' => 2000, 'tasa' => 36.5,
            'neto_usd_e2' => 2000, 'neto_ves_e2' => 73000, 'estado' => Pago::APROBADO, 'expira_el' => now()->addMinutes(15),
        ]);
        Comentario::create(['origen' => 'web', 'tipo' => Comentario::BUG, 'mensaje' => 'No me carga la agenda', 'estado' => Comentario::NUEVO, 'medico_id' => $this->medico->id]);

        Livewire::test(DetalleDelMedico::class, ['medico' => $this->medico])
            ->assertSee('Ayúdanos a mejorar')
            ->call('irA', 'pagos')->assertSee('Plan del detalle')->assertSee(route('admin.pagos'))
            ->call('irA', 'comentarios')->assertSee('No me carga la agenda')->assertSee(route('admin.comentarios'));
    }

    public function test_editar_abre_el_dialogo_de_edicion_de_medicos_con_este_medico(): void
    {
        $this->actingAs($this->usuario('Root'));

        $this->get(route('admin.cuentas.medico', $this->medico))
            ->assertSee(route('admin.cuentas', ['editar' => $this->medico->id]), false);

        $this->get(route('admin.cuentas', ['editar' => $this->medico->id]))
            ->assertOk()
            ->assertSee('MPPS-1234');

        Livewire::withQueryParams(['editar' => $this->medico->id])
            ->test(Cuentas::class)
            ->assertSet('modal', 'editar')
            ->assertSet('medicoId', $this->medico->id);

        // Un id inexistente no abre nada ni rompe la lista.
        Livewire::withQueryParams(['editar' => 99999999])
            ->test(Cuentas::class)
            ->assertSet('modal', null);
    }

    public function test_la_lista_de_medicos_ofrece_ver_y_solo_entran_los_administradores(): void
    {
        $this->actingAs($this->usuario('Administrador'));
        $this->get(route('admin.cuentas', ['search' => 'Detalle']))
            ->assertOk()
            ->assertSee(route('admin.cuentas.medico', $this->medico), false);
        $this->get(route('admin.cuentas.medico', $this->medico))->assertOk();

        $this->actingAs($this->usuario('Medico'));
        $this->get(route('admin.cuentas.medico', $this->medico))->assertForbidden();
        Livewire::test(DetalleDelMedico::class, ['medico' => $this->medico])->assertForbidden();

        $this->actingAs($this->usuario('Paciente'));
        $this->get(route('admin.cuentas.medico', $this->medico))->assertForbidden();
    }

    public function test_un_invitado_va_al_login_y_un_id_inexistente_es_404(): void
    {
        auth()->logout();
        $this->get(route('admin.cuentas.medico', $this->medico))->assertRedirect(route('login'));

        $this->actingAs($this->usuario('Root'));
        $this->get(route('admin.cuentas.medico', 99999999))->assertNotFound();
    }

    public function test_cada_pestana_hace_pocas_consultas(): void
    {
        $this->actingAs($this->usuario('Root'));

        foreach (array_keys(DetalleDelMedico::PESTANAS) as $pestana) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(DetalleDelMedico::class, ['medico' => $this->medico])->call('irA', $pestana);
            $consultas = count(DB::getQueryLog());
            DB::disableQueryLog();

            $this->assertLessThan(60, $consultas, "La pestaña {$pestana} hizo {$consultas} consultas.");
        }
    }
}
