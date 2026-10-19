<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\Subspecialty;
use App\Models\SyncChange;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **Indicadores incidentes** en la consulta pediátrica (nefrología pediátrica, 2026-10-19).
 *
 * Lo que se fija:
 *  - la sección es el **ítem 5 de pediatría** y sólo existe con el postgrado de nefrología;
 *  - muestra los 26 campos y el resumen guardado (o el estado vacío);
 *  - guardar **compone el texto** y lo deja en `consultas.resultadoexamencomp`, con su rastro en `sync_changes`
 *    (`source = web`, por columna) y sin crear una fila vacía cuando no hay valores;
 *  - la secretaría no la usa (403), como todas las secciones clínicas de la consulta.
 */
class ConsultaIndicadoresWebTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $pediatria = Specialty::where('slug', 'pediatria')->firstOrFail();
        $regMedico = 'ind-test-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Doctora de prueba', 'email' => 'ind-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Doctora', 'lastname' => 'De Prueba', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($pediatria->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $pediatria];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null,
        ]])->actingAs($user);
    }

    /** @return array{0:int, 1:Consulta} */
    private function pacienteYConsulta(Medico $medico): array
    {
        $pacienteId = DB::table('pacientes')->insertGetId([
            'nombres' => 'Ana', 'apellidos' => 'Alvarez', 'cedula' => (string) random_int(10000000, 30000000),
            'sexo' => 'F', 'fnacimiento' => '2018-05-04', 'created_at' => now(), 'updated_at' => now(),
        ]);

        MedicoPaciente::create([
            'medico_id' => $medico->id, 'paciente_id' => $pacienteId,
            'reg_medico' => $medico->reg_medico, 'numhistoria' => '1001',
        ]);

        Historia::create([
            'numhistoria' => '1001', 'reg_medico' => $medico->reg_medico,
            'paciente_id' => $pacienteId, 'medico_id' => $medico->id,
        ]);

        $consulta = Consulta::create([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'nroconsulta' => 1,
            'fecha' => now()->toDateString(),
        ]);

        return [$pacienteId, $consulta];
    }

    private function conNefrologia(Medico $medico): void
    {
        $nefro = Subspecialty::where('slug', 'nefrologia-pediatrica')->firstOrFail();
        $medico->subespecialidades()->attach($nefro->id);
    }

    private function url(Consulta $consulta): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => 'indicadores']);
    }

    public function test_la_seccion_solo_existe_con_el_postgrado_de_nefrologia(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        // Sin el postgrado, la sección no está en el catálogo: cae en el estado vacío del shell.
        $this->conContexto($user, $medico, $pediatria)->get($this->url($consulta))
            ->assertOk()->assertSee('Elige una sección en el menú');

        $this->conNefrologia($medico);

        $this->conContexto($user, $medico->fresh(), $pediatria)->get($this->url($consulta))
            ->assertOk()->assertSee('Indicadores incidentes')
            ->assertSee('Sin cargar');
    }

    public function test_la_pantalla_muestra_los_campos_y_el_resumen_guardado(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conNefrologia($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $pediatria)->get($this->url($consulta))
            ->assertOk()
            ->assertSee('UREA')->assertSee('Cr s')->assertSee('Na')
            ->assertSee('Ac Uu')->assertSee('Ca u')
            ->assertSee('Sangre')->assertSee('Orina')
            ->assertSee('todavía no tiene un resumen');

        // Con un resumen guardado, se muestra y la pastilla lo dice.
        $consulta->forceFill(['resultadoexamencomp' => 'UREA: 30. Na: 140.'])->save();

        $this->conContexto($user, $medico, $pediatria)->get($this->url($consulta))
            ->assertOk()->assertSee('Cargado')->assertSee('UREA: 30. Na: 140.');
    }

    public function test_guardar_compone_el_texto_y_deja_su_rastro(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conNefrologia($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $pediatria)
            ->post(route('clinica.consulta.indicadores.guardar', $consulta->id), [
                'urea' => '30', 'sodio' => '140', 'calcio' => '9,5',
            ])
            ->assertRedirect($this->url($consulta))
            ->assertSessionHas('estado');

        $this->assertSame(
            'UREA: 30. Na: 140. Ca: 9,5.',
            (string) $consulta->fresh()->resultadoexamencomp,
        );

        // El rastro del sync: la columna, con `source = web` (la sube el escritorio; sin rastro, una edición vieja
        // la pisaría).
        $rastro = SyncChange::where('table_name', 'consultas')
            ->where('record_id', $consulta->id)
            ->where('column_name', 'resultadoexamencomp')
            ->first();

        $this->assertNotNull($rastro);
        $this->assertSame('web', $rastro->source);
        $this->assertSame('updated', $rastro->operation);
    }

    public function test_sin_valores_no_se_crea_un_resumen_vacio(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conNefrologia($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $consulta->forceFill(['resultadoexamencomp' => 'UREA: 30.'])->save();

        $this->conContexto($user, $medico, $pediatria)
            ->post(route('clinica.consulta.indicadores.guardar', $consulta->id), ['urea' => ''])
            ->assertSessionHas('error');

        $this->assertSame('UREA: 30.', (string) $consulta->fresh()->resultadoexamencomp);
    }

    public function test_un_valor_que_no_es_numero_se_rechaza_y_no_borra_lo_escrito(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conNefrologia($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $pediatria)
            ->from($this->url($consulta))
            ->post(route('clinica.consulta.indicadores.guardar', $consulta->id), ['urea' => 'treinta'])
            ->assertRedirect($this->url($consulta))
            ->assertSessionHasErrors('urea');

        $this->assertNull($consulta->fresh()->resultadoexamencomp);
    }

    public function test_la_secretaria_no_puede_guardar(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conNefrologia($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $secretaria = User::create([
            'name' => 'Secretaria', 'email' => 'sec-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');

        // La secretaría del consultorio: el contexto le da acceso al `reg_medico` del médico (el pivote con
        // `user_id`), pero el grupo de rutas de la consulta es `role:Root|Medico` y la deja afuera.
        MedicoRegistro::where('medico_id', $medico->id)
            ->where('reg_medico', $medico->reg_medico)
            ->update(['user_id' => $secretaria->id]);

        $this->conContexto($secretaria, $medico, $pediatria)
            ->post(route('clinica.consulta.indicadores.guardar', $consulta->id), ['urea' => '30'])
            ->assertForbidden();

        $this->assertNull($consulta->fresh()->resultadoexamencomp);
    }
}
