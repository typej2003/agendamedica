<?php

namespace Tests\Feature;

use App\Clinica\Archivos\ArchivosClinicos;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Http\Requests\SyncAppDataRequest;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\ImagenConsulta;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * **Los exámenes y las imágenes de una consulta** (WEB-4.7, ítem 7 del panel del legado).
 *
 * Es la sección más delicada de F4 porque **mueve un archivo que sube el usuario**, así que lo que estos
 * casos fijan es, en este orden:
 *
 *  1. **Dónde vive el dato y de qué cuelga**: `imagen_consulta` llaveada por `nrohistoria` + `nroconsulta`
 *     (la misma llave de `consultas`), que es como la escribe el escritorio
 *     (`w_consulta_principal.srw:10744`), y los exámenes de laboratorio en `examen_paciente` con el nombre
 *     resuelto por el catálogo `examenes` (`d_examenes_listado.srd`, `retrieve(historia, consulta)`).
 *  2. **La subida, con sus reglas de verdad**: tipo **real** permitido, extensión, tope de tamaño y **el
 *     nombre del cliente descartado** —el del servidor, sin `../`, sin pisar el archivo de otro médico—.
 *  3. **El disco privado**: el archivo queda **fuera de `public/`** (y no hay enlace simbólico que lo
 *     alcance), y la fila guarda la ruta relativa al disco en `imagen` con `disco` como marca de origen.
 *  4. **La autorización**: la imagen la abre **sólo el médico dueño** de la historia; otro médico recibe
 *     **404** y la secretaría **403**.
 *  5. **El aislamiento**: borrar la fila borra el archivo, y quitar la imagen de una consulta no toca la de
 *     otra ni el archivo de una fila que no es de la web.
 *  6. **El rastro del sync** (`created` / `deleted` con `source = web`) y que el **camino del móvil no
 *     cambió** (esa tabla no está en su contrato de sync).
 *
 * `DatabaseTransactions` + `Storage::fake('clinica')`, como el resto de los tests de la web clínica: el
 * disco falso tiene el mismo nombre que el real, así que lo que se prueba es la ruta y el nombre.
 */
class ConsultaImagenesWebTest extends TestCase
{
    use DatabaseTransactions;

