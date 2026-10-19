<?php

namespace Tests\Feature;

use App\Clinica\Consulta\Vistas\ConsultaShellVista;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **Exámenes/Estudios de la consulta pediátrica** (ítem 6 de su árbol, 2026-10-19): la sección que en el escritorio
 * es un ítem propio (`tab_3` de `tabpage_signos`, `w_consulta_principal.srw:1650-1670`) y en la web era un redirect a
 * la solapa *Resultado de exámenes* del ítem 4 —por eso se veía la misma pantalla que la evaluación física—.
 *
 * Lo que fijan estos casos:
 *
 *  - **Es otra sección**: `?seccion=examenes-estudios` es un 200 con sus tres solapas (*Exámenes*, *Imagenología* y
 *    *Estudios exploratorios*) y **no** redirige a `?seccion=evaluacion`; el ítem 4 conserva las suyas.
 *  - **Dónde vive el texto**: las tres son una columna de `consultas`, **las del escritorio**: `nariz` (Exámenes),
 *    `laringe` (Imagenología) y `oido` (Estudios exploratorios), con el rastro por columna en `sync_changes`
 *    (`source = web`).
 *  - **Las plantillas**: las de su tipo (`EX`, `IG` y `EE`) se ofrecen, y las de otro tipo **no**.
 *  - **Sólo pediatría**: en ginecología la sección no existe (la URL cae en el estado vacío del shell, no abre la
 *    evaluación física).
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class ExamenesEstudiosPediatriaWebTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-19 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // --------------------------------------------------------------------------------------- Ayudas

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $especialidad = 'pediatria'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $esp = Specialty::firstOrCreate(['slug' => $especialidad], ['name' => ucfirst($especialidad)]);
        $reg = 'exa-ped-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'exa-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dra. de prueba', 'lastname' => 'Exámenes', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $reg,
        ]);
        $medico->specialties()->attach($esp->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        return [$user, $medico, $esp];
    }

    private function conContexto(Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null,
        ]]);
    }

    private function consulta(Medico $medico): Consulta
    {
        $id = DB::table('pacientes')->insertGetId([
            'nombres' => 'Mateo', 'apellidos' => 'Exámenes', 'sexo' => 'M', 'fnacimiento' => '2024-01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        MedicoPaciente::create(['medico_id' => $medico->id, 'reg_medico' => $medico->reg_medico, 'numhistoria' => '9601', 'paciente_id' => $id]);
        Historia::create(['numhistoria' => '9601', 'reg_medico' => $medico->reg_medico, 'paciente_id' => $id, 'medico_id' => $medico->id]);

        return Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => 9601, 'nroconsulta' => 1, 'fecha' => '2026-10-19']);
    }

    private function url(Consulta $consulta, string $pestana = ''): string
    {
        return route('clinica.consulta', array_filter([
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_EXAMENES_ESTUDIOS,
            'pestana' => $pestana,
        ], fn ($valor) => $valor !== ''));
    }

    private function plantilla(Medico $medico, string $tipo, string $titulo, string $texto): void
    {
        DB::table('formato_print')->insert([
            'reg_medico' => $medico->reg_medico, 'codigo' => 'P' . substr(uniqid(), -3), 'tipo' => $tipo,
            'titulo' => $titulo, 'descripcion' => $texto, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // -------------------------------------------------------------------------------------- Casos

    public function test_la_seccion_tiene_sus_tres_solapas_y_abre_en_examenes(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($consulta))
            ->assertOk()
            // Sus tres solapas, con el nombre del escritorio.
            ->assertSee('Exámenes')->assertSee('Imagenología')->assertSee('Estudios exploratorios')
            // La primera es la que abre, y su campo es el de la sección (no el de la evaluación física).
            ->assertSee('id="examenes"', false)
            // **No** es la evaluación física: sus solapas no están.
            ->assertDontSee('Mediciones')
            ->assertDontSee('Cuestionario');
    }

    public function test_cada_solapa_guarda_su_texto_en_su_columna_del_escritorio(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);
        $pediatra = $this->conContexto($medico, $pediatria)->actingAs($user);

        // Exámenes → `consultas.nariz`.
        $pediatra->post(route('clinica.consulta.examen.texto.guardar', [$consulta->id, 'examenes']), [
            'examenes' => 'Hemograma pedido: dentro de lo normal.',
        ])->assertRedirect($this->url($consulta, 'examenes'));

        // Imagenología → `consultas.laringe`.
        $pediatra->post(route('clinica.consulta.examen.texto.guardar', [$consulta->id, 'imagenologia']), [
            'imagenologia' => 'R.X. de tórax: sin infiltrados.',
        ])->assertRedirect($this->url($consulta, 'imagenologia'));

        // Estudios exploratorios → `consultas.oido`.
        $pediatra->post(route('clinica.consulta.examen.texto.guardar', [$consulta->id, 'exploratorios']), [
            'exploratorios' => 'Espirometría: patrón obstructivo leve.',
        ])->assertRedirect($this->url($consulta, 'exploratorios'));

        $fila = DB::table('consultas')->where('id', $consulta->id)->first();
        $this->assertSame('Hemograma pedido: dentro de lo normal.', trim((string) $fila->nariz));
        $this->assertSame('R.X. de tórax: sin infiltrados.', trim((string) $fila->laringe));
        $this->assertSame('Espirometría: patrón obstructivo leve.', trim((string) $fila->oido));

        // El rastro por columna, con el origen `web`, en la tabla de la fila.
        foreach (['nariz', 'laringe', 'oido'] as $columna) {
            $this->assertSame(1, SyncChange::where('table_name', 'consultas')->where('record_id', $consulta->id)
                ->where('column_name', $columna)->where('source', 'web')->count(), 'Falta el rastro de ' . $columna . '.');
        }

        // Y se vuelve a leer en su solapa.
        $pediatra->get($this->url($consulta, 'imagenologia'))->assertOk()
            ->assertSee('R.X. de tórax: sin infiltrados.');
    }

    public function test_ofrece_las_plantillas_de_su_tipo_y_no_las_de_otro(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);

        $this->plantilla($medico, 'IG', 'R.X. DE TORAX', 'La plantilla de la imagenología.');
        $this->plantilla($medico, 'EX', 'HEMOGRAMA', 'La plantilla de los exámenes.');
        $this->plantilla($medico, 'EE', 'ESPIROMETRIA', 'La plantilla de los exploratorios.');
        $this->plantilla($medico, 'EV', 'EVOLUCION CUALQUIERA', 'No es de esta sección.');

        $pediatra = $this->conContexto($medico, $pediatria)->actingAs($user);

        $pediatra->get($this->url($consulta, 'imagenologia'))
            ->assertOk()
            ->assertSee('R.X. DE TORAX')
            ->assertDontSee('HEMOGRAMA')
            ->assertDontSee('ESPIROMETRIA')
            ->assertDontSee('EVOLUCION CUALQUIERA');

        $pediatra->get($this->url($consulta, 'examenes'))->assertOk()->assertSee('HEMOGRAMA');
        $pediatra->get($this->url($consulta, 'exploratorios'))->assertOk()->assertSee('ESPIROMETRIA');
    }

    public function test_una_solapa_que_no_existe_abre_la_primera(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);

        $this->conContexto($medico, $pediatria)->actingAs($user)
            ->get($this->url($consulta, 'no-existe'))->assertOk()
            ->assertSee('id="examenes"', false);
    }

    public function test_en_ginecologia_la_seccion_no_existe(): void
    {
        // La clave es del catálogo pediátrico: en ginecología la URL cae en el estado vacío del shell **sin** abrir la
        // evaluación física (que era el defecto: el ítem 6 se veía igual que el ítem 4).
        [$user, $medico, $gineco] = $this->medicoConAcceso('ginecologia-y-obstetricia');
        $consulta = $this->consulta($medico);

        $this->conContexto($medico, $gineco)->actingAs($user)->get($this->url($consulta))
            ->assertOk()
            ->assertDontSee('Imagenología')
            ->assertDontSee('Estudios exploratorios');
    }
}
