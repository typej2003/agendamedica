<?php

namespace Tests\Feature;

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
 * decidido**. Si el médico tiene **una sola especialidad** y **uno o ningún consultorio**, esos campos no
 * se ofrecen —se entra con el único valor que hay— y la pantalla sólo muestra cuál es; en cuanto haya algo
 * entre qué elegir (dos especialidades o dos consultorios) vuelve el `<select>` de siempre.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class ContextoWebTest extends TestCase
{
    use DatabaseTransactions;

    // --------------------------------------------------------------------------------------- Ayudas

    /**
     * Un médico con su cuenta. `$especialidades` es cuántas especialidades activas tiene (1 o 2).
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
        $medico->specialties()->attach($gineco->id);
        if ($especialidades > 1) {
            $medico->specialties()->attach($pediatria->id);
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

    // --------------------------------------------------------------------------- No se pregunta nada

    public function test_con_una_especialidad_y_un_consultorio_no_se_pregunta_ninguno_de_los_dos(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consultorio = $this->consultorio($medico, $this->sede('San José'), '701');

        $respuesta = $this->actingAs($user)->get('/clinica/contexto');

        $respuesta->assertOk();
        // No hay nada que elegir: ni un `<select>` por ninguno de los dos conceptos...
        $respuesta->assertDontSee('<select name="specialty_id"', false);
        $respuesta->assertDontSee('<select name="office_id"', false);
        // ...pero los dos valores viajan, para que el contexto quede fijado de forma explícita.
        $respuesta->assertSee('type="hidden" name="specialty_id" value="' . $gineco->id . '"', false);
        $respuesta->assertSee('type="hidden" name="office_id" value="' . $consultorio->id . '"', false);
        // Y la pantalla dice con qué se va a trabajar.
        $respuesta->assertSee('Ginecología y Obstetricia');
        $respuesta->assertSee($consultorio->medicalCenter->name . ' · 701');
        // Sin nada que elegir el encabezado no pregunta (2026-10-19).
        $respuesta->assertSee('Tu consultorio');
        $respuesta->assertDontSee('¿Con qué consultorio vas a trabajar?');
    }

    public function test_con_una_especialidad_y_un_consultorio_el_formulario_guarda_los_dos(): void
    {
        // Lo que manda el formulario tal como queda (los dos campos ocultos) fija el contexto completo: es
        // lo que evita que «Trabajar aquí» entre en «todos mis consultorios» cuando sólo hay uno.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consultorio = $this->consultorio($medico, $this->sede(), '702');

        $this->actingAs($user)
            ->post('/clinica/contexto', [
                'reg_medico'   => $medico->reg_medico,
                'specialty_id' => $gineco->id,
                'office_id'    => $consultorio->id,
            ])
            ->assertRedirect(route('clinica.inicio'));

        $contexto = app(ContextoTrabajo::class)->actual($user);

        $this->assertNotNull($contexto);
        $this->assertSame($gineco->id, $contexto['specialty']->id);
        $this->assertSame($consultorio->id, $contexto['office']->id);
    }

    public function test_con_una_especialidad_y_sin_consultorios_tampoco_se_pregunta_el_consultorio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $respuesta = $this->actingAs($user)->get('/clinica/contexto');

        $respuesta->assertOk();
        $respuesta->assertDontSee('<select name="specialty_id"', false);
        $respuesta->assertDontSee('<select name="office_id"', false);
        // Sin consultorios no hay valor que fijar: el contexto queda en «todos».
        $respuesta->assertDontSee('name="office_id"', false);
        $respuesta->assertSee('type="hidden" name="specialty_id" value="' . $gineco->id . '"', false);
        // Y sigue el camino para darlos de alta, que era el aviso de siempre.
        $respuesta->assertSee('todavía no tiene consultorios cargados');
    }

    // --------------------------------------------------------------------------- Se sigue preguntando

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
