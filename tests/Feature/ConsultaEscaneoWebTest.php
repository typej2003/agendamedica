<?php

namespace Tests\Feature;

use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Escaneo\LecturaFallida;
use App\Clinica\Escaneo\LectorDeHojas;
use App\Models\Consulta;
use App\Models\Historia;
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
use Tests\TestCase;

/**
 * **Escanear la hoja de resultados** (WEB-13.15): la solapa *Resultado de exámenes* ofrece leer la foto o el PDF de
 * la hoja del laboratorio y el texto vuelve al campo, editable.
 *
 * Lo que estos casos fijan:
 *
 *  - El botón sale **sólo** en las solapas escaneables y sólo si el servidor tiene con qué leer.
 *  - La lectura devuelve el texto y **no escribe nada** (ni `consultas.oido` ni `sync_changes`).
 *  - El tipo se decide por el **contenido**: un `.jpg` que no es imagen es un 422 y no llega al lector.
 *  - Los fallos del lector salen con su estado (422 no es resultado, 502 proveedor, 503 sin configurar).
 *  - Una solapa no escaneable es 404, la consulta de otro consultorio también, y la secretaría recibe 403.
 *
 * El lector real (`LectorDeHojasConClaude`) se prueba aparte, con el HTTP simulado: acá va uno falso.
 */
class ConsultaEscaneoWebTest extends TestCase
{
    use DatabaseTransactions;

    /** Un PNG de 1×1 válido: el contenido es real, así que el tipo se detecta. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @var \ArrayObject<int,array{mime:string, bytes:int}> lo que recibió el lector falso */
    private \ArrayObject $lecturas;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-25 09:00:00'));
        $this->lecturas = new \ArrayObject();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // --------------------------------------------------------------------------------------- Ayudas

