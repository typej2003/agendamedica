<?php

namespace Tests\Feature;

use App\Models\Consulta;
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
 * **El aviso de vacunas pendientes al atender** (WEB-7.4.4d): un banner discreto en la consulta del niño (sólo pediatría) con las dosis atrasadas y
 * próximas. No aparece sin catálogo, sin pendientes, sin nacimiento ni en ginecología, y no es la alerta roja de antecedentes.
 */
class AvisoDeVacunasWebTest extends TestCase
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
        $reg = 'aviso-vac-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'aviso-' . substr(uniqid(), -6) . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');
        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dra. de prueba', 'lastname' => 'Aviso', 'email' => $user->email,
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

    /** Un niño de 4 meses con su consulta de hoy. */
    private function consulta(Medico $medico, ?string $nacimiento = '2026-06-17'): Consulta
    {
        $id = DB::table('pacientes')->insertGetId([
            'nombres' => 'Mateo', 'apellidos' => 'Aviso', 'sexo' => 'M', 'fnacimiento' => $nacimiento, 'created_at' => now(), 'updated_at' => now(),
        ]);
        MedicoPaciente::create(['medico_id' => $medico->id, 'paciente_id' => $id, 'reg_medico' => $medico->reg_medico, 'numhistoria' => '9901']);
        Historia::create(['numhistoria' => '9901', 'reg_medico' => $medico->reg_medico, 'paciente_id' => $id, 'medico_id' => $medico->id]);

        return Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => 9901, 'nroconsulta' => 1, 'fecha' => '2026-10-17']);
    }

    private function vacuna(Medico $medico, string $codigo, string $nombre, int $edad): void
    {
        DB::table('vacunas')->insert(['reg_medico' => $medico->reg_medico, 'codi_vacuna' => $codigo, 'vacuna' => $nombre, 'edad_inicial_meses' => $edad,
            'fija' => 'O', 'sexo' => 'A', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_avisa_las_atrasadas_y_las_proximas_con_enlace_al_calendario(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);
        $this->vacuna($medico, 'BCG', 'BCG', 0);      // atrasada
        $this->vacuna($medico, 'POLI', 'Polio', 2);   // atrasada
        $this->vacuna($medico, 'ROTA', 'Rotavirus', 4); // próxima (hoy)
        $this->vacuna($medico, 'SRP', 'Triple viral', 12); // pendiente: no cuenta

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.consulta', $consulta->id))
            ->assertOk()
            ->assertSee('id="consulta-aviso-vacunas"', false)
            ->assertSee('Vacunas: 2 dosis atrasadas y 1 próxima.')
            ->assertSee('Ver el calendario')
            ->assertDontSee('id="consulta-alertas"', false);
    }

    public function test_se_ve_en_todas_las_secciones_de_la_consulta(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);
        $this->vacuna($medico, 'BCG', 'BCG', 0);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.consulta', [$consulta->id, 'seccion' => 'evolucion']))
            ->assertOk()->assertSee('Vacunas: 1 dosis atrasada.');
    }

    public function test_no_aparece_sin_catalogo_sin_pendientes_ni_sin_nacimiento(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);

        // Sin catálogo del médico.
        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.consulta', $consulta->id))
            ->assertOk()->assertDontSee('consulta-aviso-vacunas', false);

        // Sólo una dosis pendiente a futuro.
        $this->vacuna($medico, 'SRP', 'Triple viral', 12);
        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.consulta', $consulta->id))
            ->assertOk()->assertDontSee('consulta-aviso-vacunas', false);

        // Con pendientes pero ya aplicadas.
        $this->vacuna($medico, 'BCG', 'BCG', 0);
        DB::table('vacuna_paciente')->insert(['reg_medico' => $medico->reg_medico, 'historia' => 9901, 'codi_vacuna' => 'BCG', 'dosis_numero' => 1,
            'ejecutada' => 'S', 'fecha_ejecutada' => '2026-06-18', 'created_at' => now(), 'updated_at' => now()]);
        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.consulta', $consulta->id))
            ->assertOk()->assertDontSee('consulta-aviso-vacunas', false);

        // Sin fecha de nacimiento no se puede calcular.
        DB::table('pacientes')->update(['fnacimiento' => null]);
        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.consulta', $consulta->id))
            ->assertOk()->assertDontSee('consulta-aviso-vacunas', false);
    }

    public function test_en_ginecologia_nunca_aparece(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso('ginecologia-y-obstetricia');
        $consulta = $this->consulta($medico);
        $this->vacuna($medico, 'BCG', 'BCG', 0);

        $this->conContexto($medico, $gineco)->actingAs($user)->get(route('clinica.consulta', $consulta->id))
            ->assertOk()->assertDontSee('consulta-aviso-vacunas', false);
    }
}
