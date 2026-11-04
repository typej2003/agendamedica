<?php

namespace Tests\Feature;

use App\Clinica\Consulta\Cardiologia\MapaDelCardiologo;
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
 * **La consulta del cardiólogo** (CardioReport, CAR-2, 2026-11-04).
 *
 * Lo que se fija:
 *  - el árbol de cardiología tiene **Estudios cardiológicos** y no el estudio ecográfico ni el control de embarazo;
 *  - la sección abre el ecocardiograma con sus medidas y sus conclusiones;
 *  - el guardado escribe sólo lo que cambió, con su rastro en `sync_changes`, y no crea la fila si llega vacía;
 *  - ginecología no ve la sección.
 */
class ConsultaCardiologiaWebTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medico(string $slug = 'cardiologia'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $especialidad = Specialty::where('slug', $slug)->firstOrFail();
        $regMedico = 'cardio-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Doctora de prueba', 'email' => 'cardio-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Doctora', 'lastname' => 'De Prueba', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($especialidad->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $especialidad];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null,
        ]])->actingAs($user);
    }

    private function consulta(Medico $medico): Consulta
    {
        $pacienteId = DB::table('pacientes')->insertGetId([
            'nombres' => 'Ana', 'apellidos' => 'Gómez', 'cedula' => (string) random_int(10000000, 30000000),
            'sexo' => 'F', 'fnacimiento' => '1958-03-11', 'created_at' => now(), 'updated_at' => now(),
        ]);
        MedicoPaciente::create(['medico_id' => $medico->id, 'paciente_id' => $pacienteId, 'reg_medico' => $medico->reg_medico, 'numhistoria' => '3001']);
        Historia::create(['numhistoria' => '3001', 'reg_medico' => $medico->reg_medico, 'paciente_id' => $pacienteId, 'medico_id' => $medico->id]);

        return Consulta::create([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 3001, 'nroconsulta' => 1,
            'fecha' => now()->toDateString(),
        ]);
    }

    public function test_el_arbol_de_cardiologia_tiene_sus_estudios_y_no_los_de_ginecologia(): void
    {
        [$user, $medico, $especialidad] = $this->medico();
        $consulta = $this->consulta($medico);

        $this->conContexto($user, $medico, $especialidad)->get(route('clinica.consulta', $consulta->id))
            ->assertOk()
            ->assertSee('Estudios cardiológicos')
            ->assertDontSee('Estudio ecográfico')
            ->assertDontSee('Control de embarazo');
    }

    public function test_la_seccion_abre_el_ecocardiograma_con_sus_medidas_y_conclusiones(): void
    {
        [$user, $medico, $especialidad] = $this->medico();
        $consulta = $this->consulta($medico);

        $this->conContexto($user, $medico, $especialidad)
            ->get(route('clinica.consulta', [$consulta->id, 'seccion' => MapaDelCardiologo::SECCION]))
            ->assertOk()
            ->assertSee('Aurícula izquierda')
            ->assertSee('Fracción de eyección')
            ->assertSee('Conclusiones')
            ->assertSee('Eco de estrés')
            ->assertSee('Eco transesofágico')
            ->assertSee('Doppler de miembros superiores')
            ->assertSee('Doppler carotídeo')
            ->assertSee('Doppler venoso de miembros inferiores')
            ->assertSee('Prueba de esfuerzo')
            ->assertSee('Oscilometría')
            ->assertSee('Marcapasos')
            ->assertSee('Guardar el estudio');
    }

    public function test_guarda_el_doppler_de_miembros_superiores_y_el_carotideo(): void
    {
        [$user, $medico, $especialidad] = $this->medico();
        $consulta = $this->consulta($medico);
        $url = route('clinica.consulta.cardiologia.guardar', $consulta->id);

        $this->conContexto($user, $medico, $especialidad)->post($url, [
            'pestana' => MapaDelCardiologo::PESTANA_DOPPLER_MS,
            'indicacion_ms' => 'Claudicación',
            'equipo_ms' => 'Voluson',
            'miembro_superior_derecho' => 'Flujo conservado.',
            'miembro_superior_izquierdo' => 'Estenosis leve.',
            'conclusion_ms' => 'Sin trombosis.',
        ])->assertSessionHas('estado', 'Estudio guardado.');

        $this->assertDatabaseHas('dd_arterial_venoso_ms', [
            'reg_medico' => $medico->reg_medico, 'historia' => 3001, 'consulta' => 1,
            'indicacion' => 'Claudicación', 'conclusion' => 'Sin trombosis.',
        ]);

        $this->conContexto($user, $medico, $especialidad)->post($url, [
            'pestana' => MapaDelCardiologo::PESTANA_CAROTIDAS,
            'indicacion_car' => 'Soplo carotídeo',
            'accp_der' => '119,3',
            'accd_izq' => '92',
            'rim_der' => '0,9',
            'hallasgos_car' => 'Placa blanda en la bifurcación.',
            'conclusion_car' => 'Estenosis leve.',
        ])->assertSessionHas('estado', 'Estudio guardado.');

        $this->assertDatabaseHas('dd_arterias_carotidas_dos', [
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 3001, 'numconsulta' => 1,
            'indicacion' => 'Soplo carotídeo', 'conclusion' => 'Estenosis leve.',
        ]);
        $this->assertSame(119.3, (float) DB::table('dd_arterias_carotidas_dos')->where('numhistoria', 3001)->value('ar_accp_der'));
        $this->assertSame(0.9, (float) DB::table('dd_arterias_carotidas_dos')->where('numhistoria', 3001)->value('relac_intima_media_d'));

        $this->conContexto($user, $medico, $especialidad)->post($url, [
            'pestana' => MapaDelCardiologo::PESTANA_VENOSO,
            'indicacion_venoso' => 'Varices',
            'fem_comun_r2' => 'SI',
            'poplitea_e1' => 'NO',
            'conclusion_venoso' => 'Insuficiencia venosa superficial.',
        ])->assertSessionHas('estado', 'Estudio guardado.');

        $this->assertDatabaseHas('ddflujo_venoso', [
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 3001, 'numconsulta' => 1,
            'fem_comun_r2' => 'SI', 'poplitea_e1' => 'NO', 'conclusiones' => 'Insuficiencia venosa superficial.',
        ]);
    }

    public function test_guarda_el_eco_de_estres_y_el_ete_en_sus_tablas(): void
    {
        [$user, $medico, $especialidad] = $this->medico();
        $consulta = $this->consulta($medico);
        $url = route('clinica.consulta.cardiologia.guardar', $consulta->id);

        $this->conContexto($user, $medico, $especialidad)->post($url, [
            'pestana' => MapaDelCardiologo::PESTANA_ESTRES,
            'diag_estres' => 'Cardiopatía isquémica',
            'fc_maxima' => '142',
            'fc_submaxima' => '120',
            'farmacologico' => 'S',
            'medicamento' => 'Persantin',
            'hallasgos' => 'Cambios isquémicos en la cara inferior.',
            'conclusion_estres' => 'Prueba positiva.',
        ])->assertSessionHas('estado', 'Estudio guardado.');

        $this->assertDatabaseHas('ecocardiografico_in', [
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 3001, 'numconsulta' => 1,
            'diagnostico' => 'Cardiopatía isquémica', 'farmacologico' => 'S', 'medicamento' => 'Persantin',
            'conclusion' => 'Prueba positiva.',
        ]);

        $this->conContexto($user, $medico, $especialidad)->post($url, [
            'pestana' => MapaDelCardiologo::PESTANA_ETE,
            'diag_ete' => 'Válvula mitral',
            'indicacion' => 'Descartar trombo',
            'sedacion' => 'Midazolam',
            'conclusiones_ete' => 'Sin trombos.',
        ])->assertSessionHas('estado', 'Estudio guardado.');

        $this->assertDatabaseHas('ecocardiograma_ete', [
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 3001, 'numconsulta' => 1,
            'indicacion' => 'Descartar trombo', 'conclusiones' => 'Sin trombos.',
        ]);
    }

    public function test_una_opcion_que_no_es_del_catalogo_no_se_guarda(): void
    {
        [$user, $medico, $especialidad] = $this->medico();
        $consulta = $this->consulta($medico);

        $this->conContexto($user, $medico, $especialidad)
            ->post(route('clinica.consulta.cardiologia.guardar', $consulta->id), [
                'pestana' => MapaDelCardiologo::PESTANA_ESTRES,
                'farmacologico' => 'S',
                'medicamento' => 'Aspirina',
            ])->assertSessionHasErrors('medicamento');
    }

    public function test_guarda_el_eco_solo_lo_que_cambio_y_con_su_rastro(): void
    {
        [$user, $medico, $especialidad] = $this->medico();
        $consulta = $this->consulta($medico);

        $url = route('clinica.consulta.cardiologia.guardar', $consulta->id);

        $this->conContexto($user, $medico, $especialidad)->post($url, [
            'pestana' => MapaDelCardiologo::PESTANA_ECO,
            'ha_ai' => '3,4',
            'ha_fe' => '60',
            'conclusiones' => 'Función sistólica conservada.',
        ])->assertRedirect()->assertSessionHas('estado', 'Estudio guardado.');

        $this->assertDatabaseHas('ecocardiografia', [
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 3001, 'numconsulta' => 1,
            'ha_ai' => '3,4', 'ha_fe' => '60', 'conclusiones' => 'Función sistólica conservada.',
        ]);

        $this->assertSame(1, DB::table('sync_changes')->where('table_name', 'ecocardiografia')
            ->where('reg_medico', $medico->reg_medico)->where('operation', 'created')->count());

        // Segundo guardado sin cambios: no escribe nada.
        $this->conContexto($user, $medico, $especialidad)->post($url, [
            'pestana' => MapaDelCardiologo::PESTANA_ECO,
            'ha_ai' => '3,4', 'ha_fe' => '60', 'conclusiones' => 'Función sistólica conservada.',
        ])->assertSessionHas('estado', 'Sin cambios: el estudio ya estaba así.');

        // Tercero: cambia una sola medida y sólo esa columna deja su rastro.
        $antes = DB::table('sync_changes')->where('table_name', 'ecocardiografia')
            ->where('operation', 'updated')->count();

        $this->conContexto($user, $medico, $especialidad)->post($url, [
            'pestana' => MapaDelCardiologo::PESTANA_ECO,
            'ha_ai' => '3,4', 'ha_fe' => '55', 'conclusiones' => 'Función sistólica conservada.',
        ])->assertSessionHas('estado', 'Estudio guardado.');

        $this->assertSame($antes + 1, DB::table('sync_changes')->where('table_name', 'ecocardiografia')
            ->where('operation', 'updated')->count());
        $this->assertDatabaseHas('ecocardiografia', ['numhistoria' => 3001, 'ha_fe' => '55']);
    }

    public function test_un_estudio_vacio_no_crea_la_fila(): void
    {
        [$user, $medico, $especialidad] = $this->medico();
        $consulta = $this->consulta($medico);

        $this->conContexto($user, $medico, $especialidad)
            ->post(route('clinica.consulta.cardiologia.guardar', $consulta->id), [
                'pestana' => MapaDelCardiologo::PESTANA_ECO,
                'ha_ai' => '', 'conclusiones' => '',
            ])->assertRedirect();

        $this->assertDatabaseMissing('ecocardiografia', ['numhistoria' => 3001, 'numconsulta' => 1]);
    }

    public function test_guarda_la_prueba_de_esfuerzo_la_oscilometria_y_el_marcapasos(): void
    {
        [$user, $medico, $especialidad] = $this->medico();
        $consulta = $this->consulta($medico);
        $url = route('clinica.consulta.cardiologia.guardar', $consulta->id);

        $this->conContexto($user, $medico, $especialidad)->post($url, [
            'pestana' => MapaDelCardiologo::PESTANA_ESFUERZO,
            'diag_esfuerzo' => 'Angina estable',
            'mets' => '9,5',
            'porc_fre_max' => '85',
            'conclusion_esfuerzo' => 'Prueba negativa para isquemia.',
        ])->assertSessionHas('estado', 'Estudio guardado.');

        $this->assertDatabaseHas('prueba_esfuerzo', [
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 3001, 'numconsulta' => 1,
            'diagnostico' => 'Angina estable', 'mets' => '9,5', 'conclusiones' => 'Prueba negativa para isquemia.',
        ]);

        $this->conContexto($user, $medico, $especialidad)->post($url, [
            'pestana' => MapaDelCardiologo::PESTANA_OSCILOMETRIA,
            'indicacion_oscilo' => 'Claudicación',
            'itb_tobillo_der' => '1,1',
            'reserva_der' => 'S',
            'conclusion_oscilo' => 'Índices conservados.',
        ])->assertSessionHas('estado', 'Estudio guardado.');

        $this->assertDatabaseHas('oscilometria', [
            'reg_medico' => $medico->reg_medico, 'historia' => 3001, 'consulta' => 1,
            'indicacion' => 'Claudicación', 'reserva_circulatoria_der' => 'S', 'conclusiones' => 'Índices conservados.',
        ]);
        $this->assertSame(1.1, (float) DB::table('oscilometria')->where('historia', 3001)->value('ind_tobillo_brazo_derecho'));

        $this->conContexto($user, $medico, $especialidad)->post($url, [
            'pestana' => MapaDelCardiologo::PESTANA_MARCAPASOS,
            'mp_marca' => 'Medtronic',
            'mp_modelo' => 'Azure',
            'mp_modo' => 'DDDR',
            'mp_umbral_ventriculo' => '0,5',
            'mp_conclusiones' => 'Implante sin complicaciones.',
        ])->assertSessionHas('estado', 'Estudio guardado.');

        $this->assertDatabaseHas('implantacion_marcapasos', [
            'reg_medico' => $medico->reg_medico, 'historia' => 3001, 'consulta' => 1,
            'marca' => 'Medtronic', 'modo' => 'DDDR', 'conclusiones' => 'Implante sin complicaciones.',
        ]);
    }

    public function test_una_pestana_que_no_existe_no_guarda(): void
    {
        [$user, $medico, $especialidad] = $this->medico();
        $consulta = $this->consulta($medico);

        $this->conContexto($user, $medico, $especialidad)
            ->post(route('clinica.consulta.cardiologia.guardar', $consulta->id), [
                'pestana' => 'inventada', 'ha_ai' => '3,4',
            ])->assertSessionHasErrors('pestana');
    }

    public function test_ginecologia_no_ofrece_los_estudios_cardiacos(): void
    {
        [$user, $medico, $especialidad] = $this->medico('ginecologia-y-obstetricia');
        $consulta = $this->consulta($medico);

        $this->conContexto($user, $medico, $especialidad)->get(route('clinica.consulta', $consulta->id))
            ->assertOk()
            ->assertDontSee('Estudios cardiológicos');
    }
}
