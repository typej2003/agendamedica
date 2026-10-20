<?php

namespace Tests\Feature;

use App\Clinica\ContextoAutomatico;
use App\Clinica\ContextoTrabajo;
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
 * La pantalla de **elegir consultorio** (`/clinica/contexto`): con qué médico, especialidad y consultorio se
 * opera.
 *
 * Lo que fija este test es la decisión del usuario del 2026-10-19: **no se pregunta lo que ya está
 * decidido**. Con **un solo médico**, **una sola especialidad** y **uno o ningún consultorio** la pantalla
 * **se salta entera** —se fija el contexto y se entra directo al consultorio—; cuando queda algo que elegir
 * (dos especialidades, dos consultorios, varios médicos o ninguna especialidad) la pantalla sigue estando, y
 * en la tarjeta de cada médico sólo se ofrecen los campos que tienen más de una opción.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class ContextoWebTest extends TestCase
{
    use DatabaseTransactions;

    // --------------------------------------------------------------------------------------- Ayudas

    /**
     * Un médico con su cuenta. `$especialidades` es cuántas especialidades activas tiene (0, 1 o 2).
     *
     * @return array{0: User, 1: Medico, 2: Specialty, 3: Specialty}
     */
    private function medicoConAcceso(int $especialidades = 1): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $pediatria = Specialty::where('slug', 'pediatria')->firstOrFail();

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'contexto-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dra. de prueba',
            'lastname'   => 'Contexto',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => 'contexto-' . substr(uniqid(), -6),
        ]);

        // Se adjuntan en el orden pedido: 0 es «ninguna».
        foreach (array_slice([$gineco, $pediatria], 0, $especialidades) as $especialidad) {
            $medico->specialties()->attach($especialidad->id);
        }

        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $medico->reg_medico]);

        return [$user, $medico, $gineco, $pediatria];
    }

    private function sede(string $nombre = 'Sede de prueba'): MedicalCenter
    {
        return MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id'   => City::firstOrFail()->state_id,
            'city_id'    => City::firstOrFail()->id,
            'name'       => $nombre . ' ' . substr(uniqid(), -6),
            'address'    => 'Av. Principal',
            'activo'     => true,
        ]);
    }

    private function consultorio(Medico $medico, MedicalCenter $sede, string $numero): Office
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

    /** La secretaría: sin ficha de médico, con el acceso a los registros que se le pasen. */
    private function secretariaDe(array $registros): User
    {
        $user = User::create([
            'name'                 => 'Secretaría de prueba',
            'email'                => 'contexto-sec-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('Secretaria');

        foreach ($registros as $registro) {
            MedicoRegistro::create(['user_id' => $user->id, 'reg_medico' => $registro]);
        }

        return $user;
    }

    // ------------------------------------------------------------- Nada que elegir: no hay pantalla

    public function test_con_una_especialidad_y_un_consultorio_la_pantalla_se_salta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consultorio = $this->consultorio($medico, $this->sede(), '701');

        // La dirección de la pantalla no la muestra: fija el contexto y entra.
        $this->actingAs($user)->get('/clinica/contexto')->assertRedirect(route('clinica.inicio'));

        $contexto = app(ContextoTrabajo::class)->actual($user);
        $this->assertNotNull($contexto);
        $this->assertSame($gineco->id, $contexto['specialty']->id);
        $this->assertSame($consultorio->id, $contexto['office']->id);

        // Y queda dentro del consultorio, con la barra armada y sin «Cambiar consultorio» (no hay nada que cambiar).
        $this->actingAs($user)->get('/clinica')
            ->assertOk()
            ->assertDontSee('Cambiar consultorio');
    }

    public function test_entrar_sin_contexto_no_deja_al_medico_en_la_pantalla(): void
    {
        // El camino real: pide /clinica, el middleware lo manda a elegir y la pantalla se salta sola.
        [$user, $medico] = $this->medicoConAcceso();
        $consultorio = $this->consultorio($medico, $this->sede(), '702');

        $this->actingAs($user)->get('/clinica')->assertRedirect(route('clinica.contexto'));
        $this->actingAs($user)->get('/clinica/contexto')->assertRedirect(route('clinica.inicio'));

        $this->assertSame($medico->reg_medico, session('clinica.contexto.reg_medico'));
        $this->assertSame($consultorio->id, session('clinica.contexto.office_id'));
    }

    public function test_con_una_especialidad_y_sin_consultorios_tambien_se_salta_y_avisa(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->actingAs($user)->get('/clinica/contexto')
            ->assertRedirect(route('clinica.inicio'))
            ->assertSessionHas('avisos');

        $contexto = app(ContextoTrabajo::class)->actual($user);
        $this->assertNotNull($contexto);
        $this->assertSame($gineco->id, $contexto['specialty']->id);
        // Sin consultorios el contexto queda en «todos», que es lo único que hay.
        $this->assertNull($contexto['office']);
        $this->assertNull(session('clinica.contexto.office_id'));
    }

    public function test_la_decision_de_saltarse_la_pantalla_vive_en_un_solo_lugar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consultorio = $this->consultorio($medico, $this->sede(), '703');

        $this->assertEquals(
            ['reg_medico' => $medico->reg_medico, 'specialty_id' => $gineco->id, 'office_id' => $consultorio->id],
            app(ContextoAutomatico::class)->de($user)
        );
    }

    public function test_con_una_especialidad_y_sin_consultorios_el_contexto_automatico_no_fija_sede(): void
    {
        [$user] = $this->medicoConAcceso();

        $this->assertNull(app(ContextoAutomatico::class)->de($user)['office_id']);
    }

    public function test_con_dos_medicos_se_pregunta_igual(): void
    {
        // La secretaría que trabaja en dos centros tiene que decir en cuál entra, aunque cada uno tenga una
        // sola especialidad y un solo consultorio.
        [$uno, $medicoUno] = $this->medicoConAcceso();
        [$dos, $medicoDos] = $this->medicoConAcceso();
        $this->consultorio($medicoUno, $this->sede(), '704');
        $this->consultorio($medicoDos, $this->sede(), '705');

        $secretaria = $this->secretariaDe([$medicoUno->reg_medico, $medicoDos->reg_medico]);

        $this->actingAs($secretaria)->get('/clinica/contexto')
            ->assertOk()
            ->assertSee('¿Con qué consultorio vas a trabajar?');

        $this->assertNull(app(ContextoAutomatico::class)->de($secretaria));
        $this->assertNotSame($uno->id, $dos->id);
    }

    public function test_sin_especialidad_asignada_la_pantalla_no_se_salta(): void
    {
        // Cero especialidades no es «una sola»: es una configuración incompleta, y es la pantalla donde se ve.
        [$user, $medico] = $this->medicoConAcceso(0);
        $this->consultorio($medico, $this->sede(), '706');

        $this->actingAs($user)->get('/clinica/contexto')
            ->assertOk()
            ->assertSee('no tiene especialidad asignada');
    }

    public function test_sin_acceso_a_ningun_medico_la_pantalla_no_se_salta(): void
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $user = $this->secretariaDe([]);

        $this->actingAs($user)->get('/clinica/contexto')
            ->assertOk()
            ->assertSee('todavía no tiene acceso a los datos de ningún médico');
    }

    // ------------------------------------------------------------- Con algo que elegir: hay pantalla

    public function test_con_dos_especialidades_y_dos_consultorios_se_preguntan_los_dos(): void
    {
        [$user, $medico] = $this->medicoConAcceso(2);
        $sede = $this->sede();
        $primero = $this->consultorio($medico, $sede, '801');
        $segundo = $this->consultorio($medico, $sede, '802');

        $respuesta = $this->actingAs($user)->get('/clinica/contexto');

        $respuesta->assertOk();
        $respuesta->assertSee('<select name="specialty_id"', false);
        $respuesta->assertSee('<select name="office_id"', false);
        $respuesta->assertSee('Todos mis consultorios (sin especificar)');
        $respuesta->assertSee('<option value="' . $primero->id . '">', false);
        $respuesta->assertSee('<option value="' . $segundo->id . '">', false);
        $respuesta->assertDontSee('type="hidden" name="specialty_id"', false);
        $respuesta->assertDontSee('type="hidden" name="office_id"', false);
        // Acá sí hay algo que elegir: el encabezado vuelve a preguntar.
        $respuesta->assertSee('¿Con qué consultorio vas a trabajar?');

        // Y elegir sigue funcionando: el contexto queda fijado con lo que se pidió.
        $this->actingAs($user)->post('/clinica/contexto', [
            'reg_medico'   => $medico->reg_medico,
            'specialty_id' => $medico->specialties->first()->id,
            'office_id'    => $segundo->id,
        ])->assertRedirect(route('clinica.inicio'));

        $this->assertSame($segundo->id, app(ContextoTrabajo::class)->actual($user)['office']->id);
        // Y la barra sigue ofreciendo «Cambiar consultorio», que ahora sí lleva a algo.
        $this->actingAs($user)->get('/clinica')->assertOk()->assertSee('Cambiar consultorio');
    }

    public function test_con_una_especialidad_y_dos_consultorios_solo_se_pregunta_el_consultorio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede();
        $this->consultorio($medico, $sede, '901');
        $this->consultorio($medico, $sede, '902');

        $respuesta = $this->actingAs($user)->get('/clinica/contexto');

        $respuesta->assertOk();
        $respuesta->assertSee('<select name="office_id"', false);
        $respuesta->assertDontSee('<select name="specialty_id"', false);
        $respuesta->assertSee('type="hidden" name="specialty_id" value="' . $gineco->id . '"', false);
        $respuesta->assertDontSee('type="hidden" name="office_id"', false);
    }

    public function test_con_dos_especialidades_y_un_consultorio_solo_se_pregunta_la_especialidad(): void
    {
        [$user, $medico] = $this->medicoConAcceso(2);
        $consultorio = $this->consultorio($medico, $this->sede(), '951');

        $respuesta = $this->actingAs($user)->get('/clinica/contexto');

        $respuesta->assertOk();
        $respuesta->assertSee('<select name="specialty_id"', false);
        $respuesta->assertDontSee('<select name="office_id"', false);
        $respuesta->assertSee('type="hidden" name="office_id" value="' . $consultorio->id . '"', false);
        $respuesta->assertDontSee('type="hidden" name="specialty_id"', false);
    }
}
