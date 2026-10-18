<?php

namespace Tests\Feature;

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
 * **La alimentación de pediatría** (WEB-12.4). La pestaña *Alimentación* del escritorio de pediatría escribe en la **misma** fila de `dieta_paciente`
 * (`histroria` + `consulta`) que la pestaña *Dieta / Recomendaciones* de las solicitudes: la web no tiene una pantalla aparte; en pediatría esa pestaña se
 * llama *Alimentación* y ofrece, además de las plantillas del médico, el catálogo de `dietas`. Ginecología no cambia.
 */
class AlimentacionPediatricaWebTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $especialidad = 'pediatria'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $esp = Specialty::firstOrCreate(['slug' => $especialidad], ['name' => ucfirst($especialidad)]);
        $reg = 'alim-ped-' . uniqid();

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'alim-' . uniqid() . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');
        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dra. de prueba', 'lastname' => 'Alimentacion', 'email' => $user->email,
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

    private function consulta(Medico $medico): Consulta
    {
        $id = DB::table('pacientes')->insertGetId([
            'nombres' => 'Mateo', 'apellidos' => 'Alimentacion', 'sexo' => 'M', 'fnacimiento' => '2024-01-01', 'created_at' => now(), 'updated_at' => now(),
        ]);
        MedicoPaciente::create(['medico_id' => $medico->id, 'reg_medico' => $medico->reg_medico, 'numhistoria' => '9501', 'paciente_id' => $id]);
        Historia::create(['numhistoria' => '9501', 'reg_medico' => $medico->reg_medico, 'paciente_id' => $id, 'medico_id' => $medico->id]);

        return Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => 9501, 'nroconsulta' => 2, 'fecha' => '2026-10-17']);
    }

    private function url(Consulta $consulta): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => 'solicitudes', 'solicitud' => 'dieta']);
    }

    private function dieta(Medico $medico, string $codigo, string $titulo, ?string $texto, int $calorias = 1000): void
    {
        DB::table('dietas')->insert(['reg_medico' => $medico->reg_medico, 'cod_dieta' => $codigo, 'dietas' => $titulo, 'descripcion' => $texto, 'calorias' => $calorias,
            'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_en_pediatria_la_pestana_se_llama_alimentacion_y_ofrece_el_catalogo_de_dietas(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);
        $this->dieta($medico, '01', 'LACTANTE 6 MESES', 'Leche materna a libre demanda y papilla de frutas.');
        $this->dieta($medico, '02', 'SIN TEXTO', 'none');
        DB::table('formato_print')->insert(['reg_medico' => $medico->reg_medico, 'codigo' => 'D1', 'tipo' => 'DI', 'titulo' => 'DIETA BLANDA', 'descripcion' => 'Arroz y pollo hervido.',
            'created_at' => now(), 'updated_at' => now()]);
        $otro = $this->medicoConAcceso()[1];
        $this->dieta($otro, '01', 'DE OTRO MEDICO', 'No es mía.');

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($consulta))
            ->assertOk()
            ->assertSee('Alimentación')
            ->assertSee('LACTANTE 6 MESES')->assertSee('Leche materna a libre demanda')
            ->assertSee('DIETA BLANDA')
            ->assertDontSee('SIN TEXTO')->assertDontSee('DE OTRO MEDICO');
    }

    public function test_lo_que_se_guarda_es_la_misma_fila_de_dieta_paciente(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);
        $this->dieta($medico, '01', 'LACTANTE 6 MESES', 'Leche materna.');

        $this->conContexto($medico, $pediatria)->actingAs($user)
            ->post(route('clinica.consulta.dieta.guardar', $consulta->id), ['titulo' => 'LACTANTE 6 MESES', 'descripcion' => 'Leche materna y frutas.'])
            ->assertRedirect();

        $fila = DB::table('dieta_paciente')->where('reg_medico', $medico->reg_medico)->where('histroria', 9501)->where('consulta', 2)->first();
        $this->assertSame('LACTANTE 6 MESES', $fila->dieta);
        $this->assertSame('Leche materna y frutas.', $fila->descripcion);
        $this->assertSame(1, DB::table('dieta_paciente')->where('reg_medico', $medico->reg_medico)->count());

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($consulta))->assertOk()->assertSee('Leche materna y frutas.');
    }

    public function test_ginecologia_conserva_su_dieta_y_no_ve_el_catalogo_de_dietas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso('ginecologia-y-obstetricia');
        $consulta = $this->consulta($medico);
        $this->dieta($medico, '01', 'LACTANTE 6 MESES', 'Leche materna.');

        $this->conContexto($medico, $gineco)->actingAs($user)->get($this->url($consulta))
            ->assertOk()->assertSee('Dieta / Recomendaciones')->assertDontSee('LACTANTE 6 MESES')->assertDontSee('Alimentación');
    }
}
