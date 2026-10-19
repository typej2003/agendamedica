<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Cola;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Bloques de horario de un consultorio (PLAN-WEB.md, F2 / WEB-2.8b.3).
 *
 * Lo que se prueba es la regla que el paso agregó —el solape **dentro** del consultorio se rechaza y
 * el de **otro consultorio del mismo médico avisa pero no bloquea** (decisión del 2026-10-09)— y que
 * el bloque cargado es de verdad la jornada que ve la agenda (cupo incluido). El borrado deja rastro
 * en `sync_changes` para que el móvil deje de mostrarlo. `DatabaseTransactions`, como el resto de los
 * tests de la web clínica.
 */
class HorariosDeConsultorioWebTest extends TestCase
{
    use DatabaseTransactions;

    // --------------------------------------------------------------------------------------- Ayudas

    private function medicoConAcceso(string $rol = 'Medico'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'horarios-test' . substr(uniqid(), -6);

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'horarios-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Horarios',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico'   => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id'    => null,
        ]]);
    }

    private function sede(Medico $medico, string $nombre = 'Sede de prueba', string $numero = 'C1'): Office
    {
        $centro = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id'   => City::firstOrFail()->state_id,
            'city_id'    => City::firstOrFail()->id,
            'name'       => $nombre . ' ' . substr(uniqid(), -6),
            'address'    => 'Av. Principal',
            'activo'     => true,
        ]);

        return Office::create([
            'medical_center_id' => $centro->id,
            'medico_id'         => $medico->id,
            'reg_medico'        => $medico->reg_medico,
            'office_number'     => $numero,
            'modalidad'         => Office::MODALIDAD_ORDEN,
            'duracion_cita'     => 30,
            'activo'            => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function datosDeBloque(array $extra = []): array
    {
        return array_merge([
            'dia_semana'  => 1,          // lunes
            'hora_inicio' => '08:00',
            'hora_fin'    => '12:00',
            'cupo'        => null,
        ], $extra);
    }

    private function urlHorarios(Office $consultorio, array $query = []): string
    {
        $url = '/clinica/consultorios/' . $consultorio->id . '/horarios';

        return $query === [] ? $url : $url . '?' . http_build_query($query);
    }

    // ----------------------------------------------------------------------------------------- ABM

    public function test_un_invitado_no_entra(): void
    {
        [$user, $medico] = $this->medicoConAcceso();
        $consultorio = $this->sede($medico);

        $this->get($this->urlHorarios($consultorio))->assertRedirect(route('login'));
    }

    public function test_sin_contexto_no_se_puede_administrar_horarios(): void
    {
        // Los bloques son de un consultorio de un médico: sin contexto no hay sujeto.
        [$user, $medico] = $this->medicoConAcceso();
        $consultorio = $this->sede($medico);

        $this->actingAs($user)->get($this->urlHorarios($consultorio))->assertRedirect(route('clinica.contexto'));
    }

    public function test_un_consultorio_de_otro_medico_da_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $ajeno = $this->sede($otro, 'Sede ajena', 'AJENO');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlHorarios($ajeno))
            ->assertNotFound();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlHorarios($ajeno), $this->datosDeBloque())
            ->assertNotFound();
    }

    public function test_la_pantalla_lista_los_bloques_por_dia(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consultorio = $this->sede($medico, 'San José', '701');

        OfficeSchedule::create([
            'office_id' => $consultorio->id, 'reg_medico' => $medico->reg_medico,
            'dia_semana' => 3, 'hora_inicio' => '14:00', 'hora_fin' => '18:00', 'cupo' => 12,
        ]);
        OfficeSchedule::create([
            'office_id' => $consultorio->id, 'reg_medico' => $medico->reg_medico,
            'dia_semana' => 1, 'hora_inicio' => '08:00', 'hora_fin' => '12:00',
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlHorarios($consultorio));

        $respuesta->assertOk();
        $respuesta->assertSee('San José');
        $respuesta->assertSee('Lunes');
        $respuesta->assertSee('Miércoles');
        $respuesta->assertSee('08:00 a 12:00');
        $respuesta->assertSee('14:00 a 18:00');
        $respuesta->assertSee('12 pacientes');
        $respuesta->assertSee('sin tope');   // el bloque sin cupo no inventa un tope
    }

    public function test_agregar_un_bloque_lo_guarda_con_el_registro_del_contexto(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consultorio = $this->sede($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlHorarios($consultorio), $this->datosDeBloque(['cupo' => 20]));

        $respuesta->assertRedirect(route('clinica.consultorios.horarios', $consultorio));
        $respuesta->assertSessionHas('estado');

        $this->assertDatabaseHas('office_schedules', [
            'office_id'   => $consultorio->id,
            'reg_medico'  => $medico->reg_medico,
            'dia_semana'  => 1,
            'cupo'        => 20,
        ]);
    }

    public function test_el_solape_dentro_del_mismo_consultorio_se_rechaza(): void
    {
        // Dos bloques que se pisan el mismo día dejan la jornada ambigua: la cita cae en los dos y
        // el cupo se contaría dos veces. No es un caso a decidir por el usuario.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consultorio = $this->sede($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlHorarios($consultorio), $this->datosDeBloque());

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlHorarios($consultorio), $this->datosDeBloque(['hora_inicio' => '11:00', 'hora_fin' => '15:00']));

        $respuesta->assertSessionHasErrors('hora_inicio');
        $this->assertSame(1, OfficeSchedule::where('office_id', $consultorio->id)->count());
    }

    public function test_bloques_contiguos_no_se_pisan(): void
    {
        // El fin es exclusivo: 8-12 y 12-16 son la misma jornada partida, no un solape.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consultorio = $this->sede($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlHorarios($consultorio), $this->datosDeBloque());

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlHorarios($consultorio), $this->datosDeBloque(['hora_inicio' => '12:00', 'hora_fin' => '16:00']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, OfficeSchedule::where('office_id', $consultorio->id)->count());
    }

    public function test_el_solape_con_otro_consultorio_del_mismo_medico_avisa_pero_guarda(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $manana = $this->sede($medico, 'Hospital', 'A');
        $tarde = $this->sede($medico, 'Clínica', 'B');

        OfficeSchedule::create([
            'office_id' => $manana->id, 'reg_medico' => $medico->reg_medico,
            'dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '11:00',
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlHorarios($tarde), $this->datosDeBloque());

        $respuesta->assertSessionHasNoErrors();
        $respuesta->assertSessionHas('avisos');
        $avisos = session('avisos');
        $this->assertStringContainsString('Hospital', $avisos[0]);
        $this->assertStringContainsString('Queda guardado igual', $avisos[0]);

        // El aviso no frena: el bloque quedó guardado.
        $this->assertDatabaseHas('office_schedules', ['office_id' => $tarde->id, 'dia_semana' => 1]);
    }

    public function test_no_avisa_por_el_bloque_de_un_consultorio_de_otro_medico(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $propio = $this->sede($medico, 'Propia', 'P');
        $ajeno = $this->sede($otro, 'Ajena', 'X');

        OfficeSchedule::create([
            'office_id' => $ajeno->id, 'reg_medico' => $otro->reg_medico,
            'dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '11:00',
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlHorarios($propio), $this->datosDeBloque());

        $this->assertSame([], session('avisos'));
        $this->assertDatabaseHas('office_schedules', ['office_id' => $propio->id, 'dia_semana' => 1]);
    }

    public function test_la_hora_de_fin_tiene_que_ser_posterior_a_la_de_inicio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consultorio = $this->sede($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlHorarios($consultorio), $this->datosDeBloque(['hora_inicio' => '12:00', 'hora_fin' => '08:00']));

        $respuesta->assertSessionHasErrors('hora_fin');
        $this->assertSame(0, OfficeSchedule::where('office_id', $consultorio->id)->count());
    }

    public function test_editar_un_bloque_no_se_pisa_a_si_mismo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consultorio = $this->sede($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlHorarios($consultorio), $this->datosDeBloque());

        $bloque = OfficeSchedule::where('office_id', $consultorio->id)->firstOrFail();

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlHorarios($consultorio), $this->datosDeBloque([
                'id'   => $bloque->id,
                'cupo' => 15,
            ]));

        $respuesta->assertSessionHasNoErrors();
        $this->assertSame(1, OfficeSchedule::where('office_id', $consultorio->id)->count());
        $this->assertSame(15, OfficeSchedule::find($bloque->id)->cupo);
    }

    public function test_un_bloque_de_otro_consultorio_no_se_edita_desde_este(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $uno = $this->sede($medico, 'Uno', 'U');
        $dos = $this->sede($medico, 'Dos', 'D');

        $bloqueDeDos = OfficeSchedule::create([
            'office_id' => $dos->id, 'reg_medico' => $medico->reg_medico,
            'dia_semana' => 2, 'hora_inicio' => '08:00', 'hora_fin' => '12:00',
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlHorarios($uno), $this->datosDeBloque(['id' => $bloqueDeDos->id, 'dia_semana' => 2]))
            ->assertNotFound();

        // Sigue siendo del otro consultorio y sin tocar.
        $this->assertSame($dos->id, OfficeSchedule::find($bloqueDeDos->id)->office_id);
    }

    public function test_quitar_un_bloque_deja_rastro_para_el_movil(): void
    {
        // El delta del móvil manda la tabla por `updated_at`: una fila borrada sólo baja como
        // "eliminado", y eso lo escribe el modelo.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consultorio = $this->sede($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlHorarios($consultorio), $this->datosDeBloque());

        $bloque = OfficeSchedule::where('office_id', $consultorio->id)->firstOrFail();

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->delete($this->urlHorarios($consultorio) . '/' . $bloque->id);

        $respuesta->assertRedirect(route('clinica.consultorios.horarios', $consultorio));
        $this->assertDatabaseMissing('office_schedules', ['id' => $bloque->id]);
        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'office_schedules',
            'record_id'  => $bloque->id,
            'operation'  => 'deleted',
        ]);
    }

    public function test_el_bloque_cargado_desde_la_web_es_la_jornada_que_ve_la_agenda(): void
    {
        // El objetivo del paso: lo que se carga acá es lo que la agenda usa para el cupo y la
        // apertura del bloque (misma regla que probaba el sembrador, ahora por la pantalla).
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consultorio = $this->sede($medico, 'San José', '701');

        $lunes = Carbon::parse('2026-10-05')->startOfWeek(Carbon::MONDAY);
        $dia = $lunes->dayOfWeekIso;

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlHorarios($consultorio), $this->datosDeBloque(['dia_semana' => $dia, 'cupo' => 1]));

        $paciente = DB::table('pacientes')->insertGetId([
            'nombres' => 'Ana', 'apellidos' => 'Alvarez', 'cedula' => (string) random_int(10000000, 30000000),
            'sexo' => 'F', 'fnacimiento' => '1990-05-04', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('medico_pacientes')->insert([
            'medico_id' => $medico->id, 'paciente_id' => $paciente, 'numhistoria' => 1001,
            'reg_medico' => $medico->reg_medico, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Cola::create([
            'reg_medico' => $medico->reg_medico, 'fecha' => $lunes->toDateString(), 'hora_ini' => '08:00:00',
            'medical_center_id' => $consultorio->medical_center_id, 'numorden' => 1,
            'numhistoria' => 1001, 'atendido' => 0, 'estado' => 0,
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get(
            '/clinica/agenda?vista=dia&fecha=' . $lunes->toDateString() . '&sede=todas'
        );

        $respuesta->assertOk();
        $respuesta->assertSee('08:00 a 12:00');
        $respuesta->assertSee('Cupo completo');
    }

    /**
     * **Las dos pantallas son la misma tabla** (A-10): un bloque cargado desde la fila del listado de
     * consultorios aparece en la pantalla de horarios del consultorio, y al revés. La pantalla aparte se
     * conserva —para cargar muchos— y no puede quedar contando otra cosa.
     */
    public function test_un_bloque_cargado_desde_el_listado_se_ve_en_la_pantalla_de_horarios(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consultorio = $this->sede($medico, 'San José', '701');

        // Alta desde el listado (con su `volver`, que es lo único que cambia la vuelta).
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/consultorios/' . $consultorio->id . '/horarios', $this->datosDeBloque([
                'volver'      => 'consultorios',
                'consultorio' => $consultorio->id,
                'cupo'        => 8,
            ]))
            ->assertRedirect(route('clinica.consultorios'));

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlHorarios($consultorio));

        $respuesta->assertOk();
        $respuesta->assertSee('08:00 a 12:00');
        $respuesta->assertSee('8 pacientes');
    }

    /** Sin `volver` la vuelta sigue siendo la de siempre: la pantalla aparte y sus enlaces no cambiaron. */
    public function test_desde_su_pantalla_la_vuelta_sigue_siendo_la_pantalla_de_horarios(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consultorio = $this->sede($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlHorarios($consultorio), $this->datosDeBloque())
            ->assertRedirect(route('clinica.consultorios.horarios', $consultorio));

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/consultorios/' . $consultorio->id . '/horarios', $this->datosDeBloque([
                'volver'      => 'consultorios',
                'consultorio' => $consultorio->id,
                'dia_semana'  => 2,
            ]))
            ->assertRedirect(route('clinica.consultorios'));
    }
}
