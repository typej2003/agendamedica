<?php

namespace Tests\Feature;

use App\Actions\Comentarios\ResponderComentario;
use App\Clinica\Ayuda\ArchivosDeLosComentarios;
use App\Models\Comentario;
use App\Models\ComentarioImagen;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **"Ayúdanos a mejorar" desde el app** (ROADMAP §9): `POST /api/app/comentarios` con sus capturas y
 * `GET /api/app/comentarios` con la respuesta, más la imagen por la ruta autorizada del autor.
 *
 * Lo que estos casos fijan, en orden:
 *
 *  1. **De quién es**: el comentario del app queda con el usuario, el rol, el médico y el `reg_medico`, y con
 *     `origen = app` — el mismo dato que ya guarda la web, con la Action compartida.
 *  2. **Las capturas**: hasta 3, 5 MB, PNG/JPG/WEBP y tipo real; la cuarta no entra y no queda nada a medias.
 *  3. **La lectura**: cada uno ve los suyos (con la respuesta de la bandeja) y la captura de otro da 404.
 *  4. **El contexto**: la versión y la pantalla viajan como JSON; si vienen mal, el comentario llega igual.
 *
 * `DatabaseTransactions` + `Storage::fake('comentarios')`, como los demás tests de archivos.
 */
class ComentariosAppTest extends TestCase
{
    use DatabaseTransactions;

    /** Un PNG de 1×1 válido (el más chico que existe): el contenido es real, así que el MIME se detecta. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** Un GIF de 1×1 válido: para el perfil de comentario es un tipo **no permitido**. */
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    /** Los archivos temporales que crea `archivoReal()`, para borrarlos al terminar. */
    private array $temporales = [];

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake(ArchivosDeLosComentarios::DISCO);

