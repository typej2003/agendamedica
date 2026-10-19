<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\ExamenPacienteConsulta;
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
 *  - muestra los 33 campos con **los valores guardados** y el resumen de la consulta;
 *  - guardar escribe **las dos cosas**: los números en `examenes_paciente_consulta` (`hhistoria` + `hconsulta`) y
 *    el texto compuesto en `consultas.resultadoexamencomp`, cada uno con su rastro en `sync_changes`
 *    (`source = web`, por columna); sin valores no crea la fila;
 *  - la secretaría no la usa (403).
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

        $this->conContexto($user, $medico, $pediatria)->get($this->url($consulta))
            ->assertOk()->assertSee('Elige una sección en el menú');

        $this->conNefrologia($medico);

        $this->conContexto($user, $medico->fresh(), $pediatria)->get($this->url($consulta))
            ->assertOk()->assertSee('Indicadores incidentes')->assertSee('Sin cargar');
    }

    public function test_la_pantalla_muestra_los_valores_guardados_y_el_resumen(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conNefrologia($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        // La fila del panel con dos números y el resumen en la consulta (como los deja el escritorio).
        ExamenPacienteConsulta::create([
            'hhistoria' => 1001, 'hconsulta' => 1, 'urea' => 30, 'sodio' => 140, 'g_v_ph' => 7.35,
        ]);
        $consulta->forceFill(['resultadoexamencomp' => 'UREA: 30. Na: 140.'])->save();

        $this->conContexto($user, $medico, $pediatria)->get($this->url($consulta))
            ->assertOk()
            ->assertSee('UREA')->assertSee('Cr s')->assertSee('Na u')->assertSee('Gases')
            ->assertSee('value="30"', false)->assertSee('value="140"', false)->assertSee('value="7.35"', false)
            ->assertSee('Cargado')->assertSee('UREA: 30. Na: 140.');
    }

    public function test_guardar_escribe_los_numeros_y_el_resumen_con_su_rastro(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conNefrologia($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $pediatria)
            ->post(route('clinica.consulta.indicadores.guardar', $consulta->id), [
                'urea' => '30', 'sodio' => '140', 'calcio' => '9,5', 'ph' => '7.35',
            ])
            ->assertRedirect($this->url($consulta))
            ->assertSessionHas('estado');

        // 1) Los números, en la fila del panel (clave del DataWindow: hhistoria + hconsulta).
        $fila = ExamenPacienteConsulta::where('hhistoria', 1001)->where('hconsulta', 1)->first();
        $this->assertNotNull($fila);
        $this->assertSame(30.0, (float) $fila->urea);
        $this->assertSame(140.0, (float) $fila->sodio);
        $this->assertEqualsWithDelta(9.5, (float) $fila->calcio, 0.0001);
        $this->assertEqualsWithDelta(7.35, (float) $fila->g_v_ph, 0.0001);

        // 2) El resumen compuesto, sin los gases (no entran en el texto) y con la coma normalizada.
        $this->assertSame('UREA: 30. Na: 140. Ca: 9.5.', (string) $consulta->fresh()->resultadoexamencomp);

        // 3) Los rastros: uno por columna de la tabla y uno del texto en la consulta.
        foreach (['urea', 'sodio', 'calcio', 'g_v_ph'] as $columna) {
            $rastro = SyncChange::where('table_name', 'examenes_paciente_consulta')
                ->where('record_id', $fila->id)->where('column_name', $columna)->first();
            $this->assertNotNull($rastro, 'Falta el rastro de ' . $columna . '.');
            $this->assertSame('web', $rastro->source);
        }

        $texto = SyncChange::where('table_name', 'consultas')
            ->where('record_id', $consulta->id)->where('column_name', 'resultadoexamencomp')->first();
        $this->assertNotNull($texto);
    }

    public function test_sin_valores_no_crea_fila_ni_toca_el_resumen(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conNefrologia($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $pediatria)
            ->post(route('clinica.consulta.indicadores.guardar', $consulta->id), ['urea' => ''])
            ->assertSessionHas('estado');

        $this->assertSame(0, ExamenPacienteConsulta::count());
        $this->assertNull($consulta->fresh()->resultadoexamencomp);
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

        $this->assertSame(0, ExamenPacienteConsulta::count());
    }

    public function test_la_solapa_del_eco_renal_abre_el_formulario(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conNefrologia($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $pediatria)
            ->get(route('clinica.consulta', [$consulta->id, 'seccion' => 'indicadores', 'pestana' => 'renal']))
            ->assertOk()
            ->assertSee('Eco renal')
            ->assertSee('Riñón derecho')->assertSee('Riñón izquierdo')
            ->assertSee('Guardar el eco renal');
    }

    public function test_guardar_el_eco_renal_escribe_su_tabla_con_rastro(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conNefrologia($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $pediatria)
            ->post(route('clinica.consulta.eco-renal.guardar', $consulta->id), [
                'rd_longitud' => '7.2', 'ri_longitud' => '7.8', 'conlusion' => 'Riñones de tamaño normal.',
            ])
            ->assertRedirect(route('clinica.consulta', [$consulta->id, 'seccion' => 'indicadores', 'pestana' => 'renal']))
            ->assertSessionHas('estado');

        $fila = \App\Models\UltraRenal::where('historia', 1001)->first();
        $this->assertNotNull($fila);
        $this->assertEqualsWithDelta(7.2, (float) $fila->rd_longitud, 0.001);
        $this->assertEqualsWithDelta(7.8, (float) $fila->ri_longitud, 0.001);

        foreach (['rd_longitud', 'ri_longitud', 'conlusion'] as $columna) {
            $rastro = SyncChange::where('table_name', 'ultra_renal')
                ->where('record_id', $fila->id)->where('column_name', $columna)->first();
            $this->assertNotNull($rastro, 'Falta el rastro de ' . $columna . '.');
        }
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

        MedicoRegistro::where('medico_id', $medico->id)
            ->where('reg_medico', $medico->reg_medico)
            ->update(['user_id' => $secretaria->id]);

        $this->conContexto($secretaria, $medico, $pediatria)
            ->post(route('clinica.consulta.indicadores.guardar', $consulta->id), ['urea' => '30'])
            ->assertForbidden();

        $this->assertSame(0, ExamenPacienteConsulta::count());
    }
}
