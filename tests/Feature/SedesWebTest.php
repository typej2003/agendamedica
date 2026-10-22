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
 * Sedes de la web clínica (PLAN-WEB.md, F2 / WEB-2.8b.1).
 *
 * El **lugar** donde atiende un médico: lo que el escritorio tiene repartido entre `w_horarios` y la
 * tabla legada `clinicas`, y que hasta ahora sólo se sembraba. El lugar es del **catálogo de la
 * clínica** (sin `reg_medico`): "Clínica Metropolitana" es una sola para todos los médicos que
 * atienden ahí, y lo que cambia por médico es el consultorio (WEB-2.8b.2).
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class SedesWebTest extends TestCase
{
    use DatabaseTransactions;

    // --------------------------------------------------------------------------------------- Ayudas

    private function usuarioClinico(string $rol = 'Medico'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'sedes-test-' . substr(uniqid(), -6);

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'sedes-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Sedes',
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

    /** Una ciudad del catálogo sembrado, con su estado y su país. */
    private function ciudad(): City
    {
        return City::with('state')->firstOrFail();
    }

    private function datosDeSede(array $extra = []): array
    {
        return array_merge([
            'name'    => 'Sede de prueb' . substr(uniqid(), -6),
            'address' => 'Calle 1 con carrera 2',
            'phone'   => '0212-1234567',
            'city_id' => $this->ciudad()->id,
        ], $extra);
    }

    // ----------------------------------------------------------------------------------------- ABM

    public function test_un_invitado_no_entra(): void
    {
        $this->get('/clinica/sedes')->assertRedirect(route('login'));
    }

    public function test_la_pantalla_lista_las_sedes_activas(): void
    {
        [$user] = $this->usuarioClinico();

        $sede = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id'   => $this->ciudad()->state_id,
            'city_id'    => $this->ciudad()->id,
            'name'       => 'Clínica de pr' . substr(uniqid(), -6),
            'address'    => 'Av. Principal',
            'activo'     => true,
        ]);

        $respuesta = $this->actingAs($user)->get('/clinica/sedes');

        $respuesta->assertOk();
        $respuesta->assertSee('Sedes y consultorios');
        $respuesta->assertSee($sede->name);
        $respuesta->assertSee('Av. Principal');
    }

    public function test_la_lista_por_defecto_no_muestra_las_inactivas_pero_la_vista_todas_si(): void
    {
        [$user] = $this->usuarioClinico();

        $vieja = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id'   => $this->ciudad()->state_id,
            'city_id'    => $this->ciudad()->id,
            'name'       => 'Sede cerrada ' . substr(uniqid(), -6),
            'address'    => 'Calle vieja',
            'activo'     => false,
        ]);

        $this->actingAs($user)->get('/clinica/sedes')->assertOk()->assertDontSee($vieja->name);
        $this->actingAs($user)->get('/clinica/sedes?todas=1')->assertOk()->assertSee($vieja->name);
    }

    /**
     * **Sedes y consultorios** (2026-10-20): cada sede es una tarjeta con los consultorios del médico adentro y sus
     * horarios; las sedes donde el médico no tiene consultorio van plegadas en «Otras sedes de la clínica».
     */
    public function test_cada_sede_muestra_sus_consultorios_y_las_ajenas_van_plegadas(): void
    {
        [$user, $medico, $gineco] = $this->usuarioClinico();
        $conConsultorio = $this->sedePropia($medico, 'Con consultorio');
        OfficeSchedule::create([
            'office_id' => $conConsultorio->offices()->first()->id, 'reg_medico' => $medico->reg_medico,
            'dia_semana' => 1, 'hora_inicio' => '08:00', 'hora_fin' => '12:00', 'cupo' => 20,
        ]);
        $sinConsultorio = $this->sedeSuelta('Sede sin usar');

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/sedes')->assertOk()->getContent();

        $this->assertStringContainsString('Sedes y consultorios', $html);
        // La sede del médico va primero, con su consultorio, su modalidad y su horario.
        $this->assertMatchesRegularExpression(
            '/' . preg_quote(e($conConsultorio->name), '/') . '.*?sede-consultorio-nombre">C1<.*?Hora de cita.*?8:00 AM a 12:00 PM.*?20 pacientes.*?Otras sedes de la clínica \(\d+\).*?' . preg_quote(e($sinConsultorio->name), '/') . '/s',
            $html,
        );
        // Cada tarjeta ofrece agregarle un consultorio, con la sede ya elegida.
        $this->assertStringContainsString('data-panel="dialogo-consultorio" data-sede="' . $sinConsultorio->id . '"', $html);
        $this->assertStringContainsString(e(route('clinica.sedes', ['panel' => 'consultorio', 'en' => $sinConsultorio->id])), $html);
    }

    public function test_sin_contexto_la_pantalla_muestra_solo_las_sedes(): void
    {
        // «Sin contexto» es el caso en el que **hay algo que elegir**: con un solo registro la pantalla lo
        // resuelve sola desde el 2026-10-22 (lo cubre `ConsultorioSinContextoWebTest`). Acá el médico tiene dos
        // registros —el suyo y un centro—, así que no se inventa el dueño y la pantalla muestra el catálogo.
        [$user, $medico] = $this->usuarioClinico();
        $this->segundoRegistro($user, 'Centro sin contexto');
        $sede = $this->sedePropia($medico, 'Sede sin contexto');

        $html = $this->actingAs($user)->get('/clinica/sedes')->assertOk()->getContent();

        $this->assertStringContainsString(e($sede->name), $html);
        // Sin dueño resuelto no hay consultorios que mostrar ni panel para cargarlos, y se dice qué hacer.
        $this->assertStringNotContainsString('id="dialogo-consultorio"', $html);
        $this->assertStringNotContainsString('sede-consultorio-nombre', $html);
        $this->assertStringContainsString('id="dialogo-sede"', $html);
        $this->assertStringContainsString('Elegir consultorio aquí', $html);
    }

    public function test_sin_ninguna_sede_la_tarjeta_guia_ofrece_la_primera(): void
    {
        [$user, $medico, $gineco] = $this->usuarioClinico();
        MedicalCenter::query()->update(['activo' => false]);

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/sedes')->assertOk()->getContent();

        $this->assertStringContainsString('Empieza por tu primera sede', $html);
        // El botón de la tarjeta es la única acción principal: el encabezado no lo repite.
        $this->assertSame(1, substr_count($html, 'data-tour="sedes-nueva"'));
        $this->assertStringContainsString(e(route('clinica.sedes', ['panel' => 'sede'])), $html);
    }

    // ----------------------------------------------------------- Las rutas de antes y el menú

    public function test_la_ruta_vieja_de_consultorios_lleva_a_la_pantalla_unificada(): void
    {
        [$user, $medico, $gineco] = $this->usuarioClinico();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/consultorios')
            ->assertRedirect(route('clinica.sedes'));

        // Lo que pedía la ruta vieja se traduce: editar uno es su panel y «inactivos» es «Todas».
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/consultorios?editar=55&inactivos=1')
            ->assertRedirect(route('clinica.sedes', ['todas' => 1, 'consultorio' => 55]));
    }

    public function test_el_menu_tiene_una_sola_entrada_para_sedes_y_consultorios(): void
    {
        [$user, $medico, $gineco] = $this->usuarioClinico();

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/agenda')->assertOk()->getContent();

        $this->assertStringContainsString('Sedes y consultorios', $html);
        $this->assertStringContainsString('href="' . route('clinica.sedes') . '"', $html);
        $this->assertStringNotContainsString('href="' . route('clinica.consultorios') . '"', $html);
        $this->assertStringNotContainsString('Consultorios y horarios', $html);
    }

    // ----------------------------------------------------------- Crear la sede y la pregunta

    public function test_crear_una_sede_pregunta_si_agregar_un_consultorio_en_ella(): void
    {
        [$user, $medico, $gineco] = $this->usuarioClinico();
        $datos = $this->datosDeSede(['name' => 'Sede nueva ' . substr(uniqid(), -6)]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/sedes', $datos + ['formulario' => 'sede'])
            ->assertRedirect(route('clinica.sedes'))
            ->assertSessionHas('sede_creada');

        $sede = MedicalCenter::where('name', $datos['name'])->firstOrFail();
        $this->assertSame($sede->id, session('sede_creada'));

        $html = $this->get('/clinica/sedes')->assertOk()->getContent();

        $this->assertStringContainsString('data-panel-al-cargar="dialogo-sede-creada"', $html);
        $this->assertStringContainsString('¿Agregar un consultorio en <strong>' . e($sede->name) . '</strong>?', $html);
        $this->assertStringContainsString('Ahora no', $html);
        // «Agregar consultorio» abre el panel del consultorio con esa sede elegida.
        $this->assertStringContainsString('data-panel="dialogo-consultorio" data-sede="' . $sede->id . '" autofocus', $html);
        // La sede recién creada se ve aunque el médico todavía no tenga consultorio en ella.
        $this->assertStringContainsString(e($sede->name), $html);
    }

    public function test_editar_una_sede_no_vuelve_a_preguntar(): void
    {
        [$user, $medico, $gineco] = $this->usuarioClinico();
        $sede = $this->sedeSuelta('Sede ya creada');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/sedes', $this->datosDeSede(['id' => $sede->id, 'name' => $sede->name, 'activo' => 1]))
            ->assertRedirect(route('clinica.sedes'))
            ->assertSessionMissing('sede_creada');
    }

    public function test_sin_contexto_crear_la_sede_no_pregunta_por_el_consultorio(): void
    {
        // Sin dueño resuelto (dos registros) no hay consultorio que ofrecer: la pregunta no se hace.
        [$user] = $this->usuarioClinico();
        $this->segundoRegistro($user);
        $datos = $this->datosDeSede();

        $this->actingAs($user)->post('/clinica/sedes', $datos)->assertSessionHas('sede_creada');

        $this->get('/clinica/sedes')->assertOk()->assertDontSee('dialogo-sede-creada', false);
    }

    public function test_el_panel_del_consultorio_llega_con_la_sede_elegida(): void
    {
        [$user, $medico, $gineco] = $this->usuarioClinico();
        $sede = $this->sedeSuelta('Sede elegida');

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.sedes', ['panel' => 'consultorio', 'en' => $sede->id]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-panel-al-cargar="dialogo-consultorio"', $html);
        $this->assertMatchesRegularExpression('/<option value="' . $sede->id . '" selected>/', $html);
        $this->assertStringContainsString('data-abrir-sede-rapida', $html);
    }

    public function test_con_una_sola_sede_activa_el_consultorio_nace_con_ella(): void
    {
        [$user, $medico, $gineco] = $this->usuarioClinico();
        MedicalCenter::query()->update(['activo' => false]);
        $unica = $this->sedeSuelta('La única');

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.sedes', ['panel' => 'consultorio']))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<option value="' . $unica->id . '" selected>/', $html);
    }

    public function test_el_consultorio_se_crea_en_la_sede_elegida_y_vuelve_a_la_pantalla(): void
    {
        [$user, $medico, $gineco] = $this->usuarioClinico();
        $sede = $this->sedeSuelta('Sede del consultorio');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/consultorios', [
                'formulario'        => 'consultorio',
                'medical_center_id' => $sede->id,
                'office_number'     => 'P2-5',
                'modalidad'         => Office::MODALIDAD_HORA,
                'duracion_cita'     => 20,
                'activo'            => 1,
            ])
            ->assertRedirect(route('clinica.sedes'))
            ->assertSessionHas('estado', 'Consultorio registrado.');

        $this->assertDatabaseHas('offices', [
            'medical_center_id' => $sede->id, 'office_number' => 'P2-5',
            'reg_medico' => $medico->reg_medico, 'medico_id' => $medico->id,
        ]);

        // La sede pasa a ser «suya»: su tarjeta lista el consultorio.
        $this->get('/clinica/sedes')->assertOk()->assertSeeInOrder([$sede->name, 'P2-5']);
    }

    // ----------------------------------------------------------- La sede rápida (desde el consultorio)

    /** «Nueva sede» del panel del consultorio la crea por `fetch`: la respuesta es JSON, sin la pregunta. */
    public function test_crear_la_sede_desde_el_consultorio_responde_json(): void
    {
        [$user, $medico, $gineco] = $this->usuarioClinico();
        $datos = $this->datosDeSede(['name' => 'Sede rápida ' . substr(uniqid(), -6), 'activo' => 1]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->postJson('/clinica/sedes', $datos);

        $sede = MedicalCenter::where('name', $datos['name'])->firstOrFail();
        $respuesta->assertCreated()->assertExactJson(['id' => $sede->id, 'nombre' => $datos['name'], 'estado' => 'Sede registrada.']);
        $respuesta->assertSessionMissing('sede_creada');
        $this->assertTrue($sede->activo);
    }

    public function test_los_errores_de_la_sede_rapida_vuelven_como_json(): void
    {
        [$user, $medico, $gineco] = $this->usuarioClinico();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->postJson('/clinica/sedes', ['city_id' => $this->ciudad()->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'address']);
    }

    // ----------------------------------------------------------- Errores: el panel vuelve abierto

    public function test_un_error_de_la_sede_reabre_su_panel_con_lo_escrito(): void
    {
        [$user, $medico, $gineco] = $this->usuarioClinico();

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->from('/clinica/sedes')
            ->followingRedirects()
            ->post('/clinica/sedes', ['formulario' => 'sede', 'name' => 'Sin dirección', 'phone' => '0414-1112233', 'city_id' => $this->ciudad()->id])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-panel-al-cargar="dialogo-sede"', $html);
        $this->assertStringContainsString('La dirección es obligatoria', $html);
        $this->assertMatchesRegularExpression('/id="sede-name"[^>]*value="Sin dirección"/s', $html);
        // El teléfono de la sede no se cuela en el del consultorio (los dos formularios mandan `phone`).
        $this->assertMatchesRegularExpression('/id="consultorio-telefono"[^>]*value=""/', $html);
    }

    public function test_un_error_del_consultorio_reabre_su_panel_con_lo_escrito(): void
    {
        [$user, $medico, $gineco] = $this->usuarioClinico();
        $sede = $this->sedeSuelta('Sede con error');

        $html = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->from('/clinica/sedes')
            ->followingRedirects()
            ->post('/clinica/consultorios', [
                'formulario' => 'consultorio', 'medical_center_id' => $sede->id, 'office_number' => '', 'modalidad' => Office::MODALIDAD_ORDEN,
                'phone' => '0212-5556677',
            ])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-panel-al-cargar="dialogo-consultorio"', $html);
        $this->assertStringContainsString('Pon el número o el nombre del consultorio.', $html);
        $this->assertMatchesRegularExpression('/<option value="' . $sede->id . '" selected>/', $html);
        $this->assertMatchesRegularExpression('/id="consultorio-telefono"[^>]*value="0212-5556677"/', $html);
        // Y no se pinta en el panel de la sede.
        $this->assertMatchesRegularExpression('/id="sede-phone"[^>]*value=""/s', $html);
    }

    public function test_la_secretaria_ve_y_carga_los_consultorios_del_medico_en_la_misma_pantalla(): void
    {
        [$user, $medico, $gineco] = $this->usuarioClinico();
        $secretaria = User::create([
            'name' => 'Secretaria', 'email' => 'sec-sedes-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);
        $sede = $this->sedePropia($medico, 'Sede de la secretaria');

        $html = $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->get('/clinica/sedes')->assertOk()->getContent();

        $this->assertStringContainsString('id="dialogo-consultorio"', $html);
        $this->assertStringContainsString('data-sede="' . $sede->id . '"', $html);
    }

    /**
     * Un **segundo registro** para el mismo usuario (un centro donde trabaja, con su `medico_registros`), que es
     * lo que hace que la pantalla tenga algo que elegir y no resuelva sola el dueño de los consultorios.
     */
    private function segundoRegistro(User $user, string $nombre = 'Centro de prueba'): Medico
    {
        $regMedico = 'centro-' . substr(uniqid(), -6);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => $nombre,
            'lastname'   => 'Dos registros',
            'reg_medico' => $regMedico,
        ]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return $medico;
    }

    private function sedeSuelta(string $nombre): MedicalCenter
    {
        return MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id'   => $this->ciudad()->state_id,
            'city_id'    => $this->ciudad()->id,
            'name'       => $nombre . ' ' . substr(uniqid(), -6),
            'address'    => 'Av. Principal',
            'activo'     => true,
        ]);
    }

    private function sedePropia(Medico $medico, string $nombre): MedicalCenter
    {
        $sede = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id'   => $this->ciudad()->state_id,
            'city_id'    => $this->ciudad()->id,
            'name'       => $nombre . ' ' . substr(uniqid(), -6),
            'address'    => 'Av. Principal',
            'activo'     => true,
        ]);

        Office::create([
            'medical_center_id' => $sede->id,
            'medico_id'         => $medico->id,
            'reg_medico'        => $medico->reg_medico,
            'office_number'     => 'C1',
            'modalidad'         => Office::MODALIDAD_HORA,
            'duracion_cita'     => 30,
            'activo'            => true,
        ]);

        return $sede;
    }

    public function test_crear_una_sede_deriva_el_estado_y_el_pais_de_la_ciudad(): void
    {
        [$user] = $this->usuarioClinico();
        $ciudad = $this->ciudad();
        $datos = $this->datosDeSede(['name' => 'Hospital Cent' . substr(uniqid(), -6)]);

        $this->actingAs($user)
            ->post('/clinica/sedes', $datos)
            ->assertRedirect(route('clinica.sedes'));

        $sede = MedicalCenter::where('name', $datos['name'])->firstOrFail();

        // El formulario no pregunta ni el estado ni el país: se deducen de la ciudad.
        $this->assertSame($ciudad->id, (int) $sede->city_id);
        $this->assertSame((int) $ciudad->state_id, (int) $sede->state_id);
        $this->assertSame((int) $ciudad->state->country_id, (int) $sede->country_id);
        $this->assertTrue($sede->activo);
    }

    public function test_el_nombre_y_la_direccion_son_obligatorios(): void
    {
        [$user] = $this->usuarioClinico();

        $this->actingAs($user)
            ->post('/clinica/sedes', ['city_id' => $this->ciudad()->id])
            ->assertSessionHasErrors(['name', 'address']);
    }

    public function test_la_ciudad_tiene_que_existir(): void
    {
        [$user] = $this->usuarioClinico();

        $this->actingAs($user)
            ->post('/clinica/sedes', $this->datosDeSede(['city_id' => 999999]))
            ->assertSessionHasErrors('city_id');
    }

    public function test_no_se_repiten_los_nombres_de_sede(): void
    {
        [$user] = $this->usuarioClinico();
        $datos = $this->datosDeSede(['name' => 'Clínica Única' . substr(uniqid(), -6)]);

        $this->actingAs($user)->post('/clinica/sedes', $datos)->assertRedirect();
        $this->actingAs($user)->post('/clinica/sedes', $datos)->assertSessionHasErrors('name');

        $this->assertSame(1, MedicalCenter::where('name', $datos['name'])->count());
    }

    public function test_editar_la_sede_cambia_sus_datos_sin_crear_otra(): void
    {
        [$user] = $this->usuarioClinico();
        $datos = $this->datosDeSede(['name' => 'Sede editable' . substr(uniqid(), -6)]);

        $this->actingAs($user)->post('/clinica/sedes', $datos)->assertRedirect();
        $sede = MedicalCenter::where('name', $datos['name'])->firstOrFail();
        $antes = MedicalCenter::count();

        $this->actingAs($user)
            ->post('/clinica/sedes', array_merge($datos, [
                'id'      => $sede->id,
                'name'    => 'Sede editada ' . substr(uniqid(), -6),
                'address' => 'Dirección nueva',
                'phone'   => '',
            ]))
            ->assertRedirect(route('clinica.sedes'));

        $this->assertSame($antes, MedicalCenter::count());

        $sede->refresh();
        $this->assertSame('Dirección nueva', $sede->address);
        $this->assertNull($sede->phone, 'el teléfono vacío queda nulo, no en cadena vacía');
    }

    public function test_el_formulario_de_edicion_llega_con_la_sede_cargada(): void
    {
        [$user] = $this->usuarioClinico();
        $datos = $this->datosDeSede(['name' => 'Sede a editar' . substr(uniqid(), -6)]);

        $this->actingAs($user)->post('/clinica/sedes', $datos)->assertRedirect();
        $sede = MedicalCenter::where('name', $datos['name'])->firstOrFail();

        $respuesta = $this->actingAs($user)->get('/clinica/sedes?editar=' . $sede->id);

        $respuesta->assertOk();
        $respuesta->assertSee('Editar la sede');
        $respuesta->assertSee($datos['name']);
    }

    // ------------------------------------------------------------------------------- Activar y bajar

    public function test_desactivar_una_sede_no_la_borra(): void
    {
        [$user] = $this->usuarioClinico();
        $datos = $this->datosDeSede();

        $this->actingAs($user)->post('/clinica/sedes', $datos)->assertRedirect();
        $sede = MedicalCenter::where('name', $datos['name'])->firstOrFail();

        $this->actingAs($user)
            ->patch('/clinica/sedes/' . $sede->id . '/activar')
            ->assertRedirect(route('clinica.sedes'));

        // La cita guarda `medical_center_id`: la fila se conserva y sólo se deja de ofrecer.
        $this->assertFalse($sede->refresh()->activo);
        $this->assertDatabaseHas('medical_centers', ['id' => $sede->id]);

        $this->actingAs($user)->get('/clinica/sedes')->assertDontSee($sede->name);
        $this->actingAs($user)->get('/clinica/sedes?todas=1')->assertSee($sede->name);
    }

    public function test_volver_a_activarla_la_devuelve_a_la_lista(): void
    {
        [$user] = $this->usuarioClinico();
        $datos = $this->datosDeSede();

        $this->actingAs($user)->post('/clinica/sedes', $datos)->assertRedirect();
        $sede = MedicalCenter::where('name', $datos['name'])->firstOrFail();

        $this->actingAs($user)->patch('/clinica/sedes/' . $sede->id . '/activar');
        $this->actingAs($user)->patch('/clinica/sedes/' . $sede->id . '/activar');

        $this->assertTrue($sede->refresh()->activo);
    }

    public function test_la_secretaria_tambien_carga_sedes(): void
    {
        [$secretaria] = $this->usuarioClinico('Secretaria');
        $datos = $this->datosDeSede();

        $this->actingAs($secretaria)->post('/clinica/sedes', $datos)->assertRedirect();

        $this->assertSame(1, MedicalCenter::where('name', $datos['name'])->count());
    }

    public function test_la_pantalla_se_ve_sin_contexto_elegido(): void
    {
        // Las sedes son de la clínica, no de un médico: se cargan antes de elegir con qué trabajar.
        [$user] = $this->usuarioClinico();

        $this->actingAs($user)->get('/clinica/sedes')->assertOk();
    }

    public function test_el_contexto_avisa_cuando_el_medico_no_tiene_consultorios(): void
    {
        // Sin consultorios no hay nada que elegir: la pantalla se salta (2026-10-19) y el aviso viaja al
        // consultorio, que es donde el médico lo va a leer.
        [$user, $medico, $gineco] = $this->usuarioClinico();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/contexto')
            ->assertRedirect(route('clinica.inicio'))
            ->assertSessionHas('avisos');

        $this->actingAs($user)->get('/clinica')
            ->assertOk()
            ->assertSee('Todavía no tienes consultorios cargados');
    }

    public function test_la_sede_inactiva_deja_de_ofrecerse_en_el_contexto(): void
    {
        [$user, $medico, $gineco] = $this->usuarioClinico();
        // Dos sedes abiertas para que la pantalla exista (con una sola se salta) y una que se cierra.
        $abierta = $this->sedePropia($medico, 'Sede abierta');
        $this->sedePropia($medico, 'Otra sede abierta');
        $cerrada = $this->sedePropia($medico, 'Sede que se cierra');

        $cerrada->update(['activo' => false]);

        // `ContextoTrabajo` sólo lista sedes activas, así que la sede cerrada no se puede elegir.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/contexto')
            ->assertOk()
            ->assertSee($abierta->name)
            ->assertDontSee($cerrada->name);
    }
}
