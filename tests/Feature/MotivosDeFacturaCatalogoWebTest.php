<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * **Conceptos de la pre-factura** (`motivo_factura`, F9): el ABM en `/clinica/catalogos/motivos-factura`. Listado con buscador
 * sin tildes, alta con código del servidor y sin duplicar, edición de sólo lo que cambia con su rastro `source = web`,
 * y quitar = borrar si ninguna pre-factura lo usa (si lo usa, avisar dónde y no borrar).
 */
class MotivosDeFacturaCatalogoWebTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // --------------------------------------------------------------------------------------- Ayudas

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $rol = 'Medico'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'cat-clin-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'catclin-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr. de prueba', 'lastname' => 'Catalogos', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null,
        ]])->actingAs($user);
    }

    private function fila(string $tabla, Medico $medico, array $datos): int
    {
        return (int) DB::table($tabla)->insertGetId(array_merge([
            'reg_medico' => $medico->reg_medico, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ], $datos));
    }

    private function concepto(Medico $m, string $codigo, string $descripcion, float $monto = 10, ?float $seg = null): int
    {
        return $this->fila('motivo_factura', $m, ['codigo' => $codigo, 'descripcion' => $descripcion, 'monto' => $monto, 'monto_seg' => $seg]);
    }

    public function test_el_listado_busca_sin_tildes_y_es_solo_del_medico(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $this->concepto($medico, '001', 'CONSULTA GINECOLÓGICA', 50, 40);
        $this->concepto($medico, '002', 'COLPOSCOPIA', 80);
        $this->concepto($otro, '001', 'DE OTRO MEDICO');

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.motivos-factura'))
            ->assertOk()->assertSee('CONSULTA GINECOLÓGICA')->assertSee('COLPOSCOPIA')->assertDontSee('DE OTRO MEDICO')
            ->assertSee('50,00')->assertSee('40,00');

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.motivos-factura', ['buscar' => 'ginecologica']))
            ->assertOk()->assertSee('CONSULTA GINECOLÓGICA')->assertDontSee('COLPOSCOPIA');
    }

    public function test_el_alta_numera_en_el_servidor_guarda_en_mayusculas_y_deja_su_rastro(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->concepto($medico, '007', 'EXISTENTE');

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.motivos-factura.guardar'), [
            'descripcion' => '  papanicolau   completo ', 'monto' => '25,5', 'monto_seg' => '',
        ])->assertSessionDoesntHaveErrors();

        $fila = DB::table('motivo_factura')->where('reg_medico', $medico->reg_medico)->where('descripcion', 'PAPANICOLAU COMPLETO')->first();
        $this->assertNotNull($fila);
        $this->assertSame('008', $fila->codigo);
        $this->assertEquals(25.5, $fila->monto);
        $this->assertNull($fila->monto_seg);
        $this->assertSame(['created'], SyncChange::where('table_name', 'motivo_factura')->where('record_id', $fila->id)->pluck('operation')->all());
    }

    public function test_el_alta_rechaza_nombre_vacio_monto_invalido_y_duplicado(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->concepto($medico, '001', 'COLPOSCOPIA');
        $ruta = route('clinica.catalogos.motivos-factura.guardar');

        $this->conContexto($user, $medico, $gineco)->post($ruta, ['descripcion' => '', 'monto' => '5'])->assertSessionHasErrors('descripcion');
        $this->conContexto($user, $medico, $gineco)->post($ruta, ['descripcion' => 'NUEVO', 'monto' => ''])->assertSessionHasErrors('monto');
        $this->conContexto($user, $medico, $gineco)->post($ruta, ['descripcion' => 'NUEVO', 'monto' => '-1'])->assertSessionHasErrors('monto');
        $this->conContexto($user, $medico, $gineco)->post($ruta, ['descripcion' => 'NUEVO', 'monto' => '5', 'monto_seg' => 'abc'])->assertSessionHasErrors('monto_seg');
        $this->conContexto($user, $medico, $gineco)->post($ruta, ['descripcion' => str_repeat('A', 46), 'monto' => '5'])->assertSessionHasErrors('descripcion');
        $this->conContexto($user, $medico, $gineco)->post($ruta, ['descripcion' => 'colposcopía', 'monto' => '5'])->assertSessionHasErrors('descripcion');

        $this->assertSame(1, DB::table('motivo_factura')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_editar_escribe_solo_lo_que_cambia_y_el_codigo_no_se_toca(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $id = $this->concepto($medico, '001', 'COLPOSCOPIA', 80);
        $ruta = route('clinica.catalogos.motivos-factura.guardar');

        $this->conContexto($user, $medico, $gineco)->post($ruta, ['id' => $id, 'descripcion' => 'COLPOSCOPIA', 'monto' => '80', 'monto_seg' => '']);
        $this->assertCount(0, SyncChange::where('table_name', 'motivo_factura')->where('record_id', $id)->get());

        $this->conContexto($user, $medico, $gineco)->post($ruta, ['id' => $id, 'descripcion' => 'colposcopia', 'monto' => '90', 'monto_seg' => '70', 'codigo' => '999']);
        $fila = DB::table('motivo_factura')->find($id);
        $this->assertSame('001', $fila->codigo);
        $this->assertEquals(90, $fila->monto);
        $this->assertEquals(70, $fila->monto_seg);
        $this->assertEqualsCanonicalizing(['monto', 'monto_seg'], SyncChange::where('table_name', 'motivo_factura')->where('record_id', $id)->pluck('column_name')->all());
    }

    public function test_quitar_borra_si_no_tiene_uso_y_avisa_si_una_prefactura_lo_usa(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $usado = $this->concepto($medico, '001', 'COLPOSCOPIA');
        $libre = $this->concepto($medico, '002', 'MATERIAL');
        DB::table('his_con_pre_factura')->insert([
            'reg_medico' => $medico->reg_medico, 'id_registro' => 1, 'numhistoria' => 1001, 'nroconsulta' => 1,
            'codigo' => '001', 'descripcion' => 'COLPOSCOPIA', 'cantidad' => 1, 'monto' => 80,
        ]);

        $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.motivos-factura.quitar', $usado))->assertSessionHas('error');
        $this->assertStringContainsString('1 consulta con pre-factura', session('error'));
        $this->assertNotNull(DB::table('motivo_factura')->find($usado));

        $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.motivos-factura.quitar', $libre))->assertSessionHas('estado');
        $this->assertNull(DB::table('motivo_factura')->find($libre));
        $this->assertTrue(SyncChange::where('table_name', 'motivo_factura')->where('record_id', $libre)->where('operation', 'deleted')->exists());
    }

    public function test_el_concepto_de_otro_medico_da_404_la_secretaria_403_y_el_invitado_va_al_login(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $ajeno = $this->concepto($otro, '001', 'AJENO');

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.motivos-factura', ['editar' => $ajeno]))->assertNotFound();
        $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.motivos-factura.quitar', $ajeno))->assertNotFound();

        [$sec, $medicoSec, $especialidad] = $this->medicoConAcceso('Secretaria');
        $this->conContexto($sec, $medicoSec, $especialidad)->get(route('clinica.catalogos.motivos-factura'))->assertForbidden();

        auth()->logout();
        $this->get(route('clinica.catalogos.motivos-factura'))->assertRedirect();
    }

    public function test_el_submenu_de_catalogos_lo_ofrece(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.motivos-factura'))
            ->assertOk()->assertSee(route('clinica.catalogos.motivos-factura'), false)->assertSee('Conceptos de factura');
    }
}
