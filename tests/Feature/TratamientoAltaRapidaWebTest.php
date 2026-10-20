<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use App\Models\Vademecum;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **El editor de tratamientos con una sola tarjeta** (2026-10-20): el buscador de medicamentos lleva al final un botón
 * «Nuevo medicamento» que abre el alta en un diálogo lateral; el alta es JSON y devuelve el medicamento para que el
 * editor lo agregue a la lista sin recargar.
 */
class TratamientoAltaRapidaWebTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $pediatria = Specialty::where('slug', 'pediatria')->firstOrFail();
        $regMedico = 'altarap' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Doctora', 'email' => 'altarap-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Doctora', 'lastname' => 'De Prueba', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($pediatria->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $pediatria];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null,
        ]])->actingAs($user);
    }

    public function test_el_editor_trae_el_buscador_con_su_boton_y_el_dialogo_en_una_sola_tarjeta(): void
    {
        [$user, $medico, $esp] = $this->medicoConAcceso();

        $r = $this->conContexto($user, $medico, $esp)->get(route('clinica.catalogos.tratamientos.nuevo'));

        $r->assertOk();
        $r->assertSee('id="recipe-nuevo-medicamento"', false);
        $r->assertSee('id="dialogo-medicamento"', false);
        $r->assertSee(route('clinica.catalogos.medicamentos.rapido'), false);
        $r->assertSee('Crear y agregar al tratamiento');
        $r->assertDontSee('Agregar medicamentos</h', false);
    }

    public function test_el_alta_rapida_crea_el_medicamento_y_lo_devuelve(): void
    {
        [$user, $medico, $esp] = $this->medicoConAcceso();

        $r = $this->conContexto($user, $medico, $esp)->postJson(route('clinica.catalogos.medicamentos.rapido'), [
            'nombrecomercial' => 'rapidol', 'presentacion' => 'Tabletas 500 mg', 'uso' => 'Una al día',
        ]);

        $r->assertCreated()->assertJsonPath('creado', true)->assertJsonPath('medicamento.uso', 'Una al día');
        $this->assertSame(1, Vademecum::where('reg_medico', $medico->reg_medico)->where('nombrecomercial', 'RAPIDOL')->count());

        $otra = $this->conContexto($user, $medico, $esp)->postJson(route('clinica.catalogos.medicamentos.rapido'), [
            'nombrecomercial' => 'RAPIDOL', 'presentacion' => 'Tabletas 500 mg',
        ]);
        $otra->assertOk()->assertJsonPath('creado', false);
        $this->assertSame(1, Vademecum::where('reg_medico', $medico->reg_medico)->where('nombrecomercial', 'RAPIDOL')->count());
    }

    public function test_el_alta_rapida_sin_nombre_responde_422_con_el_aviso(): void
    {
        [$user, $medico, $esp] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $esp)
            ->postJson(route('clinica.catalogos.medicamentos.rapido'), ['nombrecomercial' => '  '])
            ->assertStatus(422)->assertJsonStructure(['message']);
    }
}
