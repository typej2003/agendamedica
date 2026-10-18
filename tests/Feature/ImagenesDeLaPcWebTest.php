<?php

namespace Tests\Feature;

use App\Clinica\Archivos\ArchivosClinicos;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\ImagenConsulta;
use App\Models\ImagenDeLaPc;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * **Lo que la web hace con las imágenes que `subir-imagenes` trajo de la PC** (WEB-T.1): la sección de exámenes e imágenes de
 * WEB-4.7 las muestra como «En el servidor» y las abre por la **ruta autorizada de siempre**; un HEIC se ofrece como
 * **descarga** con su `Content-Type` real; las filas que no se migraron siguen igual; y quitar una migrada borra su archivo y
 * su registro. Los tests de WEB-4.7 (`ConsultaImagenesWebTest`) no se tocaron.
 */
class ImagenesDeLaPcWebTest extends TestCase
{
    use DatabaseTransactions;

    private const API_KEY = 'MiClaveSecreta123!';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ArchivosClinicos::DISCO);
    }

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'imgpcweb-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Usuario de prueba',
            'email' => 'imgpcweb-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id,
            'name' => 'Dr. de prueba',
            'lastname' => 'ImagenesPc',
            'email' => $user->email,
            'password' => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function conContexto(Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id' => null,
        ]]);
    }

    private function pacienteYConsulta(Medico $medico, int $numhistoria): Consulta
    {
        $pacienteId = DB::table('pacientes')->insertGetId([
            'nombres' => 'Ana', 'apellidos' => 'Alvarez', 'cedula' => (string) random_int(10000000, 30000000),
            'sexo' => 'F', 'fnacimiento' => '1990-05-04', 'created_at' => now(), 'updated_at' => now(),
        ]);
        MedicoPaciente::create(['medico_id' => $medico->id, 'paciente_id' => $pacienteId, 'reg_medico' => $medico->reg_medico, 'numhistoria' => (string) $numhistoria]);
        Historia::create(['numhistoria' => (string) $numhistoria, 'reg_medico' => $medico->reg_medico, 'paciente_id' => $pacienteId, 'medico_id' => $medico->id]);

        return Consulta::create([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => $numhistoria, 'nroconsulta' => 1,
            'fecha' => now()->toDateString(), 'enfermedadactual' => 'x',
        ]);
    }

    private function filaDeEscritorio(Medico $medico, int $numhistoria, string $ruta): ImagenConsulta
    {
        return ImagenConsulta::create([
            'reg_medico' => $medico->reg_medico, 'nrohistoria' => $numhistoria, 'nroconsulta' => 1,
            'observacion' => 'Colposcopia de ' . basename(str_replace('\\', '/', $ruta)), 'imagen' => $ruta, 'orden' => 1,
        ]);
    }

    /** Sube por la API de sync, como lo hace la herramienta de la PC. */
    private function migrar(Medico $medico, ImagenConsulta $fila, string $contenido): void
    {
        $this->post('/api/sync/imagenes/subir', [
            'reg_medico' => $medico->reg_medico, 'tabla' => 'imagen_consulta', 'fila_id' => $fila->id, 'columna' => 'imagen',
            'ruta_pc' => $fila->imagen, 'sha256' => hash('sha256', $contenido),
            'archivo' => UploadedFile::fake()->createWithContent('x.bin', $contenido),
        ], ['X-API-KEY' => self::API_KEY, 'Accept' => 'application/json'])->assertOk();
    }

    private function seccion(User $user, Medico $medico, Specialty $specialty, Consulta $consulta)
    {
        return $this->conContexto($medico, $specialty)->actingAs($user)
            ->get(route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_EVALUACION, 'pestana' => 'imagenes']));
    }

    public function test_una_imagen_migrada_se_ve_en_el_servidor_y_se_abre_por_la_ruta_autorizada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico, 9701);
        $migrada = $this->filaDeEscritorio($medico, 9701, 'C:\\Users\\IBYTE\\Downloads\\20211025_121548.jpg');
        $sinMigrar = $this->filaDeEscritorio($medico, 9701, 'C:\\Users\\IBYTE\\Downloads\\20220505_081302.jpg');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $this->migrar($medico, $migrada, $png);

        $pagina = $this->seccion($user, $medico, $gineco, $consulta)->assertOk();
        $pagina->assertSee('En el servidor');
        $pagina->assertSee('En el disco del consultorio'); // la que no se migró, igual que antes
        // El enlace «Ver» (href) existe sólo para la migrada: la URL de «Quitar» es la misma ruta con otro verbo (trampa 18/44).
        $pagina->assertSee('href="' . route('clinica.consulta.examenes.descargar', [$consulta->id, $migrada->id]) . '"', false);
        $pagina->assertDontSee('href="' . route('clinica.consulta.examenes.descargar', [$consulta->id, $sinMigrar->id]) . '"', false);
        $pagina->assertDontSee('storage/app', false);
        $pagina->assertDontSee('importadas/', false);
        // Se muestra el nombre que el archivo tenía en el consultorio y no el aleatorio del servidor.
        $pagina->assertSee('20211025_121548.jpg');
        $pagina->assertDontSee(basename(ImagenDeLaPc::where('fila_id', $migrada->id)->value('ruta')));

        // Se abre por la ruta autorizada, en línea, con el tipo real.
        $r = $this->conContexto($medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta.examenes.descargar', [$consulta->id, $migrada->id]));
        $r->assertOk()->assertHeader('content-type', 'image/png');
        $this->assertStringStartsWith('inline;', (string) $r->headers->get('content-disposition'));
        $this->assertSame($png, $r->streamedContent());
        $this->assertStringNotContainsString(storage_path(), (string) $r->headers->get('content-disposition'));

        // La no migrada sigue sin servirse.
        $this->conContexto($medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta.examenes.descargar', [$consulta->id, $sinMigrar->id]))->assertNotFound();
    }

    public function test_sin_sesion_la_ruta_manda_al_login_y_otro_medico_recibe_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico, 9702);
        $fila = $this->filaDeEscritorio($medico, 9702, 'C:\\a.jpg');
        $this->migrar($medico, $fila, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $url = route('clinica.consulta.examenes.descargar', [$consulta->id, $fila->id]);

        $this->get($url)->assertRedirect(route('login'));

        [$otroUser, $otroMedico, $otraEspecialidad] = $this->medicoConAcceso();
        $suya = $this->pacienteYConsulta($otroMedico, 9703);
        $this->conContexto($otroMedico, $otraEspecialidad)->actingAs($otroUser)
            ->get(route('clinica.consulta.examenes.descargar', [$suya->id, $fila->id]))->assertNotFound();
    }

    public function test_un_heic_migrado_se_ofrece_como_descarga_con_su_tipo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico, 9704);
        $fila = $this->filaDeEscritorio($medico, 9704, 'C:\\celular\\IMG_4021.HEIC');
        $heic = \Tests\Unit\TipoRealDeImagenTest::iso('heic', ['mif1', 'heic']) . random_bytes(80);
        $this->migrar($medico, $fila, $heic);

        $pagina = $this->seccion($user, $medico, $gineco, $consulta)->assertOk();
        $pagina->assertSee('Descargar');
        $pagina->assertSee('foto HEIC');
        // El HEIC no se pinta como imagen: la lista de la galería no tiene ningún `<img>` (el único de la
        // página es el logo de la especialidad del armazón).
        $html = $pagina->getContent();
        $desde = strpos($html, 'id="evaluacion-imagenes-imagenes"');
        $this->assertNotFalse($desde, 'falta la lista de imágenes de la galería');
        $hasta = strpos($html, '</ul>', $desde);
        $this->assertStringNotContainsString('<img', substr($html, $desde, $hasta - $desde));

        $r = $this->conContexto($medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta.examenes.descargar', [$consulta->id, $fila->id]));
        $r->assertOk()->assertHeader('content-type', 'image/heic');
        $this->assertStringStartsWith('attachment;', (string) $r->headers->get('content-disposition'));
        $this->assertStringContainsString('IMG_4021.HEIC', (string) $r->headers->get('content-disposition'));
        $this->assertSame($heic, $r->streamedContent());
    }

    public function test_quitar_una_migrada_borra_el_archivo_y_su_registro(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico, 9705);
        $fila = $this->filaDeEscritorio($medico, 9705, 'C:\\b.jpg');
        $this->migrar($medico, $fila, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $this->assertCount(1, Storage::disk('clinica')->allFiles());
        $this->assertSame(1, ImagenDeLaPc::where('fila_id', $fila->id)->where('tabla', 'imagen_consulta')->count());

        $this->conContexto($medico, $gineco)->actingAs($user)
            ->delete(route('clinica.consulta.examenes.quitar', [$consulta->id, $fila->id]))->assertRedirect();

        $this->assertSame([], Storage::disk('clinica')->allFiles());
        $this->assertSame(0, ImagenDeLaPc::where('fila_id', $fila->id)->where('tabla', 'imagen_consulta')->count());
        $this->assertNull(ImagenConsulta::find($fila->id));
    }

    public function test_las_filas_de_la_web_y_las_no_migradas_se_comportan_como_en_4_7(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico, 9706);

        // Una subida de la web: ruta relativa + `disco`, sin registro de importación.
        $this->conContexto($medico, $gineco)->actingAs($user)->post(
            route('clinica.consulta.examenes.subir', $consulta->id),
            ['imagen' => UploadedFile::fake()->createWithContent('estudio.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')), 'descripcion' => 'de la web'],
        )->assertSessionHasNoErrors();
        $this->filaDeEscritorio($medico, 9706, 'C:\\archivos\\vieja.jpg');

        $pagina = $this->seccion($user, $medico, $gineco, $consulta)->assertOk();
        $pagina->assertSee('de la web');
        $pagina->assertSee('Ver');
        $pagina->assertSee('En el disco del consultorio');
        $pagina->assertDontSee('Descargar');
        $this->assertSame(0, ImagenDeLaPc::where('reg_medico', $medico->reg_medico)->count());
    }
}