    /** Reemplaza el lector: devuelve `$texto` o lanza `$falla`. */
    private function lector(string $texto = '', ?LecturaFallida $falla = null, bool $disponible = true): void
    {
        $this->app->instance(LectorDeHojas::class, new class($texto, $falla, $disponible, $this->lecturas) implements LectorDeHojas {
            public function __construct(private string $texto, private ?LecturaFallida $falla, private bool $disponible, private \ArrayObject $lecturas)
            {
            }

            public function disponible(): bool
            {
                return $this->disponible;
            }

            public function leer(string $contenido, string $mime): string
            {
                $this->lecturas[] = ['mime' => $mime, 'bytes' => strlen($contenido)];

                if ($this->falla !== null) {
                    throw $this->falla;
                }

                return $this->texto;
            }
        });
    }

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $slug = 'ginecologia-y-obstetricia'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $especialidad = Specialty::where('slug', $slug)->firstOrFail();
        $regMedico = 'escaneo-t' . substr(uniqid(), -6);

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'escaneo-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Escaneo',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($especialidad->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $especialidad];
    }

    private function conContexto(Medico $medico, Specialty $especialidad): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico'   => $medico->reg_medico,
            'specialty_id' => $especialidad->id,
            'office_id'    => null,
        ]]);
    }

    private function consulta(Medico $medico, int $numhistoria = 1001): Consulta
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

        return Consulta::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => $numhistoria,
            'nroconsulta' => 1,
            'fecha'       => now()->toDateString(),
        ]);
    }

    private function urlSolapa(Consulta $consulta, string $pestana): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_EVALUACION, 'pestana' => $pestana]);
    }

    private function urlEscanear(Consulta $consulta, string $solapa = 'resultados'): string
    {
        return route('clinica.consulta.examen.escanear', [$consulta->id, $solapa]);
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('hoja.png', base64_decode(self::PNG));
    }

    // ------------------------------------------------------------------------------------ El botón

    public function test_resultado_de_examenes_ofrece_escanear_y_la_colposcopia_no(): void
    {
        $this->lector('x');
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);

        $resultados = $this->conContexto($medico, $gineco)->actingAs($user)
            ->get($this->urlSolapa($consulta, 'resultados'))->assertOk()->getContent();
        $this->assertStringContainsString('Escanear hoja', $resultados);
        $this->assertStringContainsString('data-escanear-hoja', $resultados);
        $this->assertStringContainsString($this->urlEscanear($consulta), $resultados);
        $this->assertStringContainsString('js/clinica/escanear-hoja.js', $resultados);

        $colposcopia = $this->conContexto($medico, $gineco)->actingAs($user)
            ->get($this->urlSolapa($consulta, 'colposcopia'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-escanear-hoja', $colposcopia);
    }

    public function test_sin_lector_disponible_no_se_ofrece_el_boton(): void
    {
        $this->lector('x', null, false);
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);

        $html = $this->conContexto($medico, $gineco)->actingAs($user)
            ->get($this->urlSolapa($consulta, 'resultados'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-escanear-hoja', $html);
    }

    public function test_examenes_de_pediatria_tambien_ofrece_escanear(): void
    {
        $this->lector('x');
        [$user, $medico, $pediatria] = $this->medicoConAcceso('pediatria');
        $consulta = $this->consulta($medico);

        $html = $this->conContexto($medico, $pediatria)->actingAs($user)
            ->get(route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_EXAMENES_ESTUDIOS, 'pestana' => 'examenes']))
            ->assertOk()->getContent();

        $this->assertStringContainsString($this->urlEscanear($consulta, 'examenes'), $html);
    }

    // ------------------------------------------------------------------------------------ La lectura

    public function test_la_lectura_devuelve_el_texto_y_no_guarda_nada(): void
    {
        $this->lector("HEMOGLOBINA: 12,8 g/dL (ref.: 12-16)\nGLUCOSA: 92 mg/dL (ref.: 70-110)");
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);
        $rastros = SyncChange::count();

        $this->conContexto($medico, $gineco)->actingAs($user)
            ->post($this->urlEscanear($consulta), ['hoja' => $this->png()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('texto', "HEMOGLOBINA: 12,8 g/dL (ref.: 12-16)\nGLUCOSA: 92 mg/dL (ref.: 70-110)")
            ->assertJsonStructure(['texto', 'aviso']);

        $this->assertSame([['mime' => 'image/png', 'bytes' => strlen(base64_decode(self::PNG))]], $this->lecturas->getArrayCopy());
        $this->assertNull($consulta->fresh()->oido, 'La lectura no escribe la solapa: eso es del guardado.');
        $this->assertSame($rastros, SyncChange::count(), 'La lectura no deja rastro de sync.');
    }

    public function test_un_pdf_se_lee_como_pdf(): void
    {
        $this->lector('Laboratorio: X — Fecha: 20/10/2026');
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);

        $pdf = UploadedFile::fake()->createWithContent('hoja.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");

        $this->conContexto($medico, $gineco)->actingAs($user)
            ->post($this->urlEscanear($consulta), ['hoja' => $pdf], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertSame('application/pdf', $this->lecturas[0]['mime']);
    }

    public function test_un_archivo_que_no_es_imagen_ni_pdf_es_422_y_no_llega_al_lector(): void
    {
        $this->lector('x');
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);

        $falso = UploadedFile::fake()->createWithContent('hoja.jpg', 'esto no es una imagen, aunque se llame .jpg');

        $this->conContexto($medico, $gineco)->actingAs($user)
            ->post($this->urlEscanear($consulta), ['hoja' => $falso], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('hoja');

        $this->conContexto($medico, $gineco)->actingAs($user)
            ->post($this->urlEscanear($consulta), [], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('hoja');

        $this->assertCount(0, $this->lecturas);
    }

    public function test_los_fallos_del_lector_salen_con_su_estado(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);

        foreach ([
            [LecturaFallida::noEsResultado(), 422, 'no_es_resultado'],
            [LecturaFallida::delProveedor(), 502, 'proveedor'],
            [LecturaFallida::noDisponible(), 503, 'no_disponible'],
        ] as [$falla, $estado, $motivo]) {
            $this->lector('', $falla);

            $this->conContexto($medico, $gineco)->actingAs($user)
                ->post($this->urlEscanear($consulta), ['hoja' => $this->png()], ['Accept' => 'application/json'])
                ->assertStatus($estado)->assertJsonPath('motivo', $motivo);
        }
    }

    // ------------------------------------------------------------------------------------ Acceso

    public function test_una_solapa_no_escaneable_es_404(): void
    {
        $this->lector('x');
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);

        $this->conContexto($medico, $gineco)->actingAs($user)
            ->post('/clinica/consulta/' . $consulta->id . '/examen/colposcopia/escanear', ['hoja' => $this->png()], ['Accept' => 'application/json'])
            ->assertNotFound();
    }

    public function test_la_consulta_de_otro_consultorio_es_404_y_la_secretaria_403(): void
    {
        $this->lector('x');
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $ajena = $this->consulta($otro, 2002);

        $this->conContexto($medico, $gineco)->actingAs($user)
            ->post($this->urlEscanear($ajena), ['hoja' => $this->png()], ['Accept' => 'application/json'])
            ->assertNotFound();

        $secretaria = User::create([
            'name'                 => 'Secretaria',
            'email'                => 'escaneo-s' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);
        $propia = $this->consulta($medico);

        $this->conContexto($medico, $gineco)->actingAs($secretaria)
            ->post($this->urlEscanear($propia), ['hoja' => $this->png()], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->assertCount(0, $this->lecturas);
    }
}
