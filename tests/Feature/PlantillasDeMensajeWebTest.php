<?php

namespace Tests\Feature;

use App\Actions\Notificaciones\EnviarRecordatorio;
use App\Clinica\Agenda\RecordatoriosDeAgenda;
use App\Models\Cola;
use App\Models\Evolucion;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\NotificacionCita;
use App\Models\NotificacionCumple;
use App\Models\Paciente;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\User;
use App\Notificaciones\MensajeDeRecordatorio;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **Mensajes a pacientes en la web** (WEB-8.4): el editor de las plantillas de citas y de cumpleaños en `/clinica/mensajes`.
 *
 * Lo que estos casos fijan:
 *
 *  - **Quién entra**: el médico del contexto; la secretaría recibe 403 y no ve la entrada del menú; el invitado va al login.
 *  - **Qué se escribe y dónde**: `evolucion.plantilla_cita` / `plantilla_cumple` bajo el registro principal, **sólo lo que cambia**, con
 *    el rastro por columna en `sync_changes` (`source = web`) y el last-write-wins; vacío o «Restablecer» deja la columna en `NULL`
 *    (usa el mensaje por defecto).
 *  - **La vista previa es la del mensaje real**: el JSON que pide el script arma el texto con las mismas clases que el envío.
 *  - **El API del móvil no cambia** (el mismo JSON) y **lo guardado llega al teléfono** en `sync-app-data`.
 *  - **El envío usa lo guardado**: el recordatorio de la cita y el saludo de cumpleaños salen con la plantilla del editor.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica. Los envíos van con `Mail::fake()`.
 */
