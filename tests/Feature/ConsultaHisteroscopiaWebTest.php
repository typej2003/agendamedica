<?php

namespace Tests\Feature;

use App\Clinica\Archivos\ArchivosClinicos;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\PestanasDelExamen;
use App\Models\Consulta;
use App\Models\DibujoDelExamen;
use App\Models\EleccionDeImagenDelExamen;
use App\Models\Historia;
use App\Models\ImagenConsulta;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * **La histeroscopía con su imagen** (O-10): la misma tarjeta de dos modos que la colposcopía (O-09), reusando todo —el
 * lienzo, la elección y la impresión—, con su **imagen predeterminada** nueva (el esquema de la cavidad uterina que dejó
 * el cliente, `public/img/clinica/histeroscopia.svg`) y su foto real (el videohisteroscopio).
 *
 * Lo que estos casos fijan:
 *
 *  - la solapa tiene **la misma tarjeta**: el radiobutón de los dos modos, **un** lienzo de esquema (`utero`) sobre el SVG
 *    nuevo, sin selector de base (el esquema es uno solo) y las fotos del estudio adentro del modo de la foto;
 *  - el **texto libre sigue** (la columna `consultas.cuello`) y su hoja «INFORME DE HISTEROSCOPÍA» no cambia;
 *  - la **elección** se guarda por examen (colposcopía e histeroscopía no se pisan) y abre en el modo que tiene datos;
 *  - se **marca sobre el esquema y sobre la foto**, y el trazo queda atado a la foto (`foto:<id>`);
 *  - el informe imprime **sólo lo elegido**.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica. La ronda de Chrome es la misma de O-09
 * (`../.agent-tools/verificar-consulta-imagen-examen-web.js`, sección F).
 */
class ConsultaHisteroscopiaWebTest extends TestCase
{
    use DatabaseTransactions;

