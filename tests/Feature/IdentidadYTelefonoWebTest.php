<?php

namespace Tests\Feature;

use App\Models\BloqueoDePaciente;
use App\Models\Cola;
use App\Models\Paciente;
use App\Models\PacienteIdentidad;
use App\Models\User;
use App\Notificaciones\Telefono;
use App\Paciente\BloqueoDePacientes;
use App\Paciente\NivelesDeIdentidad;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **Confirmar la cédula en el consultorio** (identidad nivel 2) y **la expiración de la validación del teléfono**
 * (nivel 1) en el portal «Mis citas» y en la reserva pública.
 */
class IdentidadYTelefonoWebTest extends TestCase
{
    use DatabaseTransactions;
    use PreparaRecipesParaImprimir;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->fijarHoy();
    }

    protected function tearDown(): void
    {
        $this->soltarHoy();
        parent::tearDown();
    }

    private function cuenta(string $cedula, string $telefono, ?\DateTimeInterface $validadoEn): User
    {
        $user = User::create([
            'name' => 'Cuenta Portal',
            'email' => 'itw-' . substr(uniqid(), -8) . '@example.com',
            'password' => Hash::make('secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('Paciente');
        Paciente::create(['cedula' => $cedula, 'nombres' => 'Ana', 'apellidos' => 'Portal', 'telefono' => $telefono, 'email' => $user->email, 'user_id' => $user->id]);

        if ($validadoEn !== null) {
            $user->forceFill(['telefono_validado' => Telefono::internacional($telefono), 'telefono_validado_en' => $validadoEn])->save();
        }

        return $user->fresh();
    }

    /** Una cita cualquiera: la ruta resuelve `{cita}` antes del middleware, que es el que redirige. */
    private function citaCualquiera(): int
    {
        return Cola::create(['reg_medico' => 'itw-' . substr(uniqid(), -6), 'fecha' => '2026-10-19', 'hora_ini' => '09:00', 'atendido' => 0, 'estado' => 2])->id;
    }

    // ------------------------------------------------------------------ Confirmar la cédula

    public function test_el_medico_confirma_la_cedula_y_la_ficha_lo_muestra(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [$pacienteId] = $this->pacienteYConsulta($medico, 1001, 1);
        $ficha = Paciente::findOrFail($pacienteId);
        $ficha->update(['cedula' => '21000001']);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.ver', $pacienteId))
            ->assertOk()
            ->assertSee('Confirmar cédula (cédula laminada)')
            ->assertDontSee('Cédula confirmada');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.pacientes.confirmar-cedula', $pacienteId))
            ->assertRedirect(route('clinica.pacientes.ver', $pacienteId))
            ->assertSessionHas('estado', 'Cédula confirmada.');

        $identidad = PacienteIdentidad::where('paciente_id', $pacienteId)->firstOrFail();
        $this->assertSame('21000001', $identidad->cedula);
        $this->assertSame($user->id, (int) $identidad->confirmada_por_user_id);
        $this->assertSame($medico->reg_medico, $identidad->reg_medico);
        $this->assertTrue($identidad->cedula_confirmada_en->equalTo(now()));

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.ver', $pacienteId))
            ->assertSee('Cédula confirmada')
            ->assertDontSee('Confirmar cédula (cédula laminada)');

        $this->assertTrue(app(NivelesDeIdentidad::class)->cedulaConfirmada('21000001'));
    }

    public function test_la_secretaria_tambien_confirma_la_cedula(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = $this->secretariaConAcceso($medico);
        [$pacienteId] = $this->pacienteYConsulta($medico, 1001, 1);
        Paciente::findOrFail($pacienteId)->update(['cedula' => '21000002']);

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->post(route('clinica.pacientes.confirmar-cedula', $pacienteId))
            ->assertSessionHas('estado', 'Cédula confirmada.');

        $this->assertSame($secretaria->id, (int) PacienteIdentidad::where('paciente_id', $pacienteId)->value('confirmada_por_user_id'));
    }

    public function test_un_paciente_de_otro_consultorio_da_404_y_un_paciente_cuenta_no_puede(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [$otroUser, $otroMedico] = $this->medicoConAcceso();
        [$deOtro] = $this->pacienteYConsulta($otroMedico, 2002, 1);
        Paciente::findOrFail($deOtro)->update(['cedula' => '21000003']);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.pacientes.confirmar-cedula', $deOtro))
            ->assertNotFound();
        $this->assertSame(0, PacienteIdentidad::where('paciente_id', $deOtro)->count());

        $portal = $this->cuenta('21000004', '0414-2100004', now());
        $this->actingAs($portal)
            ->post(route('clinica.pacientes.confirmar-cedula', $deOtro))
            ->assertForbidden();
    }

    public function test_sin_cedula_no_se_puede_confirmar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [$pacienteId] = $this->pacienteYConsulta($medico, 1001, 1);
        Paciente::findOrFail($pacienteId)->update(['cedula' => 'S.C.']);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.ver', $pacienteId))
            ->assertDontSee('Confirmar cédula (cédula laminada)');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.pacientes.confirmar-cedula', $pacienteId))
            ->assertSessionHas('error');
        $this->assertSame(0, PacienteIdentidad::where('paciente_id', $pacienteId)->count());
    }

    public function test_confirmar_la_cedula_hace_que_el_proximo_bloqueo_la_incluya(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $cuenta = $this->cuenta('21000005', '0414-2100005', now());
        $ficha = $cuenta->paciente;
        $regla = app(BloqueoDePacientes::class);
        $admin = User::create(['name' => 'Root', 'email' => 'itw-root-' . substr(uniqid(), -6) . '@example.com', 'password' => Hash::make('x12345678'), 'is_active' => true, 'must_change_password' => false]);
        $admin->assignRole('Root');

        $regla->bloquear($cuenta, '', $admin);
        $this->assertFalse($regla->estaBloqueado(null, null, '21000005'), 'Cédula sin confirmar: no bloquea.');
        $regla->desbloquear($cuenta->fresh(), $admin);

        // Vínculo del paciente con el médico para que la ficha sea visible, y confirmación en la web.
        $this->pacienteYConsulta($medico, 3003, 1);
        \App\Models\MedicoPaciente::updateOrCreate(
            ['reg_medico' => $medico->reg_medico, 'paciente_id' => $ficha->id],
            ['medico_id' => $medico->id, 'numhistoria' => '3004'],
        );
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.pacientes.confirmar-cedula', $ficha->id))
            ->assertSessionHas('estado');

        $regla->bloquear($cuenta->fresh(), '', $admin);
        $this->assertTrue($regla->estaBloqueado(null, null, '21000005'));
        $this->assertSame(1, BloqueoDePaciente::vigentes()->where('user_id', $cuenta->id)->whereNotNull('cedula')->count());
    }

    // ------------------------------------------------------------------ Expiración del teléfono

    public function test_el_registro_deja_el_telefono_validado(): void
    {
        $datos = [
            'cedula' => '21000010', 'nombres' => 'Rosa', 'apellidos' => 'Nueva', 'telefono' => '0412-2100010',
            'email' => 'rosa-' . substr(uniqid(), -6) . '@example.com', 'password' => 'Secreto123', 'password_confirmation' => 'Secreto123',
            'terminos' => '1',
        ];

        $this->post(route('paciente.registro.codigo.enviar'), $datos)->assertRedirect(route('paciente.registro.codigo'));
        $this->post(route('paciente.registro.confirmar'), ['codigo' => (string) session('registro.pendiente.codigo')])
            ->assertRedirect(route('paciente.citas'));

        $user = User::where('email', $datos['email'])->firstOrFail();
        $this->assertSame('584122100010', $user->telefono_validado);
        $this->assertTrue($user->telefono_validado_en->equalTo(now()));
        $this->assertSame(1, app(NivelesDeIdentidad::class)->nivel($user));
    }

    public function test_con_el_telefono_vencido_reprogramar_pide_confirmarlo_y_luego_vuelve(): void
    {
        $vencida = $this->cuenta('21000020', '0414-2100020', now()->subMonths(12)->subDay());
        $url = route('paciente.citas.reprogramar', $this->citaCualquiera());

        $this->actingAs($vencida)->get($url)
            ->assertRedirect(route('paciente.telefono'))
            ->assertSessionHas('error');

        $this->get(route('paciente.telefono'))->assertOk()->assertSee('Confirma tu teléfono')->assertSee('04142100020');

        // Confirmarlo: mismo número, código al teléfono.
        $this->post(route('paciente.telefono.codigo'), ['telefono' => '0414-2100020'])->assertRedirect(route('paciente.telefono'));
        $this->post(route('paciente.telefono.confirmar'), ['codigo' => '000000'])->assertSessionHasErrors('codigo');
        $this->post(route('paciente.telefono.confirmar'), ['codigo' => (string) session('telefono.pendiente.codigo')])
            ->assertRedirect($url)
            ->assertSessionHas('estado');

        $vencida->refresh();
        $this->assertTrue($vencida->telefono_validado_en->equalTo(now()));
        $this->assertSame(1, app(NivelesDeIdentidad::class)->nivel($vencida));
    }

    public function test_cambiar_el_telefono_exige_el_codigo_al_numero_nuevo_y_actualiza_la_ficha(): void
    {
        $cuenta = $this->cuenta('21000030', '0414-2100030', now()->subMonths(14));
        $this->actingAs($cuenta);

        $this->post(route('paciente.telefono.codigo'), ['telefono' => '0424-2199999']);
        $this->assertSame('584242199999', session('telefono.pendiente.telefono'));
        $this->assertSame('584142100030', $cuenta->fresh()->telefono_validado, 'Todavía no cambia: falta el código.');

        $this->post(route('paciente.telefono.confirmar'), ['codigo' => (string) session('telefono.pendiente.codigo')])
            ->assertRedirect(route('paciente.citas'));

        $cuenta->refresh();
        $this->assertSame('584242199999', $cuenta->telefono_validado);
        $this->assertSame('584242199999', Telefono::internacional($cuenta->paciente->telefono));
        $this->assertTrue(app(NivelesDeIdentidad::class)->telefonoVigente($cuenta));
    }

    public function test_un_telefono_no_valido_se_rechaza(): void
    {
        $cuenta = $this->cuenta('21000031', '0414-2100031', now()->subMonths(14));

        $this->actingAs($cuenta)->post(route('paciente.telefono.codigo'), ['telefono' => '123'])
            ->assertSessionHasErrors('telefono');
    }

    public function test_un_paciente_bloqueado_por_telefono_no_reprograma_aunque_su_cuenta_este_activa(): void
    {
        $bloqueada = $this->cuenta('21000040', '0414-2100040', now());
        $admin = User::create(['name' => 'Root', 'email' => 'itw-r2-' . substr(uniqid(), -6) . '@example.com', 'password' => Hash::make('x12345678'), 'is_active' => true, 'must_change_password' => false]);
        $admin->assignRole('Root');
        app(BloqueoDePacientes::class)->bloquear($bloqueada, '', $admin);

        // Otra cuenta, activa, que heredó el número (validado y vigente): queda bloqueada por el teléfono.
        $heredera = $this->cuenta('21000041', '0414-2100040', now());

        $this->actingAs($heredera)->get(route('paciente.citas.reprogramar', $this->citaCualquiera()))
            ->assertRedirect(route('paciente.citas'))
            ->assertSessionHas('error', BloqueoDePacientes::AVISO);
    }
}