    /** Un PNG de 1×1 válido (el más chico que existe): el contenido es real, así que el MIME se detecta. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private Carbon $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hoy = Carbon::parse('2026-10-11 09:00:00');
        Carbon::setTestNow($this->hoy->copy());
        Storage::fake(ArchivosClinicos::DISCO);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // --------------------------------------------------------------------------------------- Ayudas

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'examenes-test-' . uniqid();

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'examenes-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Examenes',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function secretariaConAcceso(Medico $medico): User
    {
        $secretaria = User::create([
            'name'                 => 'Secretaria',
            'email'                => 'examenes-secre-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        return $secretaria;
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico'   => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id'    => null,
        ]]);
    }

    /**
     * Un paciente con historia y su consulta.
     *
     * El `numhistoria` sale alto a propósito: `FakeClinicalDataSeeder` siembra las historias 1001–1050 y
     * reparte 80 consultas entre ellas (trampa 27 del repo). Una segunda consulta del mismo paciente
     * (el caso de "consulta nueva" del mismo día) sólo agrega la fila de `consultas`.
     */
    private function pacienteYConsulta(Medico $medico, int $numhistoria, int $nroconsulta = 1): Consulta
    {
        $yaEsta = Historia::where('reg_medico', $medico->reg_medico)
            ->where('numhistoria', (string) $numhistoria)
            ->exists();

        if (! $yaEsta) {
            $pacienteId = DB::table('pacientes')->insertGetId([
                'nombres'     => 'Ana',
                'apellidos'   => 'Alvarez',
                'cedula'      => (string) random_int(10000000, 30000000),
                'sexo'        => 'F',
                'fnacimiento' => '1990-05-04',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            MedicoPaciente::create([
                'medico_id'   => $medico->id,
                'paciente_id' => $pacienteId,
                'reg_medico'  => $medico->reg_medico,
                'numhistoria' => (string) $numhistoria,
            ]);

            Historia::create([
                'numhistoria' => (string) $numhistoria,
                'reg_medico'  => $medico->reg_medico,
                'paciente_id' => $pacienteId,
                'medico_id'   => $medico->id,
            ]);
        }

        return Consulta::create([
            'reg_medico'   => $medico->reg_medico,
            'numhistoria'  => $numhistoria,
            'nroconsulta'  => $nroconsulta,
            'fecha'        => $this->hoy->toDateString(),
            'enfermedadactual' => 'Enfermedad actual de la consulta.',
        ]);
    }

    /** Un catálogo de exámenes chico, como el que el escritorio sube en la carga inicial. */
    private function catalogo(Medico $medico): void
    {
        foreach ([
            ['017', 'HEMOGRAMA COMPLETO', '001'],
            ['022', 'GLICEMIA', '001'],
            ['076', 'RX DE TORAX  PA  LI', '002'],
        ] as [$codigo, $nombre, $tipo]) {
            DB::table('examenes')->insert([
                'reg_medico' => $medico->reg_medico,
                'codeexamen' => $codigo,
                'examen'     => $nombre,
                'codetipo'   => $tipo,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('tipos_examenes')->insert([
            'reg_medico' => $medico->reg_medico,
            'codetipo'   => '001',
            'tipo'       => 'LABORATORIO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Un PNG real, con el nombre que se le pase (es lo que el cliente manda y el servidor descarta). */
    private function png(string $nombre = 'estudio.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, base64_decode(self::PNG));
    }

    private function subir(User $user, Medico $medico, Specialty $specialty, Consulta $consulta, array $datos = []): TestResponse
    {
        return $this->conContexto($user, $medico, $specialty)->actingAs($user)->post(
            route('clinica.consulta.examenes.subir', $consulta->id),
            array_merge(['imagen' => $this->png(), 'descripcion' => 'Colposcopia: ectopia leve'], $datos),
        );
    }

    // ------------------------------------------------------------------- 1) La lectura de la sección

    public function test_la_seccion_lista_los_examenes_de_la_consulta_con_su_catalogo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico, 9001);
        $this->catalogo($medico);

        DB::table('examen_paciente')->insert([
            ['reg_medico' => $medico->reg_medico, 'nrohistoria' => 9001, 'nroconsulta' => 1, 'codeexamen' => '017', 'resultado' => null],
            // Un código que **no** está en el catálogo: la fila existe y se muestra con su código.
            ['reg_medico' => $medico->reg_medico, 'nrohistoria' => 9001, 'nroconsulta' => 1, 'codeexamen' => '999', 'resultado' => null],
            // Y uno de **otra** consulta del mismo paciente: no se mezcla.
            ['reg_medico' => $medico->reg_medico, 'nrohistoria' => 9001, 'nroconsulta' => 2, 'codeexamen' => '022', 'resultado' => null],
        ]);
        DB::table('examen_obs')->insert([
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => 9001,
            'numconsulta' => 1,
            'observacion' => 'HIV, VDRL',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // O-22 (2026-10-17): los exámenes informados por el laboratorio se leen dentro de la solapa
        // *Resultado de exámenes* de Evaluación física (la sección *Exámenes* se retiró).
        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_EVALUACION, 'pestana' => 'resultados']));

        $respuesta->assertOk();
        $respuesta->assertSee('Exámenes informados por el laboratorio');
        $respuesta->assertSee('HEMOGRAMA COMPLETO');
        $respuesta->assertSee('Código 017');
        // Fuera del catálogo: el código a la vista y el aviso.
        $respuesta->assertSee('No está en tu catálogo de exámenes');
        // La observación del conjunto, que no es el resultado de una fila.
        $respuesta->assertSee('HIV, VDRL');
        // El examen de la consulta 2 **no** está.
        $respuesta->assertDontSee('GLICEMIA');
    }

    public function test_el_panel_ya_no_tiene_el_item_de_examenes_y_su_galeria_vive_en_evaluacion(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico, 9002);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta', $consulta->id));

        $respuesta->assertOk();
        $respuesta->assertSee('Pre-factura', false);
        // O-22: *Exámenes* salió del panel; la sección de evaluación física sigue ofrecida.
        $respuesta->assertDontSee('?seccion=examenes', false);
        $respuesta->assertSee('?seccion=evaluacion', false);
        $respuesta->assertDontSee('Llega con WEB-4.7', false);
    }

    public function test_una_consulta_de_otro_medico_da_404_en_la_seccion(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otroMedico] = $this->medicoConAcceso();
        $ajena = $this->pacienteYConsulta($otroMedico, 9003);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta', [$ajena->id, 'seccion' => ConsultaShellVista::SECCION_EVALUACION, 'pestana' => 'imagenes']))
            ->assertNotFound();
    }

    // ---------------------------------------------------------------------------- 2) La subida

    public function test_el_medico_sube_una_imagen_y_queda_en_el_disco_privado(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico, 9004);

        $respuesta = $this->subir($user, $medico, $gineco, $consulta);

        $respuesta->assertRedirect(route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_EVALUACION, 'pestana' => 'imagenes']));
        $respuesta->assertSessionHas('estado');

        $imagen = ImagenConsulta::where('reg_medico', $medico->reg_medico)->firstOrFail();

        // **La fila cuelga de la consulta** (las dos mitades de la llave) y guarda la ruta en `imagen` —la
        // columna del legado, que es `NOT NULL` y significa exactamente eso—, con `disco` como marca de
        // origen.
        $this->assertSame(9004, $imagen->nrohistoria);
        $this->assertSame(1, $imagen->nroconsulta);
        $this->assertSame('Colposcopia: ectopia leve', $imagen->observacion);
        $this->assertSame(ArchivosClinicos::DISCO, $imagen->disco);
        $this->assertStringStartsWith('examenes/' . $medico->reg_medico . '/2026-10/', $imagen->imagen);
        $this->assertStringEndsWith('.png', $imagen->imagen);

        // El archivo está en el disco, con el nombre que armó el servidor.
        Storage::disk(ArchivosClinicos::DISCO)->assertExists($imagen->imagen);
    }

