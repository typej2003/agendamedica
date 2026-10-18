<?php

namespace Tests\Feature;

use App\Clinica\Archivos\ArchivosClinicos;
use App\Clinica\Ayuda\ArchivosDeLosComentarios;
use App\Http\Livewire\Admin\Comentarios;
use App\Models\Comentario;
use App\Models\ComentarioImagen;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * **Las capturas adjuntas a "Ayúdanos a mejorar"** (O-25): el usuario pega o elige hasta tres imágenes, el
 * comentario se guarda con ellas, el autor las ve y un tercero no, y al descartar el reporte se borran las filas
 * y los archivos.
 *
 * Lo que estos casos fijan, en orden:
 *
 *  1. **La cantidad**: tres imágenes entran, la cuarta se rechaza y no se guarda nada a medias.
 *  2. **La política de archivos**: 5 MB por imagen, PNG/JPG/WEBP y el **tipo real** (un PDF llamado `.png` y un
 *     GIF legítimo se rechazan).
 *  3. **Dónde viven**: disco privado `comentarios` (`storage/app/comentarios`, fuera de `public/`), ruta por
 *     cuenta y nombre que arma el servidor.
 *  4. **La autorización**: el autor abre las suyas; otro médico recibe 404; la bandeja de `/admin` las abre.
 *  5. **El borrado**: al descartar, las filas y los archivos se van; el comentario queda como registro.
 *
 * `DatabaseTransactions` + `Storage::fake('comentarios')`, como el resto de los tests de archivos de la web
 * clínica: el disco falso tiene el mismo nombre que el real, así que se prueba la ruta, el nombre y la baja.
 */
class ComentarioImagenesWebTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Storage::fake(ArchivosDeLosComentarios::DISCO);
    }

    /** Un PNG de 1×1 válido (el más chico que existe): el contenido es real, así que el MIME se detecta. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** Un GIF de 1×1 válido: para el perfil de comentario es un tipo **no permitido**. */
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    // --------------------------------------------------------------------------------------- Ayudas

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoDePrueba(): array
    {
        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $reg = 'ayuda-img-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Dr. de prueba', 'email' => "{$reg}@example.com",
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr. de prueba', 'lastname' => 'Capturas',
            'email' => $user->email, 'password' => $user->password, 'reg_medico' => $reg,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        return [$user, $medico, $gineco];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id' => null,
        ]]);
    }

    private function usuario(string $rol): User
    {
        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'ayuda-img-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        return $user;
    }

    private function png(string $nombre = 'captura.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, base64_decode(self::PNG));
    }

    /** Los archivos temporales que crea `archivoReal()`, para borrarlos al terminar. */
    private array $temporales = [];

    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }

        parent::tearDown();
    }

    /**
     * Un archivo **de verdad** en el disco, para probar el tipo que detecta el contenido.
     *
     * `UploadedFile::fake()` deduce el MIME **del nombre** (`Illuminate\Http\Testing\File::getMimeType()`), así
     * que un `.png` falso siempre se ve como `image/png` aunque su contenido sea un PDF. Con un archivo real,
     * `getMimeType()` pasa por `finfo` y es lo que la política comprueba.
     */
    private function archivoReal(string $nombre, string $contenido): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'adjunto-comentario-');
        file_put_contents($ruta, $contenido);
        $this->temporales[] = $ruta;

        return new UploadedFile($ruta, $nombre, null, null, true);
    }

    /** El comentario con sus adjuntos, como lo deja el formulario. */
    private function enviar(User $user, Medico $medico, Specialty $gineco, array $imagenes, string $mensaje = 'Se ve mal la agenda en el celular.')
    {
        return $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.ayuda.guardar'), [
                'tipo' => Comentario::BUG,
                'mensaje' => $mensaje,
                'imagenes' => $imagenes,
            ]);
    }

    /** Los archivos que hay hoy en el disco (el `Storage::fake` arranca vacío en cada test). */
    private function archivos(): array
    {
        return Storage::disk(ArchivosDeLosComentarios::DISCO)->allFiles();
    }

    // ----------------------------------------------------------------------------------- La cantidad

    public function test_tres_imagenes_quedan_guardadas_con_su_fila_y_su_archivo(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();

        $this->enviar($user, $medico, $gineco, [
            $this->png('agenda.png'),
            $this->png('recipe.png'),
            $this->png('paciente.png'),
        ])->assertRedirect(route('clinica.ayuda'));

        $comentario = Comentario::firstOrFail();

        $this->assertSame(3, $comentario->imagenes()->count(), 'las tres capturas quedaron colgadas del comentario');
        $this->assertSame(3, count($this->archivos()), 'y los tres archivos están en el disco privado');

        foreach ($comentario->imagenes as $imagen) {
            $this->assertSame(ArchivosDeLosComentarios::DISCO, $imagen->disco);
            $this->assertSame('image/png', $imagen->tipo);
            $this->assertGreaterThan(0, $imagen->tamano);
            $this->assertMatchesRegularExpression(
                '~^comentarios/' . $user->id . '/\d{4}-\d{2}/[A-Za-z0-9]{40}\.png$~',
                $imagen->ruta,
                'la ruta es la de la cuenta, con el nombre que arma el servidor y la extensión del contenido',
            );
            $this->assertTrue(Storage::disk($imagen->disco)->exists($imagen->ruta));
        }

        // El nombre del archivo del usuario se conserva sólo para mostrarlo y para la descarga.
        $this->assertSame('agenda.png', $comentario->imagenes->first()->nombre_original);

        // Y el disco está fuera de `public/`: no hay ninguna URL que alcance el archivo.
        $this->assertSame(storage_path('app/comentarios'), config('filesystems.disks.comentarios.root'));
        $this->assertStringNotContainsString('public', config('filesystems.disks.comentarios.root'));
    }

    public function test_la_cuarta_imagen_se_rechaza_y_no_se_guarda_nada(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();

        $this->enviar($user, $medico, $gineco, [
            $this->png('1.png'),
            $this->png('2.png'),
            $this->png('3.png'),
            $this->png('4.png'),
        ])->assertSessionHasErrors('imagenes');

        $this->assertSame(0, Comentario::count(), 'no se guardó el comentario a medias');
        $this->assertSame(0, ComentarioImagen::count());
        $this->assertSame([], $this->archivos(), 'ni un archivo huérfano');
    }

    public function test_tres_imagenes_mas_el_texto_es_el_unico_limite_no_la_cantidad_de_archivos(): void
    {
        // El tope es de producto: 3. Comprobado también por el otro lado, con dos que sí entran.
        [$user, $medico, $gineco] = $this->medicoDePrueba();

        $this->enviar($user, $medico, $gineco, [$this->png('1.png'), $this->png('2.png')])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, ComentarioImagen::count());
    }

    // ------------------------------------------------------------------------------ La política de archivos

    public function test_una_imagen_de_mas_de_cinco_mb_se_rechaza(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();

        $enorme = UploadedFile::fake()->create(
            'captura.png',
            ArchivosClinicos::MAXIMO_KB_COMENTARIO + 1024,
            'image/png',
        );

        $this->enviar($user, $medico, $gineco, [$enorme])->assertSessionHasErrors('imagenes.0');

        $this->assertSame(0, Comentario::count());
        $this->assertSame([], $this->archivos());
    }

    public function test_un_archivo_con_contenido_que_no_es_imagen_se_rechaza_aunque_se_llame_png(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();

        // Un PDF de verdad con nombre de imagen: el tipo lo detecta el contenido (finfo), no la extensión.
        $pdf = $this->archivoReal('captura.png', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");

        $this->enviar($user, $medico, $gineco, [$pdf])->assertSessionHasErrors('imagenes.0');

        $this->assertSame(0, ComentarioImagen::count());
        $this->assertSame([], $this->archivos());
    }

    public function test_un_gif_real_se_rechaza_porque_el_perfil_de_comentario_no_lo_acepta(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();

        $gif = UploadedFile::fake()->createWithContent('captura.gif', base64_decode(self::GIF));

        $this->enviar($user, $medico, $gineco, [$gif])->assertSessionHasErrors('imagenes.0');

        $this->assertSame(0, ComentarioImagen::count());
        $this->assertSame([], $this->archivos());
    }

    // --------------------------------------------------------------------------------------- El pegado

    public function test_el_pegado_del_portapapeles_es_una_imagen_mas_que_se_guarda_igual(): void
    {
        // La parte de servidor del pegado: lo que el JS carga en el campo es un archivo con el nombre de una
        // captura pegada (`image.png`, que es el que pone Chrome). No hay ninguna diferencia con uno elegido.
        [$user, $medico, $gineco] = $this->medicoDePrueba();

        $this->enviar($user, $medico, $gineco, [$this->png('image.png')])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('clinica.ayuda'));

        $comentario = Comentario::firstOrFail();

        $this->assertSame(1, $comentario->imagenes()->count());
        $this->assertSame('image.png', $comentario->imagenes->first()->nombre_original);

        // La pantalla muestra la miniatura de lo mandado, con su ruta autorizada.
        $imagen = $comentario->imagenes->first();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.ayuda'))
            ->assertOk()
            ->assertSee(route('clinica.ayuda.imagen', ['comentario' => $comentario->id, 'imagen' => $imagen->id]), false);
    }

    // ---------------------------------------------------------------------------------- La autorización

    public function test_el_autor_abre_sus_capturas_y_un_tercero_no(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();
        [$otro, $otroMedico, $otraEspecialidad] = $this->medicoDePrueba();

        $this->enviar($user, $medico, $gineco, [$this->png()]);

        $comentario = Comentario::firstOrFail();
        $imagen = $comentario->imagenes->first();
        $url = route('clinica.ayuda.imagen', ['comentario' => $comentario->id, 'imagen' => $imagen->id]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($url);

        $respuesta->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        // No se cachea en un proxy compartido: los datos de una captura son de un paciente.
        $this->assertStringContainsString('no-store', (string) $respuesta->headers->get('Cache-Control'));

        // Otro médico no la ve: 404, no 403 (no se confirma que exista).
        $this->conContexto($otro, $otroMedico, $otraEspecialidad)->actingAs($otro)
            ->get($url)
            ->assertNotFound();

        // Ni siquiera el autor de otro comentario: la fila se resuelve por su comentario.
        $this->assertSame(1, ComentarioImagen::count());
    }

    public function test_la_bandeja_del_admin_abre_las_capturas_y_muestra_sus_miniaturas(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();

        $this->enviar($user, $medico, $gineco, [$this->png('agenda.png')], 'MENSAJE-CON-CAPTURA');

        $comentario = Comentario::firstOrFail();
        $imagen = $comentario->imagenes->first();
        $url = route('admin.comentarios.imagen', ['comentario' => $comentario->id, 'imagen' => $imagen->id]);

        $this->actingAs($this->usuario('Root'))
            ->get('/admin/comentarios')
            ->assertOk()
            ->assertSee('MENSAJE-CON-CAPTURA')
            ->assertSee($url, false);

        $this->actingAs($this->usuario('Root'))
            ->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        // Un médico no entra a la bandeja ni aunque adivine la URL.
        $this->actingAs($user)->get($url)->assertForbidden();
    }

    // ------------------------------------------------------------------------------------- El borrado

    public function test_al_descartar_el_comentario_se_borran_las_filas_y_los_archivos(): void
    {
        [$user, $medico, $gineco] = $this->medicoDePrueba();

        $this->enviar($user, $medico, $gineco, [$this->png('1.png'), $this->png('2.png')]);

        $comentario = Comentario::firstOrFail();
        $rutas = $comentario->imagenes->pluck('ruta')->all();

        $this->assertCount(2, $this->archivos());

        Livewire::actingAs($this->usuario('Root'))
            ->test(Comentarios::class)
            ->call('descartar', $comentario->id)
            ->assertHasNoErrors();

        // El comentario queda —es el registro de lo que nos dijeron—, pero sin capturas.
        $comentario->refresh();
        $this->assertTrue($comentario->fueDescartado());
        $this->assertSame(0, ComentarioImagen::count(), 'las filas se borraron');
        $this->assertSame([], $this->archivos(), 'y los archivos también');

        foreach ($rutas as $ruta) {
            Storage::disk(ArchivosDeLosComentarios::DISCO)->assertMissing($ruta);
        }
    }

    public function test_descartar_limpia_las_capturas_aunque_el_comentario_ya_estuviera_descartado(): void
    {
        // Reintentar el descarte no puede romper ni dejar archivos: es idempotente.
        [$user, $medico, $gineco] = $this->medicoDePrueba();

        $this->enviar($user, $medico, $gineco, [$this->png()]);

        $comentario = Comentario::firstOrFail();

        Livewire::actingAs($this->usuario('Root'))->test(Comentarios::class)->call('descartar', $comentario->id);
        Livewire::actingAs($this->usuario('Root'))->test(Comentarios::class)->call('descartar', $comentario->id);

        $this->assertSame(0, ComentarioImagen::count());
        $this->assertSame([], $this->archivos());
    }
}
