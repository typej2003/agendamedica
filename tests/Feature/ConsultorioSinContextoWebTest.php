<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Office;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **Cargar el consultorio sin haber elegido contexto** (2026-10-22).
 *
 * El caso que lo motivó: un médico recién creado desde el panel —`CuentaService::crearMedico` no le asigna
 * especialidad— entra a «Sedes y consultorios» y ahí no había nada que hacer. El contexto de trabajo se fija
 * sólo en la pantalla «Elegir consultorio», y sin él la pantalla no pinta la mitad de los consultorios (ni el
 * botón que los carga) y el menú lateral queda en «Inicio»: el médico quedaba encerrado, justo en la pantalla
 * a la que la propia app lo manda a cargar su consultorio.
 *
 * La regla: cuando el usuario tiene **un solo registro**, la pantalla resuelve de quién es el consultorio y fija
 * el contexto (`clinica.contexto:consultorios`). Con dos registros —el propio y el centro donde trabaja— o con
 * varios médicos hay algo que elegir de verdad: la pantalla se abre como antes y avisa.
 */
class ConsultorioSinContextoWebTest extends TestCase
{
    use DatabaseTransactions;

    // --------------------------------------------------------------------------------------- Ayudas

    /**
     * Un usuario con rol `Medico` y **una** ficha de médico, sin especialidad y sin consultorios, como lo deja
     * el alta desde el panel. La especialidad se agrega sólo cuando el test la pide.
     */
    private function medicoNuevo(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $regMedico = 'nuevo-' . substr(uniqid(), -6);

        $user = User::create([
            'name'                 => 'Médico nuevo',
            'email'                => 'nuevo-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. nuevo',
            'lastname'   => 'Sin contexto',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico];
    }

    private function ciudad(): City
    {
        return City::with('state')->firstOrFail();
    }

    private function sede(string $nombre): MedicalCenter
    {
        return MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id'   => $this->ciudad()->state_id,
            'city_id'    => $this->ciudad()->id,
            'name'       => $nombre,
            'address'    => 'Calle de la sede',
            'activo'     => true,
        ]);
    }

    /** Un segundo registro para el mismo usuario: otra ficha, como un centro donde trabaja. */
    private function segundoRegistro(User $user): Medico
    {
        $regMedico = 'centro-' . substr(uniqid(), -6);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Centro de prueba',
            'lastname'   => 'Dos registros',
            'reg_medico' => $regMedico,
        ]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return $medico;
    }

    // ------------------------------------------------------------------------------- El caso que fallaba

    public function test_el_medico_nuevo_entra_a_sedes_y_puede_cargar_su_consultorio_sin_haber_elegido(): void
    {
        [$user, $medico] = $this->medicoNuevo();
        $sede = $this->sede('Sede para el médico nuevo');

        // Sin contexto en la sesión: es como se llega desde el enlace de «Elegir consultorio».
        $html = $this->actingAs($user)->get('/clinica/sedes')->assertOk()->getContent();

        // La pantalla ya ofrece el alta del consultorio, con la sede lista para elegirla.
        $this->assertStringContainsString('id="dialogo-consultorio"', $html);
        $this->assertStringContainsString('data-panel="dialogo-consultorio" data-sede="' . $sede->id . '"', $html);
        // Y no aparece el aviso: no hay nada que elegir, así que no se le pide nada.
        $this->assertStringNotContainsString('Elegir consultorio aquí', $html);

        // El contexto quedó fijado con **su** registro, que es lo que le faltaba.
        $this->assertSame($medico->reg_medico, session('clinica.contexto.reg_medico'));
    }

    public function test_ese_medico_ya_tiene_menu_lateral_al_entrar_a_sedes(): void
    {
        // El síntoma del callejón: sin contexto el menú lateral queda sólo en «Inicio» y no hay cómo navegar.
        [$user] = $this->medicoNuevo();
        $this->sede('Sede del menú');

        $html = $this->actingAs($user)->get('/clinica/sedes')->assertOk()->getContent();

        $this->assertStringContainsString('href="' . route('clinica.sedes') . '"', $html);
        $this->assertStringContainsString('Configuración', $html);
    }

    public function test_el_consultorio_se_crea_y_queda_a_nombre_del_medico(): void
    {
        [$user, $medico] = $this->medicoNuevo();
        $sede = $this->sede('Sede donde carga');

        // La pantalla fija el contexto al abrirse; el POST es el mismo que usa todo el mundo.
        $this->actingAs($user)->get('/clinica/sedes')->assertOk();

        $this->actingAs($user)
            ->post('/clinica/consultorios', [
                'formulario'        => 'consultorio',
                'medical_center_id' => $sede->id,
                'office_number'     => 'N-1',
                'modalidad'         => Office::MODALIDAD_HORA,
                'duracion_cita'     => 20,
                'activo'            => 1,
            ])
            ->assertRedirect(route('clinica.sedes'))
            ->assertSessionHas('estado', 'Consultorio registrado.');

        $this->assertDatabaseHas('offices', [
            'medical_center_id' => $sede->id,
            'office_number'     => 'N-1',
            'reg_medico'        => $medico->reg_medico,
            'medico_id'         => $medico->id,
        ]);

        $this->actingAs($user)->get('/clinica/sedes')->assertOk()
            ->assertSeeInOrder([$sede->name, 'N-1']);
    }

    public function test_el_medico_nuevo_con_una_especialidad_tambien_carga_su_consultorio(): void
    {
        // El atajo no depende de la especialidad, pero tampoco la estorba: con una sola, el contexto la usa.
        [$user, $medico] = $this->medicoNuevo();
        $especialidad = \App\Models\Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $medico->specialties()->attach($especialidad->id);
        $this->sede('Sede con especialidad');

        $this->actingAs($user)->get('/clinica/sedes')->assertOk();

        $this->assertSame($especialidad->id, (int) session('clinica.contexto.specialty_id'));
    }

    // ------------------------------------------------------------------------- Cuando sí hay que elegir

    public function test_con_dos_registros_no_se_resuelve_solo_y_la_pantalla_avisa(): void
    {
        [$user] = $this->medicoNuevo();
        $this->segundoRegistro($user);
        $this->sede('Sede con dos registros');

        $html = $this->actingAs($user)->get('/clinica/sedes')->assertOk()->getContent();

        // No se inventa un dueño: no hay panel de consultorio y se manda a elegir.
        $this->assertStringNotContainsString('id="dialogo-consultorio"', $html);
        $this->assertStringContainsString('Elegir consultorio aquí', $html);
        $this->assertStringContainsString('href="' . route('clinica.contexto') . '"', $html);
        // Y el contexto sigue sin fijarse.
        $this->assertNull(session('clinica.contexto'));
    }

    public function test_con_dos_registros_el_alta_del_consultorio_lleva_a_elegir_antes(): void
    {
        [$user] = $this->medicoNuevo();
        $this->segundoRegistro($user);
        $sede = $this->sede('Sede sin dueño');

        $this->actingAs($user)
            ->post('/clinica/consultorios', [
                'formulario'        => 'consultorio',
                'medical_center_id' => $sede->id,
                'office_number'     => 'X-1',
                'modalidad'         => Office::MODALIDAD_HORA,
            ])
            ->assertRedirect(route('clinica.contexto'));

        $this->assertDatabaseMissing('offices', ['office_number' => 'X-1']);
    }

    public function test_la_pantalla_no_cambia_el_contexto_ya_elegido(): void
    {
        [$user, $medico] = $this->medicoNuevo();
        $sede = $this->sede('Sede del contexto elegido');
        $consultorio = Office::create([
            'medical_center_id' => $sede->id,
            'reg_medico'        => $medico->reg_medico,
            'medico_id'         => $medico->id,
            'office_number'     => 'E-1',
            'modalidad'         => Office::MODALIDAD_HORA,
            'activo'            => true,
        ]);

        $this->withSession(['clinica.contexto' => [
            'reg_medico'   => $medico->reg_medico,
            'specialty_id' => null,
            'office_id'    => $consultorio->id,
        ]])->actingAs($user)->get('/clinica/sedes')->assertOk();

        $this->assertSame($consultorio->id, (int) session('clinica.contexto.office_id'));
    }
}
