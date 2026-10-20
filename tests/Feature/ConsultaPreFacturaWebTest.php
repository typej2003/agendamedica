<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\MotivoFactura;
use App\Models\PreFacturaConsulta;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **Pre-factura** de la consulta (F9): los conceptos de `motivo_factura` cargados en `his_con_pre_factura`.
 *
 * Lo que se fija:
 *  - la sección abre en las dos especialidades, con los ítems, sus subtotales y el total;
 *  - agregar un concepto del catálogo crea la fila con la descripción copiada, el monto del catálogo, el
 *    `id_registro` que sigue y su rastro `created`;
 *  - el mismo concepto se corrige en el lugar (sólo lo que cambió, `updated` por columna) y no se duplica;
 *  - un código fuera del catálogo se rechaza; quitar deja su `deleted`; la secretaría no puede escribir.
 */
class ConsultaPreFacturaWebTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $especialidad = 'pediatria'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $specialty = Specialty::where('slug', $especialidad)->firstOrFail();
        $regMedico = 'pf-test-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Doctora de prueba', 'email' => 'pf-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Doctora', 'lastname' => 'De Prueba', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($specialty->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $specialty];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null,
        ]])->actingAs($user);
    }

    private function consulta(Medico $medico): Consulta
    {
        $pacienteId = DB::table('pacientes')->insertGetId([
            'nombres' => 'Ana', 'apellidos' => 'Alvarez', 'cedula' => (string) random_int(10000000, 30000000),
            'sexo' => 'F', 'fnacimiento' => '2018-05-04', 'created_at' => now(), 'updated_at' => now(),
        ]);

        MedicoPaciente::create([
            'medico_id' => $medico->id, 'paciente_id' => $pacienteId,
            'reg_medico' => $medico->reg_medico, 'numhistoria' => '1001',
        ]);

        Historia::create([
            'numhistoria' => '1001', 'reg_medico' => $medico->reg_medico,
            'paciente_id' => $pacienteId, 'medico_id' => $medico->id,
        ]);

        return Consulta::create([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'nroconsulta' => 1,
            'fecha' => now()->toDateString(),
        ]);
    }

    private function catalogo(Medico $medico): void
    {
        MotivoFactura::create(['reg_medico' => $medico->reg_medico, 'codigo' => 'CON01', 'descripcion' => 'CONSULTA PEDIATRICA',
            'monto' => 150, 'monto_seg' => 120]);
        MotivoFactura::create(['reg_medico' => $medico->reg_medico, 'codigo' => 'NEB02', 'descripcion' => 'NEBULIZACION',
            'monto' => 35.5]);
    }

    private function url(Consulta $consulta): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => 'prefactura']);
    }

    public function test_la_seccion_lista_los_items_con_el_total_en_las_dos_especialidades(): void
    {
        foreach (['pediatria', 'ginecologia-y-obstetricia'] as $especialidad) {
            [$user, $medico, $specialty] = $this->medicoConAcceso($especialidad);
            $this->catalogo($medico);
            $consulta = $this->consulta($medico);

            PreFacturaConsulta::create(['reg_medico' => $medico->reg_medico, 'id_registro' => 7, 'numhistoria' => 1001,
                'nroconsulta' => 1, 'codigo' => 'CON01', 'descripcion' => 'CONSULTA PEDIATRICA', 'cantidad' => 1, 'monto' => 150]);
            PreFacturaConsulta::create(['reg_medico' => $medico->reg_medico, 'id_registro' => 8, 'numhistoria' => 1001,
                'nroconsulta' => 1, 'codigo' => 'NEB02', 'descripcion' => 'NEBULIZACION', 'cantidad' => 2, 'monto' => 35.5]);

            $this->conContexto($user, $medico, $specialty)
                ->get($this->url($consulta))
                ->assertOk()
                ->assertSee('Pre-factura')
                ->assertSee('CONSULTA PEDIATRICA')->assertSee('NEBULIZACION')
                ->assertSee('71,00')            // 2 × 35,50
                ->assertSee('221,00')           // el total
                ->assertSee('2 ítems')
                ->assertSee('(seguro: 120,00)', false);
        }
    }

    public function test_agregar_un_concepto_lo_crea_con_el_monto_del_catalogo_y_su_rastro(): void
    {
        [$user, $medico, $specialty] = $this->medicoConAcceso();
        $this->catalogo($medico);
        $consulta = $this->consulta($medico);

        // Otra fila del médico, de otra consulta: el correlativo sigue después de ella.
        PreFacturaConsulta::create(['reg_medico' => $medico->reg_medico, 'id_registro' => 41, 'numhistoria' => 1001,
            'nroconsulta' => 9, 'codigo' => 'CON01', 'cantidad' => 1, 'monto' => 150]);

        $this->conContexto($user, $medico, $specialty)
            ->post(route('clinica.consulta.prefactura.guardar', $consulta->id), ['codigo' => 'NEB02', 'cantidad' => '2'])
            ->assertRedirect($this->url($consulta))
            ->assertSessionHas('estado', 'Pre-factura guardada.');

        $fila = PreFacturaConsulta::where('nroconsulta', 1)->where('reg_medico', $medico->reg_medico)->first();
        $this->assertNotNull($fila);
        $this->assertSame('NEB02', $fila->codigo);
        $this->assertSame('NEBULIZACION', $fila->descripcion);
        $this->assertSame(2.0, $fila->cantidad);
        $this->assertSame(35.5, $fila->monto);
        $this->assertSame(42.0, $fila->id_registro);

        $rastro = SyncChange::where('table_name', 'his_con_pre_factura')
            ->where('record_id', $fila->id)->where('operation', 'created')->first();
        $this->assertNotNull($rastro);
        $this->assertSame('web', $rastro->source);
    }

    public function test_el_mismo_concepto_se_corrige_en_el_lugar_con_un_rastro_por_columna(): void
    {
        [$user, $medico, $specialty] = $this->medicoConAcceso();
        $this->catalogo($medico);
        $consulta = $this->consulta($medico);

        $fila = PreFacturaConsulta::create(['reg_medico' => $medico->reg_medico, 'id_registro' => 1, 'numhistoria' => 1001,
            'nroconsulta' => 1, 'codigo' => 'CON01', 'descripcion' => 'CONSULTA PEDIATRICA', 'cantidad' => 1, 'monto' => 150]);

        $this->conContexto($user, $medico, $specialty)
            ->post(route('clinica.consulta.prefactura.guardar', $consulta->id), ['codigo' => 'CON01', 'cantidad' => '1', 'monto' => '120'])
            ->assertSessionHas('estado', 'Pre-factura guardada.');

        $this->assertSame(1, PreFacturaConsulta::where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(120.0, $fila->fresh()->monto);

        $rastros = SyncChange::where('table_name', 'his_con_pre_factura')->where('record_id', $fila->id)->get();
        $this->assertSame(['monto'], $rastros->pluck('column_name')->all());

        // Lo mismo otra vez: no hay nada que escribir.
        $this->conContexto($user, $medico, $specialty)
            ->post(route('clinica.consulta.prefactura.guardar', $consulta->id), ['codigo' => 'CON01', 'cantidad' => '1', 'monto' => '120'])
            ->assertSessionHas('estado', 'Sin cambios: el concepto ya estaba así.');
    }

    public function test_un_codigo_fuera_del_catalogo_se_rechaza(): void
    {
        [$user, $medico, $specialty] = $this->medicoConAcceso();
        $this->catalogo($medico);
        $consulta = $this->consulta($medico);

        $this->conContexto($user, $medico, $specialty)
            ->from($this->url($consulta))
            ->post(route('clinica.consulta.prefactura.guardar', $consulta->id), ['codigo' => 'XXX', 'cantidad' => '1'])
            ->assertRedirect($this->url($consulta))
            ->assertSessionHasErrors('codigo');

        $this->assertSame(0, PreFacturaConsulta::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_quitar_un_concepto_deja_su_baja(): void
    {
        [$user, $medico, $specialty] = $this->medicoConAcceso();
        $this->catalogo($medico);
        $consulta = $this->consulta($medico);

        $fila = PreFacturaConsulta::create(['reg_medico' => $medico->reg_medico, 'id_registro' => 1, 'numhistoria' => 1001,
            'nroconsulta' => 1, 'codigo' => 'CON01', 'cantidad' => 1, 'monto' => 150]);

        $this->conContexto($user, $medico, $specialty)
            ->delete(route('clinica.consulta.prefactura.quitar', $consulta->id), ['codigo' => 'CON01'])
            ->assertRedirect($this->url($consulta))
            ->assertSessionHas('estado', 'Concepto quitado de la pre-factura.');

        $this->assertNull($fila->fresh());
        $this->assertNotNull(SyncChange::where('table_name', 'his_con_pre_factura')
            ->where('record_id', $fila->id)->where('operation', 'deleted')->first());
    }

    public function test_sin_catalogo_la_seccion_lo_avisa(): void
    {
        [$user, $medico, $specialty] = $this->medicoConAcceso();
        $consulta = $this->consulta($medico);

        $this->conContexto($user, $medico, $specialty)
            ->get($this->url($consulta))
            ->assertOk()
            ->assertSee('Todavía no hay conceptos en la pre-factura')
            ->assertSee('todavía no tiene conceptos de facturación')
            ->assertDontSee('id="prefactura-form"', false);
    }

    public function test_la_secretaria_no_puede_escribir(): void
    {
        [, $medico, $specialty] = $this->medicoConAcceso();
        $this->catalogo($medico);
        $consulta = $this->consulta($medico);

        $secretaria = User::create([
            'name' => 'Secretaria', 'email' => 'sec-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');

        MedicoRegistro::where('medico_id', $medico->id)
            ->where('reg_medico', $medico->reg_medico)
            ->update(['user_id' => $secretaria->id]);

        $this->conContexto($secretaria, $medico, $specialty)
            ->post(route('clinica.consulta.prefactura.guardar', $consulta->id), ['codigo' => 'CON01', 'cantidad' => '1'])
            ->assertForbidden();

        $this->assertSame(0, PreFacturaConsulta::where('reg_medico', $medico->reg_medico)->count());
    }
}
