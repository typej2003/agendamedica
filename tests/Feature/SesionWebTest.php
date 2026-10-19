<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **La sesión que se vencía con la consulta abierta** (2026-10-19).
 *
 * El síntoma reportado: al apretar «Terminar la consulta» salía la pantalla del **419** («Tu sesión venció.
 * Pasó mucho tiempo sin actividad…»), la cita no quedaba atendida y no había forma de terminar la atención
 * sin adivinar que había que ir a `/login`. La causa es la de Laravel: la sesión vence a los
 * `SESSION_LIFETIME` minutos **sin peticiones** y la consulta se queda abierta horas sin que el navegador
 * mande nada entre tecleo y tecleo.
 *
 * Lo que se fija acá:
 *  - el **latido** (`clinica.sesion`): contesta 204 sin cuerpo, exige estar autenticado, **no** exige
 *    contexto (es lo primero que corre después de volver a entrar) y el armazón lo carga en todas las
 *    pantallas del consultorio, con su URL en el `meta`;
 *  - un envío de `/clinica` **sin sesión** ya no cae en el 419: el formulario va al login con el aviso, y una
 *    petición de `fetch` (el autoguardado) recibe 419 **en JSON**, para que no dé por guardado lo que no se
 *    guardó;
 *  - fuera de `/clinica` (el panel, el API) el 419 sigue siendo el 419 de siempre.
 *
 * La verificación de Chrome vive en `../.agent-tools/verificar-sesion-consulta-web.js` y mide lo que un test
 * no puede: con `SESSION_LIFETIME` en un minuto, la pantalla **sin** latido se vence y la que late no.
 */
class SesionWebTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $slug = 'ginecologia'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $especialidad = Specialty::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $regMedico = 'sesion-test-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Doctora de la sesión', 'email' => 'sesion-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Doctora', 'lastname' => 'De La Sesión', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($especialidad->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $especialidad];
    }

    private function conContexto(User $user, Medico $medico, Specialty $especialidad): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico, 'specialty_id' => $especialidad->id, 'office_id' => null,
        ]])->actingAs($user);
    }

    /**
     * El chequeo del token del `@csrf` queda **desactivado en los tests** (`runningUnitTests()`), que es lo
     * que permite probar las pantallas sin ir leyendo tokens. Para medir el 419 hay que apagarlo: dentro de
     * este cierre la aplicación deja de decir «estoy en los tests» y la verificación es la de verdad.
     */
    private function conLaVerificacionRealDeTokens(callable $prueba)
    {
        $entorno = $this->app['env'];
        $this->app['env'] = 'local';

        try {
            return $prueba();
        } finally {
            $this->app['env'] = $entorno;
        }
    }

    // ------------------------------------------------------------------ el latido

    public function test_el_latido_de_la_sesion_contesta_sin_contenido(): void
    {
        [$user, $medico, $especialidad] = $this->medicoConAcceso();

        $respuesta = $this->conContexto($user, $medico, $especialidad)->get(route('clinica.sesion'));

        $respuesta->assertNoContent(204);
        $this->assertSame('', trim($respuesta->getContent() ?: ''));
    }

    public function test_el_latido_no_exige_contexto_de_trabajo(): void
    {
        // Es lo primero que corre cuando se vuelve a entrar desde una sesión vencida: en ese momento la
        // sesión es **nueva** y el consultorio todavía no está elegido. Si exigiera contexto, el latido
        // mandaría a la pantalla de elegir consultorio cada cinco minutos.
        [$user] = $this->medicoConAcceso();

        $this->actingAs($user)->get(route('clinica.sesion'))->assertNoContent(204);
    }

    public function test_el_latido_exige_estar_autenticado(): void
    {
        $this->get(route('clinica.sesion'))->assertRedirect(route('login'));
    }

    public function test_el_armazon_carga_el_latido_en_las_pantallas_del_consultorio(): void
    {
        [$user, $medico, $especialidad] = $this->medicoConAcceso();

        $html = $this->conContexto($user, $medico, $especialidad)->get('/clinica')->assertOk()->getContent();

        // La URL del latido va en el `meta` (el layout no publica el nombre de la ruta) y el JS que lo usa se
        // carga en todas las pantallas, no sólo en la consulta.
        $this->assertStringContainsString('<meta name="latido-sesion" content="' . route('clinica.sesion') . '">', $html);
        $this->assertStringContainsString('js/clinica/latido-sesion.js', $html);
    }

    // --------------------------------------------------------------- el 419 de la sesión vencida

    public function test_un_envio_de_la_clinica_sin_sesion_lleva_al_login_y_no_al_419(): void
    {
        $respuesta = $this->conLaVerificacionRealDeTokens(fn () => $this->post('/clinica/tour/agenda'));

        // Sin token no hay forma de saber quién es: el aviso del 419 («vuelve a cargar la página») no sirve
        // porque la sesión no vuelve. Se lo manda a entrar, que es la única salida.
        $respuesta->assertRedirect(route('login'));
        $respuesta->assertSessionHas('aviso', fn (string $aviso) => str_contains($aviso, 'Tu sesión venció'));
    }

    public function test_una_peticion_de_la_clinica_sin_sesion_contesta_419_en_json(): void
    {
        // El autoguardado de la consulta manda `X-Requested-With`, así que Laravel la trata como «espera
        // JSON». Si se la redirigiera al login, el `fetch` seguiría el 302 y recibiría el HTML del login con
        // un 200: el autoguardado creería que guardó y el texto clínico se perdería en silencio.
        $respuesta = $this->conLaVerificacionRealDeTokens(
            fn () => $this->postJson('/clinica/consulta/1/enfermedad', ['enfermedadactual' => 'texto'])
        );

        $respuesta->assertStatus(419);
        $this->assertStringContainsString('json', (string) $respuesta->headers->get('content-type'));
        $this->assertStringContainsString('Tu sesión venció', (string) $respuesta->json('message'));
    }

    public function test_el_token_correcto_sigue_pasando(): void
    {
        // El arreglo no puede aflojar la verificación: con el token de la sesión el envío sigue su camino
        // (acá lo frena el contexto —no hay consultorio elegido—, que es el destino normal de la ruta).
        [$user] = $this->medicoConAcceso();

        $respuesta = $this->conLaVerificacionRealDeTokens(fn () => $this->actingAs($user)
            ->withSession(['_token' => 'token-de-la-prueba'])
            ->post('/clinica/tour/agenda', ['_token' => 'token-de-la-prueba']));

        $respuesta->assertRedirect(route('clinica.contexto'));
    }

    public function test_fuera_de_la_clinica_el_419_sigue_siendo_el_419(): void
    {
        $respuesta = $this->conLaVerificacionRealDeTokens(fn () => $this->post('/login', ['email' => 'x@example.com']));

        $respuesta->assertStatus(419);
    }
}
