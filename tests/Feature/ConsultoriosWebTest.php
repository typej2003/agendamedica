<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Consultorios del médico (PLAN-WEB.md, F2 / WEB-2.8b.2).
 *
 * El consultorio es **de un médico** (a diferencia de la sede, que es del catálogo de la clínica):
 * `reg_medico` y `medico_id` salen del contexto de trabajo y de ahí depende que el **móvil** lo vea
 * —su delta filtra por `reg_medico`— y que la agenda pueda calcular la jornada con su modalidad.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class ConsultoriosWebTest extends TestCase
{
    use DatabaseTransactions;

    // --------------------------------------------------------------------------------------- Ayudas

    private function medicoConAcceso(string $rol = 'Medico'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'consultorios-' . substr(uniqid(), -6);

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'consultorios-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Consultorios',
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

    private function sede(string $nombre = 'Sede de prueba', bool $activa = true): MedicalCenter
    {
        return MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id'   => City::firstOrFail()->state_id,
            'city_id'    => City::firstOrFail()->id,
            'name'       => $nombre . ' ' . substr(uniqid(), -6),
            'address'    => 'Av. Principal',
            'activo'     => $activa,
        ]);
    }

    private function datosDeConsultorio(MedicalCenter $sede, array $extra = []): array
    {
        return array_merge([
            'medical_center_id' => $sede->id,
            'office_number'     => 'C' . random_int(100, 999),
            'modalidad'         => Office::MODALIDAD_ORDEN,
            'duracion_cita'     => 30,
        ], $extra);
    }

    // ----------------------------------------------------------------------------------------- ABM

    public function test_un_invitado_no_entra(): void
    {
        $this->get('/clinica/consultorios')->assertRedirect(route('login'));
    }

    public function test_sin_contexto_no_se_puede_administrar_consultorios(): void
    {
        // El consultorio es de un médico: sin saber cuál, la pantalla no tiene sujeto.
        [$user] = $this->medicoConAcceso();

        $this->actingAs($user)->get('/clinica/consultorios')->assertRedirect(route('clinica.contexto'));
    }

    public function test_la_pantalla_lista_los_consultorios_del_medico(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede('San José');

        Office::create([
            'medical_center_id' => $sede->id,
            'medico_id'         => $medico->id,
            'reg_medico'        => $medico->reg_medico,
            'office_number'     => '701',
            'modalidad'         => Office::MODALIDAD_ORDEN,
            'duracion_cita'     => 20,
            'activo'            => true,
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/consultorios');

        $respuesta->assertOk();
        $respuesta->assertSee($sede->name);
        $respuesta->assertSee('701');
        $respuesta->assertSee('Orden de llegada');
        $respuesta->assertSee('20 min');
        // Los bloques se editan en la misma fila (A-10): sin bloques, la fila lo dice.
        $respuesta->assertSee('Todavía no tiene bloques');
    }

    public function test_crear_un_consultorio_escribe_el_registro_y_el_dueno_del_contexto(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede();
        $datos = $this->datosDeConsultorio($sede, ['office_number' => 'C1', 'duracion_cita' => 15]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/consultorios', $datos)
            ->assertRedirect(route('clinica.consultorios'));

        $consultorio = Office::where('office_number', 'C1')->firstOrFail();

        // Lo que hace que el móvil lo vea (su delta filtra por `reg_medico`) y que la agenda lo use.
        $this->assertSame($medico->reg_medico, $consultorio->reg_medico);
        $this->assertSame($medico->id, (int) $consultorio->medico_id);
        $this->assertSame($sede->id, (int) $consultorio->medical_center_id);
        $this->assertSame(Office::MODALIDAD_ORDEN, $consultorio->modalidad);
        $this->assertSame(15, (int) $consultorio->duracion_cita);
        $this->assertTrue($consultorio->activo);
    }

    public function test_la_modalidad_y_el_numero_son_obligatorios(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/consultorios', ['medical_center_id' => $this->sede()->id])
            ->assertSessionHasErrors(['office_number', 'modalidad']);
    }

    public function test_no_se_carga_un_consultorio_en_una_sede_desactivada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $cerrada = $this->sede('Sede cerrada', activa: false);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/consultorios', $this->datosDeConsultorio($cerrada))
            ->assertSessionHasErrors('medical_center_id');

        $this->assertSame(0, Office::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_la_duracion_es_opcional_y_queda_nula(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/consultorios', $this->datosDeConsultorio($sede, ['duracion_cita' => '']))
            ->assertRedirect();

        $consultorio = Office::where('reg_medico', $medico->reg_medico)->firstOrFail();

        // Sin default inventado: el esquema la admite nula y el móvil ya cae a su propio valor.
        $this->assertNull($consultorio->duracion_cita);
    }

    public function test_editar_cambia_la_modalidad_sin_crear_otro(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede();
        $otraSede = $this->sede('Otra sede');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/consultorios', $this->datosDeConsultorio($sede, ['office_number' => 'C1']))
            ->assertRedirect();

        $consultorio = Office::where('reg_medico', $medico->reg_medico)->firstOrFail();
        $antes = Office::where('reg_medico', $medico->reg_medico)->count();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/consultorios', [
                'id'                => $consultorio->id,
                'medical_center_id' => $otraSede->id,
                'office_number'     => 'C9',
                'modalidad'         => Office::MODALIDAD_HORA,
            ])
            ->assertRedirect(route('clinica.consultorios'));

        $this->assertSame($antes, Office::where('reg_medico', $medico->reg_medico)->count());

        $consultorio->refresh();
        $this->assertSame(Office::MODALIDAD_HORA, $consultorio->modalidad);
        $this->assertSame('C9', $consultorio->office_number);
        $this->assertSame($otraSede->id, (int) $consultorio->medical_center_id);
    }

    public function test_el_formulario_de_edicion_llega_con_el_consultorio_cargado(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/consultorios', $this->datosDeConsultorio($sede, ['office_number' => 'C7']))
            ->assertRedirect();

        $consultorio = Office::where('reg_medico', $medico->reg_medico)->firstOrFail();

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/consultorios?editar=' . $consultorio->id);

        $respuesta->assertOk();
        $respuesta->assertSee('Editar el consultorio');
        $respuesta->assertSee('C7');
    }

    // ------------------------------------------------------------------------------- Activar y bajar

    public function test_desactivar_un_consultorio_no_lo_borra_y_deja_de_ofrecerse(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/consultorios', $this->datosDeConsultorio($sede, ['office_number' => 'C1']))
            ->assertRedirect();

        $consultorio = Office::where('reg_medico', $medico->reg_medico)->firstOrFail();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->patch('/clinica/consultorios/' . $consultorio->id . '/activar')
            ->assertRedirect(route('clinica.consultorios'));

        // Se conserva la fila —la cita guarda la modalidad y la duración con las que se calculó su
        // jornada— y sólo deja de ofrecerse.
        $this->assertFalse($consultorio->refresh()->activo);
        $this->assertDatabaseHas('offices', ['id' => $consultorio->id]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/consultorios')
            ->assertDontSee('C1');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/consultorios?inactivos=1')
            ->assertSee('C1')
            ->assertSee('Inactivo');
    }

    public function test_el_consultorio_de_otro_medico_no_se_toca(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();

        $ajeno = Office::create([
            'medical_center_id' => $this->sede()->id,
            'medico_id'         => $otro->id,
            'reg_medico'        => $otro->reg_medico,
            'office_number'     => 'AJENO',
            'modalidad'         => Office::MODALIDAD_HORA,
            'activo'            => true,
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->patch('/clinica/consultorios/' . $ajeno->id . '/activar')
            ->assertNotFound();

        $this->assertTrue($ajeno->refresh()->activo);

        // Y tampoco se puede editar: el id ajeno no está entre los del médico del contexto.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/consultorios', $this->datosDeConsultorio($this->sede(), ['id' => $ajeno->id, 'office_number' => 'PISADO']))
            ->assertNotFound();

        $this->assertSame('AJENO', $ajeno->refresh()->office_number);
    }

    public function test_los_consultorios_de_otro_medico_no_se_listan(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();

        Office::create([
            'medical_center_id' => $this->sede()->id,
            'medico_id'         => $otro->id,
            'reg_medico'        => $otro->reg_medico,
            'office_number'     => 'DEL-OTRO',
            'modalidad'         => Office::MODALIDAD_HORA,
            'activo'            => true,
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/consultorios?inactivos=1')
            ->assertOk()
            ->assertDontSee('DEL-OTRO');
    }

    public function test_la_secretaria_puede_cargar_el_consultorio_del_medico_que_atiende(): void
    {
        // La secretaria es quien carga los horarios del consultorio en la vida real.
        [$secretaria, $medico, $gineco] = $this->medicoConAcceso('Secretaria');
        MedicoRegistro::where('medico_id', $medico->id)->update(['user_id' => $secretaria->id]);

        $sede = $this->sede();

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->post('/clinica/consultorios', $this->datosDeConsultorio($sede, ['office_number' => 'SEC1']))
            ->assertRedirect(route('clinica.consultorios'));

        $consultorio = Office::where('office_number', 'SEC1')->firstOrFail();

        // Se escribe el registro y el dueño del contexto, no los de la secretaria (que no tiene ficha).
        $this->assertSame($medico->reg_medico, $consultorio->reg_medico);
        $this->assertSame($medico->id, (int) $consultorio->medico_id);
    }

    public function test_el_consultorio_nuevo_queda_disponible_en_la_agenda(): void
    {
        // Es el objetivo del paso: un médico sin consultorios no podía agendar. Al cargarlo, la sede
        // aparece en la agenda con su modalidad.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede('Hospital Central');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda')
            ->assertOk()
            ->assertDontSee($sede->name);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/consultorios', $this->datosDeConsultorio($sede, [
                'office_number' => 'C1',
                'modalidad'     => Office::MODALIDAD_HORA,
            ]))
            ->assertRedirect();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda')
            ->assertOk()
            ->assertSee($sede->name)
            ->assertSee('C1');
    }

    // ------------------------------------------------------- Los bloques de horario, en la misma fila (A-10)

    /**
     * La duración de la cita y los horarios estaban cada uno en su pantalla y el médico no los encontraba
     * juntos: desde A-10 la fila del consultorio los lista y los edita ahí mismo.
     */
    public function test_la_fila_muestra_los_bloques_de_horario_del_consultorio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede('San José');
        $consultorio = $this->consultorioDe($medico, $sede, '701');

        OfficeSchedule::create([
            'office_id' => $consultorio->id, 'reg_medico' => $medico->reg_medico,
            'dia_semana' => 3, 'hora_inicio' => '14:00', 'hora_fin' => '18:00', 'cupo' => 12,
        ]);
        OfficeSchedule::create([
            'office_id' => $consultorio->id, 'reg_medico' => $medico->reg_medico,
            'dia_semana' => 1, 'hora_inicio' => '08:00', 'hora_fin' => '12:00',
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/consultorios');

        $respuesta->assertOk();
        // El orden es el de la semana (lunes antes que miércoles), no el de la carga.
        $respuesta->assertSeeInOrder(['Lunes', '8:00 AM a 12:00 PM', 'sin tope', 'Miércoles', '2:00 PM a 6:00 PM', '12 pacientes'], false);
        // Las horas se escriben con AM/PM (campo de texto) y viajan en 24 h (hidden con el `name` de siempre).
        $respuesta->assertDontSee('type="time"', false);
        $respuesta->assertSee('data-hora-ampm="bloque-desde-' . $consultorio->id . '-valor"', false);
        $respuesta->assertSee('<input type="hidden" name="hora_fin" id="bloque-hasta-' . $consultorio->id . '-valor" value="">', false);
        // Y el alta de un bloque está en la misma fila, con las rutas de siempre.
        $respuesta->assertSee(route('clinica.consultorios.horarios.guardar', $consultorio) . '"', false);
        $respuesta->assertSee('name="volver" value="consultorios"', false);
    }

    public function test_agregar_un_bloque_desde_el_listado_vuelve_al_listado(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consultorio = $this->consultorioDe($medico, $this->sede(), 'C1');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post(
            '/clinica/consultorios/' . $consultorio->id . '/horarios',
            [
                'volver'      => 'consultorios',
                'consultorio' => $consultorio->id,
                'dia_semana'  => 1,
                'hora_inicio' => '08:00',
                'hora_fin'    => '12:00',
                'cupo'        => 20,
            ],
        );

        // La vuelta es al listado (de donde vino el formulario), no a la pantalla de horarios.
        $respuesta->assertRedirect(route('clinica.consultorios'));
        $respuesta->assertSessionHas('estado');
        $this->assertDatabaseHas('office_schedules', [
            'office_id'  => $consultorio->id,
            'reg_medico' => $medico->reg_medico,
            'dia_semana' => 1,
            'cupo'       => 20,
        ]);
    }

    public function test_quitar_un_bloque_desde_el_listado_vuelve_al_listado(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consultorio = $this->consultorioDe($medico, $this->sede(), 'C1');
        $bloque = OfficeSchedule::create([
            'office_id' => $consultorio->id, 'reg_medico' => $medico->reg_medico,
            'dia_semana' => 2, 'hora_inicio' => '08:00', 'hora_fin' => '12:00',
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->delete(
            '/clinica/consultorios/' . $consultorio->id . '/horarios/' . $bloque->id,
            ['volver' => 'consultorios'],
        );

        $respuesta->assertRedirect(route('clinica.consultorios'));
        $this->assertDatabaseMissing('office_schedules', ['id' => $bloque->id]);
    }

    /** «Lunes a viernes de 8 a 13» se carga de una vez: un bloque por día encendido, con el mismo horario y cupo. */
    public function test_encender_varios_dias_crea_un_bloque_por_dia(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consultorio = $this->consultorioDe($medico, $this->sede(), 'C1');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post(
            '/clinica/consultorios/' . $consultorio->id . '/horarios',
            ['dias' => [1, 2, 3, 4, 5], 'hora_inicio' => '08:00', 'hora_fin' => '13:00', 'cupo' => 15],
        );

        $respuesta->assertRedirect(route('clinica.consultorios.horarios', $consultorio));
        $respuesta->assertSessionHas('estado', fn ($texto) => str_contains($texto, '5 bloques') && str_contains($texto, 'viernes'));
        $this->assertSame(
            [1, 2, 3, 4, 5],
            OfficeSchedule::where('office_id', $consultorio->id)->orderBy('dia_semana')->pluck('dia_semana')->map(fn ($d) => (int) $d)->all(),
        );
        $this->assertSame(5, OfficeSchedule::where('office_id', $consultorio->id)->where('hora_fin', 'like', '13:00%')->where('cupo', 15)->count());
    }

    /** Es todo o nada: si un día se pisa con un bloque del mismo consultorio no se guarda ninguno y se nombra el día. */
    public function test_si_un_dia_se_pisa_no_se_guarda_ninguno(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consultorio = $this->consultorioDe($medico, $this->sede(), 'C1');
        OfficeSchedule::create([
            'office_id' => $consultorio->id, 'reg_medico' => $medico->reg_medico,
            'dia_semana' => 3, 'hora_inicio' => '10:00', 'hora_fin' => '12:00',
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->from('/clinica/consultorios/' . $consultorio->id . '/horarios')
            ->post('/clinica/consultorios/' . $consultorio->id . '/horarios', [
                'dias' => [1, 2, 3], 'hora_inicio' => '08:00', 'hora_fin' => '13:00',
            ]);

        $respuesta->assertSessionHasErrors('hora_inicio');
        $this->assertStringContainsString('Miércoles', session('errors')->first('hora_inicio'));
        $this->assertSame(1, OfficeSchedule::where('office_id', $consultorio->id)->count(), 'el lunes y el martes no quedaron');
    }

    public function test_sin_ningun_dia_encendido_se_pide_elegir_al_menos_uno(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consultorio = $this->consultorioDe($medico, $this->sede(), 'C1');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->from('/clinica/consultorios/' . $consultorio->id . '/horarios')
            ->post('/clinica/consultorios/' . $consultorio->id . '/horarios', ['hora_inicio' => '08:00', 'hora_fin' => '13:00'])
            ->assertSessionHasErrors('dias');

        $this->assertSame(0, OfficeSchedule::where('office_id', $consultorio->id)->count());
    }

    public function test_la_pantalla_ofrece_siete_botones_de_dos_letras_y_el_alta_en_linea_tambien(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consultorio = $this->consultorioDe($medico, $this->sede(), 'C1');

        $pantalla = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/consultorios/' . $consultorio->id . '/horarios')->assertOk();
        $listado = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/consultorios')->assertOk();

        foreach ([$pantalla, $listado] as $r) {
            $html = $r->getContent();
            $this->assertSame(7, substr_count($html, 'name="dias[]"'));
            foreach (['Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sa', 'Do'] as $dos) {
                $this->assertStringContainsString('>' . $dos . '</label>', $html);
            }
            $this->assertStringNotContainsString('<select name="dia_semana"', $html);
        }
    }

    /** Un bloque que ya existe es de un solo día: al editarlo los botones son de radio y llevan su día. */
    public function test_editar_un_bloque_muestra_su_dia_como_un_solo_boton_encendido(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consultorio = $this->consultorioDe($medico, $this->sede(), 'C1');
        $bloque = OfficeSchedule::create([
            'office_id' => $consultorio->id, 'reg_medico' => $medico->reg_medico,
            'dia_semana' => 4, 'hora_inicio' => '08:00', 'hora_fin' => '12:00',
        ]);

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/consultorios/' . $consultorio->id . '/horarios?editar=' . $bloque->id)
            ->assertOk()->getContent();

        $this->assertSame(7, substr_count($html, 'type="radio" class="btn-check" name="dia_semana"'));
        $this->assertSame(1, preg_match_all('/<input[^>]*checked/', $html), 'un solo día encendido');
        $this->assertSame(1, preg_match('/id="dia-4"[^>]*checked/', $html), 'el jueves');
    }

    /** La regla del solape es la de `GuardarBloqueHorario`: desde el listado se comporta igual. */
    public function test_desde_el_listado_el_solape_del_mismo_consultorio_se_rechaza(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consultorio = $this->consultorioDe($medico, $this->sede(), 'C1');
        OfficeSchedule::create([
            'office_id' => $consultorio->id, 'reg_medico' => $medico->reg_medico,
            'dia_semana' => 1, 'hora_inicio' => '08:00', 'hora_fin' => '12:00',
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->from('/clinica/consultorios')
            ->post('/clinica/consultorios/' . $consultorio->id . '/horarios', [
                'volver'      => 'consultorios',
                'consultorio' => $consultorio->id,
                'dia_semana'  => 1,
                'hora_inicio' => '11:00',
                'hora_fin'    => '15:00',
            ]);

        $respuesta->assertSessionHasErrors('hora_inicio');
        $this->assertSame(1, OfficeSchedule::where('office_id', $consultorio->id)->count());
    }

    /** El cruce con **otro** consultorio del mismo médico avisa y guarda igual (decisión del 2026-10-09). */
    public function test_desde_el_listado_el_solape_con_otro_consultorio_avisa_y_guarda(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $manana = $this->consultorioDe($medico, $this->sede('Hospital'), 'A');
        $tarde = $this->consultorioDe($medico, $this->sede('Clínica'), 'B');

        OfficeSchedule::create([
            'office_id' => $manana->id, 'reg_medico' => $medico->reg_medico,
            'dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '11:00',
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/consultorios/' . $tarde->id . '/horarios', [
                'volver'      => 'consultorios',
                'consultorio' => $tarde->id,
                'dia_semana'  => 1,
                'hora_inicio' => '08:00',
                'hora_fin'    => '12:00',
            ]);

        $respuesta->assertSessionHasNoErrors();
        $respuesta->assertSessionHas('avisos');
        $this->assertDatabaseHas('office_schedules', ['office_id' => $tarde->id, 'dia_semana' => 1]);
    }

    /**
     * El alta en línea es **un formulario por consultorio** y todos mandan los mismos nombres: lo escrito
     * sólo se repone en el que lo mandó (hay uno por fila y sin el `consultorio` oculto aparecería en todos).
     */
    public function test_el_alta_en_linea_repone_lo_escrito_solo_en_su_consultorio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $uno = $this->consultorioDe($medico, $this->sede('San José'), '701');
        $dos = $this->consultorioDe($medico, $this->sede('San José'), '702');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->from('/clinica/consultorios')
            ->post('/clinica/consultorios/' . $uno->id . '/horarios', [
                'volver'      => 'consultorios',
                'consultorio' => $uno->id,
                'dia_semana'  => 2,
                'hora_inicio' => '10:00',
                'hora_fin'    => '08:00',   // la hora de fin no es posterior: falla la validación
            ])
            ->assertSessionHasErrors('hora_fin');

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/consultorios')->assertOk()->getContent();

        preg_match('/id="bloque-desde-' . $uno->id . '-valor"[^>]*value="([^"]*)"/', $html, $suyo);
        preg_match('/id="bloque-desde-' . $dos->id . '-valor"[^>]*value="([^"]*)"/', $html, $ajeno);

        $this->assertSame('10:00', $suyo[1] ?? null, 'El consultorio que mandó el alta tiene que reponer lo escrito.');
        $this->assertSame('', $ajeno[1] ?? 'x', 'El otro consultorio no tiene que heredar lo escrito.');
    }

    /** La duración de la cita queda donde estaba, pero la ayuda dice para qué sirve y no promete de más. */
    public function test_la_duracion_de_la_cita_explica_para_que_sirve(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/consultorios');

        $respuesta->assertOk();
        // Sigue en su campo, con su rótulo de siempre.
        $respuesta->assertSee('Duración de la cita');
        $respuesta->assertSee('name="duracion_cita"', false);
        // Y la ayuda dice qué hace y de dónde sale el tope de la jornada (del cupo del bloque).
        $respuesta->assertSee('Lo que dura una cita en este consultorio', false);
        $respuesta->assertSee('tope de pacientes de cada jornada lo pone el cupo', false);
    }

    /** Un consultorio del médico, para los casos de los bloques. */
    private function consultorioDe(Medico $medico, MedicalCenter $sede, string $numero): Office
    {
        return Office::create([
            'medical_center_id' => $sede->id,
            'medico_id'         => $medico->id,
            'reg_medico'        => $medico->reg_medico,
            'office_number'     => $numero,
            'modalidad'         => Office::MODALIDAD_ORDEN,
            'duracion_cita'     => 30,
            'activo'            => true,
        ]);
    }

    // ---------------------------------------------------------------------------- El menú lateral (A-10)

    /**
     * La barra superior «Consultorios» desaparece si no hay consultorio elegido, así que los horarios y los
     * días no laborables tienen que estar en el menú lateral: es lo que el médico no encontraba.
     */
    public function test_el_menu_lateral_ofrece_los_horarios_y_los_dias_no_laborables(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/consultorios')->assertOk()->getContent();

        // El grupo «Configuración» existe, con las dos entradas nuevas y abierto (estamos en una de ellas).
        $this->assertStringContainsString('<details class="nav-grupo" data-menu="configuracion" open>', $html);
        preg_match('/data-menu="configuracion".*?<\/details>/s', $html, $bloque);
        $grupo = $bloque[0] ?? '';
        $this->assertStringContainsString('Consultorios y horarios', $grupo);
        $this->assertStringContainsString('Días no laborables', $grupo);
        $this->assertStringContainsString('href="' . route('clinica.consultorios') . '"', $grupo);
        $this->assertStringContainsString('href="' . route('clinica.agenda.no-laborables') . '"', $grupo);
        // Y el ítem de los horarios es el activo de la página.
        $this->assertMatchesRegularExpression('/nav-link active[^>]*title="Consultorios y horarios"/', $grupo);

        // La barra superior conserva «Consultorios» como atajo.
        $this->assertStringContainsString('href="' . route('clinica.consultorios') . '"', $html);
    }

    /**
     * «Configuración» era sólo del médico, pero los horarios y los días no laborables los carga también la
     * secretaría (lo dicen los roles de sus rutas): ve esas dos entradas y ninguna de las que le dan 403.
     */
    public function test_la_secretaria_ve_en_configuracion_solo_lo_que_puede_abrir(): void
    {
        [$secretaria, $medico, $gineco] = $this->medicoConAcceso('Secretaria');
        MedicoRegistro::where('medico_id', $medico->id)->update(['user_id' => $secretaria->id]);

        $html = $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->get('/clinica/consultorios')->assertOk()->getContent();

        preg_match('/data-menu="configuracion".*?<\/details>/s', $html, $bloque);
        $grupo = $bloque[0] ?? '';

        $this->assertStringContainsString('Consultorios y horarios', $grupo);
        $this->assertStringContainsString('Días no laborables', $grupo);
        // Lo que la secretaría no administra no se le ofrece.
        $this->assertStringNotContainsString('Formato de impresión', $grupo);
        $this->assertStringNotContainsString('Usuarios', $grupo);
        $this->assertStringNotContainsString('Bitácora', $grupo);
    }
}
