<?php

namespace Tests\Feature;

use App\Actions\Pacientes\SembrarTiposDeAntecedente;
use App\Clinica\Consulta\TiposDeAntecedente;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **Los antecedentes de pediatría** (WEB-12.3): las siete categorías del escritorio (Patológicos, C.A., Madre, Padre, Perinatales, Alimentación y D.P.)
 * sembradas en el catálogo del médico, como chips que filtran la lista, con el nombre que **cada médico** le da a cada tipo. Desde el 2026-10-28
 * ginecología tiene sus propios nombres (`TiposDeAntecedentePorEspecialidadTest`); el último caso de este archivo cuida que no se crucen.
 */
class AntecedentesPediatricosWebTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $especialidad = 'pediatria'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $esp = Specialty::firstOrCreate(['slug' => $especialidad], ['name' => ucfirst($especialidad)]);
        $reg = 'antec-ped-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'antec-ped-' . substr(uniqid(), -6) . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');
        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dra. de prueba', 'lastname' => 'Antecedentes', 'email' => $user->email,
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

    private function consulta(Medico $medico, int $historia = 9601): Consulta
    {
        $id = DB::table('pacientes')->insertGetId([
            'nombres' => 'Mateo', 'apellidos' => 'Antecedentes', 'sexo' => 'M', 'fnacimiento' => '2024-01-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
        MedicoPaciente::create(['medico_id' => $medico->id, 'reg_medico' => $medico->reg_medico, 'numhistoria' => (string) $historia, 'paciente_id' => $id]);
        Historia::create(['numhistoria' => (string) $historia, 'reg_medico' => $medico->reg_medico, 'paciente_id' => $id, 'medico_id' => $medico->id]);

        return Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => $historia, 'nroconsulta' => 1, 'fecha' => '2026-10-17']);
    }

    private function antecedente(Medico $medico, int $historia, string $codigo, string $nombre, string $tipo, ?string $tipo2 = null): void
    {
        DB::table('antecedentes')->insert(['reg_medico' => $medico->reg_medico, 'codantecedente' => $codigo, 'descripcion' => $nombre, 'codtipo' => $tipo, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('antece_paciente')->insert([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => $historia, 'codeantecedente' => $codigo, 'descripcion' => $nombre, 'tipo' => $tipo, 'tipo2' => $tipo2,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function url(Consulta $consulta, array $extra = []): string
    {
        return route('clinica.consulta', array_merge([$consulta->id, 'seccion' => 'antecedentes'], $extra));
    }

    public function test_abrir_los_antecedentes_siembra_los_siete_tipos_una_sola_vez(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($consulta))->assertOk();
        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($consulta))->assertOk();

        $tipos = DB::table('tipo_antecedente')->where('reg_medico', $medico->reg_medico)->orderBy('codetipo')->pluck('descripcion', 'codetipo')->all();
        $this->assertSame(TiposDeAntecedente::PEDIATRIA, $tipos);
        $this->assertSame('C.A.', $tipos['02']);
        $this->assertSame(7, DB::table('tipo_antecedente')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_el_sembrado_no_pisa_lo_que_el_medico_ya_tiene(): void
    {
        [, $medico] = $this->medicoConAcceso();
        DB::table('tipo_antecedente')->insert(['reg_medico' => $medico->reg_medico, 'codetipo' => '01', 'descripcion' => 'MIS PATOLOGIAS', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(6, app(SembrarTiposDeAntecedente::class)->ejecutar($medico->reg_medico, 'pediatria'));
        $this->assertSame('MIS PATOLOGIAS', DB::table('tipo_antecedente')->where('reg_medico', $medico->reg_medico)->where('codetipo', '01')->value('descripcion'));
        $this->assertSame(0, app(SembrarTiposDeAntecedente::class)->ejecutar($medico->reg_medico, 'pediatria'));
    }

    public function test_los_chips_son_las_siete_categorias_con_su_cuenta_y_el_elegido_filtra(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        // Hay otros médicos (de ginecología) con sus tipos en la base: no pueden prestarle sus nombres a pediatría.
        [, $gineco] = $this->medicoConAcceso('ginecologia-y-obstetricia');
        DB::table('tipo_antecedente')->insert(['reg_medico' => $gineco->reg_medico, 'codetipo' => '03', 'descripcion' => 'PSICOBIOLOGICOS', 'created_at' => now(), 'updated_at' => now()]);
        $consulta = $this->consulta($medico);
        $this->antecedente($medico, 9601, '0001', 'ASMA', '01');
        $this->antecedente($medico, 9601, '0002', 'DIABETES MATERNA', '03');
        $this->antecedente($medico, 9601, '0003', 'PARTO A TÉRMINO', '05');

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($consulta))
            ->assertOk()
            ->assertSeeInOrder(['PATOLÓGICOS', 'C.A.', 'MADRE', 'PADRE', 'PERINATALES', 'ALIMENTACIÓN', 'D.P.'])
            ->assertDontSee('PSICOBIOLOGICOS');

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($consulta, ['tipo' => '03']))
            ->assertOk()->assertSee('DIABETES MATERNA')->assertDontSee('PARTO A TÉRMINO')->assertDontSee('>ASMA<', false);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($consulta, ['tipo' => '06']))
            ->assertOk()->assertDontSee('ASMA')->assertDontSee('PARTO A TÉRMINO');
    }

    public function test_la_alerta_sigue_funcionando_en_pediatria(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);
        $this->antecedente($medico, 9601, '0001', 'ALERGIA A LA PENICILINA', '01', 'A');

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.consulta', $consulta->id))
            ->assertOk()->assertSee('id="consulta-alertas"', false)->assertSee('ALERGIA A LA PENICILINA');
    }

    public function test_crear_un_antecedente_en_una_categoria_pediatrica_lo_agrega_al_nino(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);

        // Abrir la sección siembra los tipos; después se crea en «Perinatales» (05).
        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($consulta))->assertOk();
        $this->conContexto($medico, $pediatria)->actingAs($user)
            ->post(route('clinica.consulta.antecedentes.crear', $consulta->id), ['descripcion' => 'apgar bajo', 'tipo' => '05'])
            ->assertRedirect()->assertSessionHas('estado');

        $this->assertDatabaseHas('antecedentes', ['reg_medico' => $medico->reg_medico, 'descripcion' => 'APGAR BAJO', 'codtipo' => '05']);
        $this->assertDatabaseHas('antece_paciente', ['reg_medico' => $medico->reg_medico, 'numhistoria' => 9601, 'descripcion' => 'APGAR BAJO', 'tipo' => '05']);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($consulta, ['tipo' => '05']))
            ->assertOk()->assertSee('APGAR BAJO');
    }

    /**
     * Ginecología ya no es «la que no cambia» (2026-10-28): sus siete nombres se siembran en el mismo punto que los
     * de pediatría y nunca se le prestan los de pediatría. El caso completo está en
     * `TiposDeAntecedentePorEspecialidadTest`; acá queda el cruce de las dos especialidades en la misma base.
     */
    public function test_ginecologia_siembra_sus_nombres_y_no_los_de_pediatria(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso('ginecologia-y-obstetricia');
        // Un tipo que el médico ya tenía, con su propio nombre: el sembrado no lo pisa.
        DB::table('tipo_antecedente')->insert(['reg_medico' => $medico->reg_medico, 'codetipo' => '06', 'descripcion' => 'N. PAREJAS', 'created_at' => now(), 'updated_at' => now()]);
        $consulta = $this->consulta($medico);
        $this->antecedente($medico, 9601, '0001', 'MIOMATOSIS', '01');

        $this->conContexto($medico, $gineco)->actingAs($user)->get($this->url($consulta))
            ->assertOk()
            ->assertSee('N. PAREJAS')->assertSee('PERSONAL')->assertSee('MIOMATOSIS')
            ->assertDontSee('PATOLÓGICOS');

        // Completó los seis que le faltaban y conservó el suyo.
        $this->assertSame(7, DB::table('tipo_antecedente')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame('N. PAREJAS', DB::table('tipo_antecedente')->where('reg_medico', $medico->reg_medico)->where('codetipo', '06')->value('descripcion'));
    }
}
