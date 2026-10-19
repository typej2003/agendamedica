<?php

namespace Tests\Feature;

use App\Actions\Consulta\ArmarResumenDelExamen;
use App\Clinica\Consulta\ResumenMedicoDeLaConsulta;
use App\Models\Consulta;
use App\Models\Evolucion;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **La historia pediátrica** (WEB-12.5): la hoja imprimible del niño (familia, antecedentes por categoría, mediciones y vacunas) y los bloques pediátricos del
 * *Resumen médico* de cada consulta (mediciones, examen físico de `examen_fisico` y cuestionario).
 */
class HistoriaPediatricaWebTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-17 09:00:00'));
        DB::table('vacunas')->whereNull('reg_medico')->delete();
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
        $reg = 'hist-ped-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'hist-ped-' . substr(uniqid(), -6) . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');
        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dra. de prueba', 'lastname' => 'Historia', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $reg,
        ]);
        $medico->specialties()->attach($esp->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);
        Evolucion::create(['reg_medico' => $reg, 'clave' => 1, 'especialidad' => 'PEDIATRIA Y PUERICULTURA', 'rif' => 'V-1234']);

        return [$user, $medico, $esp];
    }

    private function conContexto(Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => ['reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null]]);
    }

    /** @return array{0:int, 1:Consulta} */
    private function nino(Medico $medico, int $historia = 9401): array
    {
        $id = DB::table('pacientes')->insertGetId([
            'nombres' => 'Mateo', 'apellidos' => 'Historia', 'sexo' => 'M', 'fnacimiento' => '2024-10-17', 'created_at' => now(), 'updated_at' => now(),
        ]);
        MedicoPaciente::create(['medico_id' => $medico->id, 'reg_medico' => $medico->reg_medico, 'numhistoria' => (string) $historia, 'paciente_id' => $id]);
        Historia::create(['numhistoria' => (string) $historia, 'reg_medico' => $medico->reg_medico, 'paciente_id' => $id, 'medico_id' => $medico->id]);

        return [$id, Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => $historia, 'nroconsulta' => 1, 'fecha' => '2026-10-17'])];
    }

    public function test_la_hoja_reune_datos_familia_antecedentes_mediciones_y_vacunas(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        [$id, $consulta] = $this->nino($medico);
        $reg = $medico->reg_medico;

        DB::table('paciente_chamo')->insert(['reg_medico' => $reg, 'familia' => 5, 'historia' => 9401, 'nombre' => 'HISTORIA MATEO', 'alergia' => 'PENICILINA', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('familia_bebe')->insert(['reg_medico' => $reg, 'familia' => 5, 'nom_madre' => 'ROSA GOMEZ', 'ocupa_madre' => 'DOCENTE', 'nom_padre' => 'LUIS PEREZ',
            'celu_madre' => '04141234567', 'ced_representante' => '12345678', 'direccion' => 'AV. PRINCIPAL', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('antecedentes')->insert(['reg_medico' => $reg, 'codantecedente' => '0001', 'descripcion' => 'ASMA', 'codtipo' => '01', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('antece_paciente')->insert(['reg_medico' => $reg, 'numhistoria' => 9401, 'codeantecedente' => '0001', 'descripcion' => 'ASMA', 'detalles' => 'DESDE LOS 6 MESES',
            'tipo' => '01', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('antecedentes')->insert(['reg_medico' => $reg, 'codantecedente' => '0002', 'descripcion' => 'PARTO A TÉRMINO', 'codtipo' => '05', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('antece_paciente')->insert(['reg_medico' => $reg, 'numhistoria' => 9401, 'codeantecedente' => '0002', 'descripcion' => 'PARTO A TÉRMINO', 'tipo' => '05', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('percentiles_paciente')->insert(['reg_medico' => $reg, 'hhistoria' => 9401, 'hconsulta' => 1, 'peso' => 12.5, 'talla' => 88, 'fecha' => '2026-10-17', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('vacunas')->insert(['reg_medico' => $reg, 'codi_vacuna' => 'BCG', 'vacuna' => 'BCG', 'edad_inicial_meses' => 0, 'fija' => 'O', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('vacuna_paciente')->insert(['reg_medico' => $reg, 'historia' => 9401, 'codi_vacuna' => 'BCG', 'dosis' => 'Única', 'ejecutada' => 'S', 'fecha_ejecutada' => '2024-10-18',
            'lote' => 'L-9', 'created_at' => now(), 'updated_at' => now()]);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.pacientes.historia-pediatrica', $id))
            ->assertOk()
            ->assertSee('PEDIATRIA Y PUERICULTURA')->assertSee('HISTORIA PEDIÁTRICA')
            ->assertSeeInOrder(['Paciente:', 'Historia, Mateo', 'Nacimiento:', '17/10/2024', 'Edad:', '2 años', 'Alergias:', 'PENICILINA'])
            ->assertSee('id="historia-familia"', false)->assertSeeInOrder(['Madre:', 'ROSA GOMEZ · DOCENTE', 'Padre:', 'LUIS PEREZ', 'Cédula del representante:', '12345678'])
            ->assertSee('id="historia-antecedentes"', false)->assertSeeInOrder(['PATOLÓGICOS', 'ASMA', 'DESDE LOS 6 MESES', 'PERINATALES', 'PARTO A TÉRMINO'])
            ->assertSee('id="historia-mediciones"', false)->assertSeeInOrder(['17/10/2026', '12,5', '88'])
            ->assertSee('id="historia-vacunas"', false)->assertSeeInOrder(['18/10/2024', 'BCG', 'Única', 'L-9'])
            ->assertSee('data-imprimir', false);
    }

    public function test_un_nino_sin_nada_cargado_dice_que_no_hay_en_cada_bloque(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        [$id] = $this->nino($medico);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.pacientes.historia-pediatrica', $id))
            ->assertOk()->assertSee('Sin antecedentes registrados')->assertSee('Todavía no hay mediciones')->assertSee('Todavía no se le registró ninguna dosis')
            ->assertDontSee('id="historia-familia"', false);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.pacientes.historia-pediatrica', [$id, 'incrustado' => 1]))
            ->assertOk()->assertDontSee('barra-impresion-titulo', false);
    }

    public function test_la_ficha_ofrece_el_enlace_solo_en_pediatria_y_al_medico_y_el_ajeno_da_404(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        [$id] = $this->nino($medico);
        $url = route('clinica.pacientes.historia-pediatrica', $id);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.pacientes.ver', $id))->assertOk()->assertSee($url, false);

        [$userG, $medicoG, $gineco] = $this->medicoConAcceso('ginecologia-y-obstetricia');
        [$idG] = $this->nino($medicoG, 9402);
        $this->conContexto($medicoG, $gineco)->actingAs($userG)->get(route('clinica.pacientes.ver', $idG))->assertOk()->assertDontSee('Historia pediátrica');

        $secretaria = User::create([
            'name' => 'Secretaria', 'email' => 'hist-ped-secr' . substr(uniqid(), -6) . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);
        $this->conContexto($medico, $pediatria)->actingAs($secretaria)->get($url)->assertForbidden();

        [$otroUser, $otro, $otraEsp] = $this->medicoConAcceso();
        $this->conContexto($otro, $otraEsp)->actingAs($otroUser)->get($url)->assertNotFound();
    }

    // ------------------------------------------------------------------------------------------ Resumen médico

    public function test_el_resumen_medico_de_pediatria_lleva_mediciones_examen_y_cuestionario(): void
    {
        [, $medico] = $this->medicoConAcceso();
        [, $consulta] = $this->nino($medico);
        $reg = $medico->reg_medico;
        DB::table('percentiles_paciente')->insert(['reg_medico' => $reg, 'hhistoria' => 9401, 'hconsulta' => 1, 'peso' => 12.5, 'talla' => 88, 'p_a_sistolica' => 90, 'p_a_diastolica' => 60,
            'imc' => 16.14, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('examen_fisico')->insert(['reg_medico' => $reg, 'nrohistoria' => 9401, 'nroconsulta' => 1, 'fisico_resumen' => 'ACTIVO Y REACTIVO.', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cuestionario')->insert(['reg_medico' => $reg, 'nrohistoria' => 9401, 'nroconsulta' => 1, 'digestivo' => 'SIN VÓMITOS', 'respiratorio' => 'none', 'lactancia' => 'EXCLUSIVA',
            'created_at' => now(), 'updated_at' => now()]);

        $resumen = app(ResumenMedicoDeLaConsulta::class)->de($consulta);

        $this->assertTrue($resumen->esDocumento());
        $this->assertSame(['MEDICIONES:', 'EXAMEN FÍSICO:', 'CUESTIONARIO:'], $resumen->rotulos());
        $textos = collect($resumen->parrafos())->pluck('texto')->implode(' | ');
        $this->assertStringContainsString('PESO: 12.5 Kg', $textos);
        $this->assertStringContainsString('PRESIÓN ARTERIAL: 90/60 mmHg', $textos);
        $this->assertStringContainsString('ACTIVO Y REACTIVO', $textos);
        $this->assertStringContainsString('Digestivo: SIN VÓMITOS', $textos);
        $this->assertStringContainsString('Lactancia: EXCLUSIVA', $textos);
        $this->assertStringNotContainsString('Respiratorio', $textos);

        // Y la consulta cuenta como una que tiene resumen (lo usa la historia del paciente).
        $this->assertContains(1, app(ResumenMedicoDeLaConsulta::class)->conResumen($reg, 9401));
    }

    public function test_el_resumen_de_ginecologia_no_cambia_y_el_de_mediciones_sin_datos_no_sale(): void
    {
        [, $medico] = $this->medicoConAcceso('ginecologia-y-obstetricia');
        [, $consulta] = $this->nino($medico, 9403);
        DB::table('examen_fisico_nuevo')->insert(['reg_medico' => $medico->reg_medico, 'historia' => 9403, 'consulta' => 1, 'total_f' => 'EXAMEN NORMAL.', 'created_at' => now(), 'updated_at' => now()]);

        $resumen = app(ResumenMedicoDeLaConsulta::class)->de($consulta);

        $this->assertSame(['EXAMEN FÍSICO:'], $resumen->rotulos());
        $this->assertSame('', ArmarResumenDelExamen::mediciones([]));
        $this->assertSame('PESO: 12.5 Kg.  PULSO: 100 lpm.  .', ArmarResumenDelExamen::mediciones(['peso' => 12.5, 'pulso' => 100, 'talla' => null]));
    }
}
