<?php

namespace Tests\Feature;

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
 * **El calendario de vacunas del niño** (WEB-7.4.4a): `/clinica/pacientes/{id}/vacunas`, de sólo lectura. Se fija que: sin catálogo del médico lo
 * dice; sin dosis cargadas usa la edad inicial como dosis única; con dosis las usa; muestra lo aplicado, el filtro de pendientes y el estado
 * de cada dosis; no lee el catálogo inicial ni el de otro consultorio; es del médico (la secretaría 403) y de su consultorio (otro 404).
 */
class VacunasDelPacienteWebTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-17 09:00:00'));
        DB::table('vacunas')->whereNull('reg_medico')->delete();
        DB::table('vacunas_dosis')->whereNull('reg_medico')->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $rol = 'Medico'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $pediatria = Specialty::firstOrCreate(['slug' => 'pediatria'], ['name' => 'Pediatría']);
        $reg = 'vac-test-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'vac-' . substr(uniqid(), -6) . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole($rol);
        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dra. de prueba', 'lastname' => 'Vacunas', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $reg,
        ]);
        $medico->specialties()->attach($pediatria->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        return [$user, $medico, $pediatria];
    }

    private function conContexto(Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => ['reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null]]);
    }

    /** Un niño nacido el 2026-06-17 (4 meses hoy). */
    private function nino(Medico $medico, int $historia = 9001, ?string $nacimiento = '2026-06-17', ?string $sexo = 'M'): int
    {
        $id = DB::table('pacientes')->insertGetId([
            'nombres' => 'Mateo', 'apellidos' => 'Vacuna', 'sexo' => $sexo, 'fnacimiento' => $nacimiento, 'created_at' => now(), 'updated_at' => now(),
        ]);
        MedicoPaciente::create(['medico_id' => $medico->id, 'paciente_id' => $id, 'reg_medico' => $medico->reg_medico, 'numhistoria' => (string) $historia]);

        return $id;
    }

    private function vacuna(?string $reg, string $codigo, string $nombre, ?int $edad, string $fija = 'O'): void
    {
        DB::table('vacunas')->insert([
            'reg_medico' => $reg, 'codi_vacuna' => $codigo, 'vacuna' => $nombre, 'edad_inicial_meses' => $edad, 'fija' => $fija, 'sexo' => 'A',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function dosis(string $reg, string $codigo, int $numero, string $rotulo, float $meses): void
    {
        DB::table('vacunas_dosis')->insert([
            'reg_medico' => $reg, 'codi_vacuna' => $codigo, 'dosis' => $numero, 'dosis_descri' => $rotulo, 'meses' => $meses,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function aplicar(string $reg, int $historia, string $codigo, string $fecha, array $extra = []): void
    {
        DB::table('vacuna_paciente')->insert(array_merge([
            'reg_medico' => $reg, 'historia' => $historia, 'codi_vacuna' => $codigo, 'fecha_ejecutada' => $fecha, 'ejecutada' => 'S',
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function url(int $paciente, array $query = []): string
    {
        return route('clinica.pacientes.vacunas', array_merge([$paciente], $query));
    }

    public function test_sin_catalogo_del_medico_lo_dice_y_no_usa_el_inicial(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->vacuna(null, 'BCG', 'BCG inicial', 0);
        $this->vacuna('otro-consultorio', 'POL', 'Polio de otro', 2);
        $id = $this->nino($medico);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($id))
            ->assertOk()
            ->assertSee('id="vacunas-sin-catalogo"', false)
            ->assertSee('todavía no tiene vacunas')
            ->assertDontSee('BCG inicial')->assertDontSee('Polio de otro');
    }

    public function test_sin_dosis_cargadas_usa_la_edad_inicial_como_dosis_unica(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->vacuna($medico->reg_medico, 'BCG', 'BCG', 0);
        $this->vacuna($medico->reg_medico, 'ROTA', 'Rotavirus', 4);
        $this->vacuna($medico->reg_medico, 'SRP', 'Triple viral', 12);
        $this->vacuna($medico->reg_medico, 'TOX', 'Toxoide', null);
        $id = $this->nino($medico);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($id))
            ->assertOk()
            ->assertSee('Dosis única')
            ->assertSeeInOrder(['BCG', '17/06/2026', 'Atrasada'])
            ->assertSeeInOrder(['Rotavirus', '17/10/2026', 'Próxima'])
            ->assertSeeInOrder(['Triple viral', '17/06/2027', 'Pendiente'])
            ->assertSee('Sin edad definida');
    }

    public function test_con_dosis_y_lo_aplicado_muestra_cada_estado_y_el_filtro_de_pendientes(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $reg = $medico->reg_medico;
        $this->vacuna($reg, 'POLI', 'Polio', 2, 'R');
        $this->dosis($reg, 'POLI', 1, '1era. Dosis', 2);
        $this->dosis($reg, 'POLI', 2, '2da. Dosis', 4);
        $this->dosis($reg, 'POLI', 3, 'Refuerzo', 18);
        $this->aplicar($reg, 9001, 'POLI', '2026-08-20', ['dosis_numero' => 1, 'lote' => 'L-77']);
        $id = $this->nino($medico);

        $sesion = $this->conContexto($medico, $pediatria)->actingAs($user);
        $sesion->get($this->url($id))
            ->assertOk()
            ->assertSee('Recomendada')
            ->assertSeeInOrder(['1era. Dosis', '17/08/2026', '20/08/2026', 'Lote L-77', 'Aplicada'])
            ->assertSeeInOrder(['2da. Dosis', '17/10/2026', 'Próxima'])
            ->assertSeeInOrder(['Refuerzo', '17/12/2027', 'Pendiente'])
            ->assertSee('1 aplicada')->assertSee('1 próxima')->assertSee('1 pendiente');

        $sesion->get($this->url($id, ['ver' => 'pendientes']))
            ->assertOk()->assertSee('2da. Dosis')->assertDontSee('Refuerzo')->assertDontSee('1era. Dosis');
    }

    public function test_pendientes_sin_nada_pendiente_dice_nada_pendiente(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->vacuna($medico->reg_medico, 'SRP', 'Triple viral', 12);
        $id = $this->nino($medico);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($id, ['ver' => 'pendientes']))
            ->assertOk()->assertSee('Nada pendiente');
    }

    public function test_una_aplicada_de_una_vacuna_que_ya_no_esta_se_muestra_aparte(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->vacuna($medico->reg_medico, 'BCG', 'BCG', 0);
        $this->aplicar($medico->reg_medico, 9001, 'OLD', '2026-07-01', ['descripcion' => 'VACUNA VIEJA', 'dosis' => '1']);
        $id = $this->nino($medico);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($id))
            ->assertOk()->assertSee('id="vacunas-otras"', false)->assertSee('VACUNA VIEJA');
    }

    public function test_sin_fecha_de_nacimiento_avisa_pero_lista_las_dosis(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->vacuna($medico->reg_medico, 'BCG', 'BCG', 0);
        $id = $this->nino($medico, 9001, null);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($id))
            ->assertOk()->assertSee('id="vacunas-sin-nacimiento"', false)->assertSee('BCG')->assertSee('Sin fecha');
    }

    public function test_las_vacunas_de_otro_sexo_no_se_ofrecen(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        DB::table('vacunas')->insert([
            'reg_medico' => $medico->reg_medico, 'codi_vacuna' => 'VPH', 'vacuna' => 'VPH niñas', 'edad_inicial_meses' => 108, 'fija' => 'O', 'sexo' => 'F',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->vacuna($medico->reg_medico, 'BCG', 'BCG', 0);
        $id = $this->nino($medico, 9001, '2026-06-17', 'M');

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($id))
            ->assertOk()->assertSee('BCG')->assertDontSee('VPH niñas');
    }

    public function test_la_secretaria_no_entra_y_el_paciente_ajeno_da_404_y_la_ficha_tiene_el_enlace(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $id = $this->nino($medico);

        $secretaria = User::create([
            'name' => 'Secretaria', 'email' => 'vac-secre-' . substr(uniqid(), -6) . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);
        $this->conContexto($medico, $pediatria)->actingAs($secretaria)->get($this->url($id))->assertForbidden();

        [$otroUser, $otro, $otraEsp] = $this->medicoConAcceso();
        $this->conContexto($otro, $otraEsp)->actingAs($otroUser)->get($this->url($id))->assertNotFound();

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.pacientes.ver', $id))
            ->assertOk()->assertSee($this->url($id), false);
    }
}
