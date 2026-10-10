<?php

namespace Tests\Feature;

use App\Clinica\Agenda\DiasNoLaborables;
use App\Models\Cola;
use App\Models\DiaNoLaborable;
use App\Models\Evolucion;
use App\Models\Medico;
use App\Models\MedicalCenter;
use App\Models\MedicoRegistro;
use App\Models\Office;
use App\Models\Specialty;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Días no laborables (PLAN-WEB.md, F2 / WEB-2.8).
 *
 * El ABM que el escritorio tiene en `w_horarios` (`d_calendar_feriados`) y el aviso al agendar, que en
 * el escritorio **aborta** el agendamiento (`w_nueva_cita_7.srw:698-706`) y acá **avisa y deja
 * decidir** (decisión del 2026-10-09).
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class DiasNoLaborablesWebTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $lunes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lunes = Carbon::parse('2026-10-05')->startOfWeek(Carbon::MONDAY);
    }

    // --------------------------------------------------------------------------------------- Ayudas

    private function medicoConAcceso(string $rol = 'Medico'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'nolab-test-' . uniqid();

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'nolab-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'No laborable',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty, ?int $officeId = null): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico'   => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id'    => $officeId,
        ]]);
    }

    private function centro(string $nombre): MedicalCenter
    {
        return MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id'   => DB::table('estados')->value('id'),
            'city_id'    => DB::table('cities')->value('id'),
            'name'       => $nombre,
            'address'    => 'Dirección de prueba',
        ]);
    }

    private function pacienteConHistoria(Medico $medico, int $numhistoria, string $nombres, string $apellidos = 'Paciente'): int
    {
        $id = DB::table('pacientes')->insertGetId([
            'nombres'    => $nombres,
            'apellidos'  => $apellidos,
            'cedula'     => (string) random_int(10000000, 30000000),
            'sexo'       => 'F',
            'fnacimiento'=> '1990-05-04',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('medico_pacientes')->insert([
            'medico_id'   => $medico->id,
            'paciente_id' => $id,
            'numhistoria' => $numhistoria,
            'reg_medico'  => $medico->reg_medico,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return $id;
    }

    /** La clave con la que la web marca los días y con la que el escritorio los lee (`cola.medico`). */
    private function clave(Medico $medico): int
    {
        return app(DiasNoLaborables::class)->claveDe($medico, $medico->reg_medico);
    }

    // ------------------------------------------------------------------------ La regla, en el dominio

    public function test_sin_configuracion_de_evolucion_la_clave_cae_al_id_del_medico(): void
    {
        [$user, $medico] = $this->medicoConAcceso();

        $this->assertSame($medico->id, $this->clave($medico));
    }

    public function test_con_evolucion_la_clave_es_la_del_escritorio(): void
    {
        [$user, $medico] = $this->medicoConAcceso();

        Evolucion::create(['reg_medico' => $medico->reg_medico, 'correo_med' => $medico->email, 'clave' => 7]);

        // El escritorio consulta `cola_dia_no_labor.medico`, que es la clave de `evolucion`, no el id.
        $this->assertSame(7, $this->clave($medico));
    }

    public function test_la_consulta_del_dia_es_por_consultorio_medico_y_fecha(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $reglas = app(DiasNoLaborables::class);

        DiaNoLaborable::create([
            'reg_medico' => $medico->reg_medico,
            'dia'        => $this->lunes->toDateString(),
            'tipo'       => DiaNoLaborable::FERIADO,
            'motivo'     => 'Día de la Independencia',
            'medico'     => $this->clave($medico),
        ]);

        $dia = $reglas->delDia($medico->reg_medico, $this->clave($medico), $this->lunes);

        $this->assertNotNull($dia);
        $this->assertSame('Día de la Independencia', $dia->motivo);
        $this->assertStringContainsString('Día de la Independencia', $reglas->aviso($dia));

        // Otro día del mismo médico no está marcado, y el mismo día de otro médico tampoco.
        $this->assertNull($reglas->delDia($medico->reg_medico, $this->clave($medico), $this->lunes->copy()->addDay()));
        $this->assertNull($reglas->delDia('otro-registro', $this->clave($medico), $this->lunes));
    }

    public function test_los_dias_de_un_rango_se_indexan_por_fecha(): void
    {
        [$user, $medico] = $this->medicoConAcceso();

        foreach ([0, 2] as $corrimiento) {
            DiaNoLaborable::create([
                'reg_medico' => $medico->reg_medico,
                'dia'        => $this->lunes->copy()->addDays($corrimiento)->toDateString(),
                'tipo'       => DiaNoLaborable::CONGRESO,
                'medico'     => $this->clave($medico),
            ]);
        }

        $delRango = app(DiasNoLaborables::class)->entre(
            $medico->reg_medico,
            $this->lunes->copy(),
            $this->lunes->copy()->endOfWeek(Carbon::SUNDAY),
        );

        $this->assertSame(2, $delRango->count());
        $this->assertTrue($delRango->has($this->lunes->toDateString()));
        $this->assertTrue($delRango->has($this->lunes->copy()->addDays(2)->toDateString()));
    }

    public function test_el_aviso_sin_motivo_igual_dice_el_tipo(): void
    {
        [$user, $medico] = $this->medicoConAcceso();

        $dia = DiaNoLaborable::create([
            'reg_medico' => $medico->reg_medico,
            'dia'        => $this->lunes->toDateString(),
            'tipo'       => DiaNoLaborable::QUIRURGICO,
            'medico'     => $this->clave($medico),
        ]);

        $aviso = app(DiasNoLaborables::class)->aviso($dia);

        $this->assertStringContainsString('05/10/2026', $aviso);
        $this->assertStringContainsString('Quirúrgico', $aviso);
    }

    // ------------------------------------------------------------------------------------------- ABM

    public function test_un_invitado_no_entra(): void
    {
        $this->get('/clinica/agenda/no-laborables')->assertRedirect(route('login'));
    }

    public function test_la_pantalla_lista_los_dias_del_mes_con_su_tipo_y_motivo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        DiaNoLaborable::create([
            'reg_medico' => $medico->reg_medico,
            'dia'        => '2026-10-12',
            'tipo'       => DiaNoLaborable::FERIADO,
            'motivo'     => 'Día de la Resistencia Indígena',
            'medico'     => $this->clave($medico),
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda/no-laborables?mes=2026-10');

        $respuesta->assertOk();
        $respuesta->assertSee('Días no laborables');
        $respuesta->assertSee('Día de la Resistencia Indígena');
        $respuesta->assertSee('Feriado');
        $respuesta->assertSee('12/10/2026');
    }

    public function test_marcar_un_dia_lo_guarda_con_la_clave_del_medico(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda/no-laborables', [
                'dia'    => '2026-10-12',
                'tipo'   => DiaNoLaborable::FERIADO,
                'motivo' => 'Feriado bancario',
            ])
            ->assertRedirect(route('clinica.agenda.no-laborables', ['mes' => '2026-10']));

        $dia = DiaNoLaborable::where('reg_medico', $medico->reg_medico)->firstOrFail();

        $this->assertSame('2026-10-12', $dia->dia->toDateString());
        $this->assertSame(DiaNoLaborable::FERIADO, $dia->tipo);
        $this->assertSame('Feriado bancario', $dia->motivo);
        $this->assertSame($this->clave($medico), $dia->medico);
    }

    public function test_el_mismo_dia_no_se_marca_dos_veces(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        DiaNoLaborable::create([
            'reg_medico' => $medico->reg_medico,
            'dia'        => '2026-10-12',
            'tipo'       => DiaNoLaborable::FERIADO,
            'medico'     => $this->clave($medico),
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda/no-laborables', ['dia' => '2026-10-12', 'tipo' => DiaNoLaborable::CONGRESO])
            ->assertSessionHasErrors('dia');

        $this->assertSame(1, DiaNoLaborable::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_editar_el_dia_marcado_cambia_tipo_y_motivo_sin_duplicar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $dia = DiaNoLaborable::create([
            'reg_medico' => $medico->reg_medico,
            'dia'        => '2026-10-12',
            'tipo'       => DiaNoLaborable::FERIADO,
            'motivo'     => 'Algo',
            'medico'     => $this->clave($medico),
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda/no-laborables', [
                'id'     => $dia->id,
                'dia'    => '2026-10-12',
                'tipo'   => DiaNoLaborable::CONGRESO,
                'motivo' => 'Congreso de ginecología',
            ])
            ->assertRedirect(route('clinica.agenda.no-laborables', ['mes' => '2026-10']));

        $this->assertSame(1, DiaNoLaborable::where('reg_medico', $medico->reg_medico)->count());

        $dia->refresh();
        $this->assertSame(DiaNoLaborable::CONGRESO, $dia->tipo);
        $this->assertSame('Congreso de ginecología', $dia->motivo);
    }

    public function test_quitar_la_marca_deja_al_medico_atendiendo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $dia = DiaNoLaborable::create([
            'reg_medico' => $medico->reg_medico,
            'dia'        => '2026-10-12',
            'tipo'       => DiaNoLaborable::FERIADO,
            'medico'     => $this->clave($medico),
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->delete('/clinica/agenda/no-laborables/' . $dia->id)
            ->assertRedirect(route('clinica.agenda.no-laborables', ['mes' => '2026-10']));

        $this->assertSame(0, DiaNoLaborable::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_un_dia_de_otro_consultorio_no_se_toca(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();

        $ajeno = DiaNoLaborable::create([
            'reg_medico' => $otro->reg_medico,
            'dia'        => '2026-10-12',
            'tipo'       => DiaNoLaborable::FERIADO,
            'medico'     => $this->clave($otro),
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->delete('/clinica/agenda/no-laborables/' . $ajeno->id)
            ->assertNotFound();

        $this->assertSame(1, DiaNoLaborable::where('reg_medico', $otro->reg_medico)->count());
    }

    public function test_la_secretaria_puede_marcar_los_dias(): void
    {
        [$secretaria, $medico, $gineco] = $this->medicoConAcceso('Secretaria');

        // La secretaria no es dueña de la ficha del médico: su acceso es por `medico_registros`.
        MedicoRegistro::where('medico_id', $medico->id)->update(['user_id' => $secretaria->id]);

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->post('/clinica/agenda/no-laborables', ['dia' => '2026-10-12', 'tipo' => DiaNoLaborable::FERIADO])
            ->assertRedirect(route('clinica.agenda.no-laborables', ['mes' => '2026-10']));

        $this->assertSame(1, DiaNoLaborable::where('reg_medico', $medico->reg_medico)->count());
    }

    // ------------------------------------------------------------------- El aviso al agendar (agenda)

    public function test_el_formulario_de_nueva_cita_avisa_que_ese_dia_no_se_atiende(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        DiaNoLaborable::create([
            'reg_medico' => $medico->reg_medico,
            'dia'        => $this->lunes->toDateString(),
            'tipo'       => DiaNoLaborable::FERIADO,
            'motivo'     => 'Feriado regional',
            'medico'     => $this->clave($medico),
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda/nueva?fecha=' . $this->lunes->toDateString());

        $respuesta->assertOk();
        $respuesta->assertSee('no atiende');
        $respuesta->assertSee('Feriado regional');
    }

    public function test_la_agenda_marca_el_dia_no_laborable_sin_bloquear_la_vista(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        DiaNoLaborable::create([
            'reg_medico' => $medico->reg_medico,
            'dia'        => $this->lunes->toDateString(),
            'tipo'       => DiaNoLaborable::FERIADO,
            'motivo'     => 'Feriado regional',
            'medico'     => $this->clave($medico),
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda?vista=dia&fecha=' . $this->lunes->toDateString() . '&sede=todas');

        $respuesta->assertOk();
        $respuesta->assertSee('Día no laborable');
        $respuesta->assertSee('Feriado regional');
    }

    public function test_agendar_en_un_dia_no_laborable_avisa_pero_no_bloquea(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = Office::create([
            'medical_center_id' => $this->centro('San José')->id,
            'medico_id'         => $medico->id,
            'reg_medico'        => $medico->reg_medico,
            'office_number'     => 'C1',
            'modalidad'         => Office::MODALIDAD_HORA,
            'duracion_cita'     => 30,
            'activo'            => true,
        ]);
        $pacienteId = $this->pacienteConHistoria($medico, 3001, 'Ana', 'Alvarez');

        DiaNoLaborable::create([
            'reg_medico' => $medico->reg_medico,
            'dia'        => $this->lunes->toDateString(),
            'tipo'       => DiaNoLaborable::FERIADO,
            'motivo'     => 'Feriado regional',
            'medico'     => $this->clave($medico),
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda', [
                'sede'        => $sede->id,
                'fecha'       => $this->lunes->toDateString(),
                'hora'        => '09:00',
                'paciente_id' => $pacienteId,
            ]);

        // La cita se agenda igual (la decisión fue no copiar el aborto del escritorio)…
        $this->assertSame(1, Cola::where('reg_medico', $medico->reg_medico)->count());

        // …y el aviso viaja a la agenda, con el motivo del legado palabra por palabra.
        $respuesta->assertRedirect(route('clinica.agenda', [
            'vista' => 'dia',
            'fecha' => $this->lunes->toDateString(),
            'sede'  => $sede->id,
        ]));
        $respuesta->assertSessionHas('avisos', fn ($avisos) => (bool) array_filter(
            (array) $avisos,
            fn ($aviso) => str_contains($aviso, 'Feriado regional'),
        ));
    }

    public function test_la_marca_de_otro_medico_no_avisa_en_la_agenda(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();

        DiaNoLaborable::create([
            'reg_medico' => $medico->reg_medico,
            'dia'        => $this->lunes->toDateString(),
            'tipo'       => DiaNoLaborable::FERIADO,
            'motivo'     => 'Feriado regional',
            'medico'     => $this->clave($otro),
        ]);

        // La marca es del consultorio y del médico: la de otro médico del mismo consultorio no aplica.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda/nueva?fecha=' . $this->lunes->toDateString())
            ->assertOk()
            ->assertDontSee('Feriado regional');
    }

    public function test_la_vista_de_mes_marca_los_dias_no_laborables(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        DiaNoLaborable::create([
            'reg_medico' => $medico->reg_medico,
            'dia'        => '2026-10-12',
            'tipo'       => DiaNoLaborable::QUIRURGICO,
            'medico'     => $this->clave($medico),
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda?vista=mes&fecha=2026-10-01&sede=todas')
            ->assertOk()
            ->assertSee('Quirúrgico');
    }

    public function test_los_dias_de_otro_registro_no_aparecen_en_el_abm(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();

        DiaNoLaborable::create([
            'reg_medico' => $otro->reg_medico,
            'dia'        => '2026-10-12',
            'tipo'       => DiaNoLaborable::FERIADO,
            'motivo'     => 'Feriado del otro consultorio',
            'medico'     => $this->clave($otro),
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda/no-laborables?mes=2026-10')
            ->assertOk()
            ->assertDontSee('Feriado del otro consultorio');
    }

    public function test_el_motivo_es_opcional(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda/no-laborables', ['dia' => '2026-10-12', 'tipo' => DiaNoLaborable::OTRO_CONSULTORIO])
            ->assertRedirect(route('clinica.agenda.no-laborables', ['mes' => '2026-10']));

        $dia = DiaNoLaborable::where('reg_medico', $medico->reg_medico)->firstOrFail();

        $this->assertNull($dia->motivo);
        $this->assertSame(DiaNoLaborable::OTRO_CONSULTORIO, $dia->tipo);
    }

    public function test_un_tipo_fuera_del_dominio_del_legado_se_rechaza(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda/no-laborables', ['dia' => '2026-10-12', 'tipo' => 'X'])
            ->assertSessionHasErrors('tipo');

        $this->assertSame(0, DiaNoLaborable::where('reg_medico', $medico->reg_medico)->count());
    }
}