    /** Un PNG de 1×1 válido (el más chico que existe): el contenido es real, así que el MIME se detecta. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private Carbon $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ArchivosClinicos::DISCO);

        $this->hoy = Carbon::parse('2026-10-13 09:00:00');
        Carbon::setTestNow($this->hoy->copy());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'histero-test-' . uniqid();

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'histero-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Histeroscopía',
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
        return $this->withSession(['clinica.contexto' => [
            'reg_medico'   => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id'    => null,
        ]]);
    }

    /** @return array{0:int, 1:Consulta} */
    private function pacienteYConsulta(Medico $medico, int $numhistoria = 1001, int $nroconsulta = 1): array
    {
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

        $consulta = Consulta::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => $numhistoria,
            'nroconsulta' => $nroconsulta,
            'fecha'       => $this->hoy->toDateString(),
        ]);

        return [$pacienteId, $consulta];
    }

    private function png(string $nombre = 'utero.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, base64_decode(self::PNG));
    }

    private function imagen(Medico $medico, Consulta $consulta, string $observacion, bool $enElServidor = false): ImagenConsulta
    {
        $ruta = $enElServidor ? 'examenes/' . $medico->reg_medico . '/utero.png' : 'C:\\Users\\IBYTE\\Downloads\\utero.jpg';

        if ($enElServidor) {
            Storage::disk(ArchivosClinicos::DISCO)->put($ruta, base64_decode(self::PNG));
        }

        return ImagenConsulta::create([
            'reg_medico'  => $medico->reg_medico,
            'nrohistoria' => (int) $consulta->numhistoria,
            'nroconsulta' => (int) $consulta->nroconsulta,
            'observacion' => $observacion,
            'imagen'      => $ruta,
            'disco'       => $enElServidor ? ArchivosClinicos::DISCO : null,
            'orden'       => 1,
        ]);
    }

    private function urlSolapa(Consulta $consulta, string $pestana = 'histeroscopia'): string
    {
        return route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_EVALUACION,
            'pestana' => $pestana,
        ]);
    }

    private function urlLienzo(Consulta $consulta, string $lienzo, string $verbo = 'ver'): string
    {
        return route('clinica.consulta.examen.imagen.' . $verbo, [$consulta->id, 'histeroscopia', $lienzo]);
    }

    private function urlElegir(Consulta $consulta): string
    {
        return route('clinica.consulta.examen.imagen.elegir', [$consulta->id, 'histeroscopia']);
    }

    private function panelesVisibles(string $html): array
    {
        preg_match_all('/<div data-imagen-panel="[a-z]+"[^>]*>/', $html, $coincidencias);

        return array_values(array_filter($coincidencias[0], fn (string $etiqueta) => ! str_contains($etiqueta, 'hidden')));
    }

    // ------------------------------------------------------------------------------- La tarjeta

    public function test_la_solapa_tiene_la_misma_tarjeta_de_dos_modos_con_el_esquema_nuevo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSolapa($consulta))->assertOk()->getContent();

        // El mismo radiobutón de la colposcopía, con el nombre de su foto.
        $this->assertSame(2, substr_count($html, 'data-imagen-modo-opcion'));
        $this->assertStringContainsString('Imagen predeterminada', $html);
        $this->assertStringContainsString('Videohisteroscopio', $html);
        $this->assertStringContainsString('Imagen de la histeroscopía', $html);

        // Un solo lienzo de esquema (`utero`), sobre el SVG que dejó el cliente y **sin** selector de base.
        $this->assertStringContainsString('data-variante="utero"', $html);
        $this->assertSame(1, substr_count($html, 'data-lienzo-canvas'));
        $this->assertStringContainsString('img/clinica/histeroscopia.svg', $html);
        $this->assertStringNotContainsString('data-lienzo-base', $html);

        // Abre en el esquema y se ve sólo ese modo.
        $visibles = $this->panelesVisibles($html);
        $this->assertCount(1, $visibles);
        $this->assertStringContainsString('"esquema"', $visibles[0]);

        // El texto libre de la solapa sigue: es el cuerpo de su informe.
        $this->assertStringContainsString('id="histeroscopia"', $html);
        $this->assertStringContainsString('histeroscopia-form', $html);
    }

    public function test_abre_en_el_videohisteroscopio_cuando_el_estudio_ya_tiene_foto(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $imagen = $this->imagen($medico, $consulta, 'H', true);

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSolapa($consulta))->assertOk()->getContent();

        $visibles = $this->panelesVisibles($html);
        $this->assertCount(1, $visibles);
        $this->assertStringContainsString('"foto"', $visibles[0]);
        $this->assertStringContainsString('data-foto="' . $imagen->id . '"', $html);
    }

    // ------------------------------------------------------------------------------ La elección

    public function test_la_eleccion_es_por_examen_y_no_se_pisa_con_la_de_la_colposcopia(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $web = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        $web->post(route('clinica.consulta.examen.imagen.elegir', [$consulta->id, 'colposcopia']), ['modo' => 'esquema'])
            ->assertOk();
        $web->post($this->urlElegir($consulta), ['modo' => 'foto'])->assertOk()->assertJson(['modo' => 'foto']);

        $this->assertSame(2, EleccionDeImagenDelExamen::count());
        $this->assertSame('esquema', EleccionDeImagenDelExamen::where('examen', 'colposcopia')->value('modo'));
        $this->assertSame('foto', EleccionDeImagenDelExamen::where('examen', 'histeroscopia')->value('modo'));
    }

    // --------------------------------------------------------------------------- Marcar y guardar

    public function test_se_marca_el_esquema_del_utero_y_la_foto_con_su_trazo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $imagen = $this->imagen($medico, $consulta, 'H', true);
        $web = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        // El esquema: una fila `esquema:utero` en la histeroscopía (sin base, que no elige).
        $web->post($this->urlLienzo($consulta, 'utero', 'guardar'), ['dibujo' => $this->png()])
            ->assertOk()->assertJson(['guardado' => true, 'dibujo' => true]);
        $esquema = DibujoDelExamen::where('clave', 'esquema:utero')->firstOrFail();
        $this->assertSame('histeroscopia', $esquema->examen);
        $this->assertSame('utero', $esquema->variante);
        $this->assertNull($esquema->base);
        $web->get($this->urlLienzo($consulta, 'utero'))->assertOk()->assertHeader('Content-Type', 'image/png');

        // La foto: se marca encima y el trazo queda atado a ella.
        $web->post($this->urlLienzo($consulta, 'foto-' . $imagen->id, 'guardar'), ['dibujo' => $this->png()])
            ->assertOk();
        $deLaFoto = DibujoDelExamen::where('clave', 'foto:' . $imagen->id)->firstOrFail();
        $this->assertSame((int) $imagen->id, (int) $deLaFoto->imagen_id);
        $web->get($this->urlLienzo($consulta, 'foto-' . $imagen->id))->assertOk();

        // Una foto de la colposcopía no se puede marcar desde acá.
        $deLaColposcopia = $this->imagen($medico, $consulta, '@', true);
        $web->get($this->urlLienzo($consulta, 'foto-' . $deLaColposcopia->id))->assertNotFound();
    }

    // ----------------------------------------------------------------------------- La impresión

    public function test_el_informe_imprime_solo_lo_elegido(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $consulta->cuello = 'Cavidad uterina sin lesiones.';
        $consulta->save();
        $imagen = $this->imagen($medico, $consulta, 'H', true);
        $web = $this->conContexto($user, $medico, $gineco)->actingAs($user);
        $hoja = fn () => $web->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'histeroscopia']))
            ->assertOk()->getContent();

        // La foto marcada y el esquema marcado: el informe saca la foto, que es lo que se eligió.
        $web->post($this->urlLienzo($consulta, 'utero', 'guardar'), ['dibujo' => $this->png()])->assertOk();
        $web->post($this->urlLienzo($consulta, 'foto-' . $imagen->id, 'guardar'), ['dibujo' => $this->png()])->assertOk();

        $html = $hoja();
        $this->assertStringContainsString('INFORME DE HISTEROSCOPÍA · IMÁGENES', $html);
        $this->assertSame(1, substr_count($html, '<figure class="informe-imagen informe-imagen-foto">'));
        $this->assertStringNotContainsString('informe-imagen-dibujo', $html);
        $this->assertStringContainsString('Videohisteroscopio', $html);
        $this->assertSame(2, substr_count($html, 'src="data:image/'));

        // Con el esquema elegido sale el esquema (sobre el SVG nuevo) y no la foto.
        $web->post($this->urlElegir($consulta), ['modo' => 'esquema', 'imagen_id' => $imagen->id])->assertOk();

        $html = $hoja();
        $this->assertSame(1, substr_count($html, '<figure class="informe-imagen informe-imagen-dibujo">'));
        $this->assertStringNotContainsString('informe-imagen-foto', $html);
        $this->assertStringContainsString('img/clinica/histeroscopia.svg', $html);
        $this->assertStringContainsString('Cavidad uterina (con los dos ostium y el canal cervical)', $html);
    }

    public function test_sin_texto_no_hay_hoja_aunque_haya_imagen(): void
    {
        [, $medico] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $documentos = app(\App\Clinica\Impresion\DocumentosParaImprimir::class);
        $claves = collect($documentos->disponibles($consulta, [$medico->reg_medico], true))->pluck('clave');

        $this->assertNotContains('histeroscopia', $claves);
    }
}
