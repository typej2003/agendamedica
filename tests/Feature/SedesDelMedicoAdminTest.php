<?php

namespace Tests\Feature;

use App\Http\Livewire\Admin\Sedes\ConsultoriosDeLaSede;
use App\Http\Livewire\Admin\Sedes\HorariosDelConsultorio;
use App\Http\Livewire\Admin\Sedes\SedesDelMedico;
use App\Models\City;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Models\SyncChange;
use App\Models\User;
use App\Services\CuentaService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Panel · **Sedes del médico** (`/admin/medicos/{medico}/sedes` → consultorios → horarios).
 *
 * Lo que fijan: sólo entran los administradores; se navega por niveles con migas; cada nivel da de alta, edita y
 * quita con las Actions de `/clinica` (la baja de sede y de consultorio **no borra**: desactiva); y el tenant es
 * el `reg_medico` del médico de la ruta, así que un consultorio o un bloque de otro médico da 404.
 */
class SedesDelMedicoAdminTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function admin(): User
    {
        $user = app(CuentaService::class)->crearAdministrador('Admin Sedes', 'admin-sedes-' . substr(uniqid(), -6) . '@example.com')['user'];
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }

    /** @return array{user: User, medico: Medico} */
    private function medico(string $nombre = 'Doctor'): array
    {
        $sufijo = uniqid();
        $r = app(CuentaService::class)->crearMedico([
            'name' => $nombre, 'lastname' => 'Sedes', 'email' => "sedes-{$sufijo}@example.com", 'reg_medico' => "test-sedes-{$sufijo}",
        ]);
        $r['user']->forceFill(['must_change_password' => false])->save();

        return $r;
    }

    private function sede(string $nombre = 'Sede de prueba', bool $activa = true, ?Medico $duenio = null): MedicalCenter
    {
        return MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id'   => City::firstOrFail()->state_id,
            'city_id'    => City::firstOrFail()->id,
            'medico_id'  => $duenio?->id,
            'name'       => $nombre . ' ' . substr(uniqid(), -6),
            'address'    => 'Av. Principal',
            'activo'     => $activa,
        ]);
    }

    private function consultorio(Medico $medico, MedicalCenter $sede, string $numero = '101', bool $activo = true): Office
    {
        return Office::create([
            'medical_center_id' => $sede->id,
            'medico_id'         => $medico->id,
            'reg_medico'        => $medico->reg_medico,
            'office_number'     => $numero,
            'modalidad'         => Office::MODALIDAD_ORDEN,
            'duracion_cita'     => 20,
            'activo'            => $activo,
        ]);
    }

    /**
     * Lo ajeno no existe: en la página es un 404 (el `firstOrFail` lo convierte el manejador de excepciones); en la
     * prueba de Livewire la excepción sube tal cual.
     */
    private function fueraDeAlcance(callable $accion): void
    {
        try {
            $accion();
        } catch (ModelNotFoundException $e) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Se esperaba un 404 (ModelNotFoundException).');
    }

    /* ------------------------------ acceso y navegación ------------------------------ */

    public function test_solo_entran_los_administradores(): void
    {
        $medico = $this->medico()['medico'];
        $sede = $this->sede();
        $consultorio = $this->consultorio($medico, $sede);
        $urls = [
            route('admin.medicos.sedes', $medico),
            route('admin.medicos.sedes.consultorios', [$medico, $sede]),
            route('admin.medicos.sedes.consultorios.horarios', [$medico, $sede, $consultorio]),
        ];

        foreach ($urls as $url) {
            $this->get($url)->assertRedirect('/login');
        }

        $this->actingAs($this->medico('Comun')['user']);
        foreach ($urls as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_se_navega_por_niveles_con_migas_y_el_listado_de_medicos_enlaza(): void
    {
        $medico = $this->medico('Navegante')['medico'];
        $sede = $this->sede('Clínica Norte');
        $consultorio = $this->consultorio($medico, $sede, '305');
        OfficeSchedule::create([
            'office_id' => $consultorio->id, 'reg_medico' => $medico->reg_medico,
            'dia_semana' => 1, 'hora_inicio' => '08:00', 'hora_fin' => '12:00', 'cupo' => 15,
        ]);
        $this->actingAs($this->admin());

        $this->get(route('admin.cuentas'))->assertOk()->assertSee(route('admin.medicos.sedes', $medico), false);

        $this->get(route('admin.medicos.sedes', $medico))->assertOk()
            ->assertSee('Sedes de Navegante Sedes')
            ->assertSee($sede->name)
            ->assertSee(route('admin.medicos.sedes.consultorios', [$medico, $sede]), false);

        $this->get(route('admin.medicos.sedes.consultorios', [$medico, $sede]))->assertOk()
            ->assertSee('breadcrumb', false)
            ->assertSee('305')
            ->assertSee('Orden de llegada')
            ->assertSee(route('admin.medicos.sedes.consultorios.horarios', [$medico, $sede, $consultorio]), false);

        $this->get(route('admin.medicos.sedes.consultorios.horarios', [$medico, $sede, $consultorio]))->assertOk()
            ->assertSeeInOrder(['Médicos', 'Navegante Sedes', 'Sedes', $sede->name, 'Consultorios', 'Consultorio 305', 'Horarios'])
            ->assertSee('Lunes')
            ->assertSee('15 pacientes');
    }

    /* ------------------------------ sedes ------------------------------ */

    public function test_agregar_una_sede_existente_crea_el_consultorio_del_medico(): void
    {
        $medico = $this->medico()['medico'];
        $sede = $this->sede('Existente');
        $this->actingAs($this->admin());

        Livewire::test(SedesDelMedico::class, ['medico' => $medico])
            ->call('abrirAgregar')
            ->set('modo', 'existente')
            ->set('sede_existente_id', (string) $sede->id)
            ->set('office_number', '12')
            ->set('modalidad', Office::MODALIDAD_HORA)
            ->set('duracion_cita', '30')
            ->call('guardarAlta')
            ->assertHasNoErrors()
            ->assertSet('modal', null)
            ->assertSee($sede->name);

        $this->assertDatabaseHas('offices', [
            'medical_center_id' => $sede->id, 'reg_medico' => $medico->reg_medico, 'medico_id' => $medico->id,
            'office_number' => '12', 'modalidad' => Office::MODALIDAD_HORA, 'duracion_cita' => 30, 'activo' => true,
        ]);
    }

    public function test_agregar_una_sede_nueva_la_registra_en_el_catalogo_con_su_consultorio(): void
    {
        $medico = $this->medico()['medico'];
        $ciudad = City::firstOrFail();
        $nombre = 'Sede nueva ' . uniqid();
        $this->actingAs($this->admin());

        Livewire::test(SedesDelMedico::class, ['medico' => $medico])
            ->call('abrirAgregar')
            ->set('modo', 'nueva')
            ->set('name', $nombre)
            ->set('address', 'Calle 5')
            ->set('city_id', (string) $ciudad->id)
            ->set('office_number', 'A1')
            ->call('guardarAlta')
            ->assertHasNoErrors();

        $sede = MedicalCenter::where('name', $nombre)->firstOrFail();
        $this->assertSame($ciudad->state_id, (int) $sede->state_id);
        $this->assertTrue((bool) $sede->activo);
        // La sede nueva queda **a nombre del médico de la ficha**.
        $this->assertSame($medico->id, (int) $sede->medico_id);
        $this->assertDatabaseHas('offices', ['medical_center_id' => $sede->id, 'reg_medico' => $medico->reg_medico, 'office_number' => 'A1']);
    }

    public function test_el_alta_valida_la_sede_y_el_consultorio_y_no_deja_nada_a_medias(): void
    {
        $medico = $this->medico()['medico'];
        // La repetida es **del mismo médico**: la unicidad del nombre es por médico, no global.
        $repetida = $this->sede('Repetida', duenio: $medico);
        $this->actingAs($this->admin());

        Livewire::test(SedesDelMedico::class, ['medico' => $medico])
            ->call('abrirAgregar')
            ->set('modo', 'nueva')
            ->set('name', $repetida->name)
            ->set('address', '')
            ->call('guardarAlta')
            ->assertHasErrors(['name' => 'unique', 'address' => 'required', 'city_id' => 'required', 'office_number' => 'required']);

        $this->assertSame(0, Office::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_editar_la_sede_no_la_reactiva_y_avisa_que_la_comparten(): void
    {
        $medico = $this->medico()['medico'];
        $otro = $this->medico('Vecino')['medico'];
        $sede = $this->sede('Compartida', false);
        $this->consultorio($medico, $sede);
        $this->consultorio($otro, $sede, 'AJ-900');
        $this->actingAs($this->admin());

        Livewire::test(SedesDelMedico::class, ['medico' => $medico])
            ->call('abrirEditar', $sede->id)
            ->assertSee('1 médico más atiende')
            ->set('address', 'Dirección corregida')
            ->call('guardarEdicion')
            ->assertHasNoErrors();

        $sede->refresh();
        $this->assertSame('Dirección corregida', $sede->address);
        $this->assertFalse((bool) $sede->activo);
    }

    public function test_quitar_la_sede_da_de_baja_sus_consultorios_sin_borrar_nada(): void
    {
        $medico = $this->medico()['medico'];
        $otro = $this->medico('Vecino')['medico'];
        $sede = $this->sede('A quitar');
        $propio = $this->consultorio($medico, $sede);
        $ajeno = $this->consultorio($otro, $sede, 'AJ-900');
        $this->actingAs($this->admin());

        Livewire::test(SedesDelMedico::class, ['medico' => $medico])
            ->call('abrirQuitar', $sede->id)
            ->assertSee('no se borra nada')
            ->call('confirmarQuitar')
            ->assertSee('Este médico todavía no tiene sedes');

        $this->assertFalse($propio->fresh()->activo);
        $this->assertTrue($ajeno->fresh()->activo);
        $this->assertTrue((bool) $sede->fresh()->activo);
        $this->assertNotNull(MedicalCenter::find($sede->id));
    }

    public function test_no_se_tocan_las_sedes_donde_el_medico_no_tiene_consultorio(): void
    {
        $medico = $this->medico()['medico'];
        $otro = $this->medico('Vecino')['medico'];
        $sedeAjena = $this->sede('Ajena');
        $this->consultorio($otro, $sedeAjena, 'AJ-900');
        $this->actingAs($this->admin());

        $this->get(route('admin.medicos.sedes', $medico))->assertOk()->assertDontSee($sedeAjena->name);

        Livewire::test(SedesDelMedico::class, ['medico' => $medico])->call('abrirQuitar', $sedeAjena->id)->assertStatus(404);
        Livewire::test(SedesDelMedico::class, ['medico' => $medico])->call('abrirEditar', $sedeAjena->id)->assertStatus(404);
    }

    /* ------------------------------ consultorios ------------------------------ */

    public function test_consultorios_alta_edicion_baja_y_reactivacion(): void
    {
        $medico = $this->medico()['medico'];
        $sede = $this->sede();
        $this->actingAs($this->admin());

        $componente = Livewire::test(ConsultoriosDeLaSede::class, ['medico' => $medico, 'sede' => $sede])
            ->call('abrirAgregar')
            ->set('office_number', '210')
            ->set('modalidad', Office::MODALIDAD_ORDEN)
            ->call('guardar')
            ->assertHasNoErrors();

        $consultorio = Office::where('reg_medico', $medico->reg_medico)->where('office_number', '210')->firstOrFail();
        $this->assertNull($consultorio->duracion_cita);

        $componente->call('abrirEditar', $consultorio->id)
            ->assertSet('office_number', '210')
            ->set('modalidad', Office::MODALIDAD_HORA)
            ->set('duracion_cita', '45')
            ->call('guardar')
            ->assertHasNoErrors();
        $this->assertSame(1, Office::where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(45, $consultorio->fresh()->duracion_cita);

        $componente->call('abrirQuitar', $consultorio->id)->call('confirmarQuitar');
        $this->assertFalse($consultorio->fresh()->activo);
        $this->assertNotNull(Office::find($consultorio->id));

        $componente->set('verInactivos', true)->call('reactivar', $consultorio->id);
        $this->assertTrue($consultorio->fresh()->activo);
    }

    public function test_editar_un_consultorio_de_baja_no_lo_reactiva(): void
    {
        $medico = $this->medico()['medico'];
        $sede = $this->sede();
        $consultorio = $this->consultorio($medico, $sede, '50', false);
        $this->actingAs($this->admin());

        Livewire::test(ConsultoriosDeLaSede::class, ['medico' => $medico, 'sede' => $sede])
            ->call('abrirEditar', $consultorio->id)
            ->set('phone', '0212-555')
            ->call('guardar')
            ->assertHasNoErrors();

        $this->assertFalse($consultorio->fresh()->activo);
        $this->assertSame('0212-555', $consultorio->fresh()->phone);
    }

    public function test_en_una_sede_desactivada_no_se_agrega_consultorio(): void
    {
        $medico = $this->medico()['medico'];
        $sede = $this->sede('Cerrada', false);
        $this->actingAs($this->admin());

        Livewire::test(ConsultoriosDeLaSede::class, ['medico' => $medico, 'sede' => $sede])
            ->call('abrirAgregar')
            ->set('office_number', '1')
            ->call('guardar')
            ->assertHasErrors(['office_number']);

        $this->assertSame(0, Office::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_el_consultorio_de_otro_medico_da_404(): void
    {
        $medico = $this->medico()['medico'];
        $otro = $this->medico('Vecino')['medico'];
        $sede = $this->sede();
        $ajeno = $this->consultorio($otro, $sede, 'AJ-900');
        $this->actingAs($this->admin());

        $this->get(route('admin.medicos.sedes.consultorios', [$medico, $sede]))->assertOk()->assertDontSee('AJ-900');
        $this->get(route('admin.medicos.sedes.consultorios.horarios', [$medico, $sede, $ajeno]))->assertNotFound();

        $this->fueraDeAlcance(fn () => Livewire::test(ConsultoriosDeLaSede::class, ['medico' => $medico, 'sede' => $sede])
            ->call('abrirEditar', $ajeno->id));
        $this->fueraDeAlcance(fn () => Livewire::test(ConsultoriosDeLaSede::class, ['medico' => $medico, 'sede' => $sede])
            ->call('abrirQuitar', $ajeno->id));
        $this->fueraDeAlcance(fn () => Livewire::test(ConsultoriosDeLaSede::class, ['medico' => $medico, 'sede' => $sede])
            ->call('reactivar', $ajeno->id));
        $this->assertTrue($ajeno->fresh()->activo);
    }

    public function test_el_consultorio_de_un_centro_creado_por_el_doctor_no_aparece_en_la_ficha_del_doctor(): void
    {
        // El doctor que trabaja en un centro crea consultorios con el `reg_medico` del centro y su `medico_id`: son
        // del centro, y editarlos desde el doctor los habría mudado de tenant.
        $doctor = $this->medico('Doctor')['medico'];
        $centro = $this->medico('Centro')['medico'];
        $sede = $this->sede();
        $delCentro = Office::create([
            'medical_center_id' => $sede->id, 'medico_id' => $doctor->id, 'reg_medico' => $centro->reg_medico,
            'office_number' => 'CEN-1', 'modalidad' => Office::MODALIDAD_ORDEN, 'activo' => true,
        ]);
        $this->actingAs($this->admin());

        $this->get(route('admin.medicos.sedes', $doctor))->assertOk()->assertDontSee($sede->name);
        $this->get(route('admin.medicos.sedes.consultorios', [$centro, $sede]))->assertOk()->assertSee('CEN-1');

        Livewire::test(ConsultoriosDeLaSede::class, ['medico' => $centro, 'sede' => $sede])
            ->call('abrirEditar', $delCentro->id)
            ->set('duracion_cita', '15')
            ->call('guardar')
            ->assertHasNoErrors();

        $delCentro->refresh();
        $this->assertSame($centro->reg_medico, $delCentro->reg_medico);
        $this->assertSame($doctor->id, (int) $delCentro->medico_id);
    }

    /* ------------------------------ horarios ------------------------------ */

    public function test_horarios_alta_de_varios_dias_edicion_y_quitar(): void
    {
        $medico = $this->medico()['medico'];
        $sede = $this->sede();
        $consultorio = $this->consultorio($medico, $sede);
        $this->actingAs($this->admin());

        $componente = Livewire::test(HorariosDelConsultorio::class, ['medico' => $medico, 'sede' => $sede, 'consultorio' => $consultorio])
            ->call('abrirAgregar')
            ->assertSee('id="dia-3"', false)
            ->set('dias', ['1', '3', '5'])
            ->set('hora_inicio', '08:00')
            ->set('hora_fin', '12:00')
            ->set('cupo', '20')
            ->call('guardar')
            ->assertHasNoErrors();

        $bloques = $consultorio->schedules()->orderBy('dia_semana')->get();
        $this->assertSame([1, 3, 5], $bloques->pluck('dia_semana')->all());
        $this->assertSame([20, 20, 20], $bloques->pluck('cupo')->all());
        $this->assertSame([$medico->reg_medico], $bloques->pluck('reg_medico')->unique()->values()->all());

        $lunes = $bloques->first();
        $componente->call('abrirEditar', $lunes->id)
            ->assertSet('hora_inicio', '08:00')
            ->set('hora_fin', '13:00')
            ->set('cupo', '')
            ->call('guardar')
            ->assertHasNoErrors();
        $this->assertNull($lunes->fresh()->cupo);
        $this->assertStringStartsWith('13:00', (string) $lunes->fresh()->hora_fin);

        $componente->call('abrirQuitar', $lunes->id)->call('confirmarQuitar');
        $this->assertNull(OfficeSchedule::find($lunes->id));
        $this->assertTrue(SyncChange::where('table_name', 'office_schedules')->where('record_id', $lunes->id)->where('operation', 'deleted')->exists());
        $this->assertSame(2, $consultorio->schedules()->count());
    }

    public function test_un_bloque_que_se_pisa_en_el_mismo_consultorio_se_rechaza_y_no_guarda_ninguno(): void
    {
        $medico = $this->medico()['medico'];
        $sede = $this->sede();
        $consultorio = $this->consultorio($medico, $sede);
        OfficeSchedule::create([
            'office_id' => $consultorio->id, 'reg_medico' => $medico->reg_medico,
            'dia_semana' => 3, 'hora_inicio' => '09:00', 'hora_fin' => '11:00',
        ]);
        $this->actingAs($this->admin());

        Livewire::test(HorariosDelConsultorio::class, ['medico' => $medico, 'sede' => $sede, 'consultorio' => $consultorio])
            ->call('abrirAgregar')
            ->set('dias', ['1', '3'])
            ->set('hora_inicio', '08:00')
            ->set('hora_fin', '12:00')
            ->call('guardar')
            ->assertHasErrors(['hora_inicio']);

        $this->assertSame(1, $consultorio->schedules()->count());
    }

    public function test_el_formato_del_horario_se_valida(): void
    {
        $medico = $this->medico()['medico'];
        $sede = $this->sede();
        $consultorio = $this->consultorio($medico, $sede);
        $this->actingAs($this->admin());

        Livewire::test(HorariosDelConsultorio::class, ['medico' => $medico, 'sede' => $sede, 'consultorio' => $consultorio])
            ->call('abrirAgregar')
            ->set('hora_inicio', '12:00')
            ->set('hora_fin', '08:00')
            ->set('cupo', '0')
            ->call('guardar')
            ->assertHasErrors(['dias' => 'required', 'hora_fin' => 'after', 'cupo' => 'min']);
    }

    public function test_el_bloque_de_otro_consultorio_o_de_otro_medico_da_404(): void
    {
        $medico = $this->medico()['medico'];
        $otro = $this->medico('Vecino')['medico'];
        $sede = $this->sede();
        $propio = $this->consultorio($medico, $sede);
        $ajeno = $this->consultorio($otro, $sede, 'AJ-900');
        $bloqueAjeno = OfficeSchedule::create([
            'office_id' => $ajeno->id, 'reg_medico' => $otro->reg_medico,
            'dia_semana' => 2, 'hora_inicio' => '08:00', 'hora_fin' => '10:00',
        ]);
        $this->actingAs($this->admin());

        // El consultorio propio pedido con otra sede en la URL: 404. (Va antes de `Livewire::test`, que deja la
        // aplicación sin el manejador de excepciones y la página respondería con la excepción en vez del 404.)
        $otraSede = $this->sede('Otra');
        $this->get(route('admin.medicos.sedes.consultorios.horarios', [$medico, $otraSede, $propio]))->assertNotFound();

        $this->fueraDeAlcance(fn () => Livewire::test(HorariosDelConsultorio::class, ['medico' => $medico, 'sede' => $sede, 'consultorio' => $propio])
            ->call('abrirQuitar', $bloqueAjeno->id));
        $this->fueraDeAlcance(fn () => Livewire::test(HorariosDelConsultorio::class, ['medico' => $medico, 'sede' => $sede, 'consultorio' => $propio])
            ->call('abrirEditar', $bloqueAjeno->id));
        $this->assertNotNull(OfficeSchedule::find($bloqueAjeno->id));

    }
}
