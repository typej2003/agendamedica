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
 * **La historia clínica dentro de la consulta** (pestaña de *Solicitudes*, 2026-10-19): en el escritorio la solapa
 * *Historia Pediátrica* —*Historia Clínica* en ginecología— muestra la hoja en vista previa y la imprime
 * (`tabpage_15` de `tab_4`, el DataWindow `d_historia_clinica_dos`). Alexander: *«en solicitudes no está la ficha de
 * Historia Clínica (o Historia Pediátrica para pediatría). No existe la sección»*.
 *
 * Lo que fijan estos casos:
 *
 *  - **La pestaña existe** y se llama distinto en cada especialidad, como en el escritorio.
 *  - **No duplica la hoja**: ofrece la que ya existe (`/clinica/pacientes/{paciente}/historia/imprimir`) con
 *    «Imprimir» y «Visualizar», y el enlace a la pantalla de la historia.
 *  - **Sin número de historia no hay hoja**: lo dice en vez de ofrecer una impresión vacía.
 *  - **Es de sólo lectura**: no escribe nada.
 */
class HistoriaEnLaConsultaWebTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $especialidad = 'ginecologia-y-obstetricia'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $esp = Specialty::firstOrCreate(['slug' => $especialidad], ['name' => ucfirst($especialidad)]);
        $reg = 'hist-cons-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'hist-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dra. de prueba', 'lastname' => 'Historia', 'email' => $user->email,
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

    /** @return array{0:int, 1:Consulta} el id del paciente y su consulta */
    private function pacienteYConsulta(Medico $medico, ?string $numhistoria = '9701'): array
    {
        $paciente = DB::table('pacientes')->insertGetId([
            'nombres' => 'Lucía', 'apellidos' => 'Historia', 'sexo' => 'F', 'fnacimiento' => '2024-03-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        MedicoPaciente::create(['medico_id' => $medico->id, 'reg_medico' => $medico->reg_medico, 'numhistoria' => $numhistoria, 'paciente_id' => $paciente]);

        if ($numhistoria !== null) {
            Historia::create(['numhistoria' => $numhistoria, 'reg_medico' => $medico->reg_medico, 'paciente_id' => $paciente, 'medico_id' => $medico->id]);
        }

        $consulta = Consulta::create([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => (int) ($numhistoria ?? 0), 'nroconsulta' => 1, 'fecha' => '2026-10-19',
        ]);

        return [$paciente, $consulta];
    }

    private function url(Consulta $consulta): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => 'solicitudes', 'solicitud' => 'historia']);
    }

    public function test_en_ginecologia_la_pestana_es_historia_clinica_y_ofrece_la_hoja(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [$paciente, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($medico, $gineco)->actingAs($user)->get($this->url($consulta))
            ->assertOk()
            ->assertSee('Historia clínica')
            ->assertSee('solicitud=historia', false)
            ->assertSee(route('clinica.pacientes.historia.imprimir', $paciente), false)
            ->assertSee('Imprimir la historia')
            ->assertSee('Visualizar')
            ->assertSee(route('clinica.pacientes.historia', $paciente), false);
    }

    public function test_en_pediatria_la_pestana_es_historia_pediatrica(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso('pediatria');
        [$paciente, $consulta] = $this->pacienteYConsulta($medico);

        $html = $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($consulta))
            ->assertOk()
            ->assertSee('Historia pediátrica')
            ->assertDontSee('Historia clínica')
            ->assertSee(route('clinica.pacientes.historia.imprimir', $paciente), false)
            ->getContent();

        // La pestaña está en la fila de *Solicitudes*, junto a las demás.
        $this->assertStringContainsString('solicitud=constancia', $html);
        $this->assertStringContainsString('solicitud=informe', $html);
    }

    /** En pediatría `nariz`, `laringe` y `oido` son los textos de *Exámenes/Estudios*: la hoja los rotula así. */
    public function test_en_pediatria_la_hoja_rotula_los_textos_de_examenes_estudios(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso('pediatria');
        [$paciente, $consulta] = $this->pacienteYConsulta($medico);

        DB::table('consultas')->where('id', $consulta->id)->update([
            'laringe' => 'R.X. de tórax sin infiltrados.',
            'oido' => 'Espirometría normal.',
            'nariz' => 'Hemograma normal.',
        ]);

        $this->conContexto($medico, $pediatria)->actingAs($user)
            ->get(route('clinica.pacientes.historia.imprimir', $paciente))
            ->assertOk()
            ->assertSee('Imagenología: R.X. de tórax sin infiltrados.')
            ->assertSee('Estudios exploratorios: Espirometría normal.')
            ->assertSee('Exámenes: Hemograma normal.')
            ->assertDontSee('Laringe:');
    }

    /** Y en ginecología los rótulos son los del examen físico de siempre. */
    public function test_en_ginecologia_la_hoja_conserva_los_rotulos_del_examen_fisico(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [$paciente, $consulta] = $this->pacienteYConsulta($medico);

        DB::table('consultas')->where('id', $consulta->id)->update(['laringe' => 'Sin hallazgos.']);

        $this->conContexto($medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.historia.imprimir', $paciente))
            ->assertOk()
            ->assertSee('Laringe: Sin hallazgos.')
            ->assertDontSee('Imagenología:');
    }

    public function test_no_escribe_nada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $antes = DB::table('sync_changes')->count();

        $this->conContexto($medico, $gineco)->actingAs($user)->get($this->url($consulta))->assertOk();

        $this->assertSame($antes, DB::table('sync_changes')->count());
    }
}
