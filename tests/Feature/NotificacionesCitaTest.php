<?php

namespace Tests\Feature;

use App\Actions\Notificaciones\EnviarRecordatorio;
use App\Mail\RecordatorioDeCita;
use App\Models\Cola;
use App\Models\Evolucion;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\NotificacionCita;
use App\Models\Paciente;
use App\Models\User;
use App\Notificaciones\Correo;
use App\Notificaciones\ExcepcionDeEnvio;
use App\Notificaciones\MensajeDeRecordatorio;
use App\Notificaciones\Telefono;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Recordatorios y envíos (WEB-2.6): la Action compartida y el endpoint del móvil.
 *
 * `DatabaseTransactions`, como el resto de los tests con base: la suite corre contra
 * `database/database.testing.sqlite` y la transacción deja todo como estaba.
 */
class NotificacionesCitaTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<string,string> */
    private array $twilio = [
        'services.twilio.sid'   => 'ACprueba',
        'services.twilio.token' => 'token-de-prueba',
        'services.twilio.from'  => '+15005550006',
    ];

    // --------------------------------------------------------------------------------------- Ayudas

    private function medico(?string $regMedico = null): Medico
    {
        $regMedico = $regMedico ?: 'notif-' . uniqid();

        $user = User::create([
            'name'                 => 'Médico de prueba',
            'email'                => 'notif-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Carlos',
            'lastname'   => 'Mendoza',
            'prefix'     => 'Dr',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);

        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return $medico;
    }

    private function paciente(Medico $medico, int $numhistoria, array $extra = []): Paciente
    {
        $paciente = Paciente::create(array_merge([
            'nombres'   => 'Ana',
            'apellidos' => 'Alvarez',
            'cedula'    => (string) random_int(10000000, 30000000),
            'telefono'  => '0414-1234567',
            'email'     => 'ana@example.com',
        ], $extra));

        MedicoPaciente::create([
            'medico_id'   => $medico->id,
            'paciente_id' => $paciente->id,
            'numhistoria' => $numhistoria,
            'reg_medico'  => $medico->reg_medico,
        ]);

        return $paciente;
    }

    private function cita(Medico $medico, int $numhistoria, array $extra = []): Cola
    {
        return Cola::create(array_merge([
            'reg_medico' => $medico->reg_medico,
            'fecha'      => '2026-10-05',
            'hora_ini'   => '09:30:00',
            'numhistoria' => $numhistoria,
            'atendido'   => 0,
            'estado'     => 0,
        ], $extra));
    }

    // ---------------------------------------------------------------------------------------- Dominio

    public function test_el_telefono_se_normaliza_al_formato_internacional(): void
    {
        $this->assertSame('584141234567', Telefono::internacional('0414-1234567'));
        $this->assertSame('584141234567', Telefono::internacional('4141234567'));
        $this->assertSame('584141234567', Telefono::internacional('+58 414 1234567'));
        // La regla es la del móvil (`telefono.dart`): lo que no se puede interpretar no se adivina.
        $this->assertNull(Telefono::internacional('12345'));
        $this->assertNull(Telefono::internacional(null));
    }

    public function test_solo_se_acepta_un_correo_con_forma_de_correo(): void
    {
        $this->assertSame('ana@example.com', Correo::valido(' ana@example.com '));
        $this->assertNull(Correo::valido('ana.example.com'));
        $this->assertNull(Correo::valido(''));
    }

    public function test_el_mensaje_usa_la_plantilla_del_medico_y_sustituye_las_etiquetas(): void
    {
        $mensajes = new MensajeDeRecordatorio();

        $texto = $mensajes->armar('Hola {paciente}, con el {doctor} en {centro} el {fecha} a las {hora}.', [
            'paciente' => 'Ana Alvarez',
            'doctor'   => 'Dr. Carlos Mendoza',
            'centro'   => 'Clínica Metropolitana',
            'fecha'    => '05/10/2026',
            'hora'     => '09:30',
        ]);

        $this->assertSame('Hola Ana Alvarez, con el Dr. Carlos Mendoza en Clínica Metropolitana el 05/10/2026 a las 09:30.', $texto);

        // Sin plantilla del médico vale la de por defecto (el texto que armaba el endpoint viejo).
        $this->assertSame(
            'Recordatorio de cita para Ana Alvarez: 05/10/2026 a las 09:30.',
            $mensajes->armar(null, ['paciente' => 'Ana Alvarez', 'fecha' => '05/10/2026', 'hora' => '09:30']),
        );
    }

    // --------------------------------------------------------------------------------- Envío por SMS

    public function test_el_sms_sale_por_twilio_y_deja_constancia_en_la_cita(): void
    {
        config($this->twilio);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123', 'status' => 'queued'], 201)]);

        $medico = $this->medico();
        $paciente = $this->paciente($medico, 3001);
        $cita = $this->cita($medico, 3001);

        $notificacion = app(EnviarRecordatorio::class)->ejecutar($cita, 'sms', 'Hola Ana, te esperamos.');

        $this->assertSame(NotificacionCita::ESTADO_ENVIADA, $notificacion->estado);
        $this->assertSame('sms', $notificacion->canal);
        $this->assertSame('584141234567', $notificacion->destino);
        $this->assertSame('Hola Ana, te esperamos.', $notificacion->mensaje);
        $this->assertSame($medico->reg_medico, $notificacion->reg_medico);

        // La constancia que lee el escritorio (`sms` NO se toca: en el legado es el tilde de selección).
        $this->assertSame('Hola Ana, te esperamos.', $cita->fresh()->sms_text);
        $this->assertNull($cita->fresh()->sms);

        Http::assertSent(function ($peticion) {
            return str_contains($peticion->url(), '/Accounts/ACprueba/Messages.json')
                && $peticion['To'] === '+584141234567'
                && $peticion['From'] === '+15005550006'
                && $peticion['Body'] === 'Hola Ana, te esperamos.';
        });
    }

    public function test_sin_telefono_valido_no_se_intenta_ni_se_registra(): void
    {
        config($this->twilio);
        Http::fake();

        $medico = $this->medico();
        $this->paciente($medico, 3002, ['telefono' => '12345']);
        $cita = $this->cita($medico, 3002);

        try {
            app(EnviarRecordatorio::class)->ejecutar($cita, 'sms');
            $this->fail('Se esperaba una excepción de envío.');
        } catch (ExcepcionDeEnvio $excepcion) {
            $this->assertSame(422, $excepcion->estadoHttp);
            $this->assertStringContainsString('teléfono', $excepcion->getMessage());
        }

        $this->assertSame(0, NotificacionCita::count());
        Http::assertNothingSent();
    }

    public function test_sin_proveedor_configurado_el_sms_avisa_que_no_esta_configurado(): void
    {
        // Sin credenciales: 503, y no se registra nada porque no hubo intento real.
        config(['services.twilio.sid' => null, 'services.twilio.token' => null, 'services.twilio.from' => null]);
        Http::fake();

        $medico = $this->medico();
        $this->paciente($medico, 3003);
        $cita = $this->cita($medico, 3003);

        try {
            app(EnviarRecordatorio::class)->ejecutar($cita, 'sms');
            $this->fail('Se esperaba una excepción de envío.');
        } catch (ExcepcionDeEnvio $excepcion) {
            $this->assertSame(503, $excepcion->estadoHttp);
        }

        $this->assertSame(0, NotificacionCita::count());
    }

    // ------------------------------------------------------------------------------------- Correo

    public function test_el_correo_sale_del_servidor_con_el_medico_de_la_cita_como_remitente(): void
    {
        Mail::fake();

        $medico = $this->medico();
        $this->paciente($medico, 3004);
        $cita = $this->cita($medico, 3004, ['medico' => 7]);

        Evolucion::create([
            'reg_medico'     => $medico->reg_medico,
            'clave'          => 7,
            'correo_med'     => $medico->email,
            'plantilla_cita' => 'Hola {paciente}, su cita es el {fecha} a las {hora}.',
        ]);

        $notificacion = app(EnviarRecordatorio::class)->ejecutar($cita, 'correo');

        $this->assertSame(NotificacionCita::ESTADO_ENVIADA, $notificacion->estado);
        $this->assertSame('correo', $notificacion->canal);
        $this->assertSame('ana@example.com', $notificacion->destino);
        $this->assertSame('Hola Ana Alvarez, su cita es el 05/10/2026 a las 09:30.', $notificacion->mensaje);
        $this->assertSame('plantilla_cita', $notificacion->plantilla);

        Mail::assertSent(RecordatorioDeCita::class, function (RecordatorioDeCita $correo) use ($medico) {
            return $correo->hasTo('ana@example.com')
                && $correo->remitente === $medico->email
                && $correo->paciente === 'Ana Alvarez'
                && $correo->doctor === 'Dr. Carlos Mendoza';
        });
    }

    // ---------------------------------------------------------------------------- Endpoint del móvil

    public function test_el_endpoint_del_movil_manda_el_sms_y_acepta_el_canal(): void
    {
        config($this->twilio);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM456'], 201)]);

        $medico = $this->medico();
        $this->paciente($medico, 3005);
        $cita = $this->cita($medico, 3005);

        Sanctum::actingAs(User::find($medico->user_id), ['*'], 'api');

        $respuesta = $this->postJson('/api/app/citas/' . $cita->id . '/notificar', [
            'canal'   => 'sms',
            'mensaje' => 'Recordatorio de prueba.',
        ]);

        $respuesta->assertOk();
        $respuesta->assertJsonPath('notificacion.canal', 'sms');
        $respuesta->assertJsonPath('notificacion.estado', 'enviada');
    }

    public function test_el_endpoint_rechaza_un_canal_que_no_existe(): void
    {
        $medico = $this->medico();
        $this->paciente($medico, 3006);
        $cita = $this->cita($medico, 3006);

        Sanctum::actingAs(User::find($medico->user_id), ['*'], 'api');

        $this->postJson('/api/app/citas/' . $cita->id . '/notificar', ['canal' => 'telegram'])
            ->assertStatus(422);
    }

    public function test_el_endpoint_sin_proveedor_responde_503(): void
    {
        config(['services.whatsapp.token' => null, 'services.whatsapp.phone_number_id' => null]);
        Http::fake();

        $medico = $this->medico();
        $this->paciente($medico, 3007);
        $cita = $this->cita($medico, 3007);

        Sanctum::actingAs(User::find($medico->user_id), ['*'], 'api');

        $this->postJson('/api/app/citas/' . $cita->id . '/notificar', ['canal' => 'whatsapp'])
            ->assertStatus(503);
    }

    public function test_una_cita_de_otro_medico_no_se_puede_notificar(): void
    {
        config($this->twilio);
        Http::fake();

        $medico = $this->medico();
        $otro = $this->medico();
        $this->paciente($otro, 3008);
        $cita = $this->cita($otro, 3008);

        Sanctum::actingAs(User::find($medico->user_id), ['*'], 'api');

        $this->postJson('/api/app/citas/' . $cita->id . '/notificar', ['canal' => 'sms'])
            ->assertStatus(404);
    }

    public function test_el_sms_largo_se_recorta_solo_en_la_constancia(): void
    {
        config($this->twilio);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM789'], 201)]);

        $medico = $this->medico();
        $this->paciente($medico, 3009);
        $cita = $this->cita($medico, 3009);

        $largo = str_repeat('a', 200);
        app(EnviarRecordatorio::class)->ejecutar($cita, 'sms', $largo);

        // Lo que viaja es el mensaje completo; `cola.sms_text` es de 160 y se recorta ahí (la columna
        // del legado), mientras que el registro de la notificación guarda el texto entero.
        Http::assertSent(fn ($peticion) => $peticion['Body'] === $largo);
        $this->assertSame(160, mb_strlen($cita->fresh()->sms_text));
        $this->assertSame(200, mb_strlen(NotificacionCita::first()->mensaje));
    }

    public function test_la_cita_de_un_paciente_sin_historia_tambien_recibe_el_recordatorio(): void
    {
        config($this->twilio);
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM000'], 201)]);

        $medico = $this->medico();
        $paciente = Paciente::create([
            'nombres'   => 'Nueva',
            'apellidos' => 'Paciente',
            'telefono'  => '0414-7654321',
        ]);

        // La cita de un paciente sin historia cuelga de `paciente_sinhistoria_id`, no de `numhistoria`.
        $cita = $this->cita($medico, 0, [
            'numhistoria'             => null,
            'paciente_sinhistoria_id' => $paciente->id,
        ]);

        $notificacion = app(EnviarRecordatorio::class)->ejecutar($cita, 'sms');

        $this->assertSame(NotificacionCita::ESTADO_ENVIADA, $notificacion->estado);
        $this->assertSame('584147654321', $notificacion->destino);
    }

    public function test_el_envio_deja_el_intento_registrado_cuando_el_proveedor_rechaza(): void
    {
        config($this->twilio);
        Http::fake(['api.twilio.com/*' => Http::response(['message' => 'número inválido'], 400)]);

        $medico = $this->medico();
        $this->paciente($medico, 3010);
        $cita = $this->cita($medico, 3010);

        $notificacion = app(EnviarRecordatorio::class)->ejecutar($cita, 'sms');

        $this->assertSame(NotificacionCita::ESTADO_FALLIDA, $notificacion->estado);
        $this->assertSame('número inválido', json_decode((string) $notificacion->respuesta, true)['message']);
        // No se dejó constancia en la cita: no salió.
        $this->assertNull($cita->fresh()->sms_text);
    }

    public function test_el_destino_sale_del_correo_del_paciente_y_no_del_telefono(): void
    {
        Mail::fake();

        $medico = $this->medico();
        $this->paciente($medico, 3011, ['telefono' => null, 'email' => 'otra@example.com']);
        $cita = $this->cita($medico, 3011);

        $notificacion = app(EnviarRecordatorio::class)->ejecutar($cita, 'correo');

        $this->assertSame('otra@example.com', $notificacion->destino);
        Mail::assertSent(RecordatorioDeCita::class, fn (RecordatorioDeCita $correo) => $correo->hasTo('otra@example.com'));
    }
}
