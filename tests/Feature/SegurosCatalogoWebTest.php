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
use Tests\TestCase;

/**
 * **Seguros / empresas del consultorio** (O-62, `seg_emp`): el ABM en `/clinica/catalogos/seguros`. Listado con
 * buscador sin tildes, alta con el nombre obligatorio y el resto opcional, código del servidor con la regla del
 * legado (dos dígitos, sin tocar `00` = PERSONAL), edición de sólo lo que cambia con su rastro `source = web`, y
 * quitar = borrar si **ningún paciente** de ese consultorio lo tiene (si lo tiene, avisar quiénes y no borrar).
 *
 * El último test es el que cierra la observación: lo que se carga acá aparece en el desplegable «Seguro / empresa»
 * del alta del paciente.
 */
class SegurosCatalogoWebTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-05 09:00:00'));
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
        $regMedico = 'seg-cat-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'segcat-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr. de prueba', 'lastname' => 'Seguros', 'email' => $user->email,
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

    /** @param  array<string,mixed>  $extra */
    private function seguro(Medico $medico, string $codigo, string $nombre, array $extra = []): int
    {
        return (int) DB::table('seg_emp')->insertGetId(array_merge([
            'reg_medico' => $medico->reg_medico, 'codesegemp' => $codigo, 'nombre' => $nombre,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ], $extra));
    }

    /** Un paciente del consultorio con su seguro; `reg_medico` es el de la ficha (el tenant del primer vínculo). */
    private function paciente(Medico $medico, string $codigo): int
    {
        return (int) DB::table('pacientes')->insertGetId([
            'reg_medico' => $medico->reg_medico, 'nombres' => 'ANA', 'apellidos' => 'REYNOSO',
            'cedula' => '12345678', 'codesegemp' => $codigo,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    // ------------------------------------------------------------------------------------ El listado

    public function test_el_listado_muestra_los_seguros_del_consultorio_y_busca_sin_tildes(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $this->seguro($medico, '00', 'PERSONAL');
        $this->seguro($medico, '03', 'CADELA REGIÓN LOS ANDES', ['rif' => 'J-30123456-7']);
        $this->seguro($otro, '01', 'DE OTRO CONSULTORIO');

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.seguros'))
            ->assertOk()
            ->assertSee('PERSONAL')
            ->assertSee('CADELA REGIÓN LOS ANDES')
            ->assertSee('J-30123456-7')
            ->assertDontSee('DE OTRO CONSULTORIO');

        // El buscador no distingue tildes ni mayúsculas y mira el nombre y el RIF.
        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.seguros', ['buscar' => 'region']))
            ->assertOk()->assertSee('CADELA REGIÓN LOS ANDES')->assertDontSee('PERSONAL');

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.seguros', ['buscar' => '30123456']))
            ->assertOk()->assertSee('CADELA REGIÓN LOS ANDES')->assertDontSee('PERSONAL');
    }

    // --------------------------------------------------------------------------------------- El alta

    public function test_el_alta_numera_en_dos_digitos_respeta_personal_y_guarda_el_nombre_en_mayusculas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->seguro($medico, '00', 'PERSONAL');
        $this->seguro($medico, '03', 'DESURCA CADAFE');

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.seguros.guardar'), [
            'nombre' => '  seguro   la salud ',
            'rif' => 'j-12345678-9',
            'telef' => '0212-555.44.33',
            'direccion' => 'Av. Principal, torre 5',
        ])->assertSessionDoesntHaveErrors();

        $fila = DB::table('seg_emp')->where('reg_medico', $medico->reg_medico)->where('nombre', 'SEGURO LA SALUD')->first();
        $this->assertNotNull($fila);
        // El mayor numérico del consultorio era 03: el nuevo es 04, con dos dígitos y sin reciclar el 00 de PERSONAL.
        $this->assertSame('04', $fila->codesegemp);
        $this->assertSame('J-12345678-9', $fila->rif);
        $this->assertSame('0212-555.44.33', $fila->telef);
        $this->assertSame('Av. Principal, torre 5', $fila->direccion);
        $this->assertSame(['created'], SyncChange::where('table_name', 'seg_emp')->where('record_id', $fila->id)->pluck('operation')->all());

        // Y el 00 sigue siendo PERSONAL: la pantalla lo lista y no lo reescribe.
        $personal = DB::table('seg_emp')->where('reg_medico', $medico->reg_medico)->where('codesegemp', '00')->first();
        $this->assertSame('PERSONAL', $personal->nombre);
    }

    public function test_el_alta_rechaza_el_nombre_vacio_los_largos_y_el_duplicado(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->seguro($medico, '00', 'PERSONAL');
        $ruta = route('clinica.catalogos.seguros.guardar');

        $this->conContexto($user, $medico, $gineco)->post($ruta, ['nombre' => '   '])->assertSessionHasErrors('nombre');
        $this->conContexto($user, $medico, $gineco)->post($ruta, ['nombre' => str_repeat('A', 151)])->assertSessionHasErrors('nombre');
        $this->conContexto($user, $medico, $gineco)->post($ruta, ['nombre' => 'HORIZONTE', 'rif' => str_repeat('J', 51)])->assertSessionHasErrors('rif');
        $this->conContexto($user, $medico, $gineco)->post($ruta, ['nombre' => 'HORIZONTE', 'telef' => str_repeat('1', 51)])->assertSessionHasErrors('telef');
        $this->conContexto($user, $medico, $gineco)->post($ruta, ['nombre' => 'HORIZONTE', 'direccion' => str_repeat('A', 351)])->assertSessionHasErrors('direccion');
        // El mismo nombre sin tildes ni mayúsculas ya está en el catálogo del consultorio.
        $this->conContexto($user, $medico, $gineco)->post($ruta, ['nombre' => 'personal'])->assertSessionHasErrors('nombre');

        $this->assertSame(1, DB::table('seg_emp')->where('reg_medico', $medico->reg_medico)->count());
    }

    /**
     * El correlativo del legado son dos dígitos: los códigos llegan hasta `99` (100 seguros con el `00`), y el que
     * sobra no entra —el `right("00"+…,2)` del escritorio escribiría `00` y chocaría con PERSONAL—.
     */
    public function test_el_catalogo_no_pasa_del_99_y_el_aviso_lo_dice(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        for ($numero = 0; $numero <= 99; $numero++) {
            $this->seguro($medico, str_pad((string) $numero, 2, '0', STR_PAD_LEFT), 'SEGURO ' . $numero);
        }

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.seguros.guardar'), ['nombre' => 'EL QUE NO ENTRA'])
            ->assertSessionHasErrors('nombre');
        $this->assertStringContainsString('está lleno', session('errors')->first('nombre'));
        $this->assertSame(100, DB::table('seg_emp')->where('reg_medico', $medico->reg_medico)->count());
    }

    // ------------------------------------------------------------------------------------- La edición

    public function test_editar_escribe_solo_lo_que_cambia_y_el_codigo_no_se_toca(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $id = $this->seguro($medico, '04', 'HORIZONTE', ['rif' => 'J-1', 'telef' => '0212-1']);
        $ruta = route('clinica.catalogos.seguros.guardar');

        // Los mismos datos no escriben nada.
        $this->conContexto($user, $medico, $gineco)->post($ruta, [
            'id' => $id, 'nombre' => 'HORIZONTE', 'rif' => 'J-1', 'telef' => '0212-1', 'direccion' => '',
        ])->assertSessionDoesntHaveErrors();
        $this->assertCount(0, SyncChange::where('table_name', 'seg_emp')->where('record_id', $id)->get());

        $this->conContexto($user, $medico, $gineco)->post($ruta, [
            'id' => $id, 'nombre' => ' horizonte   salud ', 'rif' => 'J-2', 'telef' => '', 'direccion' => 'Calle 8',
            'codigo' => '99',
        ])->assertSessionDoesntHaveErrors();

        $fila = DB::table('seg_emp')->find($id);
        $this->assertSame('04', $fila->codesegemp, 'El código es con el que el paciente guarda su seguro: no se edita.');
        $this->assertSame('HORIZONTE SALUD', $fila->nombre);
        $this->assertSame('J-2', $fila->rif);
        $this->assertNull($fila->telef);
        $this->assertSame('Calle 8', $fila->direccion);
        $this->assertEqualsCanonicalizing(
            ['nombre', 'rif', 'telef', 'direccion'],
            SyncChange::where('table_name', 'seg_emp')->where('record_id', $id)->pluck('column_name')->all()
        );
    }

    // ---------------------------------------------------------------------------------------- La baja

    public function test_no_se_quita_un_seguro_que_usa_un_paciente_y_si_uno_libre(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $usado = $this->seguro($medico, '04', 'HORIZONTE');
        $libre = $this->seguro($medico, '05', 'SIN NADIE');
        $this->paciente($medico, '04');

        $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.seguros.quitar', $usado))
            ->assertSessionHas('error');
        // El aviso dice cuántos y quién: es lo que el médico necesita para cambiarle el seguro en la ficha.
        $this->assertStringContainsString('1 paciente', session('error'));
        $this->assertStringContainsString('REYNOSO ANA', session('error'));
        $this->assertNotNull(DB::table('seg_emp')->find($usado));

        $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.seguros.quitar', $libre))
            ->assertSessionHas('estado');
        $this->assertNull(DB::table('seg_emp')->find($libre));
        $this->assertTrue(SyncChange::where('table_name', 'seg_emp')->where('record_id', $libre)->where('operation', 'deleted')->exists());
    }

    // --------------------------------------------------------------- Lo que cierra la observación (O-62)

    public function test_lo_que_se_carga_en_el_catalogo_aparece_en_el_alta_del_paciente(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->seguro($medico, '00', 'PERSONAL');

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.seguros.guardar'), [
            'nombre' => 'Seguro La Salud',
        ])->assertSessionDoesntHaveErrors();

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.pacientes.nuevo'))
            ->assertOk()
            ->assertSee('SEGURO LA SALUD')
            ->assertDontSee('Este consultorio todavía no tiene seguros cargados');
    }

    // --------------------------------------------------------------------------------------- Permisos

    public function test_el_seguro_de_otro_consultorio_da_404_la_secretaria_403_y_el_invitado_va_al_login(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $ajeno = $this->seguro($otro, '04', 'AJENO');

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.seguros', ['editar' => $ajeno]))->assertNotFound();
        $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.seguros.quitar', $ajeno))->assertNotFound();

        [$sec, $medicoSec, $especialidad] = $this->medicoConAcceso('Secretaria');
        $this->conContexto($sec, $medicoSec, $especialidad)->get(route('clinica.catalogos.seguros'))->assertForbidden();

        auth()->logout();
        $this->get(route('clinica.catalogos.seguros'))->assertRedirect();
    }

    public function test_el_submenu_de_catalogos_lo_ofrece(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.seguros'))
            ->assertOk()
            ->assertSee(route('clinica.catalogos.seguros'), false)
            ->assertSee('Seguros / empresas');
    }
}
