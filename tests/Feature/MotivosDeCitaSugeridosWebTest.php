<?php

namespace Tests\Feature;

use App\Actions\Catalogos\AgregarMotivosSugeridos;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\MotivoCita;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **El motivo de cita «Control de niño sano»** (WEB-7.4.4f): pediatría lo **sugiere** en `/clinica/catalogos/motivos-cita` y el médico lo agrega con un
 * botón (no se siembra solo). Idempotente, sin tocar lo que ya tiene, y sólo se ofrece en pediatría y al médico.
 */
class MotivosDeCitaSugeridosWebTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $especialidad = 'pediatria'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $esp = Specialty::firstOrCreate(['slug' => $especialidad], ['name' => ucfirst($especialidad)]);
        $reg = 'motivo-sug-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'motivo-sug-' . substr(uniqid(), -6) . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');
        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dra. de prueba', 'lastname' => 'Sugerido', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $reg,
        ]);
        $medico->specialties()->attach($esp->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        return [$user, $medico, $esp];
    }

    private function conContexto(Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => ['reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null]]);
    }

    public function test_pediatria_ofrece_el_motivo_y_el_boton_lo_agrega_una_sola_vez(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $url = route('clinica.catalogos.motivos-cita');

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($url)
            ->assertOk()->assertSee('id="motivos-sugeridos"', false)->assertSee('CONTROL DE NIÑO SANO')->assertSee('Agregar a mis motivos');

        $this->conContexto($medico, $pediatria)->actingAs($user)->post(route('clinica.catalogos.motivos-cita.sugeridos'))
            ->assertRedirect($url)->assertSessionHas('estado');

        $fila = MotivoCita::where('reg_medico', $medico->reg_medico)->firstOrFail();
        $this->assertSame('CONTROL DE NIÑO SANO', $fila->tipo_atencion);
        $this->assertNull($fila->precio);
        $this->assertNull($fila->office_id);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($url)->assertOk()->assertDontSee('id="motivos-sugeridos"', false);
        $this->conContexto($medico, $pediatria)->actingAs($user)->post(route('clinica.catalogos.motivos-cita.sugeridos'))
            ->assertSessionHas('estado', 'Ya tienes los motivos que sugiere tu especialidad: no se agregó nada.');
        $this->assertSame(1, MotivoCita::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_un_motivo_parecido_que_el_medico_ya_tiene_no_se_vuelve_a_ofrecer(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        MotivoCita::create(['reg_medico' => $medico->reg_medico, 'codigo' => '01', 'tipo_atencion' => 'control de nino sano']);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.catalogos.motivos-cita'))
            ->assertOk()->assertDontSee('id="motivos-sugeridos"', false);
        $this->assertSame([], app(AgregarMotivosSugeridos::class)->ejecutar($medico, $medico->reg_medico, 'pediatria'));
        $this->assertSame(1, MotivoCita::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_en_otras_especialidades_no_se_ofrece_ni_se_agrega(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso('ginecologia-y-obstetricia');

        $this->conContexto($medico, $gineco)->actingAs($user)->get(route('clinica.catalogos.motivos-cita'))
            ->assertOk()->assertDontSee('id="motivos-sugeridos"', false);
        $this->conContexto($medico, $gineco)->actingAs($user)->post(route('clinica.catalogos.motivos-cita.sugeridos'));
        $this->assertSame(0, MotivoCita::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_cada_medico_recibe_el_suyo_y_la_secretaria_no_puede(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();

        $this->conContexto($medico, $pediatria)->actingAs($user)->post(route('clinica.catalogos.motivos-cita.sugeridos'));
        $this->assertSame(1, MotivoCita::where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(0, MotivoCita::where('reg_medico', $otro->reg_medico)->count());

        $secretaria = User::create([
            'name' => 'Secretaria', 'email' => 'motivo-sug-se' . substr(uniqid(), -6) . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $otro->reg_medico]);
        $this->conContexto($otro, $pediatria)->actingAs($secretaria)->post(route('clinica.catalogos.motivos-cita.sugeridos'))->assertForbidden();
        $this->assertSame(0, MotivoCita::where('reg_medico', $otro->reg_medico)->count());
    }
}
