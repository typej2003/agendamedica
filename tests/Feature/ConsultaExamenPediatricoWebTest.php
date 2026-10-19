<?php

namespace Tests\Feature;

use App\Actions\Consulta\ArmarResumenDelExamen;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\PercentilesPaciente;
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
 * **El examen físico y el cuestionario de pediatría** (WEB-12.2): las solapas *Examen físico* y *Cuestionario* de la evaluación. Se fija que escriben en las tablas
 * del escritorio de pediatría (`examen_fisico` y `cuestionario`, clave `nrohistoria` + `nroconsulta`) con **los rótulos de la pantalla** distintos de las
 * columnas, que el neurológico va a `consultas.otros`, que sólo se escribe lo que cambió con su rastro, que «Actualizar» arma el resumen del escritorio,
 * que sólo se ofrecen en pediatría (ginecología no cambia) y que son del médico.
 */
class ConsultaExamenPediatricoWebTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-17 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $especialidad = 'pediatria'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $esp = Specialty::firstOrCreate(['slug' => $especialidad], ['name' => ucfirst($especialidad)]);
        $reg = 'exped-test-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'exped-' . substr(uniqid(), -6) . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');
        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dra. de prueba', 'lastname' => 'Examen', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $reg,
        ]);
        $medico->specialties()->attach($esp->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        return [$user, $medico, $esp];
    }

    private function conContexto(Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => ['reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null]]);
    }

    private function consulta(Medico $medico, int $historia = 9701, int $numero = 3): Consulta
    {
        $id = DB::table('pacientes')->insertGetId([
            'nombres' => 'Mateo', 'apellidos' => 'Examen', 'sexo' => 'M', 'fnacimiento' => '2024-01-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
        MedicoPaciente::firstOrCreate(['medico_id' => $medico->id, 'reg_medico' => $medico->reg_medico, 'numhistoria' => (string) $historia], ['paciente_id' => $id]);
        Historia::firstOrCreate(['numhistoria' => (string) $historia, 'reg_medico' => $medico->reg_medico], ['paciente_id' => $id, 'medico_id' => $medico->id]);

        return Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => $historia, 'nroconsulta' => $numero, 'fecha' => '2026-10-17']);
    }

    private function url(Consulta $consulta, string $pestana): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => 'evaluacion', 'pestana' => $pestana]);
    }

    private function fila(string $tabla, Medico $medico, int $historia = 9701, int $numero = 3): ?object
    {
        return DB::table($tabla)->where('reg_medico', $medico->reg_medico)->where('nrohistoria', $historia)->where('nroconsulta', $numero)->first();
    }

    // ------------------------------------------------------------------------------------------ Las solapas

    public function test_pediatria_ofrece_las_tres_solapas_y_ginecologia_ninguna(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($consulta, 'examen'))
            ->assertOk()->assertSee('id="examen-form"', false)->assertSeeInOrder(['Mediciones', 'Examen físico', 'Cuestionario'])
            ->assertDontSee('Imágenes de mamas')->assertDontSee('Colposcopía');

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($consulta, 'cuestionario'))
            ->assertOk()->assertSee('id="cuestionario-form"', false)->assertSee('¿Digestivo?')->assertSee('¿Dentición?');

        [$userG, $medicoG, $gineco] = $this->medicoConAcceso('ginecologia-y-obstetricia');
        $consultaG = $this->consulta($medicoG);
        $this->conContexto($medicoG, $gineco)->actingAs($userG)->get($this->url($consultaG, 'fisico'))
            ->assertOk()->assertSee('id="evaluacion-form"', false)->assertDontSee('Cuestionario')->assertDontSee('>Mediciones<', false);
    }

    public function test_el_examen_muestra_los_rotulos_de_la_pantalla_con_lo_guardado_en_las_columnas_del_escritorio(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);
        // Como lo deja el escritorio: el cuadro «PIEL» está en `torax` y el «NEUROLÓGICO» en `consultas.otros`.
        DB::table('examen_fisico')->insert([
            'reg_medico' => $medico->reg_medico, 'nrohistoria' => 9701, 'nroconsulta' => 3, 'cabeza' => 'ACTIVO Y REACTIVO', 'torax' => 'TERSA',
            'abdomen' => 'NORMOCÉFALO', 'fisico_resumen' => 'RESUMEN VIEJO', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $consulta->update(['otros' => 'SIN FOCO']);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($consulta, 'examen'))
            ->assertOk()
            ->assertSeeInOrder(['Condiciones generales', 'ACTIVO Y REACTIVO', 'Piel', 'TERSA', 'Cabeza', 'NORMOCÉFALO'])
            ->assertSee('SIN FOCO')->assertSee('RESUMEN VIEJO')->assertSee('5 de 10 cuadros con dato');
    }

    // ------------------------------------------------------------------------------------------ Guardar

    public function test_guardar_crea_la_fila_en_examen_fisico_y_el_neurologico_en_consultas_con_rastro(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);
        $url = route('clinica.consulta.examen-pediatrico.guardar', $consulta->id);

        $this->conContexto($medico, $pediatria)->actingAs($user)->post($url, [
            'cabeza' => 'BUEN ESTADO', 'torax' => 'SIN LESIONES', 'otros' => 'SIMÉTRICAS', 'neurologico' => 'NORMAL', 'fisico_resumen' => 'TODO NORMAL.', 'abdomen' => '',
        ])->assertRedirect($this->url($consulta, 'examen'))->assertSessionHas('estado');

        $fila = $this->fila('examen_fisico', $medico);
        $this->assertSame('BUEN ESTADO', $fila->cabeza);
        $this->assertSame('SIN LESIONES', $fila->torax);
        $this->assertSame('SIMÉTRICAS', $fila->otros);
        $this->assertNull($fila->abdomen);
        $this->assertSame('TODO NORMAL.', $fila->fisico_resumen);
        $this->assertSame('NORMAL', $consulta->refresh()->otros);

        $this->assertSame('created', SyncChange::where('table_name', 'examen_fisico')->where('record_id', $fila->id)->first()->operation);
        $rastro = SyncChange::where('table_name', 'consultas')->where('record_id', $consulta->id)->where('column_name', 'otros')->first();
        $this->assertSame('web', $rastro->source);
        $this->assertSame('NORMAL', $rastro->value);
    }

    public function test_guarda_solo_lo_que_cambio_y_el_formulario_vacio_no_crea_filas(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);
        $url = route('clinica.consulta.examen-pediatrico.guardar', $consulta->id);
        $sesion = fn () => $this->conContexto($medico, $pediatria)->actingAs($user);

        $sesion()->post($url, ['cabeza' => '', 'neurologico' => '', 'fisico_resumen' => ' ']);
        $this->assertNull($this->fila('examen_fisico', $medico));

        $sesion()->post($url, ['cabeza' => 'A', 'torax' => 'B']);
        Carbon::setTestNow(Carbon::now()->addMinute());
        $sesion()->post($url, ['cabeza' => 'A', 'torax' => 'C'])->assertSessionHas('estado', 'El examen físico guardado.');

        $fila = $this->fila('examen_fisico', $medico);
        $this->assertSame('C', $fila->torax);
        // La columna que no cambió no deja rastro de edición; la que cambió, uno (la alta deja sólo el `created` de la fila).
        $this->assertSame(0, SyncChange::where('table_name', 'examen_fisico')->where('record_id', $fila->id)->where('column_name', 'cabeza')->count());
        $this->assertSame(1, SyncChange::where('table_name', 'examen_fisico')->where('record_id', $fila->id)->where('column_name', 'torax')->count());
        $this->assertSame(1, DB::table('examen_fisico')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_el_guard_del_sync_no_se_pisa_y_el_cuestionario_guarda_sus_once_columnas(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);
        $sesion = fn () => $this->conContexto($medico, $pediatria)->actingAs($user);

        $sesion()->post(route('clinica.consulta.cuestionario.guardar', $consulta->id), [
            'digestivo' => 'SIN VÓMITOS', 'respiratorio' => 'TOS', 'circulatorio' => 'NO', 'musculoesqueletico' => 'NO', 'nerviosa' => 'NO', 'urinario' => 'NORMAL',
            'reproductor' => 'N/A', 'sentidos' => 'NORMAL', 'denticion' => '6 DIENTES', 'lactancia' => 'EXCLUSIVA', 'otras' => 'NADA',
        ])->assertRedirect($this->url($consulta, 'cuestionario'));

        $fila = $this->fila('cuestionario', $medico);
        $this->assertSame('6 DIENTES', $fila->denticion);
        $this->assertSame('EXCLUSIVA', $fila->lactancia);
        $this->assertSame('NADA', $fila->otras);

        SyncChange::create([
            'reg_medico' => $medico->reg_medico, 'table_name' => 'cuestionario', 'record_id' => (int) $fila->id, 'operation' => 'updated',
            'column_name' => 'respiratorio', 'value' => 'DEL ESCRITORIO', 'occurred_at' => Carbon::now()->addSecond(), 'source' => 'escritorio',
        ]);
        $sesion()->post(route('clinica.consulta.cuestionario.guardar', $consulta->id), ['respiratorio' => 'DE LA WEB'])->assertSessionHas('error');
        $this->assertSame('TOS', $this->fila('cuestionario', $medico)->respiratorio);
    }

    public function test_los_vacios_del_legado_se_leen_como_vacios(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);
        DB::table('cuestionario')->insert(['reg_medico' => $medico->reg_medico, 'nrohistoria' => 9701, 'nroconsulta' => 3, 'digestivo' => 'none', 'respiratorio' => '', 'otras' => 'ALGO',
            'created_at' => now(), 'updated_at' => now()]);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($consulta, 'cuestionario'))
            ->assertOk()->assertSee('1 de 11 cuadros con dato')->assertDontSee('>none<', false);
    }

    // ------------------------------------------------------------------------------------------ «Actualizar»

    public function test_actualizar_arma_el_resumen_del_escritorio_con_las_mediciones(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);
        PercentilesPaciente::create(['reg_medico' => $medico->reg_medico, 'hhistoria' => 9701, 'hconsulta' => 3, 'peso' => 12.5, 'talla' => 88, 'c_cefalica' => 48.5]);

        $respuesta = $this->conContexto($medico, $pediatria)->actingAs($user)->postJson(route('clinica.consulta.examen-pediatrico.resumen', $consulta->id), [
            'cabeza' => 'ACTIVO', 'torax' => 'TERSA', 'abdomen' => 'NORMOCÉFALO', 'pelvis' => 'SIN HALLAZGOS', 'genitales' => 'MV PRESENTE', 'brazos' => 'BLANDO',
            'piernas' => 'NORMALES', 'otros' => 'SIMÉTRICAS', 'neurologico' => 'SIN FOCO',
        ])->assertOk();

        $this->assertSame(
            'PESO: 12.5 Kg.  TALLA: 88 Cm.  CIRCUNFERENCIA CEFÁLICA: 48.5 Cm.  CONDICIONES GENERALES: ACTIVO.  PIEL: TERSA.  CABEZA: NORMOCÉFALO.  '
            . 'ORL: SIN HALLAZGOS.  TÓRAX: MV PRESENTE.  ABDOMEN: BLANDO.  GENITALES: NORMALES.  EXTREMIDADES: SIMÉTRICAS.  NEUROLÓGICO: SIN FOCO.  .',
            $respuesta->json('fisico'),
        );
        // No escribe nada.
        $this->assertNull($this->fila('examen_fisico', $medico));
    }

    public function test_el_resumen_puro_omite_lo_vacio_y_da_vacio_sin_datos(): void
    {
        $this->assertSame('CABEZA: X.  .', ArmarResumenDelExamen::pediatrico(['abdomen' => 'X', 'cabeza' => 'none', 'torax' => '  ']));
        $this->assertSame('', ArmarResumenDelExamen::pediatrico([]));
    }

    // ------------------------------------------------------------------------------------------ Acceso

    public function test_la_secretaria_y_el_consultorio_ajeno_no_escriben(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);

        $secretaria = User::create([
            'name' => 'Secretaria', 'email' => 'exped-secre-' . substr(uniqid(), -6) . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);
        [$otroUser, $otro, $otraEsp] = $this->medicoConAcceso();

        foreach (['clinica.consulta.examen-pediatrico.guardar', 'clinica.consulta.examen-pediatrico.resumen', 'clinica.consulta.cuestionario.guardar'] as $ruta) {
            $this->conContexto($medico, $pediatria)->actingAs($secretaria)->post(route($ruta, $consulta->id), ['cabeza' => 'X'])->assertForbidden();
            $this->conContexto($otro, $otraEsp)->actingAs($otroUser)->post(route($ruta, $consulta->id), ['cabeza' => 'X'])->assertNotFound();
        }

        $this->assertSame(0, DB::table('examen_fisico')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(0, DB::table('cuestionario')->where('reg_medico', $medico->reg_medico)->count());
    }
}
