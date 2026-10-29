<?php

namespace Tests\Feature;

use App\Mail\InvitacionAlEquipo;
use App\Models\InvitacionDeMedico;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Plan;
use App\Models\Specialty;
use App\Models\User;
use App\Services\CuentaService;
use App\Services\ServicioService;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Invitaciones de un médico a otro para unirse al equipo de un `reg_medico` (2026-10-28).
 *
 * Lo que fijan: el dueño invita y el invitado acepta; la invitación no da acceso hasta aceptar; el cupo
 * se comprueba al invitar y se revalida al aceptar; el médico afiliado no invita; la secretaría no entra;
 * y rechazar/revocar/vencer no crean el vínculo.
 */
class InvitacionesDeEquipoWebTest extends TestCase
{
    use DatabaseTransactions;

    private Specialty $especialidad;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EspecialidadesYModulosSeeder::class);
        $this->especialidad = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
    }

    /** @return array{user:User, medico:Medico, clave:string} */
    private function doctor(string $prefijo): array
    {
        $sufijo = substr(uniqid(), -6);
        $alta = app(CuentaService::class)->crearMedico([
            'name' => $prefijo,
            'lastname' => 'Prueba',
            'email' => $prefijo . '-' . $sufijo . '@example.com',
            'reg_medico' => 'equipo-' . $prefijo . '-' . $sufijo,
        ]);
        $alta['user']->forceFill(['must_change_password' => false])->save();
        $alta['medico']->specialties()->attach($this->especialidad->id);

        return $alta;
    }

    private function plan(string $reg, string $codigo = 'equipo-2'): void
    {
        app(ServicioService::class)->reemplazar($reg, Plan::porCodigo($codigo), 0, 'prueba');
    }

    private function como(User $user, Medico $medico): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico,
            'specialty_id' => $this->especialidad->id,
            'office_id' => null,
        ]])->actingAs($user);
    }

    private function secretaria(Medico $medico): User
    {
        $sufijo = substr(uniqid(), -6);
        $user = User::create([
            'name' => 'Secretaria', 'email' => 'sec-' . $sufijo . '@example.com',
            'password' => 'secreto123', 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $user->id, 'reg_medico' => $medico->reg_medico]);

        return $user;
    }

    public function test_el_duenio_invita_y_el_invitado_acepta(): void
    {
        Mail::fake();
        $duenio = $this->doctor('duenio');
        $this->plan($duenio['medico']->reg_medico);
        $invitado = $this->doctor('invitado');

        $respuesta = $this->como($duenio['user'], $duenio['medico'])
            ->post(route('clinica.equipo.invitar'), ['email' => $invitado['user']->email]);

        $respuesta->assertRedirect(route('clinica.equipo'))->assertSessionHas('estado');
        $this->assertNotNull(session('invitacion_link'));

        $invitacion = InvitacionDeMedico::where('reg_medico', $duenio['medico']->reg_medico)
            ->where('email', $invitado['user']->email)
            ->firstOrFail();
        $this->assertSame(InvitacionDeMedico::PENDIENTE, $invitacion->estado);
        Mail::assertSent(InvitacionAlEquipo::class, fn (InvitacionAlEquipo $mail) => $mail->hasTo($invitado['user']->email));

        $this->actingAs($invitado['user'])
            ->get(route('clinica.invitaciones'))
            ->assertOk()
            ->assertSee($duenio['medico']->nombreMostrar);

        $this->actingAs($invitado['user'])
            ->post(route('clinica.invitaciones.aceptar', $invitacion->id))
            ->assertRedirect(route('clinica.inicio'));

        $this->assertDatabaseHas('medico_registros', [
            'medico_id' => $invitado['medico']->id,
            'reg_medico' => $duenio['medico']->reg_medico,
        ]);
        $this->assertSame(InvitacionDeMedico::ACEPTADA, $invitacion->fresh()->estado);

        // Aceptar de nuevo no duplica el vínculo.
        $this->actingAs($invitado['user'])
            ->post(route('clinica.invitaciones.aceptar', $invitacion->id))
            ->assertRedirect(route('clinica.inicio'));
        $this->assertSame(1, MedicoRegistro::where('medico_id', $invitado['medico']->id)
            ->where('reg_medico', $duenio['medico']->reg_medico)->count());

        $accesos = app(\App\Clinica\ContextoTrabajo::class)->disponibles($invitado['user']);
        $this->assertArrayHasKey($duenio['medico']->reg_medico, $accesos);
    }

    public function test_el_invitado_puede_rechazar_y_no_se_crea_el_vinculo(): void
    {
        Mail::fake();
        $duenio = $this->doctor('duenio-rech');
        $this->plan($duenio['medico']->reg_medico);
        $invitado = $this->doctor('invitado-rech');

        $this->como($duenio['user'], $duenio['medico'])
            ->post(route('clinica.equipo.invitar'), ['email' => $invitado['user']->email]);
        $invitacion = InvitacionDeMedico::firstOrFail();

        $this->actingAs($invitado['user'])
            ->post(route('clinica.invitaciones.rechazar', $invitacion->id))
            ->assertRedirect(route('clinica.invitaciones'));

        $this->assertSame(InvitacionDeMedico::RECHAZADA, $invitacion->fresh()->estado);
        $this->assertDatabaseMissing('medico_registros', [
            'medico_id' => $invitado['medico']->id,
            'reg_medico' => $duenio['medico']->reg_medico,
        ]);
    }

    public function test_el_duenio_puede_revocar_y_la_invitacion_no_se_acepta(): void
    {
        Mail::fake();
        $duenio = $this->doctor('duenio-rev');
        $this->plan($duenio['medico']->reg_medico);
        $invitado = $this->doctor('invitado-rev');

        $this->como($duenio['user'], $duenio['medico'])
            ->post(route('clinica.equipo.invitar'), ['email' => $invitado['user']->email]);
        $invitacion = InvitacionDeMedico::firstOrFail();

        $this->como($duenio['user'], $duenio['medico'])
            ->post(route('clinica.equipo.revocar', $invitacion->id))
            ->assertRedirect(route('clinica.equipo'));

        $this->assertSame(InvitacionDeMedico::REVOCADA, $invitacion->fresh()->estado);

        $this->actingAs($invitado['user'])
            ->post(route('clinica.invitaciones.aceptar', $invitacion->id))
            ->assertRedirect();
        $this->assertDatabaseMissing('medico_registros', [
            'medico_id' => $invitado['medico']->id,
            'reg_medico' => $duenio['medico']->reg_medico,
        ]);
    }

    public function test_otro_usuario_no_puede_aceptar_la_invitacion(): void
    {
        Mail::fake();
        $duenio = $this->doctor('duenio-otro');
        $this->plan($duenio['medico']->reg_medico);
        $invitado = $this->doctor('invitado-otro');
        $ajeno = $this->doctor('ajeno');

        $this->como($duenio['user'], $duenio['medico'])
            ->post(route('clinica.equipo.invitar'), ['email' => $invitado['user']->email]);
        $invitacion = InvitacionDeMedico::firstOrFail();

        $this->actingAs($ajeno['user'])
            ->post(route('clinica.invitaciones.aceptar', $invitacion->id))
            ->assertNotFound();

        $this->assertSame(InvitacionDeMedico::PENDIENTE, $invitacion->fresh()->estado);
    }

    public function test_con_el_plan_de_un_medico_no_se_puede_invitar(): void
    {
        Mail::fake();
        $duenio = $this->doctor('duenio-cupo');
        $this->plan($duenio['medico']->reg_medico, 'equipo-1');
        $invitado = $this->doctor('invitado-cupo');

        $this->como($duenio['user'], $duenio['medico'])
            ->from(route('clinica.equipo'))
            ->post(route('clinica.equipo.invitar'), ['email' => $invitado['user']->email])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, InvitacionDeMedico::count());
    }

    public function test_la_aceptacion_revalida_el_cupo_y_se_reintenta_al_mejorar_el_plan(): void
    {
        Mail::fake();
        $duenio = $this->doctor('duenio-reval');
        $this->plan($duenio['medico']->reg_medico, 'equipo-2');
        $invitado = $this->doctor('invitado-reval');

        $this->como($duenio['user'], $duenio['medico'])
            ->post(route('clinica.equipo.invitar'), ['email' => $invitado['user']->email]);
        $invitacion = InvitacionDeMedico::firstOrFail();

        // El dueño baja a un plan de un médico antes de que el invitado acepte.
        $this->plan($duenio['medico']->reg_medico, 'equipo-1');

        $this->actingAs($invitado['user'])
            ->post(route('clinica.invitaciones.aceptar', $invitacion->id))
            ->assertRedirect();
        $this->assertDatabaseMissing('medico_registros', [
            'medico_id' => $invitado['medico']->id,
            'reg_medico' => $duenio['medico']->reg_medico,
        ]);
        $this->assertSame(InvitacionDeMedico::PENDIENTE, $invitacion->fresh()->estado);

        // Mejora el plan y el invitado reintenta.
        $this->plan($duenio['medico']->reg_medico, 'equipo-2');

        $this->actingAs($invitado['user'])
            ->post(route('clinica.invitaciones.aceptar', $invitacion->id))
            ->assertRedirect(route('clinica.inicio'));
        $this->assertDatabaseHas('medico_registros', [
            'medico_id' => $invitado['medico']->id,
            'reg_medico' => $duenio['medico']->reg_medico,
        ]);
    }

    public function test_el_equipo_muestra_mejorar_plan_cuando_no_queda_cupo(): void
    {
        $duenio = $this->doctor('duenio-tope');
        $this->plan($duenio['medico']->reg_medico, 'equipo-1');

        $this->como($duenio['user'], $duenio['medico'])
            ->get(route('clinica.equipo'))
            ->assertOk()
            ->assertSee('Mejorar plan')
            ->assertSee('Tu plan incluye 1 médico')
            ->assertDontSee('Invitar a un médico');
    }

    public function test_un_medico_afiliado_no_puede_invitar(): void
    {
        Mail::fake();
        $duenio = $this->doctor('duenio-afil');
        $this->plan($duenio['medico']->reg_medico, 'equipo-2');
        $afiliado = $this->doctor('afiliado');
        app(CuentaService::class)->asignarRegistro($afiliado['medico'], $duenio['medico']->reg_medico);
        $invitado = $this->doctor('invitado-afil');

        $this->como($afiliado['user'], $duenio['medico'])
            ->get(route('clinica.equipo'))
            ->assertOk()
            ->assertSee('Sólo el médico dueño del consultorio puede invitar')
            ->assertDontSee('Invitar a un médico');

        $this->como($afiliado['user'], $duenio['medico'])
            ->post(route('clinica.equipo.invitar'), ['email' => $invitado['user']->email])
            ->assertForbidden();
    }

    public function test_la_secretaria_no_entra_al_equipo_ni_a_invitaciones(): void
    {
        $duenio = $this->doctor('duenio-sec');
        $secretaria = $this->secretaria($duenio['medico']);

        $this->como($secretaria, $duenio['medico'])->get(route('clinica.equipo'))->assertForbidden();
        $this->actingAs($secretaria)->get(route('clinica.invitaciones'))->assertForbidden();
    }

    public function test_una_invitacion_vencida_no_se_acepta(): void
    {
        Mail::fake();
        $duenio = $this->doctor('duenio-venc');
        $this->plan($duenio['medico']->reg_medico);
        $invitado = $this->doctor('invitado-venc');

        $this->como($duenio['user'], $duenio['medico'])
            ->post(route('clinica.equipo.invitar'), ['email' => $invitado['user']->email]);
        $invitacion = InvitacionDeMedico::firstOrFail();
        $invitacion->forceFill(['expira_el' => now()->subDay()])->save();

        $this->actingAs($invitado['user'])
            ->post(route('clinica.invitaciones.aceptar', $invitacion->id))
            ->assertRedirect();

        $this->assertSame(InvitacionDeMedico::VENCIDA, $invitacion->fresh()->estado);
        $this->assertDatabaseMissing('medico_registros', [
            'medico_id' => $invitado['medico']->id,
            'reg_medico' => $duenio['medico']->reg_medico,
        ]);
    }

    public function test_no_se_invita_dos_veces_al_mismo_correo(): void
    {
        Mail::fake();
        $duenio = $this->doctor('duenio-dup');
        $this->plan($duenio['medico']->reg_medico);
        $invitado = $this->doctor('invitado-dup');

        $this->como($duenio['user'], $duenio['medico'])
            ->post(route('clinica.equipo.invitar'), ['email' => $invitado['user']->email]);

        $this->como($duenio['user'], $duenio['medico'])
            ->from(route('clinica.equipo'))
            ->post(route('clinica.equipo.invitar'), ['email' => $invitado['user']->email])
            ->assertSessionHasErrors('email');

        $this->assertSame(1, InvitacionDeMedico::count());
    }

    public function test_una_cuenta_que_no_es_de_medico_no_se_puede_invitar(): void
    {
        Mail::fake();
        $duenio = $this->doctor('duenio-nomed');
        $this->plan($duenio['medico']->reg_medico);

        $cuenta = User::create([
            'name' => 'No médico', 'email' => 'nomed-' . substr(uniqid(), -6) . '@example.com',
            'password' => 'secreto123', 'is_active' => true,
        ]);
        $cuenta->assignRole('Secretaria');

        $this->como($duenio['user'], $duenio['medico'])
            ->from(route('clinica.equipo'))
            ->post(route('clinica.equipo.invitar'), ['email' => $cuenta->email])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, InvitacionDeMedico::count());
    }
}
