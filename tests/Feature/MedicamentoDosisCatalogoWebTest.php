<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\User;
use App\Models\Vademecum;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **La dosis por kilo en el ABM del vademécum** (2026-10-19): los siete campos que el récipe necesita para calcular la
 * posología del niño ya se cargan desde la web, no sólo desde el escritorio.
 *
 * Lo que se fija:
 *  - el alta guarda `dosis`, `cada`, `durante`, `pvc`, `pvs` y `concentracion`, y la `dosificacion` con `SI`;
 *  - esos valores llegan al formulario de edición;
 *  - guardar los **mismos** números no deja rastro (el `2` del formulario y el `2.0` de la columna son el mismo valor);
 *  - un número que no es número se rechaza con su propio aviso, sin guardar nada.
 */
class MedicamentoDosisCatalogoWebTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $pediatria = Specialty::where('slug', 'pediatria')->firstOrFail();
        $regMedico = 'dosiscat' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Doctora', 'email' => 'dosiscat-' . substr(uniqid(), -6) . '@example.com',
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

    /** @return array<string,mixed> */
    private function datosDelAlta(array $extra = []): array
    {
        return $extra + [
            '_catalogo' => 'medicamento',
            'nombrecomercial' => 'AMOXICILINA',
            'nombregenerico' => 'Amoxicilina',
            'presentacion' => '250 mg / 5 ml',
            'dosificacion' => 'SI',
            'dosis' => '20',
            'cada' => '8',
            'durante' => '7',
            'pvc' => '250',
            'pvs' => '5',
            'concentracion' => '0',
        ];
    }

    public function test_el_abm_guarda_la_dosis_por_kilo(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $pediatria)
            ->post(route('clinica.catalogos.medicamentos.guardar'), $this->datosDelAlta())
            ->assertRedirect();

        $fila = Vademecum::where('reg_medico', $medico->reg_medico)->firstOrFail();

        $this->assertSame('SI', trim((string) $fila->dosificacion));
        $this->assertEqualsWithDelta(20.0, (float) $fila->dosis, 0.001);
        $this->assertEqualsWithDelta(8.0, (float) $fila->cada, 0.001);
        $this->assertEqualsWithDelta(7.0, (float) $fila->durante, 0.001);
        $this->assertEqualsWithDelta(250.0, (float) $fila->pvc, 0.001);
        $this->assertEqualsWithDelta(5.0, (float) $fila->pvs, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $fila->concentracion, 0.001);
    }

    public function test_el_formulario_de_edicion_trae_la_dosis(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $pediatria)
            ->post(route('clinica.catalogos.medicamentos.guardar'), $this->datosDelAlta());

        $fila = Vademecum::where('reg_medico', $medico->reg_medico)->firstOrFail();

        $this->conContexto($user, $medico, $pediatria)
            ->get(route('clinica.catalogos.medicamentos', ['editar' => $fila->id]))
            ->assertOk()
            ->assertSee('Dosis por kilo')
            ->assertSee('name="dosis"', false)
            ->assertSee('value="20"', false)
            ->assertSee('value="250"', false);
    }

    public function test_guardar_los_mismos_numeros_no_deja_rastro(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $pediatria)
            ->post(route('clinica.catalogos.medicamentos.guardar'), $this->datosDelAlta());

        $fila = Vademecum::where('reg_medico', $medico->reg_medico)->firstOrFail();
        $rastros = SyncChange::where('tabla', 'vademecum')->where('registro_id', (string) $fila->codemedicina)->count();

        // Se vuelve a guardar lo mismo, con los números escritos de otra forma (`8` contra `8.0`).
        $this->conContexto($user, $medico, $pediatria)
            ->post(route('clinica.catalogos.medicamentos.guardar'), $this->datosDelAlta([
                'id' => $fila->id,
                'cada' => '8.00',
                'pvc' => '250.0',
            ]))
            ->assertRedirect();

        $this->assertSame($rastros, SyncChange::where('tabla', 'vademecum')->where('registro_id', (string) $fila->codemedicina)->count());
    }

    public function test_una_dosis_que_no_es_numero_se_rechaza(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $pediatria)
            ->post(route('clinica.catalogos.medicamentos.guardar'), $this->datosDelAlta(['dosis' => 'veinte']))
            ->assertSessionHasErrors('dosis');

        $this->assertSame(0, Vademecum::where('reg_medico', $medico->reg_medico)->count());
    }
}
