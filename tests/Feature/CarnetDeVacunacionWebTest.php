<?php

namespace Tests\Feature;

use App\Models\Evolucion;
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
 * **El carnet de vacunación imprimible** (WEB-7.4.4c): la hoja con el membrete del médico, las dosis aplicadas y las que faltan, y su vista previa.
 */
class CarnetDeVacunacionWebTest extends TestCase
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
    private function medicoConAcceso(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $pediatria = Specialty::firstOrCreate(['slug' => 'pediatria'], ['name' => 'Pediatría']);
        $reg = 'carnet-test-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'carnet-' . substr(uniqid(), -6) . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');
        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dra. de prueba', 'lastname' => 'Carnet', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $reg,
        ]);
        $medico->specialties()->attach($pediatria->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);
        Evolucion::create(['reg_medico' => $reg, 'clave' => 1, 'especialidad' => 'PEDIATRIA Y PUERICULTURA', 'rif' => 'V-1234', 'linea_1' => 'AV. PRINCIPAL, CONSULTORIO 4']);

        return [$user, $medico, $pediatria];
    }

    private function conContexto(Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => ['reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null]]);
    }

    private function nino(Medico $medico, int $historia = 9801): int
    {
        $id = DB::table('pacientes')->insertGetId([
            'nombres' => 'Mateo', 'apellidos' => 'Carnet', 'sexo' => 'M', 'fnacimiento' => '2026-06-17', 'created_at' => now(), 'updated_at' => now(),
        ]);
        MedicoPaciente::create(['medico_id' => $medico->id, 'paciente_id' => $id, 'reg_medico' => $medico->reg_medico, 'numhistoria' => (string) $historia]);

        return $id;
    }

    private function catalogo(Medico $medico): void
    {
        foreach ([['BCG', 'BCG', 0], ['POLI', 'Polio', 2], ['SRP', 'Triple viral', 12]] as [$c, $n, $e]) {
            DB::table('vacunas')->insert(['reg_medico' => $medico->reg_medico, 'codi_vacuna' => $c, 'vacuna' => $n, 'edad_inicial_meses' => $e, 'fija' => 'O', 'sexo' => 'A',
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_la_hoja_trae_el_membrete_los_datos_las_aplicadas_y_las_que_faltan(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->catalogo($medico);
        $id = $this->nino($medico);
        DB::table('vacuna_paciente')->insert([
            'reg_medico' => $medico->reg_medico, 'historia' => 9801, 'codi_vacuna' => 'BCG', 'dosis' => 'Única', 'dosis_numero' => 1, 'ejecutada' => 'S',
            'fecha_ejecutada' => '2026-06-18', 'lote' => 'L-5', 'via' => 'Intradérmica', 'zona' => 'Brazo derecho', 'edad_aplicada' => 0.03,
            'fecha_proxima_dosis' => '2026-12-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('familia_bebe')->insert(['reg_medico' => $medico->reg_medico, 'familia' => 1, 'nom_madre' => 'ROSA PEREZ', 'nom_padre' => 'LUIS GOMEZ', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('paciente_chamo')->insert(['reg_medico' => $medico->reg_medico, 'familia' => 1, 'historia' => 9801, 'nombre' => 'MATEO', 'created_at' => now(), 'updated_at' => now()]);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.pacientes.vacunas.carnet', $id))
            ->assertOk()
            ->assertSee('PEDIATRIA Y PUERICULTURA')->assertSee('AV. PRINCIPAL, CONSULTORIO 4')
            ->assertSee('VACUNAS APLICADAS')
            ->assertSeeInOrder(['Paciente:', 'Carnet, Mateo', 'Historia:', 'H. 9801', 'Nacimiento:', '17/06/2026', 'Edad:', '4 meses', 'Padres:', 'ROSA PEREZ y LUIS GOMEZ'])
            ->assertSee('id="carnet-aplicadas"', false)
            ->assertSeeInOrder(['18/06/2026', 'BCG', 'Única', 'L-5', 'Intradérmica · Brazo derecho', '01/12/2026'])
            ->assertSee('DOSIS POR APLICAR')
            ->assertSeeInOrder(['Polio', 'Dosis única', '17/08/2026', 'Atrasada'])
            ->assertSeeInOrder(['Triple viral', '17/06/2027', 'Pendiente'])
            ->assertSee('data-imprimir', false);
    }

    public function test_sin_dosis_aplicadas_lo_dice_y_la_vista_previa_va_sin_barra(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->catalogo($medico);
        $id = $this->nino($medico);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.pacientes.vacunas.carnet', $id))
            ->assertOk()->assertSee('Todavía no se le registró ninguna dosis')->assertSee('barra-impresion-titulo', false);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.pacientes.vacunas.carnet', [$id, 'incrustado' => 1]))
            ->assertOk()->assertDontSee('barra-impresion-titulo', false);
    }

    public function test_el_calendario_ofrece_imprimir_y_visualizar_el_carnet(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->catalogo($medico);
        $id = $this->nino($medico);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.pacientes.vacunas', $id))
            ->assertOk()->assertSee('Imprimir el carnet')->assertSee('data-visualizar="' . route('clinica.pacientes.vacunas.carnet', $id) . '"', false)
            ->assertSee('id="dialogo-visualizar"', false);
    }

    public function test_la_secretaria_no_lo_imprime_y_el_paciente_ajeno_da_404(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $id = $this->nino($medico);

        $secretaria = User::create([
            'name' => 'Secretaria', 'email' => 'carnet-secre-' . substr(uniqid(), -6) . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);
        $this->conContexto($medico, $pediatria)->actingAs($secretaria)->get(route('clinica.pacientes.vacunas.carnet', $id))->assertForbidden();

        [$otroUser, $otro, $otraEsp] = $this->medicoConAcceso();
        $this->conContexto($otro, $otraEsp)->actingAs($otroUser)->get(route('clinica.pacientes.vacunas.carnet', $id))->assertNotFound();
    }
}
