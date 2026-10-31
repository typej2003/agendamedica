<?php

namespace Tests\Feature;

use App\Actions\Medicos\AprobarSolicitudDeMedico;
use App\Latidos\Latidos;
use App\Mail\LatidosGanadosPorReferido;
use App\Models\ConfiguracionLatidos;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Referido;
use App\Models\RegMedicoServicio;
use App\Models\SolicitudMedico;
use App\Models\Specialty;
use App\Models\User;
use App\Referidos\AcreditarReferido;
use App\Referidos\AnularReferido;
use App\Referidos\CodigosDeReferido;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **Programa de referidos** (fase 4, 2026-10-31): link, atribución, acreditación y anulación.
 *
 * Lo que se fija: el link guarda el código y cuenta el clic; el registro lo conserva; al aprobar se
 * acreditan los Latidos a los dos una sola vez; no hay autorreferencia ni premio para un cliente existente;
 * el tope mensual se respeta; y la anulación devuelve los Latidos o falla si ya se gastaron.
 */
class ReferidosWebTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Mail::fake();
    }

    /** @return array{0:User,1:Medico,2:string} */
    private function referente(): array
    {
        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $reg = 'ref-' . substr(uniqid(), -7);

        $user = User::create([
            'name' => 'Dr. Referente', 'email' => "{$reg}@example.com",
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr. Referente', 'lastname' => 'Latidos',
            'email' => $user->email, 'password' => $user->password, 'reg_medico' => $reg,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        $codigo = app(CodigosDeReferido::class)->para($reg);

        return [$user, $medico, $codigo->codigo];
    }

    private function admin(): User
    {
        $user = User::create([
            'name' => 'Admin Referidos', 'email' => 'admin-ref-' . substr(uniqid(), -7) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Administrador');

        return $user;
    }

    private function solicitud(string $referente, string $codigo, array $cambios = []): SolicitudMedico
    {
        return SolicitudMedico::create(array_merge([
            'prefijo' => 'Dr.', 'nombres' => 'Nuevo', 'apellidos' => 'Colega',
            'cedula' => '9' . random_int(1000000, 9999999), 'mpps' => (string) random_int(100000, 999999),
            'telefono' => '0414-5556677', 'email' => 'colega-' . uniqid() . '@example.com',
            'ciudad' => 'Caracas', 'estado' => SolicitudMedico::PENDIENTE,
            'referente_reg_medico' => $referente, 'codigo_referido' => $codigo,
        ], $cambios));
    }

    private function datosDeRegistro(string $email, string $codigo): array
    {
        return [
            'prefijo' => 'Dr.', 'nombres' => 'Nuevo', 'apellidos' => 'Colega',
            'cedula' => '8' . random_int(1000000, 9999999), 'mpps' => (string) random_int(100000, 999999),
            'telefono' => '0414-5556677', 'email' => $email, 'ciudad' => 'Caracas',
            'codigo_referido' => $codigo, 'terminos' => '1',
        ];
    }

    public function test_el_link_guarda_la_cookie_y_cuenta_el_clic(): void
    {
        [$user, $medico, $codigo] = $this->referente();

        $this->get('/r/' . $codigo)
            ->assertOk()
            ->assertSee('Un colega te invitó a Doctorísimo')
            ->assertSee($codigo)
            ->assertSee(route('medico.registro', ['ref' => $codigo]), false)
            ->assertCookie('doctorisimo_ref', $codigo)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->assertSame(1, (int) \App\Models\CodigoDeReferido::where('codigo', $codigo)->firstOrFail()->clics);
    }

    public function test_el_boton_de_descarga_lleva_el_referrer_para_la_app(): void
    {
        ConfiguracionLatidos::actual()->update([
            'url_descarga' => 'https://play.google.com/store/apps/details?id=com.ddrsistemas.doctorisimo',
        ]);
        [$user, $medico, $codigo] = $this->referente();

        $this->get('/r/' . $codigo)
            ->assertOk()
            ->assertSee('referrer=' . rawurlencode('ref=' . $codigo), false);
    }

    public function test_un_codigo_invalido_no_atribuye(): void
    {
        $this->get('/r/NOEXISTE')
            ->assertOk()
            ->assertSee('Esta invitación no está vigente')
            ->assertCookieMissing('doctorisimo_ref');
    }

    public function test_el_formulario_de_registro_precarga_el_codigo_del_link(): void
    {
        [$user, $medico, $codigo] = $this->referente();

        $this->get(route('medico.registro', ['ref' => $codigo]))
            ->assertOk()
            ->assertSee('Código de invitación')
            ->assertSee('value="' . $codigo . '"', false)
            ->assertSee('Te invitó');
    }

    public function test_el_registro_guarda_la_atribucion_del_codigo(): void
    {
        [$user, $medico, $codigo] = $this->referente();
        $email = 'registro-ref-' . uniqid() . '@example.com';

        $this->post(route('medico.registro.codigo.enviar'), $this->datosDeRegistro($email, $codigo))
            ->assertRedirect(route('medico.registro.codigo'));

        $this->post(route('medico.registro.confirmar'), [
            'codigo' => (string) session('registro_medico.pendiente.codigo'),
        ])->assertRedirect(route('medico.registro.recibida'));

        $solicitud = SolicitudMedico::where('email', $email)->firstOrFail();
        $this->assertSame($codigo, $solicitud->codigo_referido);
        $this->assertSame($medico->reg_medico, $solicitud->referente_reg_medico);
    }

    public function test_al_aprobar_se_acredita_a_los_dos(): void
    {
        [$user, $referente, $codigo] = $this->referente();
        $solicitud = $this->solicitud($referente->reg_medico, $codigo);

        app(AprobarSolicitudDeMedico::class)->ejecutar($solicitud, $this->admin(), 'MPPS-REF-' . random_int(1000, 9999));

        $referido = Referido::where('solicitud_id', $solicitud->id)->firstOrFail();

        $this->assertSame(Referido::ACREDITADO, $referido->estado);
        $this->assertSame(2000, $referido->latidos_referente);
        $this->assertSame(2000, $referido->latidos_referido);
        $this->assertSame(2000, app(Latidos::class)->disponibles($referente->reg_medico));
        $this->assertSame(2000, app(Latidos::class)->disponibles($referido->referido_reg_medico));
        Mail::assertSent(LatidosGanadosPorReferido::class, 2);
    }

    public function test_no_acredita_si_el_referido_era_cliente(): void
    {
        [$user, $referente, $codigo] = $this->referente();
        $solicitud = $this->solicitud($referente->reg_medico, $codigo);
        $colega = Medico::create([
            'name' => 'Cliente', 'lastname' => 'Existente', 'reg_medico' => 'MPPS-CLIENTE-' . random_int(1000, 9999),
            'is_active' => true,
        ]);
        RegMedicoServicio::create([
            'reg_medico' => $colega->reg_medico, 'plan_id' => null, 'plan_nombre' => 'Plan viejo',
            'origen' => RegMedicoServicio::ORIGEN_MANUAL, 'inicia_el' => now()->subMonth(),
            'vence_el' => now()->addMonth(), 'monto_usd' => 20, 'restricciones' => [],
            'caracteristicas' => [], 'estado' => RegMedicoServicio::ACTIVO,
        ]);

        $referido = app(AcreditarReferido::class)->ejecutar($solicitud, $colega->reg_medico, $colega, true);

        $this->assertSame(Referido::RECHAZADO, $referido->estado);
        $this->assertStringContainsString('ya era cliente', (string) $referido->motivo);
        $this->assertSame(0, app(Latidos::class)->disponibles($referente->reg_medico));
        $this->assertSame(0, app(Latidos::class)->disponibles($colega->reg_medico));
    }

    public function test_no_hay_autorreferencia(): void
    {
        [$user, $referente, $codigo] = $this->referente();
        $solicitud = $this->solicitud($referente->reg_medico, $codigo);

        $referido = app(AcreditarReferido::class)->ejecutar(
            $solicitud, $referente->reg_medico, $referente, false,
        );

        $this->assertSame(Referido::RECHAZADO, $referido->estado);
        $this->assertStringContainsString('Autorreferencia', (string) $referido->motivo);
        $this->assertSame(0, app(Latidos::class)->disponibles($referente->reg_medico));
    }

    public function test_el_tope_mensual_rechaza_el_segundo(): void
    {
        [$user, $referente, $codigo] = $this->referente();
        ConfiguracionLatidos::actual()->update(['tope_mensual_por_referente' => 1]);

        $primera = $this->solicitud($referente->reg_medico, $codigo);
        app(AcreditarReferido::class)->ejecutar($primera, 'MPPS-TOPE-1', Medico::create([
            'name' => 'Uno', 'lastname' => 'Tope', 'reg_medico' => 'MPPS-TOPE-1', 'is_active' => true,
        ]), false);

        $segunda = $this->solicitud($referente->reg_medico, $codigo);
        $rechazada = app(AcreditarReferido::class)->ejecutar($segunda, 'MPPS-TOPE-2', Medico::create([
            'name' => 'Dos', 'lastname' => 'Tope', 'reg_medico' => 'MPPS-TOPE-2', 'is_active' => true,
        ]), false);

        $this->assertSame(Referido::RECHAZADO, $rechazada->estado);
        $this->assertStringContainsString('tope', (string) $rechazada->motivo);
        $this->assertSame(2000, app(Latidos::class)->disponibles($referente->reg_medico));
    }

    public function test_la_pagina_del_medico_muestra_el_link_y_el_codigo(): void
    {
        [$user, $referente, $codigo] = $this->referente();
        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();

        $this->withSession(['clinica.contexto' => [
            'reg_medico' => $referente->reg_medico,
            'specialty_id' => $gineco->id,
            'office_id' => null,
        ]])->actingAs($user)
            ->get(route('clinica.referidos'))
            ->assertOk()
            ->assertSee('Invita y gana')
            ->assertSee($codigo)
            ->assertSee('/r/' . $codigo, false);
    }

    public function test_regenerar_cambia_el_codigo_y_invalida_el_viejo(): void
    {
        [$user, $referente, $codigoViejo] = $this->referente();
        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();

        $this->withSession(['clinica.contexto' => [
            'reg_medico' => $referente->reg_medico,
            'specialty_id' => $gineco->id,
            'office_id' => null,
        ]])->actingAs($user)
            ->post(route('clinica.referidos.regenerar'))
            ->assertRedirect(route('clinica.referidos'));

        $codigoNuevo = \App\Models\CodigoDeReferido::where('reg_medico', $referente->reg_medico)->value('codigo');
        $this->assertNotSame($codigoViejo, $codigoNuevo);

        $this->get('/r/' . $codigoViejo)->assertSee('Esta invitación no está vigente');
        $this->get('/r/' . $codigoNuevo)->assertSee('Un colega te invitó');
    }

    public function test_anular_devuelve_los_latidos_a_los_dos(): void
    {
        [$user, $referente, $codigo] = $this->referente();
        $solicitud = $this->solicitud($referente->reg_medico, $codigo);
        $colega = Medico::create([
            'name' => 'Anulado', 'lastname' => 'Prueba', 'reg_medico' => 'MPPS-ANULA-1', 'is_active' => true,
        ]);
        $referido = app(AcreditarReferido::class)->ejecutar($solicitud, $colega->reg_medico, $colega, false);
        $this->assertSame(2000, app(Latidos::class)->disponibles($referente->reg_medico));

        $admin = $this->admin();
        app(AnularReferido::class)->ejecutar($referido, 'Registro duplicado', $admin);

        $this->assertSame(Referido::ANULADO, $referido->fresh()->estado);
        $this->assertSame(0, app(Latidos::class)->disponibles($referente->reg_medico));
        $this->assertSame(0, app(Latidos::class)->disponibles($colega->reg_medico));
        $this->assertSame($admin->id, (int) $referido->fresh()->anulado_por);
    }
}