    public function test_el_archivo_queda_fuera_del_directorio_publico_y_sin_enlace(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico, 9005);

        $this->subir($user, $medico, $gineco, $consulta)->assertSessionHasNoErrors();

        $imagen = ImagenConsulta::where('reg_medico', $medico->reg_medico)->firstOrFail();

        // La raíz del disco `clinica` **no** está dentro de `public/`: es la decisión de seguridad de la
        // tarea, no un detalle de configuración. Se comprueba sobre la configuración del disco y sobre el
        // archivo que quedó escrito (`Storage::fake` usa la raíz real del disco, no otra).
        $raiz = Storage::disk(ArchivosClinicos::DISCO)->path('');
        $publica = public_path();

        $this->assertStringNotContainsString($publica, $raiz, 'El disco de archivos clínicos no puede estar dentro de public/.');
        $this->assertFileExists(Storage::disk(ArchivosClinicos::DISCO)->path($imagen->imagen));

        // Y el disco **no** está en `filesystems.links`: no hay enlace simbólico que lo publique.
        $this->assertArrayNotHasKey('public/clinica', config('filesystems.links'));
        $this->assertNotContains('clinica', array_keys(config('filesystems.links')));
    }

    public function test_un_archivo_que_no_es_imagen_se_rechaza(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico, 9006);

        $respuesta = $this->subir($user, $medico, $gineco, $consulta, [
            'imagen' => UploadedFile::fake()->createWithContent('receta.txt', 'no soy una imagen'),
        ]);

        $respuesta->assertSessionHasErrors('imagen');
        $this->assertSame(0, ImagenConsulta::where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame([], Storage::disk(ArchivosClinicos::DISCO)->allFiles());
    }

    public function test_lo_que_decide_el_tipo_es_el_contenido_y_no_el_nombre(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico, 9007);

        // Un archivo que **se llama** `.php` y no es una imagen: el nombre no lo salva ni lo hunde, lo que
        // decide es el contenido (el MIME real). Se rechaza.
        $respuesta = $this->subir($user, $medico, $gineco, $consulta, [
            'imagen' => UploadedFile::fake()->createWithContent('payload.php', '<?php echo "hola";'),
        ]);

        $respuesta->assertSessionHasErrors('imagen');
        $this->assertSame(0, ImagenConsulta::where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame([], Storage::disk(ArchivosClinicos::DISCO)->allFiles());

        // Y al revés: un PNG de verdad con nombre `.php` se guarda con la extensión del **contenido** y un
        // nombre que arma el servidor: el archivo nunca queda con una extensión ejecutable y el nombre del
        // cliente no decide qué se guarda (`ArchivosClinicos::extension()` mira el MIME, no el nombre).
        $respuesta = $this->subir($user, $medico, $gineco, $consulta, [
            'imagen' => $this->png('estudio.png'),
        ]);

        $respuesta->assertSessionHasNoErrors();

        $imagen = ImagenConsulta::where('reg_medico', $medico->reg_medico)->firstOrFail();

        $this->assertStringEndsWith('.png', $imagen->imagen);
        $this->assertStringNotContainsString('estudio', $imagen->imagen);
        Storage::disk(ArchivosClinicos::DISCO)->assertExists($imagen->imagen);
    }

    /**
     * La limitación medida del detector de tipo, escrita para que no vuelva a sorprender.
     *
     * `UploadedFile::getMimeType()` (finfo) **usa la extensión del nombre como pista** cuando el contenido
     * no alcanza para decidir: un `<?php echo "hola";` llamado `foto.png` se detecta `image/png`. O sea que
     * el MIME real **no** distingue por sí solo un archivo con contenido arbitrario y nombre de imagen.
     *
     * Lo que sí protege —y es lo que este caso fija— es que el archivo se guarda **con la extensión del
     * contenido y un nombre del servidor**, en un disco privado fuera de `public/`: nunca queda un `.php`
     * (ni un `.png` con PHP adentro servido por el servidor web, que igual no se ejecutaría). La validación
     * que sí rechaza por nombre es la de la extensión (`extensionPermitida`), cubierta arriba.
     */
    public function test_el_mime_de_finfo_usa_la_extension_como_pista_y_eso_esta_cubierto(): void
    {
        $archivo = UploadedFile::fake()->createWithContent('foto.png', '<?php echo "hola";');

        $this->assertSame('image/png', $archivo->getMimeType(), 'finfo cambió de criterio: revisar la política de archivos.');

        // Con el nombre de una extensión que no está en la lista blanca, el mismo contenido sí se rechaza.
        $this->assertFalse(ArchivosClinicos::extensionPermitida('foto.php'));
        $this->assertFalse(ArchivosClinicos::extensionPermitida('foto.png.exe'));
        $this->assertTrue(ArchivosClinicos::extensionPermitida('foto.PNG'));
    }

    public function test_una_imagen_con_extension_no_permitida_se_rechaza(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico, 9011);

        // El contenido es un PNG de verdad, pero el nombre que mandó el cliente no es de la lista blanca:
        // se rechaza igual (defensa en profundidad, y es lo que la política dice que acepta).
        $respuesta = $this->subir($user, $medico, $gineco, $consulta, [
            'imagen' => $this->png('estudio.png.exe'),
        ]);

        $respuesta->assertSessionHasErrors('imagen');
        $this->assertSame(0, ImagenConsulta::where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame([], Storage::disk(ArchivosClinicos::DISCO)->allFiles());
    }

    public function test_una_imagen_mas_grande_que_el_tope_se_rechaza(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico, 9008);

        // El tope es el de la política compartida (`ArchivosClinicos::MAXIMO_KB`), no un número repetido
        // en la vista: si mañana cambia, cambia el mensaje y el test.
        $respuesta = $this->subir($user, $medico, $gineco, $consulta, [
            'imagen' => UploadedFile::fake()->create('enorme.png', ArchivosClinicos::MAXIMO_KB + 1024, 'image/png'),
        ]);

        $respuesta->assertSessionHasErrors('imagen');
        $this->assertSame(0, ImagenConsulta::where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame([], Storage::disk(ArchivosClinicos::DISCO)->allFiles());
    }

    public function test_el_nombre_malicioso_del_cliente_se_descarta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico, 9009);

        // El nombre que manda el cliente no toca el disco: ni el `../` ni el nombre en sí. Lo que se
        // guarda es un nombre al azar con la extensión del **contenido**.
        $respuesta = $this->subir($user, $medico, $gineco, $consulta, [
            'imagen' => $this->png('../../../../etc/passwd.png'),
        ]);

        $respuesta->assertSessionHasNoErrors();

        $imagen = ImagenConsulta::where('reg_medico', $medico->reg_medico)->firstOrFail();

        $this->assertStringNotContainsString('passwd', $imagen->imagen);
        $this->assertStringNotContainsString('..', $imagen->imagen);
        $this->assertStringStartsWith('examenes/' . $medico->reg_medico . '/', $imagen->imagen);
        // El nombre del archivo en el disco es de 40 caracteres al azar + la extensión real.
        $this->assertMatchesRegularExpression(
            '~^examenes/' . preg_quote($medico->reg_medico, '~') . '/\d{4}-\d{2}/[A-Za-z0-9]{40}\.png$~',
            $imagen->imagen,
        );
        Storage::disk(ArchivosClinicos::DISCO)->assertExists($imagen->imagen);

        // Y no se escribió nada fuera de la carpeta del médico.
        $archivos = Storage::disk(ArchivosClinicos::DISCO)->allFiles();
        $this->assertCount(1, $archivos);
        $this->assertStringStartsWith('examenes/' . $medico->reg_medico . '/', $archivos[0]);
    }

    public function test_sin_archivo_la_subida_no_crea_nada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico, 9010);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post(
            route('clinica.consulta.examenes.subir', $consulta->id),
            ['descripcion' => 'sin archivo'],
        );

        $respuesta->assertSessionHasErrors('imagen');
        $this->assertSame(0, ImagenConsulta::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_una_consulta_de_otro_medico_no_admite_la_subida(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otroMedico] = $this->medicoConAcceso();
        $ajena = $this->pacienteYConsulta($otroMedico, 9012);

        // El médico del contexto es el **primero**, y la consulta es del otro: 404 sin confirmar que exista,
        // y no se subió nada. La validación del archivo ni se llega a correr: la consulta se resuelve antes.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post(
            route('clinica.consulta.examenes.subir', $ajena->id),
            ['imagen' => $this->png()],
        )->assertNotFound();

        $this->assertSame(0, ImagenConsulta::where('reg_medico', $otroMedico->reg_medico)->count());
        $this->assertSame([], Storage::disk(ArchivosClinicos::DISCO)->allFiles());

        // La **misma** consulta ajena leída por el médico del contexto: tampoco (la vara es una sola para
        // la sección y para las acciones).
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta', [$ajena->id, 'seccion' => ConsultaShellVista::SECCION_EVALUACION, 'pestana' => 'imagenes']))
            ->assertNotFound();
    }

    // ------------------------------------------------------------ 3) La descarga autorizada (404 / 403)

    public function test_el_medico_dueno_abre_su_imagen(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico, 9013);
        $this->subir($user, $medico, $gineco, $consulta)->assertSessionHasNoErrors();

        $imagen = ImagenConsulta::where('reg_medico', $medico->reg_medico)->firstOrFail();

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta.examenes.descargar', [$consulta->id, $imagen->id]));

        $respuesta->assertOk();
        $respuesta->assertHeader('content-type', 'image/png');
        // La ruta del sistema no viaja en la respuesta.
        $this->assertStringNotContainsString('storage', (string) $respuesta->headers->get('content-disposition'));
        $this->assertStringNotContainsString(storage_path(), $respuesta->getContent());
        $this->assertSame(base64_decode(self::PNG), $respuesta->streamedContent());
    }

    public function test_otro_medico_no_puede_abrir_la_imagen_ni_sabiendo_el_id(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico, 9014);
        $this->subir($user, $medico, $gineco, $consulta)->assertSessionHasNoErrors();
        $imagen = ImagenConsulta::where('reg_medico', $medico->reg_medico)->firstOrFail();

        // El otro médico **sí** tiene contexto propio y una consulta válida suya: lo único que no tiene es
        // esta imagen. La respuesta es 404 (no se confirma que exista), no un 403 que la delate.
        [$otroUser, $otroMedico, $otraEspecialidad] = $this->medicoConAcceso();
        $suya = $this->pacienteYConsulta($otroMedico, 9015);

        // (a) Con **su propia** consulta en la URL y el id de la imagen ajena.
        $this->conContexto($otroUser, $otroMedico, $otraEspecialidad)->actingAs($otroUser)
            ->get(route('clinica.consulta.examenes.descargar', [$suya->id, $imagen->id]))
            ->assertNotFound();

        // (b) Con la consulta ajena en la URL (que ni siquiera es suya): 404 por la consulta.
        $this->conContexto($otroUser, $otroMedico, $otraEspecialidad)->actingAs($otroUser)
            ->get(route('clinica.consulta.examenes.descargar', [$consulta->id, $imagen->id]))
            ->assertNotFound();
    }

    public function test_la_secretaria_no_entra_a_los_examenes(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico, 9016);
        $this->subir($user, $medico, $gineco, $consulta)->assertSessionHasNoErrors();
        $imagen = ImagenConsulta::where('reg_medico', $medico->reg_medico)->firstOrFail();

        // La consulta es **sólo del médico** (PLAN-WEB.md §3.14, trampa 19): la secretaría recibe 403 en el
        // shell y en las tres rutas de las imágenes —incluida la que sirve el archivo—.
        $secretaria = $this->secretariaConAcceso($medico);

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->get(route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_EVALUACION, 'pestana' => 'imagenes']))
            ->assertForbidden();

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->post(route('clinica.consulta.examenes.subir', $consulta->id), ['imagen' => $this->png()])
            ->assertForbidden();

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->get(route('clinica.consulta.examenes.descargar', [$consulta->id, $imagen->id]))
            ->assertForbidden();

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->delete(route('clinica.consulta.examenes.quitar', [$consulta->id, $imagen->id]))
            ->assertForbidden();

        // Y no se tocó nada.
        Storage::disk(ArchivosClinicos::DISCO)->assertExists($imagen->imagen);
        $this->assertSame(1, ImagenConsulta::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_una_imagen_del_escritorio_no_se_puede_servir(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico, 9017);

        // Una fila como la deja el escritorio: `imagen` con la ruta del disco de la PC, `disco` nulo y sin
        // archivo en el servidor. Se lista (la sección lo dice), pero no hay nada que servir: 404.
        $id = DB::table('imagen_consulta')->insertGetId([
            'reg_medico'  => $medico->reg_medico,
            'nrohistoria' => 9017,
            'nroconsulta' => 1,
            'observacion' => 'Colposcopia vieja',
            'imagen'      => 'C:\\Users\\IBYTE\\Downloads\\20211025_121548.jpg',
            'orden'       => 0,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta.examenes.descargar', [$consulta->id, $id]))
            ->assertNotFound();

        // Y la sección la lista avisando de dónde salió el archivo, sin mostrar la ruta del sistema.
        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_EVALUACION, 'pestana' => 'imagenes']));

        $respuesta->assertOk();
        $respuesta->assertSee('20211025_121548.jpg');
        $respuesta->assertSee('En el disco del consultorio');
        $respuesta->assertSee('la ruta del archivo', false);
        $respuesta->assertDontSee('IBYTE', false);
    }

    // ------------------------------------------------------------------ 4) El borrado y el aislamiento

    public function test_quitar_una_imagen_borra_la_fila_y_el_archivo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico, 9018);
        $this->subir($user, $medico, $gineco, $consulta)->assertSessionHasNoErrors();
        $imagen = ImagenConsulta::where('reg_medico', $medico->reg_medico)->firstOrFail();
        $ruta = $imagen->imagen;

        Storage::disk(ArchivosClinicos::DISCO)->assertExists($ruta);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->delete(route('clinica.consulta.examenes.quitar', [$consulta->id, $imagen->id]));

        $respuesta->assertRedirect(route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_EVALUACION, 'pestana' => 'imagenes']));
        $this->assertNull(ImagenConsulta::find($imagen->id));
        Storage::disk(ArchivosClinicos::DISCO)->assertMissing($ruta);
    }

    public function test_quitar_una_imagen_del_escritorio_no_toca_ningun_archivo_del_servidor(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico, 9019);
        $this->subir($user, $medico, $gineco, $consulta)->assertSessionHasNoErrors();

        $deLaWeb = ImagenConsulta::where('reg_medico', $medico->reg_medico)->firstOrFail();

        $idVieja = DB::table('imagen_consulta')->insertGetId([
            'reg_medico'  => $medico->reg_medico,
            'nrohistoria' => 9019,
            'nroconsulta' => 1,
            'observacion' => 'Vieja',
            'imagen'      => 'C:\\Users\\IBYTE\\Downloads\\vieja.jpg',
            'orden'       => 0,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->delete(route('clinica.consulta.examenes.quitar', [$consulta->id, $idVieja]))
            ->assertRedirect();

        // La fila del escritorio se fue y **el archivo de la web sigue**: no se tocó nada que no fuera suyo
        // (y el archivo del consultorio, que vive en su PC, tampoco se toca: eso es WEB-T.1).
        $this->assertNull(ImagenConsulta::find($idVieja));
        Storage::disk(ArchivosClinicos::DISCO)->assertExists($deLaWeb->imagen);
        $this->assertNotNull(ImagenConsulta::find($deLaWeb->id));
    }

    public function test_no_se_puede_quitar_la_imagen_de_otra_consulta_del_mismo_paciente(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico, 9020, 1);
        $otraConsulta = $this->pacienteYConsulta($medico, 9020, 2);

        // La imagen cuelga de la consulta 1; se intenta borrar desde la URL de la consulta 2.
        $this->subir($user, $medico, $gineco, $consulta)->assertSessionHasNoErrors();
        $imagen = ImagenConsulta::where('reg_medico', $medico->reg_medico)->firstOrFail();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->delete(route('clinica.consulta.examenes.quitar', [$otraConsulta->id, $imagen->id]))
            ->assertNotFound();

        $this->assertNotNull(ImagenConsulta::find($imagen->id));
        Storage::disk(ArchivosClinicos::DISCO)->assertExists($imagen->imagen);
    }

    // ------------------------------------------------------------------- 5) El rastro para el sync

    public function test_la_subida_y_la_baja_dejan_su_rastro_web_en_sync_changes(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico, 9021);
        $this->subir($user, $medico, $gineco, $consulta)->assertSessionHasNoErrors();

        $imagen = ImagenConsulta::where('reg_medico', $medico->reg_medico)->firstOrFail();

        $creado = SyncChange::where('table_name', 'imagen_consulta')
            ->where('record_id', $imagen->id)->where('operation', 'created')->first();

        $this->assertNotNull($creado, 'La subida tiene que dejar su rastro en sync_changes.');
        $this->assertSame('web', $creado->source);
        $this->assertSame('imagen', $creado->column_name);
        $this->assertSame($imagen->imagen, $creado->value);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->delete(route('clinica.consulta.examenes.quitar', [$consulta->id, $imagen->id]));

        $borrado = SyncChange::where('table_name', 'imagen_consulta')
            ->where('record_id', $imagen->id)->where('operation', 'deleted')->first();

        $this->assertNotNull($borrado, 'La baja tiene que dejar su rastro en sync_changes.');
        $this->assertSame('web', $borrado->source);
    }

    public function test_el_movil_no_cambia_su_contrato_con_esta_tabla(): void
    {
        // `imagen_consulta` **no** está entre las tablas del sync del móvil (`SyncAppDataRequest`): lo que
        // se agregó en WEB-4.7 es la web, no su contrato. Este test fija que siga así y que la tabla esté
        // en la lista blanca del escritorio (que es quien sube las suyas por su camino genérico).
        $this->assertNotContains('imagen_consulta', SyncAppDataRequest::SYNC_TABLES);
        $this->assertNotContains('imagen_consulta', SyncAppDataRequest::CLINICAL_TABLES);
        $this->assertNotContains('imagen_consulta', SyncAppDataRequest::DELETABLE_TABLES);
        $this->assertContains('imagen_consulta', config('sync_legado.tablas'));
    }
}
