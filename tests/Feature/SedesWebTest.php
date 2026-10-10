<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Office;
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
        $regMedico = 'sedes-test-' . uniqid();

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'sedes-' . uniqid() . '@example.com',
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
            'name'    => 'Sede de prueba ' . uniqid(),
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
            'name'       => 'Clínica de prueba ' . uniqid(),
            'address'    => 'Av. Principal',
            'activo'     => true,
        ]);

        $respuesta = $this->actingAs($user)->get('/clinica/sedes');

        $respuesta->assertOk();
        $respuesta->assertSee('Sedes');
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
            'name'       => 'Sede cerrada ' . uniqid(),
            'address'    => 'Calle vieja',
            'activo'     => false,
        ]);

        $this->actingAs($user)->get('/clinica/sedes')->assertOk()->assertDontSee($vieja->name);
        $this->actingAs($user)->get('/clinica/sedes?todas=1')->assertOk()->assertSee($vieja->name);
    }

    public function test_muestra_cuantos_consultorios_activos_tiene_la_sede(): void
    {
        [$user, $medico] = $this->usuarioClinico();
        $conConsultorio = $this->sedePropia($medico, 'Con consultorio');
        $sinConsultorio = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id'   => $this->ciudad()->state_id,
            'city_id'    => $this->ciudad()->id,
            'name'       => 'Sede sin usar ' . uniqid(),
            'address'    => 'Av. Principal',
            'activo'     => true,
        ]);

        $respuesta = $this->actingAs($user)->get('/clinica/sedes');
        $respuesta->assertOk();
        $respuesta->assertSee($conConsultorio->name);
        $respuesta->assertSee($sinConsultorio->name);
        // El conteo es el dato que dice si la sede se usa: sin consultorios es una sede cargada y sin usar.
        $respuesta->assertSee('ninguno');

        // El listado cuenta los consultorios **activos**: dar de baja el consultorio deja la sede en 0.
        $this->assertSame(1, $conConsultorio->loadCount(['offices as consultorios_activos' => fn ($q) => $q->where('activo', true)])->consultorios_activos);

        $conConsultorio->offices()->update(['activo' => false]);

        $this->assertSame(0, $conConsultorio->loadCount(['offices as consultorios_activos' => fn ($q) => $q->where('activo', true)])->consultorios_activos);
    }

    private function sedePropia(Medico $medico, string $nombre): MedicalCenter
    {
        $sede = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id'   => $this->ciudad()->state_id,
            'city_id'    => $this->ciudad()->id,
            'name'       => $nombre . ' ' . uniqid(),
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
        $datos = $this->datosDeSede(['name' => 'Hospital Central ' . uniqid()]);

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
        $datos = $this->datosDeSede(['name' => 'Clínica Única ' . uniqid()]);

        $this->actingAs($user)->post('/clinica/sedes', $datos)->assertRedirect();
        $this->actingAs($user)->post('/clinica/sedes', $datos)->assertSessionHasErrors('name');

        $this->assertSame(1, MedicalCenter::where('name', $datos['name'])->count());
    }

    public function test_editar_la_sede_cambia_sus_datos_sin_crear_otra(): void
    {
        [$user] = $this->usuarioClinico();
        $datos = $this->datosDeSede(['name' => 'Sede editable ' . uniqid()]);

        $this->actingAs($user)->post('/clinica/sedes', $datos)->assertRedirect();
        $sede = MedicalCenter::where('name', $datos['name'])->firstOrFail();
        $antes = MedicalCenter::count();

        $this->actingAs($user)
            ->post('/clinica/sedes', array_merge($datos, [
                'id'      => $sede->id,
                'name'    => 'Sede editada ' . uniqid(),
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
        $datos = $this->datosDeSede(['name' => 'Sede a editar ' . uniqid()]);

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
        [$user, $medico, $gineco] = $this->usuarioClinico();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/contexto')
            ->assertOk()
            ->assertSee('todavía no tiene consultorios cargados');
    }

    public function test_la_sede_inactiva_deja_de_ofrecerse_en_el_contexto(): void
    {
        [$user, $medico, $gineco] = $this->usuarioClinico();
        $sede = $this->sedePropia($medico, 'Sede que se cierra');

        $sede->update(['activo' => false]);

        // `ContextoTrabajo` sólo lista sedes activas, así que la sede cerrada no se puede elegir.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/contexto')
            ->assertOk()
            ->assertDontSee($sede->name);
    }
}