        // El app manda `Accept: application/json` (`ApiClient`), y de eso depende que un error de validación sea
        // un **422 con el `errors`** y no un redirect al formulario: los errors que muestra el teléfono son estos.
        $this->withHeaders(['Accept' => 'application/json']);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }

        parent::tearDown();
    }

    // --------------------------------------------------------------------------------------- Ayudas

    /** Un médico con su cuenta de acceso (el `Medico` es lo que la Action resuelve por `user_id`). */
    private function medicoDePrueba(bool $autenticar = true): array
    {
        $reg = 'ayuda-app-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Dr. de prueba', 'email' => "{$reg}@example.com",
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        // El rol se busca por el guard `web` **explícito**: `Sanctum::actingAs()` cambia el guard por defecto a
        // `api`, y un `assignRole('Medico')` después de autenticar buscaría el rol en ese guard y no existe.
        $user->assignRole(Role::findByName('Medico', 'web'));

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr. de prueba', 'lastname' => 'Del App',
            'email' => $user->email, 'password' => $user->password, 'reg_medico' => $reg,
        ]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        if ($autenticar) {
            Sanctum::actingAs($user, ['*'], 'api');
        }

        return [$user, $medico];
    }

    /** Un usuario con un rol suelto (la bandeja responde con Root; también sirve para la secretaría). */
    private function usuario(string $rol): User
    {
        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'ayuda-app-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole(Role::findByName($rol, 'web'));

        return $user;
    }

    private function png(string $nombre = 'captura.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, base64_decode(self::PNG));
    }

    /**
     * Un archivo **de verdad** en el disco, para probar el tipo que detecta el contenido: `UploadedFile::fake()`
     * deduce el MIME del nombre y un `.png` falso siempre se ve como `image/png`.
     */
    private function archivoReal(string $nombre, string $contenido): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'adjunto-comentario-app-');
        file_put_contents($ruta, $contenido);
        $this->temporales[] = $ruta;

        return new UploadedFile($ruta, $nombre, null, null, true);
    }

    /** El POST del app: los campos del formulario y las capturas, como las manda el teléfono. */
    private function enviar(array $datos = [], array $imagenes = [])
    {
        return $this->post('/api/app/comentarios', array_merge([
            'tipo' => Comentario::BUG,
            'mensaje' => 'La agenda del teléfono no muestra el motivo.',
            'imagenes' => $imagenes,
        ], $datos));
    }

    private function archivos(): array
    {
        return Storage::disk(ArchivosDeLosComentarios::DISCO)->allFiles();
    }

    // ------------------------------------------------------------------------------------- El envío

    public function test_un_medico_manda_un_comentario_desde_el_app_con_su_rol_y_su_medico(): void
    {
        [$user, $medico] = $this->medicoDePrueba();

        $response = $this->enviar([
            'contexto' => json_encode(['version' => 'v0.7.0-dev', 'pantalla' => 'configuracion/ayuda']),
        ])->assertCreated();

        $response->assertJsonPath('data.tipo', Comentario::BUG)
            ->assertJsonPath('data.tipo_legible', 'Un error')
            ->assertJsonPath('data.estado', Comentario::NUEVO)
            ->assertJsonPath('data.imagenes', []);

        $comentario = Comentario::findOrFail($response->json('data.id'));

        $this->assertSame($user->id, $comentario->user_id);
        $this->assertSame($medico->id, $comentario->medico_id);
        $this->assertSame($medico->reg_medico, $comentario->reg_medico);
        $this->assertSame('Medico', $comentario->rol);
        $this->assertSame('app', $comentario->origen, 'el origen distingue el móvil de la web');
        $this->assertSame(
            ['version' => 'v0.7.0-dev', 'pantalla' => 'configuracion/ayuda'],
            $comentario->contexto,
            'la versión y la pantalla se guardan para no tener que preguntar',
        );
    }

    public function test_la_secretaria_tambien_puede_y_el_comentario_queda_con_su_rol_sin_medico(): void
    {
        $secretaria = $this->usuario('Secretaria');
        Sanctum::actingAs($secretaria, ['*'], 'api');

        $response = $this->enviar(['mensaje' => 'No encuentro cómo confirmar una cita.'])->assertCreated();

        $comentario = Comentario::findOrFail($response->json('data.id'));
        $this->assertSame('Secretaria', $comentario->rol);
        $this->assertNull($comentario->medico_id, 'la secretaría no tiene médico: queda el usuario y el rol');
    }

    // ---------------------------------------------------------------------------- Las capturas

    public function test_tres_capturas_viajan_con_el_comentario_y_el_autor_las_ve(): void
    {
        [$user] = $this->medicoDePrueba();

        $response = $this->enviar([], [
            $this->png('agenda.png'),
            $this->png('recipe.png'),
            $this->png('paciente.png'),
        ])->assertCreated();

        $this->assertCount(3, $response->json('data.imagenes'));
        $this->assertSame(3, count($this->archivos()), 'los tres archivos están en el disco privado');

        $comentario = Comentario::findOrFail($response->json('data.id'));

        foreach ($comentario->imagenes as $imagen) {
            $this->assertSame(ArchivosDeLosComentarios::DISCO, $imagen->disco);
            $this->assertSame('image/png', $imagen->tipo);
            $this->assertMatchesRegularExpression(
                '~^comentarios/' . $user->id . '/\d{4}-\d{2}/[A-Za-z0-9]{40}\.png$~',
                $imagen->ruta,
                'la ruta es la de la cuenta, con el nombre del servidor y la extensión del contenido',
            );
        }

        // «Mis comentarios» del app: las capturas vienen con su id (la miniatura se pide aparte, no hay URL).
        $this->get('/api/app/comentarios')
            ->assertOk()
            ->assertJsonCount(3, 'data.0.imagenes')
            ->assertJsonPath('data.0.imagenes.0.nombre', 'agenda.png');

        // Y la captura se sirve por la ruta autorizada del autor, con su tipo real.
        $imagen = $comentario->imagenes->first();

        $this->get("/api/app/comentarios/{$comentario->id}/imagenes/{$imagen->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_la_cuarta_captura_se_rechaza_y_no_se_guarda_nada(): void
    {
        $this->medicoDePrueba();

        $this->enviar([], [
            $this->png('1.png'), $this->png('2.png'), $this->png('3.png'), $this->png('4.png'),
        ])->assertStatus(422)
            ->assertJsonValidationErrors('imagenes')
            ->assertJsonPath('errors.imagenes.0', 'Podés adjuntar hasta 3 imágenes por comentario.');

        $this->assertSame(0, Comentario::count(), 'no se guardó el comentario a medias');
        $this->assertSame(0, ComentarioImagen::count());
        $this->assertSame([], $this->archivos(), 'ni un archivo huérfano');
    }

    public function test_un_gif_real_se_rechaza_porque_el_perfil_de_comentario_no_lo_acepta(): void
    {
        $this->medicoDePrueba();

        $gif = $this->archivoReal('captura.gif', base64_decode(self::GIF));

        $this->enviar([], [$gif])->assertStatus(422)->assertJsonValidationErrors('imagenes.0');

        $this->assertSame(0, ComentarioImagen::count());
        $this->assertSame([], $this->archivos());
    }

    // ----------------------------------------------------------------------------- La validación

    public function test_el_comentario_necesita_tipo_y_mensaje(): void
    {
        $this->medicoDePrueba();

        $this->post('/api/app/comentarios', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tipo', 'mensaje']);

        $this->enviar(['tipo' => 'no-existe'])->assertStatus(422)->assertJsonValidationErrors('tipo');
        $this->enviar(['mensaje' => 'ok'])->assertStatus(422)->assertJsonValidationErrors('mensaje');

        $this->assertSame(0, Comentario::count());
    }

    public function test_un_contexto_mal_formado_no_impide_que_llegue_el_comentario(): void
    {
        $this->medicoDePrueba();

        // El contexto ahorra preguntas, no es un requisito: si el cliente lo manda roto, el comentario entra.
        $response = $this->enviar(['contexto' => '{esto no es json'])->assertCreated();

        $comentario = Comentario::findOrFail($response->json('data.id'));
        $this->assertNull($comentario->contexto);
    }

    // -------------------------------------------------------------------- La lectura y la respuesta

    public function test_la_respuesta_de_la_bandeja_llega_al_app(): void
    {
        [$user] = $this->medicoDePrueba();
        $this->enviar()->assertCreated();

        $comentario = Comentario::firstOrFail();
        app(ResponderComentario::class)->ejecutar($comentario, $this->usuario('Root'), 'Ya está corregido en la versión nueva.');

        $this->get('/api/app/comentarios')
            ->assertOk()
            ->assertJsonPath('data.0.estado', Comentario::RESPONDIDO)
            ->assertJsonPath('data.0.respuesta', 'Ya está corregido en la versión nueva.')
            ->assertJsonPath('data.0.id', $comentario->id);
    }

    public function test_cada_uno_ve_los_suyos_y_la_captura_de_otro_da_404(): void
    {
        [$user] = $this->medicoDePrueba();
        $this->enviar(['mensaje' => 'MENSAJE-DEL-PRIMERO'], [$this->png()])->assertCreated();

        $comentario = Comentario::firstOrFail();
        $imagen = $comentario->imagenes->first();
        $url = "/api/app/comentarios/{$comentario->id}/imagenes/{$imagen->id}";

        // Otro médico: su lista no trae el comentario ajeno y la captura da 404 (no 403: no se confirma que exista).
        [$otro] = $this->medicoDePrueba(autenticar: false);
        Sanctum::actingAs($otro, ['*'], 'api');

        $this->get('/api/app/comentarios')->assertOk()->assertJsonCount(0, 'data');
        $this->get($url)->assertNotFound();

        // El autor sí.
        Sanctum::actingAs($user, ['*'], 'api');
        $this->get('/api/app/comentarios')->assertOk()->assertJsonCount(1, 'data');
        $this->get($url)->assertOk();
    }

    public function test_sin_sesion_no_se_puede_mandar_ni_leer(): void
    {
        $this->post('/api/app/comentarios', ['tipo' => Comentario::BUG, 'mensaje' => 'Sin sesión.'])
            ->assertUnauthorized();
        $this->get('/api/app/comentarios')->assertUnauthorized();

        $this->assertSame(0, Comentario::count());
    }
}
