<?php

namespace Tests\Feature;

use App\Clinica\Guia\PasosEsenciales;
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
 * **La guía de puesta en marcha**: banner con el siguiente dato esencial que le falta al médico (sede →
 * consultorio → horario, de uno en uno) y diálogo para seguir después de cada paso guardado.
 */
class PasosEsencialesWebTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();

        $user = User::create([
            'name' => 'Guía', 'email' => 'guia-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dra.', 'lastname' => 'Guía', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => 'guia-' . substr(uniqid(), -6),
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $medico->reg_medico]);

        return [$user, $medico, $gineco];
    }

    /** Una sede activa; con dueño si se le pasa (la guía mira **sus** sedes, no el catálogo). */
    private function sede(?Medico $medico = null): MedicalCenter
    {
        return MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id' => City::firstOrFail()->state_id,
            'city_id' => City::firstOrFail()->id,
            'medico_id' => $medico?->id,
            'name' => 'Sede guía ' . substr(uniqid(), -6),
            'address' => 'Av. Principal',
            'activo' => true,
        ]);
    }

    private function consultorio(Medico $medico, MedicalCenter $sede): Office
    {
        return Office::create([
            'medical_center_id' => $sede->id, 'medico_id' => $medico->id, 'reg_medico' => $medico->reg_medico,
            'office_number' => '9A', 'modalidad' => Office::MODALIDAD_ORDEN, 'duracion_cita' => 30, 'activo' => true,
        ]);
    }

    public function test_los_pasos_van_de_uno_en_uno_del_menos_dependiente_al_mas(): void
    {
        [, $medico] = $this->medico();
        $pasos = app(PasosEsenciales::class);

        MedicalCenter::query()->update(['activo' => false]);
        $this->assertSame('sede', $pasos->siguiente($medico)->clave);

        $sede = $this->sede($medico);
        $this->assertSame('consultorio', $pasos->siguiente($medico)->clave);

        $consultorio = $this->consultorio($medico, $sede);
        $paso = $pasos->siguiente($medico);
        $this->assertSame('horario', $paso->clave);
        $this->assertStringContainsString('consultorio 9A', $paso->banner);
        $this->assertSame(route('clinica.consultorios.horarios', $consultorio), $paso->url());

        OfficeSchedule::create([
            'office_id' => $consultorio->id, 'reg_medico' => $medico->reg_medico,
            'dia_semana' => 1, 'hora_inicio' => '08:00', 'hora_fin' => '12:00',
        ]);
        $this->assertNull($pasos->siguiente($medico));
    }

    public function test_el_banner_aparece_con_el_enlace_y_el_dialogo_solo_tras_guardar(): void
    {
        [$user, $medico, $gineco] = $this->medico();
        $this->sede($medico);

        $this->actingAs($user)->post('/clinica/contexto', [
            'reg_medico' => $medico->reg_medico, 'specialty_id' => $gineco->id,
        ]);

        $this->get('/clinica')
            ->assertOk()
            ->assertSee('No has agregado ningún consultorio.')
            ->assertSee(e(route('clinica.sedes', ['panel' => 'consultorio'])), false)
            ->assertDontSee('dialogo-paso-esencial');

        $this->withSession(['guiado' => true])->get('/clinica')
            ->assertSee('dialogo-paso-esencial')
            ->assertSee('¿Quieres agregarlo ahora?');
    }

    public function test_la_secretaria_no_ve_la_guia(): void
    {
        [, $medico] = $this->medico();
        $secretaria = User::create([
            'name' => 'Sec', 'email' => 'sec-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        $this->actingAs($secretaria)->get('/clinica/sedes')->assertDontSee('data-paso-esencial', false);
    }
}
