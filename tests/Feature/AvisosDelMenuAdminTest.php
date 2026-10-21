<?php

namespace Tests\Feature;

use App\Http\Livewire\Layouts\Aside;
use App\Models\Comentario;
use App\Models\Pago;
use App\Models\SolicitudMedico;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **Los números del menú del administrador** (2026-10-21): junto a Pagos, Solicitudes de médicos y Ayúdanos a
 * mejorar, cuántos esperan respuesta. Se cuenta lo pendiente (pagos pendientes o en proceso, solicitudes
 * pendientes, comentarios nuevos) y nada más; quien no es administrador no ve esas bandejas ni sus números.
 */
class AvisosDelMenuAdminTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // La base de pruebas puede traer filas de otros tests: se parte de cero en las tres bandejas.
        Pago::query()->delete();
        SolicitudMedico::query()->delete();
        Comentario::query()->delete();
    }

    private function usuario(string $rol): User
    {
        $user = User::create([
            'name' => 'Usuario del menú',
            'email' => 'menu-' . substr(uniqid(), -8) . '@example.com',
            'password' => Hash::make('secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        return $user;
    }

    private function pago(string $estado): Pago
    {
        return Pago::create([
            'reg_medico' => 'menu-' . substr(uniqid(), -6),
            'beneficiario_tipo' => Pago::BENEFICIARIO_PLATAFORMA,
            'recaudador' => Pago::RECAUDADOR_PLATAFORMA,
            'canal' => Pago::CANAL_PLAN,
            'pagable_tipo' => Pago::PAGABLE_PLAN,
            'concepto' => 'Plan de prueba',
            'moneda' => 'VES',
            'monto_e2' => 73000,
            'monto_usd_e2' => 2000,
            'tasa' => 36.5,
            'neto_usd_e2' => 2000,
            'neto_ves_e2' => 73000,
            'estado' => $estado,
            'expira_el' => now()->addMinutes(15),
        ]);
    }

    private function solicitud(string $estado): SolicitudMedico
    {
        return SolicitudMedico::create([
            'nombres' => 'Ana',
            'apellidos' => 'Prueba',
            'cedula' => (string) random_int(10000000, 29999999),
            'mpps' => (string) random_int(10000, 99999),
            'telefono' => '584145556677',
            'email' => 'solicitud-' . substr(uniqid(), -8) . '@example.com',
            'estado' => $estado,
        ]);
    }

    private function comentario(string $estado): Comentario
    {
        return Comentario::create([
            'origen' => 'web',
            'tipo' => Comentario::BUG,
            'mensaje' => 'Algo no funciona',
            'estado' => $estado,
        ]);
    }

    public function test_el_administrador_ve_cuántos_esperan_en_cada_bandeja(): void
    {
        $this->pago(Pago::PENDIENTE);
        $this->pago(Pago::EN_PROCESO);
        $this->pago(Pago::APROBADO);
        $this->pago(Pago::EXPIRADO);

        $this->solicitud(SolicitudMedico::PENDIENTE);
        $this->solicitud(SolicitudMedico::PENDIENTE);
        $this->solicitud(SolicitudMedico::PENDIENTE);
        $this->solicitud(SolicitudMedico::APROBADA);

        $this->comentario(Comentario::NUEVO);
        $this->comentario(Comentario::RESPONDIDO);
        $this->comentario(Comentario::DESCARTADO);

        $this->actingAs($this->usuario('Administrador'));

        Livewire::test(Aside::class)
            ->assertViewHas('avisos', ['pagos' => 2, 'solicitudes' => 3, 'comentarios' => 1])
            ->assertSeeHtml('2 sin confirmar')
            ->assertSeeHtml('3 sin responder')
            ->assertSeeHtml('1 sin responder')
            ->assertSeeHtml('title="Pagos (2 sin confirmar)"');
    }

    public function test_sin_pendientes_no_aparece_ningún_número(): void
    {
        $this->pago(Pago::APROBADO);
        $this->comentario(Comentario::RESPONDIDO);

        $this->actingAs($this->usuario('Administrador'));

        Livewire::test(Aside::class)
            ->assertViewHas('avisos', ['pagos' => 0, 'solicitudes' => 0, 'comentarios' => 0])
            ->assertDontSeeHtml('app-sidebar-aviso')
            ->assertSeeHtml('title="Pagos"');
    }

    public function test_más_de_99_se_muestra_como_99_más(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->comentario(Comentario::NUEVO);
        }

        $this->actingAs($this->usuario('Administrador'));

        Livewire::test(Aside::class)
            ->assertSeeHtml('99+')
            ->assertSeeHtml('100 sin responder');
    }

    public function test_quien_no_es_administrador_no_ve_los_números(): void
    {
        $this->pago(Pago::PENDIENTE);
        $this->comentario(Comentario::NUEVO);

        $this->actingAs($this->usuario('Medico'));

        Livewire::test(Aside::class)
            ->assertViewHas('avisos', ['pagos' => 0, 'solicitudes' => 0, 'comentarios' => 0])
            ->assertDontSeeHtml('app-sidebar-aviso');
    }
}
