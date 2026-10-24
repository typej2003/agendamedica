<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\UsuariosPacientes;
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
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **Identidad por niveles y bloqueo de pacientes-cuenta** (nivel 0 sin validar, nivel 1 teléfono validado por
 * código, nivel 2 cédula confirmada por el personal): qué se bloquea según el nivel, la regla de coincidencia, el
 * vencimiento del bloqueo por teléfono y que la secretaría y el médico agendan igual desde la web clínica.
 */
class IdentidadPorNivelesTest extends TestCase
{
    use DatabaseTransactions;
    use PreparaRecipesParaImprimir;

    private int $secuencia = 0;

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

    private function admin(): User
    {
        $user = User::create([
            'name' => 'Admin',
            'email' => 'idn-admin-' . substr(uniqid(), -8) . '@example.com',
            'password' => Hash::make('secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('Root');

        return $user;
    }

    /** Cuenta de paciente con ficha. `$nivel`: 0 sin validar, 1 teléfono validado hoy, 2 además cédula confirmada. */
    private function cuenta(string $cedula, string $telefono, int $nivel = 0): User
    {
        $user = User::create([
            'name' => 'Cuenta ' . (++$this->secuencia),
            'email' => 'idn-' . substr(uniqid(), -8) . '@example.com',
            'password' => Hash::make('secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('Paciente');
        $ficha = Paciente::create([
            'cedula' => $cedula, 'nombres' => 'Ana', 'apellidos' => 'Cuenta', 'telefono' => $telefono,
            'email' => $user->email, 'user_id' => $user->id,
        ]);

        if ($nivel >= 1) {
            $user->forceFill(['telefono_validado' => Telefono::internacional($telefono), 'telefono_validado_en' => now()])->save();
        }

        if ($nivel >= 2) {
            $this->confirmarCedula($ficha);
        }

        return $user->fresh();
    }

    private function confirmarCedula(Paciente $ficha): void
    {
        PacienteIdentidad::create([
            'paciente_id' => $ficha->id,
            'cedula' => preg_replace('/\D/', '', $ficha->cedula),
            'cedula_confirmada_en' => now(),
        ]);
    }

    private function regla(): BloqueoDePacientes
    {
        return app(BloqueoDePacientes::class);
    }

    public function test_el_nivel_de_la_cuenta_sale_de_la_validacion_del_telefono_y_de_la_cedula_confirmada(): void
    {
        $niveles = app(NivelesDeIdentidad::class);

        $this->assertSame(0, $niveles->nivel($this->cuenta('20000001', '0414-2000001', 0)));
        $this->assertSame(1, $niveles->nivel($this->cuenta('20000002', '0414-2000002', 1)));
        $this->assertSame(2, $niveles->nivel($this->cuenta('20000003', '0414-2000003', 2)));

        // La validación del teléfono vence a los 12 meses: la cuenta vuelve al nivel 0.
        $vieja = $this->cuenta('20000004', '0414-2000004', 1);
        $this->assertSame(1, $niveles->nivel($vieja));
        $vieja->forceFill(['telefono_validado_en' => now()->subMonths(12)->subDay()])->save();
        $this->assertSame(0, $niveles->nivel($vieja->fresh()));
        $this->assertNull($niveles->telefonoValidadoVigente($vieja->fresh()));
    }

    public function test_si_la_ficha_cambia_de_cedula_la_confirmacion_ya_no_vale(): void
    {
        $niveles = app(NivelesDeIdentidad::class);
        $cuenta = $this->cuenta('20000005', '0414-2000005', 2);
        $this->assertTrue($niveles->cedulaConfirmada('20000005'));

        $cuenta->paciente->update(['cedula' => '20000099']);

        $this->assertFalse($niveles->cedulaConfirmada('20000005'));
        $this->assertFalse($niveles->cedulaConfirmada('20000099'));
    }

    public function test_bloquear_escribe_los_alcances_segun_el_nivel(): void
    {
        $admin = $this->admin();
        $n0 = $this->cuenta('20000010', '0414-2000010', 0);
        $n1 = $this->cuenta('20000011', '0414-2000011', 1);
        $n2 = $this->cuenta('20000012', '0414-2000012', 2);

        foreach ([$n0, $n1, $n2] as $cuenta) {
            $this->regla()->bloquear($cuenta, 'spam', $admin);
        }

        $this->assertSame(1, BloqueoDePaciente::where('user_id', $n0->id)->count(), 'Nivel 0: sólo la cuenta.');
        $this->assertSame(0, BloqueoDePaciente::where('user_id', $n0->id)->whereNotNull('telefono')->count());

        $this->assertSame(2, BloqueoDePaciente::where('user_id', $n1->id)->count(), 'Nivel 1: cuenta + teléfono.');
        $telefono = BloqueoDePaciente::where('user_id', $n1->id)->whereNotNull('telefono')->firstOrFail();
        $this->assertSame('584142000011', $telefono->telefono);
        $this->assertTrue($telefono->vence_en->equalTo(now()->addMonthsNoOverflow(6)));
        $this->assertSame($admin->id, (int) $telefono->creado_por);

        $this->assertSame(3, BloqueoDePaciente::where('user_id', $n2->id)->count(), 'Nivel 2: cuenta + teléfono + cédula.');
        $this->assertSame('20000012', BloqueoDePaciente::where('user_id', $n2->id)->whereNotNull('cedula')->value('cedula'));
        $this->assertNull(BloqueoDePaciente::where('user_id', $n2->id)->whereNotNull('cedula')->value('vence_en'));
    }

    public function test_coincidencia_por_cuenta_telefono_validado_y_cedula_confirmada(): void
    {
        $bloqueada = $this->cuenta('20000020', '0414-2000020', 2);
        $this->regla()->bloquear($bloqueada, '', $this->admin());

        // (a) la cuenta.
        $this->assertTrue($this->regla()->estaBloqueado($bloqueada->fresh()));

        // (b) un teléfono validado igual.
        $this->assertTrue($this->regla()->estaBloqueado(null, '0414-2000020'));
        $this->assertTrue($this->regla()->estaBloqueado(null, '584142000020'));
        $this->assertFalse($this->regla()->estaBloqueado(null, '0414-2999999'));

        // (c) la cédula confirmada.
        $this->assertTrue($this->regla()->estaBloqueado(null, null, 'V-20.000.020'));
        $this->assertFalse($this->regla()->estaBloqueado(null, null, '20000021'));

        // Otra cuenta cuyo teléfono validado vigente es el mismo (número reasignado en 6 meses): se bloquea.
        $otra = $this->cuenta('20000022', '0414-2000020', 1);
        $this->assertTrue($this->regla()->estaBloqueado($otra));

        // Una cuenta sin relación no.
        $this->assertFalse($this->regla()->estaBloqueado($this->cuenta('20000023', '0414-2000023', 2)));
    }

    public function test_una_cedula_sin_confirmar_no_bloquea_por_si_sola(): void
    {
        // Nivel 1: bloquea cuenta y teléfono, pero la cédula tecleada por el paciente no entra.
        $bloqueada = $this->cuenta('20000030', '0414-2000030', 1);
        $this->regla()->bloquear($bloqueada, '', $this->admin());

        $this->assertFalse($this->regla()->estaBloqueado(null, null, '20000030'));
        $this->assertSame(0, BloqueoDePaciente::where('user_id', $bloqueada->id)->whereNotNull('cedula')->count());

        // Aunque exista una fila por una cédula, si esa cédula no está confirmada en ninguna ficha no bloquea.
        BloqueoDePaciente::create(['cedula' => '20000031', 'creado_en' => now()]);
        Paciente::create(['cedula' => '20000031', 'nombres' => 'Sin', 'apellidos' => 'Confirmar']);
        $this->assertFalse($this->regla()->estaBloqueado(null, null, '20000031'));
    }

    public function test_un_telefono_vencido_o_sin_validar_no_cuenta_para_bloquear(): void
    {
        $bloqueada = $this->cuenta('20000040', '0414-2000040', 1);
        $this->regla()->bloquear($bloqueada, '', $this->admin());
        $this->desbloquearSoloLaCuenta($bloqueada);

        // Otra persona con ese teléfono SIN validar (nivel 0): no se compara.
        $sinValidar = $this->cuenta('20000041', '0414-2000040', 0);
        $this->assertFalse($this->regla()->estaBloqueado($sinValidar));

        // Con la validación vencida (13 meses): tampoco.
        $vencida = $this->cuenta('20000042', '0414-2000040', 1);
        $vencida->forceFill(['telefono_validado_en' => now()->subMonths(13)])->save();
        $this->assertFalse($this->regla()->estaBloqueado($vencida->fresh()));

        // Vigente: sí.
        $vigente = $this->cuenta('20000043', '0414-2000040', 1);
        $this->assertTrue($this->regla()->estaBloqueado($vigente));
    }

    /** Deja la cuenta activa pero conserva las filas de teléfono (para probar la regla de teléfono aislada). */
    private function desbloquearSoloLaCuenta(User $cuenta): void
    {
        $cuenta->forceFill(['is_active' => true])->save();
        BloqueoDePaciente::where('user_id', $cuenta->id)->whereNull('telefono')->whereNull('cedula')->update(['levantado_en' => now()]);
    }

    public function test_el_bloqueo_por_telefono_vence_a_los_seis_meses_y_el_de_cedula_no(): void
    {
        $bloqueada = $this->cuenta('20000050', '0414-2000050', 2);
        $this->regla()->bloquear($bloqueada, '', $this->admin());

        $this->assertTrue($this->regla()->estaBloqueado(null, '0414-2000050'));

        \Illuminate\Support\Carbon::setTestNow(now()->addMonthsNoOverflow(6)->subDay());
        $this->assertTrue($this->regla()->estaBloqueado(null, '0414-2000050'), 'Un día antes del plazo sigue vigente.');

        \Illuminate\Support\Carbon::setTestNow(now()->addDays(2));
        $this->assertFalse($this->regla()->estaBloqueado(null, '0414-2000050'), 'Pasados los 6 meses el teléfono queda libre.');
        $this->assertTrue($this->regla()->estaBloqueado(null, null, '20000050'), 'La cédula confirmada sigue bloqueada.');
    }

    public function test_desbloquear_levanta_todo_lo_de_esa_persona(): void
    {
        $admin = $this->admin();
        $cuenta = $this->cuenta('20000060', '0414-2000060', 2);
        $this->regla()->bloquear($cuenta, 'x', $admin);
        $this->assertTrue($this->regla()->estaBloqueado(null, '0414-2000060', '20000060'));

        $this->regla()->desbloquear($cuenta->fresh(), $admin);

        $this->assertFalse($this->regla()->estaBloqueado(null, '0414-2000060', '20000060'));
        $this->assertFalse($this->regla()->estaBloqueado($cuenta->fresh()));
        $this->assertSame(0, BloqueoDePaciente::vigentes()->where('user_id', $cuenta->id)->count());
        $this->assertSame(3, BloqueoDePaciente::where('user_id', $cuenta->id)->whereNotNull('levantado_en')->where('levantado_por', $admin->id)->count());
    }

    public function test_volver_a_bloquear_renueva_en_vez_de_duplicar(): void
    {
        $admin = $this->admin();
        $cuenta = $this->cuenta('20000065', '0414-2000065', 1);
        $this->regla()->bloquear($cuenta, '', $admin);
        $this->regla()->bloquear($cuenta->fresh(), '', $admin);

        $this->assertSame(2, BloqueoDePaciente::vigentes()->where('user_id', $cuenta->id)->count());
    }

    public function test_el_chatbot_trata_el_numero_de_whatsapp_como_validado_pero_no_el_telefono_de_la_ficha(): void
    {
        $bloqueada = $this->cuenta('20000070', '0414-2000070', 1);
        $this->regla()->bloquear($bloqueada, '', $this->admin());

        // El número que escribe por WhatsApp coincide con el teléfono bloqueado.
        $this->assertTrue($this->regla()->estaBloqueadoPorWhatsApp('584142000070', $bloqueada->paciente));

        // Una ficha vieja de la cuenta bloqueada con OTRO teléfono: ese número de WhatsApp no es el validado de la cuenta,
        // así que la ficha no arrastra el bloqueo.
        $this->assertFalse($this->regla()->estaBloqueadoPorWhatsApp('584142999000', $bloqueada->paciente));
    }

    public function test_el_medico_y_la_secretaria_agendan_igual_a_un_paciente_bloqueado(): void
    {
        [$medicoUser, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = $this->secretariaConAcceso($medico);
        $sede = $this->sede($medico, 'hora_cita');

        $cuenta = $this->cuenta('20000080', '0414-2000080', 2);
        $this->regla()->bloquear($cuenta, 'spam', $this->admin());
        $this->assertTrue($this->regla()->estaBloqueado(null, '0414-2000080', '20000080'));

        $alta = fn (string $hora) => [
            'sede' => $sede->id, 'fecha' => '2026-10-14', 'hora' => $hora,
            'nuevo_cedula' => '20000080', 'nuevo_fnacimiento' => '1990-01-15', 'nuevo_nombres' => 'Ana', 'nuevo_apellidos' => 'Cuenta', 'nuevo_telefono' => '0414-2000080',
        ];

        $this->conContexto($medicoUser, $medico, $gineco)->actingAs($medicoUser)
            ->post(route('clinica.agenda.crear'), $alta('09:00'))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Cola::where('reg_medico', $medico->reg_medico)->count(), 'El médico agenda al paciente bloqueado.');

        // Otro paciente bloqueado (el primero ya tiene una cita pendiente y la agenda preguntaría).
        $otra = $this->cuenta('20000081', '0414-2000081', 2);
        $this->regla()->bloquear($otra, 'spam', $this->admin());

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->post(route('clinica.agenda.crear'), ['nuevo_cedula' => '20000081', 'nuevo_fnacimiento' => '1990-01-15', 'nuevo_nombres' => 'Ana', 'nuevo_apellidos' => 'Cuenta', 'nuevo_telefono' => '0414-2000081', 'sede' => $sede->id, 'fecha' => '2026-10-14', 'hora' => '10:00'])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, Cola::where('reg_medico', $medico->reg_medico)->count(), 'La secretaría también.');
    }

    public function test_el_listado_admin_muestra_el_nivel_y_que_se_bloqueara(): void
    {
        $n2 = $this->cuenta('20000090', '0414-2000090', 2);
        $n0 = $this->cuenta('20000091', '0414-2000091', 0);

        $this->actingAs($this->admin());

        Livewire::test(UsuariosPacientes::class)
            ->set('search', '20000090')
            ->assertSee('Nivel 2 · Cédula confirmada')
            ->call('abrir', 'bloquear', $n2->id)
            ->assertSee('Identidad: nivel 2')
            ->assertSee('+584142000090')
            ->assertSee('La cédula 20000090')
            ->call('cerrarModal')
            ->set('search', '20000091')
            ->assertSee('Nivel 0 · Sin validar')
            ->call('abrir', 'bloquear', $n0->id)
            ->assertSee('El teléfono no: no está validado')
            ->assertSee('La cédula no: no está confirmada');
    }
}
