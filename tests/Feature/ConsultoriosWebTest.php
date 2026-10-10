<?php

namespace Tests\Feature;

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
 * Consultorios del médico (PLAN-WEB.md, F2 / WEB-2.8b.2).
 *
 * El consultorio es **de un médico** (a diferencia de la sede, que es del catálogo de la clínica):
 * `reg_medico` y `medico_id` salen del contexto de trabajo y de ahí depende que el **móvil** lo vea
 * —su delta filtra por `reg_medico`— y que la agenda pueda calcular la jornada con su modalidad.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class ConsultoriosWebTest extends TestCase
{
    use DatabaseTransactions;

    // --------------------------------------------------------------------------------------- Ayudas

    private function medicoConAcceso(string $rol = 'Medico'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'consultorios-test-' . uniqid();

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'consultorios-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Consultorios',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico'   => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id'    => null,
        ]]);
    }

    private function sede(string $nombre = 'Sede de prueba', bool $activa = true): MedicalCenter
    {
        return MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id'   => City::firstOrFail()->state_id,
            'city_id'    => City::firstOrFail()->id,
            'name'       => $nombre . ' ' . uniqid(),
            'address'    => 'Av. Principal',
            'activo'     => $activa,
        ]);
    }

    private function datosDeConsultorio(MedicalCenter $sede, array $extra = []): array
    {
        return array_merge([
            'medical_center_id' => $sede->id,
            'office_number'     => 'C' . random_int(100, 999),
            'modalidad'         => Office::MODALIDAD_ORDEN,
            'duracion_cita'     => 30,
        ], $extra);
    }

    // ----------------------------------------------------------------------------------------- ABM

    public function test_un_invitado_no_entra(): void
    {
        $this->get('/clinica/consultorios')->assertRedirect(route('login'));
    }

    public function test_sin_contexto_no_se_puede_administrar_consultorios(): void
    {
        // El consultorio es de un médico: sin saber cuál, la pantalla no tiene sujeto.
        [$user] = $this->medicoConAcceso();

        $this->actingAs($user)->get('/clinica/consultorios')->assertRedirect(route('clinica.contexto'));
    }

    public function test_la_pantalla_lista_los_consultorios_del_medico(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede('San José');

        Office::create([
            'medical_center_id' => $sede->id,
            'medico_id'         => $medico->id,
            'reg_medico'        => $medico->reg_medico,
            'office_number'     => '701',
            'modalidad'         => Office::MODALIDAD_ORDEN,
            'duracion_cita'     => 20,
            'activo'            => true,
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/consultorios');

        $respuesta->assertOk();
        $respuesta->assertSee($sede->name);
        $respuesta->assertSee('701');
        $respuesta->assertSee('Orden de llegada');
        $respuesta->assertSee('20 min');
        $respuesta->assertSee('sin cargar');   // los bloques de horario son WEB-2.8b.3
    }

    public function test_crear_un_consultorio_escribe_el_registro_y_el_dueno_del_contexto(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede();
        $datos = $this->datosDeConsultorio($sede, ['office_number' => 'C1', 'duracion_cita' => 15]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/consultorios', $datos)
            ->assertRedirect(route('clinica.consultorios'));

        $consultorio = Office::where('office_number', 'C1')->firstOrFail();

        // Lo que hace que el móvil lo vea (su delta filtra por `reg_medico`) y que la agenda lo use.
        $this->assertSame($medico->reg_medico, $consultorio->reg_medico);
        $this->assertSame($medico->id, (int) $consultorio->medico_id);
        $this->assertSame($sede->id, (int) $consultorio->medical_center_id);
        $this->assertSame(Office::MODALIDAD_ORDEN, $consultorio->modalidad);
        $this->assertSame(15, (int) $consultorio->duracion_cita);
        $this->assertTrue($consultorio->activo);
    }

    public function test_la_modalidad_y_el_numero_son_obligatorios(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/consultorios', ['medical_center_id' => $this->sede()->id])
            ->assertSessionHasErrors(['office_number', 'modalidad']);
    }

    public function test_no_se_carga_un_consultorio_en_una_sede_desactivada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $cerrada = $this->sede('Sede cerrada', activa: false);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/consultorios', $this->datosDeConsultorio($cerrada))
            ->assertSessionHasErrors('medical_center_id');

        $this->assertSame(0, Office::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_la_duracion_es_opcional_y_queda_nula(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/consultorios', $this->datosDeConsultorio($sede, ['duracion_cita' => '']))
            ->assertRedirect();

        $consultorio = Office::where('reg_medico', $medico->reg_medico)->firstOrFail();

        // Sin default inventado: el esquema la admite nula y el móvil ya cae a su propio valor.
        $this->assertNull($consultorio->duracion_cita);
    }

    public function test_editar_cambia_la_modalidad_sin_crear_otro(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede();
        $otraSede = $this->sede('Otra sede');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/consultorios', $this->datosDeConsultorio($sede, ['office_number' => 'C1']))
            ->assertRedirect();

        $consultorio = Office::where('reg_medico', $medico->reg_medico)->firstOrFail();
        $antes = Office::where('reg_medico', $medico->reg_medico)->count();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/consultorios', [
                'id'                => $consultorio->id,
                'medical_center_id' => $otraSede->id,
                'office_number'     => 'C9',
                'modalidad'         => Office::MODALIDAD_HORA,
            ])
            ->assertRedirect(route('clinica.consultorios'));

        $this->assertSame($antes, Office::where('reg_medico', $medico->reg_medico)->count());

        $consultorio->refresh();
        $this->assertSame(Office::MODALIDAD_HORA, $consultorio->modalidad);
        $this->assertSame('C9', $consultorio->office_number);
        $this->assertSame($otraSede->id, (int) $consultorio->medical_center_id);
    }

    public function test_el_formulario_de_edicion_llega_con_el_consultorio_cargado(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/consultorios', $this->datosDeConsultorio($sede, ['office_number' => 'C7']))
            ->assertRedirect();

        $consultorio = Office::where('reg_medico', $medico->reg_medico)->firstOrFail();

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/consultorios?editar=' . $consultorio->id);

        $respuesta->assertOk();
        $respuesta->assertSee('Editar el consultorio');
        $respuesta->assertSee('C7');
    }

    // ------------------------------------------------------------------------------- Activar y bajar

    public function test_desactivar_un_consultorio_no_lo_borra_y_deja_de_ofrecerse(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/consultorios', $this->datosDeConsultorio($sede, ['office_number' => 'C1']))
            ->assertRedirect();

        $consultorio = Office::where('reg_medico', $medico->reg_medico)->firstOrFail();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->patch('/clinica/consultorios/' . $consultorio->id . '/activar')
            ->assertRedirect(route('clinica.consultorios'));

        // Se conserva la fila —la cita guarda la modalidad y la duración con las que se calculó su
        // jornada— y sólo deja de ofrecerse.
        $this->assertFalse($consultorio->refresh()->activo);
        $this->assertDatabaseHas('offices', ['id' => $consultorio->id]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/consultorios')
            ->assertDontSee('C1');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/consultorios?inactivos=1')
            ->assertSee('C1')
            ->assertSee('Inactivo');
    }

    public function test_el_consultorio_de_otro_medico_no_se_toca(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();

        $ajeno = Office::create([
            'medical_center_id' => $this->sede()->id,
            'medico_id'         => $otro->id,
            'reg_medico'        => $otro->reg_medico,
            'office_number'     => 'AJENO',
            'modalidad'         => Office::MODALIDAD_HORA,
            'activo'            => true,
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->patch('/clinica/consultorios/' . $ajeno->id . '/activar')
            ->assertNotFound();

        $this->assertTrue($ajeno->refresh()->activo);

        // Y tampoco se puede editar: el id ajeno no está entre los del médico del contexto.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/consultorios', $this->datosDeConsultorio($this->sede(), ['id' => $ajeno->id, 'office_number' => 'PISADO']))
            ->assertNotFound();

        $this->assertSame('AJENO', $ajeno->refresh()->office_number);
    }

    public function test_los_consultorios_de_otro_medico_no_se_listan(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();

        Office::create([
            'medical_center_id' => $this->sede()->id,
            'medico_id'         => $otro->id,
            'reg_medico'        => $otro->reg_medico,
            'office_number'     => 'DEL-OTRO',
            'modalidad'         => Office::MODALIDAD_HORA,
            'activo'            => true,
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/consultorios?inactivos=1')
            ->assertOk()
            ->assertDontSee('DEL-OTRO');
    }

    public function test_la_secretaria_puede_cargar_el_consultorio_del_medico_que_atiende(): void
    {
        // La secretaria es quien carga los horarios del consultorio en la vida real.
        [$secretaria, $medico, $gineco] = $this->medicoConAcceso('Secretaria');
        MedicoRegistro::where('medico_id', $medico->id)->update(['user_id' => $secretaria->id]);

        $sede = $this->sede();

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->post('/clinica/consultorios', $this->datosDeConsultorio($sede, ['office_number' => 'SEC1']))
            ->assertRedirect(route('clinica.consultorios'));

        $consultorio = Office::where('office_number', 'SEC1')->firstOrFail();

        // Se escribe el registro y el dueño del contexto, no los de la secretaria (que no tiene ficha).
        $this->assertSame($medico->reg_medico, $consultorio->reg_medico);
        $this->assertSame($medico->id, (int) $consultorio->medico_id);
    }

    public function test_el_consultorio_nuevo_queda_disponible_en_la_agenda(): void
    {
        // Es el objetivo del paso: un médico sin consultorios no podía agendar. Al cargarlo, la sede
        // aparece en la agenda con su modalidad.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede('Hospital Central');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda')
            ->assertOk()
            ->assertDontSee($sede->name);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/consultorios', $this->datosDeConsultorio($sede, [
                'office_number' => 'C1',
                'modalidad'     => Office::MODALIDAD_HORA,
            ]))
            ->assertRedirect();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda')
            ->assertOk()
            ->assertSee($sede->name)
            ->assertSee('C1');
    }
}
