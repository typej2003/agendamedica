<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\Subspecialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **El médico activa sus propias subespecialidades** (2026-10-30).
 *
 * El postgrado enciende módulos y secciones de la consulta; hasta hoy sólo el administrador podía
 * asignarlo. Lo que se prueba acá: que el médico entra, ve sólo los postgrados de sus especialidades, los
 * activa y desactiva, y que no puede tocar la ficha de otro.
 */
class MisSubespecialidadesWebTest extends TestCase
{
    use DatabaseTransactions;

    private function medicoConPediatria(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $pediatria = Specialty::where('slug', 'pediatria')->firstOrFail();
        $sufijo = substr(uniqid(), -6);
        $regMedico = 'subesp-' . $sufijo;

        $user = User::create([
            'name'                 => 'Dra. Postgrado',
            'email'                => 'subesp-' . $sufijo . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dra.',
            'lastname'   => 'Postgrado',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($pediatria->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico, 'user_id' => $user->id]);

        $postgrado = Subspecialty::where('specialty_id', $pediatria->id)->where('activo', true)->firstOrFail();

        return [$user, $medico, $postgrado, $pediatria];
    }

    public function test_la_pantalla_lista_los_postgrados_de_sus_especialidades(): void
    {
        [$user, , $postgrado] = $this->medicoConPediatria();

        $this->actingAs($user)
            ->get(route('clinica.mis-subespecialidades'))
            ->assertOk()
            ->assertSee('Mis subespecialidades')
            ->assertSee($postgrado->nombre);
    }

    public function test_el_medico_activa_y_desactiva_un_postgrado(): void
    {
        [$user, $medico, $postgrado] = $this->medicoConPediatria();

        $this->actingAs($user)
            ->post(route('clinica.mis-subespecialidades.guardar'), [
                'medico_id'           => $medico->id,
                'subespecialidades'   => [$postgrado->id],
            ])
            ->assertRedirect(route('clinica.mis-subespecialidades'));

        $this->assertSame([$postgrado->id], $medico->subespecialidades()->pluck('subespecialidades.id')->all());

        // Y quitarla: el formulario manda las tildadas, así que sin ninguna se desactiva.
        $this->actingAs($user)
            ->post(route('clinica.mis-subespecialidades.guardar'), ['medico_id' => $medico->id])
            ->assertRedirect(route('clinica.mis-subespecialidades'));

        $this->assertSame([], $medico->subespecialidades()->pluck('subespecialidades.id')->all());
    }

    /** El postgrado de una especialidad que el médico **no** tiene no se guarda (la regla es del servicio). */
    public function test_no_se_puede_activar_un_postgrado_de_otra_especialidad(): void
    {
        [$user, $medico] = $this->medicoConPediatria();

        // Una especialidad que el médico no tiene, con su postgrado: se crea acá para no depender de qué
        // postgrados declare el catálogo sembrado.
        $otra = Specialty::create([
            'name' => 'Oftalmología', 'slug' => 'oftalmo-' . substr(uniqid(), -6),
            'codigo' => 'O' . random_int(10, 99), 'activo' => true,
        ]);
        $ajeno = Subspecialty::create([
            'specialty_id' => $otra->id, 'slug' => 'retina-' . substr(uniqid(), -6),
            'nombre' => 'Retina', 'orden' => 1, 'activo' => true,
        ]);

        $this->actingAs($user)
            ->post(route('clinica.mis-subespecialidades.guardar'), [
                'medico_id'         => $medico->id,
                'subespecialidades' => [$ajeno->id],
            ])
            ->assertRedirect();

        $this->assertSame([], $medico->subespecialidades()->pluck('subespecialidades.id')->all());
    }

    /** La ficha de otro médico no se puede tocar desde acá: 404. */
    public function test_no_se_puede_tocar_la_ficha_de_otro_medico(): void
    {
        [$user] = $this->medicoConPediatria();

        $sufijo = substr(uniqid(), -6);
        $ajeno = Medico::create([
            'name' => 'Otro', 'lastname' => 'Médico', 'reg_medico' => 'ajeno-' . $sufijo,
        ]);

        $this->actingAs($user)
            ->post(route('clinica.mis-subespecialidades.guardar'), ['medico_id' => $ajeno->id])
            ->assertNotFound();
    }

    public function test_un_invitado_no_entra(): void
    {
        $this->get(route('clinica.mis-subespecialidades'))->assertRedirect(route('login'));
    }
}