class PlantillasDeMensajeWebTest extends TestCase
{
    use DatabaseTransactions;
    use PreparaRecipesParaImprimir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fijarHoy();
    }

    protected function tearDown(): void
    {
        Evolucion::flushEventListeners();
        $this->soltarHoy();

        parent::tearDown();
    }

    // --------------------------------------------------------------------------------------- Ayudas

    private function como(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->conContexto($user, $medico, $specialty)->actingAs($user);
    }

    /** El last-write-wins deja pasar una edición sólo si es más nueva que el último rastro de la columna (trampa 65). */
    private function avanzar(int $segundos = 60): void
    {
        Carbon::setTestNow(Carbon::now()->addSeconds($segundos));
    }

    private function evolucionDe(Medico $medico): ?Evolucion
    {
        return Evolucion::where('reg_medico', $medico->reg_medico)->orderBy('id')->first();
    }

    private function rastros(Evolucion $evolucion, ?string $columna = null)
    {
        $consulta = SyncChange::where('table_name', 'evolucion')->where('record_id', $evolucion->id)->where('source', 'web');

        return $columna === null ? $consulta : $consulta->where('column_name', $columna);
    }

    private function pacienteConCita(Medico $medico, int $numhistoria, ?int $clave = null): Cola
    {
        $paciente = Paciente::create([
            'nombres' => 'Ana', 'apellidos' => 'Alvarez', 'cedula' => (string) random_int(10000000, 30000000),
            'telefono' => '0414-1234567', 'email' => 'ana@example.com',
        ]);
        MedicoPaciente::create(['medico_id' => $medico->id, 'paciente_id' => $paciente->id, 'numhistoria' => $numhistoria, 'reg_medico' => $medico->reg_medico]);

        return Cola::create([
            'reg_medico' => $medico->reg_medico, 'fecha' => '2026-10-12', 'hora_ini' => '09:30:00',
            'numhistoria' => $numhistoria, 'atendido' => 0, 'estado' => 0, 'medico' => $clave,
        ]);
    }

    // -------------------------------------------------------------------------------------- Quién entra

    public function test_el_medico_ve_los_dos_editores_sin_crear_nada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $respuesta = $this->como($user, $medico, $gineco)->get(route('clinica.mensajes'));

        $respuesta->assertOk();
        $respuesta->assertSee('Mensajes a pacientes');
        $respuesta->assertSee('Recordatorio de cita');
        $respuesta->assertSee('Saludo de cumpleaños');
        $respuesta->assertSee('Usa el mensaje por defecto');
        // Las etiquetas que el servidor sustituye, y sólo ésas.
        $respuesta->assertSee('data-etiqueta="{centro}"', false);
        $respuesta->assertSee('data-etiqueta="{doctor}"', false);
        // La vista previa con datos de ejemplo claramente falsos, armada por el servidor al cargar.
        $respuesta->assertSee('Recordatorio de cita para Ana Ejemplo', false);
        $respuesta->assertSee('Feliz cumpleaños Ana Ejemplo', false);
        $respuesta->assertSee('0 de 500 caracteres', false);
        $respuesta->assertSee('data-url-vista-previa="' . route('clinica.mensajes.vista-previa', 'cita') . '"', false);
        $this->assertNull($this->evolucionDe($medico), 'Mirar la pantalla no crea la fila de evolución.');
    }

    public function test_la_secretaria_no_entra_ni_ve_la_entrada_del_menu(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = $this->secretariaConAcceso($medico);

        $this->como($secretaria, $medico, $gineco)->get(route('clinica.mensajes'))->assertForbidden();
        $this->como($secretaria, $medico, $gineco)->post(route('clinica.mensajes.guardar', 'cita'), ['texto' => 'X'])->assertForbidden();
        $this->como($secretaria, $medico, $gineco)->post(route('clinica.mensajes.restablecer', 'cita'))->assertForbidden();
        $this->como($secretaria, $medico, $gineco)->postJson(route('clinica.mensajes.vista-previa', 'cita'), ['texto' => 'X'])->assertForbidden();
        $this->assertNull($this->evolucionDe($medico));

        $this->como($secretaria, $medico, $gineco)->get(route('clinica.inicio'))->assertOk()->assertDontSee('Mensajes a pacientes');
    }

    public function test_el_medico_ve_la_entrada_en_el_grupo_configuracion_del_menu(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->como($user, $medico, $gineco)->get(route('clinica.inicio'))
            ->assertOk()
            ->assertSee('Configuración')
            ->assertSee('Mensajes a pacientes')
            ->assertSee(route('clinica.mensajes'), false);
    }

    public function test_el_invitado_va_al_login_y_una_plantilla_inexistente_es_404(): void
    {
        $this->get(route('clinica.mensajes'))->assertRedirect('/login');
        $this->post(route('clinica.mensajes.guardar', 'cita'), [])->assertRedirect('/login');
        $this->postJson(route('clinica.mensajes.vista-previa', 'cita'), [])->assertUnauthorized();

        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->como($user, $medico, $gineco)->post('/clinica/mensajes/otra', ['texto' => 'X'])->assertNotFound();
    }

    // ----------------------------------------------------------------------------------------- Guardar

    public function test_guarda_la_plantilla_de_citas_con_su_rastro_y_sin_tocar_la_otra(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->como($user, $medico, $gineco)->post(route('clinica.mensajes.guardar', 'cita'), [
            'texto' => 'Hola {paciente}, su cita es el {fecha} a las {hora}.',
        ])->assertRedirect(route('clinica.mensajes') . '#plantilla-cita')->assertSessionHas('estado');

        $evolucion = $this->evolucionDe($medico);
        $this->assertNotNull($evolucion, 'Se crea la fila de evolución del médico.');
        $this->assertSame($medico->reg_medico, $evolucion->reg_medico);
        $this->assertSame('Hola {paciente}, su cita es el {fecha} a las {hora}.', $evolucion->plantilla_cita);
        $this->assertNull($evolucion->plantilla_cumple);
        $this->assertSame(0, (int) $evolucion->clave, '`clave` es el relleno NOT NULL del esquema legado.');

        $this->assertSame(1, $this->rastros($evolucion, 'plantilla_cita')->count());
        $this->assertSame(0, $this->rastros($evolucion, 'plantilla_cumple')->count());
        $this->assertSame(1, $this->rastros($evolucion)->where('operation', 'created')->count());
    }

    public function test_guarda_la_de_cumpleanos_y_un_guardado_sin_cambios_no_escribe_nada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $web = $this->como($user, $medico, $gineco);

        $web->post(route('clinica.mensajes.guardar', 'cumple'), ['texto' => 'Feliz día {paciente}, {doctor}.'])->assertSessionHas('estado');
        $evolucion = $this->evolucionDe($medico);
        $this->assertSame('Feliz día {paciente}, {doctor}.', $evolucion->plantilla_cumple);
        $rastros = SyncChange::count();

        $this->avanzar();
        $web->post(route('clinica.mensajes.guardar', 'cumple'), ['texto' => 'Feliz día {paciente}, {doctor}.'])
            ->assertSessionHas('estado', 'No había cambios que guardar en «Saludo de cumpleaños».');
        $this->assertSame($rastros, SyncChange::count(), 'Sin cambios no hay rastro.');
    }

    public function test_el_texto_vacio_o_restablecer_deja_la_columna_en_null_y_usa_el_mensaje_por_defecto(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $web = $this->como($user, $medico, $gineco);

        $web->post(route('clinica.mensajes.guardar', 'cita'), ['texto' => 'Algo {paciente}']);
        $this->avanzar();
        $web->post(route('clinica.mensajes.guardar', 'cita'), ['texto' => '   '])->assertSessionHas('estado');
        $this->assertNull($this->evolucionDe($medico)->plantilla_cita);

        $web->post(route('clinica.mensajes.guardar', 'cita'), ['texto' => 'Otra {paciente}']);
        $this->avanzar();
        $web->post(route('clinica.mensajes.restablecer', 'cita'))
            ->assertRedirect(route('clinica.mensajes') . '#plantilla-cita')
            ->assertSessionHas('estado');
        $this->assertNull($this->evolucionDe($medico)->plantilla_cita);

        // Restablecer lo que ya está restablecido lo dice y no escribe.
        $this->avanzar();
        $web->post(route('clinica.mensajes.restablecer', 'cita'))
            ->assertSessionHas('estado', 'No había nada que restablecer: «Recordatorio de cita» ya usa el mensaje por defecto.');

        // Y el mensaje que sale es el de por defecto.
        $this->assertSame(
            'Recordatorio de cita para Ana: 12/10/2026 a las 09:30.',
            (new MensajeDeRecordatorio())->armar($this->evolucionDe($medico)->plantilla_cita, ['paciente' => 'Ana', 'fecha' => '12/10/2026', 'hora' => '09:30']),
        );
    }

    public function test_el_tope_de_500_se_rechaza_en_espanol_y_no_pierde_lo_escrito(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $largo = str_repeat('a', 501);

        $this->como($user, $medico, $gineco)->from(route('clinica.mensajes'))
            ->post(route('clinica.mensajes.guardar', 'cita'), ['_plantilla' => 'cita', 'texto' => $largo])
            ->assertRedirect(route('clinica.mensajes'))
            ->assertSessionHasErrors(['texto' => 'La plantilla no puede tener más de 500 caracteres.']);

        $this->assertNull($this->evolucionDe($medico));

        // Lo escrito vuelve al editor que falló (y sólo a ése).
        $pantalla = $this->como($user, $medico, $gineco)->withSession(['_old_input' => ['_plantilla' => 'cita', 'texto' => $largo]])->get(route('clinica.mensajes'));
        $pantalla->assertSee($largo, false);
    }

    public function test_los_saltos_de_linea_del_navegador_se_guardan_como_salto_simple(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->como($user, $medico, $gineco)->post(route('clinica.mensajes.guardar', 'cita'), ['texto' => "Hola {paciente}\r\nCita: {fecha}"]);

        $this->assertSame("Hola {paciente}\nCita: {fecha}", $this->evolucionDe($medico)->plantilla_cita);
    }

    public function test_una_etiqueta_desconocida_avisa_pero_se_puede_guardar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->como($user, $medico, $gineco)->post(route('clinica.mensajes.guardar', 'cita'), ['texto' => 'Hola {algo}'])->assertSessionHas('estado');

        $pantalla = $this->como($user, $medico, $gineco)->get(route('clinica.mensajes'));
        $pantalla->assertSee('{algo} no es una etiqueta de esta plantilla', false);
        $pantalla->assertSee('Falta {paciente}', false);
    }

    public function test_una_edicion_mas_nueva_del_movil_no_se_pisa(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $web = $this->como($user, $medico, $gineco);
        $web->post(route('clinica.mensajes.guardar', 'cita'), ['texto' => 'De la web {paciente}']);
        $evolucion = $this->evolucionDe($medico);

        // Otro equipo (el escritorio) editó esa columna más tarde.
        SyncChange::create([
            'reg_medico' => $medico->reg_medico, 'table_name' => 'evolucion', 'record_id' => $evolucion->id, 'operation' => 'updated',
            'column_name' => 'plantilla_cita', 'value' => 'Del escritorio', 'occurred_at' => Carbon::now()->addHour(), 'source' => 'escritorio',
        ]);

        $this->avanzar(60);
        $web->post(route('clinica.mensajes.guardar', 'cita'), ['texto' => 'Otra de la web {paciente}'])
            ->assertSessionHas('error');
        $this->assertSame('De la web {paciente}', $this->evolucionDe($medico)->plantilla_cita);
    }

    // ---------------------------------------------------------------------------------- Vista previa

    public function test_la_vista_previa_es_el_mensaje_real_y_no_guarda_nada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $web = $this->como($user, $medico, $gineco);

        $respuesta = $web->postJson(route('clinica.mensajes.vista-previa', 'cita'), [
            'texto' => 'Hola {paciente}, {fecha} {hora} con {doctor}. {algo}',
        ])->assertOk();

        $respuesta->assertJsonPath('vista_previa', 'Hola Ana Ejemplo, 08/10/2026 09:30 con Dra. Ana Imprime. {algo}');
        $respuesta->assertJsonPath('caracteres', mb_strlen('Hola {paciente}, {fecha} {hora} con {doctor}. {algo}'));
        $respuesta->assertJsonPath('maximo', 500);
        $respuesta->assertJsonPath('sms.codificacion', 'gsm7');
        $respuesta->assertJsonPath('sms.segmentos', 1);
        $respuesta->assertJsonPath('avisos.0.tono', 'aviso');
        $this->assertStringContainsString('{algo}', $respuesta->json('avisos.0.texto'));
        $this->assertNotEmpty($respuesta->json('sms_frase'));

        // Es exactamente lo que arma el envío real con los mismos datos.
        $this->assertSame(
            (new MensajeDeRecordatorio())->armar('Hola {paciente}, {fecha} {hora} con {doctor}. {algo}', [
                'paciente' => 'Ana Ejemplo', 'fecha' => '08/10/2026', 'hora' => '09:30', 'doctor' => 'Dra. Ana Imprime', 'centro' => 'Consultorio de ejemplo',
            ]),
            $respuesta->json('vista_previa'),
        );

        $this->assertNull($this->evolucionDe($medico), 'La vista previa no guarda.');
    }

    public function test_la_vista_previa_cuenta_los_sms_y_distingue_la_de_cumpleanos(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $web = $this->como($user, $medico, $gineco);

        $unicode = $web->postJson(route('clinica.mensajes.vista-previa', 'cita'), ['texto' => str_repeat('á', 71)])->assertOk();
        $unicode->assertJsonPath('sms.codificacion', 'unicode')->assertJsonPath('sms.segmentos', 2)->assertJsonPath('sms.limite', 67);

        // `{fecha}` no existe en la de cumpleaños: se avisa y el mensaje la deja tal cual, como el envío.
        $cumple = $web->postJson(route('clinica.mensajes.vista-previa', 'cumple'), ['texto' => 'Feliz {paciente} {fecha}'])->assertOk();
        $cumple->assertJsonPath('vista_previa', 'Feliz Ana Ejemplo {fecha}');
        $this->assertStringContainsString('{fecha} no es una etiqueta', $cumple->json('avisos.0.texto'));

        $vacio = $web->postJson(route('clinica.mensajes.vista-previa', 'cita'), ['texto' => ''])->assertOk();
        $vacio->assertJsonPath('vacia', true);
        $vacio->assertJsonPath('vista_previa', 'Recordatorio de cita para Ana Ejemplo: 08/10/2026 a las 09:30.');
    }

    // ---------------------------------------------------------------------------------------- El API

    public function test_el_api_del_movil_no_cambia_y_lee_lo_que_guardo_la_web(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        Sanctum::actingAs($user, ['*'], 'api');
        $antes = $this->postJson('/api/app/configuracion', ['rif' => 'J-1', 'plantilla_cita' => 'Del móvil {paciente}'])->assertSuccessful();
        $clavesAntes = array_keys($antes->json('data'));
        $this->assertSame('Del móvil {paciente}', $antes->json('data.plantilla_cita'));

        $this->avanzar();
        $this->como($user, $medico, $gineco)->post(route('clinica.mensajes.guardar', 'cita'), ['texto' => 'De la web {paciente}, {fecha}.']);
        $this->como($user, $medico, $gineco)->post(route('clinica.mensajes.guardar', 'cumple'), ['texto' => 'Feliz {paciente}']);

        Sanctum::actingAs($user, ['*'], 'api');
        $despues = $this->postJson('/api/app/configuracion', ['rif' => 'J-1'])->assertSuccessful();

        $this->assertSame($clavesAntes, array_keys($despues->json('data')), 'El JSON del API tiene las mismas claves.');
        $despues->assertJsonPath('data.plantilla_cita', 'De la web {paciente}, {fecha}.');
        $despues->assertJsonPath('data.plantilla_cumple', 'Feliz {paciente}');
        $despues->assertJsonPath('data.rif', 'J-1');

        // El móvil puede seguir escribiéndolas (la misma regla de largo): el tope es el mismo.
        $this->postJson('/api/app/configuracion', ['plantilla_cita' => str_repeat('a', 501)])->assertStatus(422);
    }

    public function test_lo_guardado_en_la_web_llega_al_telefono_en_la_siguiente_sincronizacion(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $web = $this->como($user, $medico, $gineco);
        $web->post(route('clinica.mensajes.guardar', 'cita'), ['texto' => 'Cita {paciente} el {fecha}']);
        $web->post(route('clinica.mensajes.guardar', 'cumple'), ['texto' => 'Feliz {paciente}']);

        Sanctum::actingAs($user, ['*'], 'api');
        $sync = $this->postJson('/api/app/sync-app-data', [])->assertOk();

        $sync->assertJsonPath('configuracion.plantilla_cita', 'Cita {paciente} el {fecha}');
        $sync->assertJsonPath('configuracion.plantilla_cumple', 'Feliz {paciente}');

        // Restablecer también llega: el móvil vuelve a su texto por defecto.
        $this->avanzar();
        $this->como($user, $medico, $gineco)->post(route('clinica.mensajes.restablecer', 'cita'));
        Sanctum::actingAs($user, ['*'], 'api');
        $this->postJson('/api/app/sync-app-data', [])->assertOk()->assertJsonPath('configuracion.plantilla_cita', null);
    }

    // ------------------------------------------------------------------------------ El envío usa lo guardado

    public function test_el_recordatorio_sale_con_la_plantilla_guardada_en_la_web(): void
    {
        Mail::fake();
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        // El consultorio ya tenía la configuración del escritorio (con su `clave`) y la cita se agendó con ella.
        Evolucion::create(['reg_medico' => $medico->reg_medico, 'clave' => 7, 'correo_med' => $medico->email]);
        $cita = $this->pacienteConCita($medico, 5001, 7);

        $this->como($user, $medico, $gineco)->post(route('clinica.mensajes.guardar', 'cita'), ['texto' => 'WEB84 {paciente}: {fecha} a las {hora} con {doctor}.']);

        $esperado = 'WEB84 Ana Alvarez: 12/10/2026 a las 09:30 con Dra. Ana Imprime.';
        $notificacion = app(EnviarRecordatorio::class)->ejecutar($cita, 'correo');
        $this->assertSame($esperado, $notificacion->mensaje);
        $this->assertSame('plantilla_cita', $notificacion->plantilla);
        $this->assertSame(NotificacionCita::ESTADO_ENVIADA, $notificacion->estado);

        // La agenda ofrece el mismo texto en su diálogo.
        $mensajes = app(RecordatoriosDeAgenda::class)->mensajesPara($medico->reg_medico, collect([$this->citaDeAgenda($cita)]), collect());
        $this->assertSame($esperado, $mensajes[$cita->id]);
    }

    public function test_el_recordatorio_de_un_medico_sin_configuracion_del_escritorio_tambien_usa_lo_guardado(): void
    {
        Mail::fake();
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        // Sin fila de `evolucion` la cita nace con `medico = null`; al guardar, la web crea la fila con `clave = 0`.
        $cita = $this->pacienteConCita($medico, 5002, null);
        $this->como($user, $medico, $gineco)->post(route('clinica.mensajes.guardar', 'cita'), ['texto' => 'SIN CLAVE {paciente}.']);

        $notificacion = app(EnviarRecordatorio::class)->ejecutar($cita, 'correo');
        $this->assertSame('SIN CLAVE Ana Alvarez.', $notificacion->mensaje);
        $this->assertSame('plantilla_cita', $notificacion->plantilla);

        $mensajes = app(RecordatoriosDeAgenda::class)->mensajesPara($medico->reg_medico, collect([$this->citaDeAgenda($cita)]), collect());
        $this->assertSame('SIN CLAVE Ana Alvarez.', $mensajes[$cita->id]);

        // Y sin nada guardado sigue valiendo el mensaje por defecto (el fallback no inventa una plantilla).
        $otra = $this->medicoConAcceso();
        $citaOtra = $this->pacienteConCita($otra[1], 5003, null);
        $this->assertSame('recordatorio_por_defecto', app(EnviarRecordatorio::class)->ejecutar($citaOtra, 'correo')->plantilla);
    }

    public function test_el_saludo_de_cumpleanos_sale_con_la_plantilla_guardada_en_la_web(): void
    {
        Mail::fake();
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $paciente = Paciente::create([
            'nombres' => 'Luisa', 'apellidos' => 'Pérez', 'cedula' => (string) random_int(10000000, 30000000),
            'fnacimiento' => '1990-10-07', 'email' => 'luisa@example.com', 'telefono' => '0414-1234567',
        ]);
        MedicoPaciente::create(['medico_id' => $medico->id, 'paciente_id' => $paciente->id, 'numhistoria' => 6001, 'reg_medico' => $medico->reg_medico]);

        $web = $this->como($user, $medico, $gineco);
        $web->post(route('clinica.mensajes.guardar', 'cumple'), ['texto' => 'WEB84 feliz {paciente}, te saluda {doctor}.']);

        $web->post(route('clinica.cumpleanos.enviar'), ['paciente' => $paciente->id, 'canal' => 'correo', 'fecha' => '2026-10-07'])->assertSessionHas('estado');

        $saludo = NotificacionCumple::where('paciente_id', $paciente->id)->firstOrFail();
        $this->assertStringStartsWith('WEB84 feliz', $saludo->mensaje);
        $this->assertStringContainsString('te saluda Dra. Ana Imprime.', $saludo->mensaje);
        $this->assertStringContainsString('Luisa', $saludo->mensaje);
        $this->assertSame('plantilla_cumple', $saludo->plantilla);
    }

    // ------------------------------------------------------------------------------ Enlaces

    public function test_cumpleanos_enlaza_al_editor_solo_para_el_medico(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = $this->secretariaConAcceso($medico);

        $this->como($user, $medico, $gineco)->get(route('clinica.cumpleanos'))->assertOk()
            ->assertSee('Editar el mensaje')->assertSee(route('clinica.mensajes'), false);

        $this->como($secretaria, $medico, $gineco)->get(route('clinica.cumpleanos'))->assertOk()
            ->assertDontSee('Editar el mensaje con etiquetas');
    }

    /** La cita como la agenda la entrega a `RecordatoriosDeAgenda` (una fila con los campos que el armado lee). */
    private function citaDeAgenda(Cola $cita): object
    {
        return (object) [
            'id' => $cita->id, 'paciente' => 'Ana Alvarez', 'fecha' => $cita->fecha, 'horaIni' => '09:30:00',
            'medico' => $cita->medico, 'centroId' => null,
        ];
    }
}
