<?php

namespace Tests\Feature;

use App\Models\Evolucion;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\NotificacionCumple;
use App\Models\Paciente;
use App\Models\Specialty;
use App\Models\User;
use App\Notificaciones\Canal;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * La pantalla de **cumpleaños** (WEB-2.9) y el comando del scheduler, contra la base real.
 *
 * Lo que se prueba es el criterio de la tarea: la pantalla lista los que cumplen años **hoy** y **sólo
 * los del médico del contexto**; el interruptor, el canal y la hora se guardan; con el envío activo y la
 * hora cumplida el comando manda y **una segunda corrida no repite**; y al que le falta el dato del
 * canal configurado se lo saltea sin romper el resto.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica. Los envíos van **siempre** con
 * `Mail::fake()`/`Http::fake()`: nunca sale un correo, un SMS ni un WhatsApp de verdad.
 */
class CumpleanosWebTest extends TestCase
{
    use DatabaseTransactions;

    private const HOY = '2026-10-10';

    /** @var array<string,string> */
    private array $twilio = [
        'services.twilio.sid'   => 'ACprueba',
        'services.twilio.token' => 'token-de-prueba',
        'services.twilio.from'  => '+15005550006',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // El reloj de la aplicación se fija en la fecha de las pruebas: el comando y la pantalla deciden
        // "hoy" con la fecha del consultorio, que sale de `now()`.
        Carbon::setTestNow(Carbon::parse(self::HOY . ' 10:00:00', 'America/Caracas'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // --------------------------------------------------------------------------------------- Ayudas

    private function medico(?string $regMedico = null): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = $regMedico ?: 'cumple-test-' . substr(uniqid(), -6);

        $user = User::create([
            'name'                 => 'Médico de prueba',
            'email'                => 'cumple-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Carlos',
            'lastname'   => 'Mendoza',
            'prefix'     => 'Dr',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->actingAs($user)->withSession(['clinica.contexto' => [
            'reg_medico'   => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id'    => null,
        ]]);
    }

    private function paciente(Medico $medico, string $fnacimiento, array $extra = []): Paciente
    {
        $paciente = Paciente::create(array_merge([
            'nombres'     => 'Ana',
            'apellidos'   => 'Pérez',
            'cedula'      => (string) random_int(10000000, 30000000),
            'fnacimiento' => $fnacimiento,
            'telefono'    => '0414-1234567',
            'email'       => 'ana@example.com',
        ], $extra));

        MedicoPaciente::create([
            'medico_id'   => $medico->id,
            'paciente_id' => $paciente->id,
            'numhistoria' => random_int(1000, 9999),
            'reg_medico'  => $medico->reg_medico,
        ]);

        return $paciente;
    }

    private function configurar(Medico $medico, array $valores = []): Evolucion
    {
        $evolucion = Evolucion::firstOrNew(['reg_medico' => $medico->reg_medico]);
        $evolucion->reg_medico = $medico->reg_medico;
        $evolucion->clave = $evolucion->clave ?? 1;

        // `array_key_exists` y no `??`: apagar el interruptor es pasar `null` a propósito, y `??` lo
        // trataría como "no lo pasaron" y lo prendería.
        $evolucion->enviar_cumpleanos = array_key_exists('enviar', $valores) ? $valores['enviar'] : 1;        $evolucion->canal_cumpleanos = $valores['canal'] ?? Canal::CORREO;
        $evolucion->hora_cumpleanos = $valores['hora'] ?? '09:00:00';
        $evolucion->plantilla_cumple = $valores['plantilla'] ?? '¡Feliz cumpleaños {paciente}! — {doctor}';
        $evolucion->save();

        return $evolucion;
    }

    /**
     * Cuántos saludos **exitosos** tiene ese paciente. Se cuenta por paciente y no con
     * `NotificacionCumple::count()` porque la base de pruebas viene sembrada con datos clínicos de
     * ejemplo, y algunos de esos pacientes cumplen años el mismo día que el de la prueba.
     */
    private function enviosDe(Paciente $paciente): int
    {
        return NotificacionCumple::where('paciente_id', $paciente->id)
            ->where('estado', NotificacionCumple::ESTADO_ENVIADA)
            ->count();
    }

    // -------------------------------------------------------------------------------------- Pantalla

    public function test_la_pantalla_lista_los_que_cumplen_hoy_y_solo_los_del_medico(): void
    {
        [$user, $medico, $gineco] = $this->medico();
        [$otroUser, $otro, $otraEspecialidad] = $this->medico();

        $hoy = $this->paciente($medico, '1990-10-10', ['nombres' => 'Ana', 'apellidos' => 'Pérez']);
        $this->paciente($medico, '1985-10-11', ['nombres' => 'Marta', 'apellidos' => 'Otra']);
        $this->paciente($otro, '1990-10-10', ['nombres' => 'Ajena', 'apellidos' => 'DeOtro']);

        $respuesta = $this->conContexto($user, $medico, $gineco)->get('/clinica/cumpleanos?fecha=' . self::HOY);

        $respuesta->assertOk();
        $respuesta->assertSee('Ana');
        $respuesta->assertSee('Pérez');
        $respuesta->assertSee('36 años');
        $respuesta->assertDontSee('Marta');
        // El paciente de otro médico no aparece ni siquiera con la misma fecha.
        $respuesta->assertDontSee('DeOtro');
    }

    /**
     * En el celular el contacto va **apilado** (etiqueta arriba, valor con todo el ancho): en la fila de siempre
     * el correo se partía a mitad de palabra. El CSS que lo apila cuelga de esta clase; el ancho real lo mide
     * `.agent-tools/verificar-visual-movil-web.js`.
     */
    public function test_la_celda_de_contacto_se_apila_en_el_celular(): void
    {
        [$user, $medico, $gineco] = $this->medico();
        $this->paciente($medico, '1990-10-10', ['nombres' => 'Ana', 'apellidos' => 'Pérez', 'email' => 'una.direccion.muy.larga@example.com']);

        $this->conContexto($user, $medico, $gineco)->get('/clinica/cumpleanos?fecha=' . self::HOY)
            ->assertOk()
            ->assertSee('<td class="celda-apilada" data-etiqueta="Contacto">', false)
            ->assertSee('una.direccion.muy.larga@example.com');

        $css = file_get_contents(public_path('css/doctorisimo-ui.css'));
        $this->assertMatchesRegularExpression('/\.tabla-tarjetas td\.celda-apilada\[data-etiqueta\]\s*\{[^}]*flex-direction:\s*column/s', $css);
    }

    public function test_el_que_nacio_un_29_de_febrero_aparece_el_28_y_la_pantalla_lo_dice(): void
    {
        [$user, $medico, $gineco] = $this->medico();
        // 1992 sí fue bisiesto. `Paciente` castea `fnacimiento` a fecha, así que un 29/02 de un año
        // común no se puede escribir por el modelo (Carbon lo corre al 1 de marzo) — en los datos
        // reales el 29/02 solo existe en años bisiestos, y es lo que el legado tiene guardado.
        $this->paciente($medico, '1992-02-29', ['nombres' => 'Bisiesto', 'apellidos' => 'Febrero']);

        // 2026 no es bisiesto: se lo saluda el 28.
        $respuesta = $this->conContexto($user, $medico, $gineco)->get('/clinica/cumpleanos?fecha=2026-02-28');

        $respuesta->assertOk();
        $respuesta->assertSee('Bisiesto');
        $respuesta->assertSee('Nació el 29/02');
        $respuesta->assertSee('34 años');

        // Y el 1 de marzo ya no está.
        $this->conContexto($user, $medico, $gineco)
            ->get('/clinica/cumpleanos?fecha=2026-03-01')
            ->assertOk()
            ->assertDontSee('Bisiesto');
    }

    public function test_la_pantalla_marca_al_que_le_falta_el_dato_del_canal_configurado(): void
    {
        [$user, $medico, $gineco] = $this->medico();
        $this->configurar($medico, ['canal' => Canal::CORREO]);
        $this->paciente($medico, '1990-10-10', ['email' => null]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->get('/clinica/cumpleanos?fecha=' . self::HOY);

        $respuesta->assertOk();
        $respuesta->assertSee('Sin correo: se saltea');
    }

    public function test_sin_cumpleanos_la_pantalla_muestra_el_estado_vacio(): void
    {
        [$user, $medico, $gineco] = $this->medico();

        $this->conContexto($user, $medico, $gineco)
            ->get('/clinica/cumpleanos?fecha=' . self::HOY)
            ->assertOk()
            ->assertSee('Hoy no cumple años ningún paciente.');
    }

    // ------------------------------------------------------------------------------- Configuración

    public function test_la_configuracion_se_guarda_con_interruptor_canal_hora_y_mensaje(): void
    {
        [$user, $medico, $gineco] = $this->medico();

        $this->conContexto($user, $medico, $gineco)
            ->patch('/clinica/cumpleanos/configuracion', [
                'enviar'    => '1',
                'canal'     => Canal::SMS,
                'hora'      => '08:15',
                'plantilla' => 'Muchas felicidades, {paciente}.',
            ])
            ->assertRedirect(route('clinica.cumpleanos'));

        $evolucion = Evolucion::where('reg_medico', $medico->reg_medico)->firstOrFail();
        $this->assertTrue((bool) $evolucion->enviar_cumpleanos);
        $this->assertSame(Canal::SMS, $evolucion->canal_cumpleanos);
        $this->assertSame('08:15:00', $evolucion->hora_cumpleanos);
        $this->assertSame('Muchas felicidades, {paciente}.', $evolucion->plantilla_cumple);
    }

    public function test_apagar_el_interruptor_deja_null_y_no_cambia_el_canal(): void
    {
        [$user, $medico, $gineco] = $this->medico();

        $this->conContexto($user, $medico, $gineco)
            ->patch('/clinica/cumpleanos/configuracion', ['canal' => Canal::WHATSAPP, 'hora' => '12:00'])
            ->assertRedirect(route('clinica.cumpleanos'));

        $evolucion = Evolucion::where('reg_medico', $medico->reg_medico)->firstOrFail();
        // "Apagado" tiene un solo valor: `null`, no `false` (el mismo criterio que las otras marcas).
        $this->assertNull($evolucion->enviar_cumpleanos);
        $this->assertSame(Canal::WHATSAPP, $evolucion->canal_cumpleanos);
        $this->assertSame('12:00:00', $evolucion->hora_cumpleanos);
    }

    public function test_la_hora_con_formato_invalido_se_rechaza(): void
    {
        [$user, $medico, $gineco] = $this->medico();

        $this->conContexto($user, $medico, $gineco)
            ->patch('/clinica/cumpleanos/configuracion', ['canal' => Canal::CORREO, 'hora' => '25:99'])
            ->assertSessionHasErrors('hora');

        $this->assertNull(Evolucion::where('reg_medico', $medico->reg_medico)->first());
    }

    public function test_un_canal_que_no_existe_se_rechaza(): void
    {
        [$user, $medico, $gineco] = $this->medico();

        $this->conContexto($user, $medico, $gineco)
            ->patch('/clinica/cumpleanos/configuracion', ['canal' => 'telegram', 'hora' => '09:00'])
            ->assertSessionHasErrors('canal');
    }

    // ------------------------------------------------------------------------------- Envío manual

    public function test_el_envio_manual_manda_el_correo_con_la_plantilla_y_lo_registra(): void
    {
        Mail::fake();

        [$user, $medico, $gineco] = $this->medico();
        $this->configurar($medico, ['canal' => Canal::CORREO, 'plantilla' => 'Feliz cumple, {paciente}.']);
        $paciente = $this->paciente($medico, '1990-10-10');

        $this->conContexto($user, $medico, $gineco)
            ->post('/clinica/cumpleanos', [
                'paciente' => $paciente->id,
                'fecha'    => self::HOY,
                'canal'    => 'automatico',
                'mensaje'  => 'Feliz cumple, Ana Pérez.',
            ])
            ->assertRedirect();

        $notificacion = NotificacionCumple::where('paciente_id', $paciente->id)->firstOrFail();
        $this->assertSame(NotificacionCumple::ESTADO_ENVIADA, $notificacion->estado);
        $this->assertSame(Canal::CORREO, $notificacion->canal);
        $this->assertSame('ana@example.com', $notificacion->destino);
        $this->assertSame(self::HOY, $notificacion->fecha_cumple->toDateString());        $this->assertSame($user->id, $notificacion->enviada_por);
        $this->assertSame('Feliz cumple, Ana Pérez.', $notificacion->mensaje);

        Mail::assertSent(\App\Mail\SaludoDeCumpleanos::class, function ($correo) {
            return $correo->hasTo('ana@example.com') && $correo->cuerpo === 'Feliz cumple, Ana Pérez.';
        });
    }

    public function test_no_se_puede_saludar_a_un_paciente_de_otro_medico(): void
    {
        Mail::fake();

        [$user, $medico, $gineco] = $this->medico();
        [$otroUser, $otro, $otraEspecialidad] = $this->medico();
        $this->configurar($medico);
        $ajeno = $this->paciente($otro, '1990-10-10');

        $this->conContexto($user, $medico, $gineco)
            ->post('/clinica/cumpleanos', [
                'paciente' => $ajeno->id,
                'fecha'    => self::HOY,
                'canal'    => 'automatico',
            ])
            ->assertStatus(404);

        $this->assertSame(0, NotificacionCumple::count());
        Mail::assertNothingSent();
    }

    public function test_si_al_paciente_le_falta_el_dato_del_canal_el_envio_avisa_y_no_registra(): void
    {
        Mail::fake();

        [$user, $medico, $gineco] = $this->medico();
        $this->configurar($medico, ['canal' => Canal::CORREO]);
        $paciente = $this->paciente($medico, '1990-10-10', ['email' => null]);

        $this->conContexto($user, $medico, $gineco)
            ->from('/clinica/cumpleanos')
            ->post('/clinica/cumpleanos', [
                'paciente' => $paciente->id,
                'fecha'    => self::HOY,
                'canal'    => 'automatico',
            ])
            ->assertRedirect('/clinica/cumpleanos')
            ->assertSessionHas('error', 'El paciente no tiene un correo válido registrado.');

        $this->assertSame(0, NotificacionCumple::count());
        Mail::assertNothingSent();
    }

    // ------------------------------------------------------------------------------------ El comando

    public function test_el_comando_manda_cuando_ya_es_la_hora_y_no_repite_en_una_segunda_corrida(): void
    {
        Mail::fake();

        [$user, $medico, $gineco] = $this->medico();
        $this->configurar($medico, ['canal' => Canal::CORREO, 'hora' => '09:00:00']);
        $paciente = $this->paciente($medico, '1990-10-10');

        // A las 10:00 del consultorio ya pasó la hora de las 09:00.
        $this->artisan('cumpleanos:enviar', ['--fecha' => self::HOY])->assertExitCode(0);

        $this->assertSame(1, NotificacionCumple::where('paciente_id', $paciente->id)->count());
        Mail::assertSent(\App\Mail\SaludoDeCumpleanos::class, 1);

        // Segunda corrida: el registro manda y no se manda de nuevo. (`expectsOutput` y no
        // `expectsOutputToContain`: esa llega en Laravel 9; acá se espera la línea exacta.)
        $this->artisan('cumpleanos:enviar', ['--fecha' => self::HOY])
            ->expectsOutput('    - Pérez, Ana: ya se le mandó.')
            ->assertExitCode(0);

        $this->assertSame(1, NotificacionCumple::where('paciente_id', $paciente->id)->count());
        Mail::assertSent(\App\Mail\SaludoDeCumpleanos::class, 1);
    }

    public function test_el_comando_no_manda_antes_de_la_hora_configurada(): void
    {
        Mail::fake();

        [$user, $medico, $gineco] = $this->medico();
        $this->configurar($medico, ['hora' => '23:00:00']);
        $this->paciente($medico, '1990-10-10');

        // Son las 10:00 en el consultorio: todavía no es la hora.
        $this->artisan('cumpleanos:enviar', ['--fecha' => self::HOY])->assertExitCode(0);

        $this->assertSame(0, NotificacionCumple::count());
        Mail::assertNothingSent();
    }

    public function test_el_comando_ignora_a_los_medicos_con_el_envio_apagado(): void
    {
        Mail::fake();

        [$user, $medico, $gineco] = $this->medico();
        $this->configurar($medico, ['enviar' => null]);
        $paciente = $this->paciente($medico, '1990-10-10');

        $this->artisan('cumpleanos:enviar', ['--fecha' => self::HOY])->assertExitCode(0);

        $this->assertSame(0, $this->enviosDe($paciente));
        Mail::assertNotSent(\App\Mail\SaludoDeCumpleanos::class);
    }

    public function test_el_comando_saltea_al_que_le_falta_el_dato_y_sigue_con_el_resto(): void
    {
        Mail::fake();

        [$user, $medico, $gineco] = $this->medico();
        $this->configurar($medico, ['canal' => Canal::CORREO]);
        // Dos que cumplen hoy: al primero le falta el correo y el segundo tiene todo.
        $sinCorreo = $this->paciente($medico, '1990-10-10', ['nombres' => 'Sin', 'apellidos' => 'Correo', 'email' => null]);
        $conCorreo = $this->paciente($medico, '1980-10-10', ['nombres' => 'Con', 'apellidos' => 'Correo']);

        $this->artisan('cumpleanos:enviar', ['--fecha' => self::HOY])
            ->expectsOutput('    - Correo, Sin: El paciente no tiene un correo válido registrado.')
            ->assertExitCode(0);

        // El que falló no dejó registro (el envío ni se intentó) y el otro sí salió.
        $this->assertSame(0, $this->enviosDe($sinCorreo));
        $this->assertSame(1, $this->enviosDe($conCorreo));
        Mail::assertSent(\App\Mail\SaludoDeCumpleanos::class, 1);
    }

    public function test_simular_recorre_todo_sin_mandar_ni_registrar(): void
    {
        Mail::fake();

        [$user, $medico, $gineco] = $this->medico();
        $this->configurar($medico, ['canal' => Canal::CORREO, 'hora' => '09:00:00']);
        $paciente = $this->paciente($medico, '1990-10-10');

        $this->artisan('cumpleanos:enviar', ['--fecha' => self::HOY, '--simular' => true])
            ->expectsOutput('    - Pérez, Ana: se le mandaría (simulación).')
            ->assertExitCode(0);

        $this->assertSame(0, $this->enviosDe($paciente));
        Mail::assertNothingSent();
    }

    public function test_el_comando_manda_por_el_canal_configurado(): void
    {
        config($this->twilio);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM999'], 201)]);

        [$user, $medico, $gineco] = $this->medico();
        $this->configurar($medico, ['canal' => Canal::SMS, 'hora' => '09:00:00']);
        $paciente = $this->paciente($medico, '1990-10-10');

        $this->artisan('cumpleanos:enviar', ['--fecha' => self::HOY])->assertExitCode(0);

        $notificacion = NotificacionCumple::where('paciente_id', $paciente->id)->firstOrFail();
        $this->assertSame(Canal::SMS, $notificacion->canal);
        $this->assertSame('584141234567', $notificacion->destino);
        Http::assertSent(fn ($peticion) => $peticion['To'] === '+584141234567');
    }

    public function test_el_comando_no_manda_el_saludo_de_otro_dia(): void
    {
        Mail::fake();

        [$user, $medico, $gineco] = $this->medico();
        $this->configurar($medico, ['hora' => '09:00:00']);
        $this->paciente($medico, '1990-10-10');

        $this->artisan('cumpleanos:enviar', ['--fecha' => '2026-10-11'])->assertExitCode(0);

        $this->assertSame(0, NotificacionCumple::count());
        Mail::assertNothingSent();
    }

    // ---------------------------------------------------------------------------------------- Avisos

    public function test_la_agenda_ofrece_el_atajo_a_los_cumpleanos_del_dia(): void
    {
        [$user, $medico, $gineco] = $this->medico();

        $this->conContexto($user, $medico, $gineco)
            ->get('/clinica/agenda?vista=dia&fecha=' . self::HOY)
            ->assertOk()
            ->assertSee(route('clinica.cumpleanos', ['fecha' => self::HOY]), false);
    }

    public function test_el_inicio_avisa_cuando_hoy_cumple_anos_alguien(): void
    {
        [$user, $medico, $gineco] = $this->medico();
        $this->paciente($medico, '1990-10-10');

        $this->conContexto($user, $medico, $gineco)
            ->get('/clinica')
            ->assertOk()
            ->assertSee('Hoy cumple años 1 paciente.')
            ->assertSee(route('clinica.cumpleanos'), false);
    }
}
