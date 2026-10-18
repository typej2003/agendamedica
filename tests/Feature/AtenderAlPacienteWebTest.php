<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\Consulta;
use App\Models\DiaNoLaborable;
use App\Models\Historia;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Office;
use App\Models\OfficeSchedule;
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
 * **«¿Atender ahora?»** (O-05, 2026-10-17): lo que pasa cuando el médico contesta que sí al diálogo que la
 * ficha ofrece después de numerar al paciente.
 *
 * La regla que se prueba es la del legado: si el paciente **tiene una cita hoy sin atender**, se abre esa
 * consulta; si **no la tiene**, se le crea la cita del día con la sede del contexto y se abre la consulta.
 * Si el día no es laborable o el consultorio no tiene horario **no se inventa nada**: se avisa por qué y el
 * paciente queda con su historia. La cita se crea con `CrearCita` (su rastro va con `source = web`) y no se
 * duplica si ya había una.
 *
 * Se corre con `DatabaseTransactions`, como el resto de la web clínica. El día es fijo (un lunes del
 * consultorio) para que el bloque de horario y el "hoy" coincidan.
 */
class AtenderAlPacienteWebTest extends TestCase
{
    use DatabaseTransactions;

    /** El lunes 12/10/2026 en el consultorio: es el "hoy" de toda la prueba. */
    private const HOY = '2026-10-12';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::HOY . ' 10:00:00', 'America/Caracas'));
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
        $regMedico = 'atender-' . uniqid();

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'atender-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Atender',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty, ?int $officeId = null): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico'   => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id'    => $officeId,
        ]])->actingAs($user);
    }

    private function centro(): MedicalCenter
    {
        return MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id'   => DB::table('estados')->value('id'),
            'city_id'    => DB::table('cities')->value('id'),
            'name'       => 'San José',
            'address'    => 'Dirección de prueba',
        ]);
    }

    private function sede(Medico $medico, int $centroId, string $modalidad = Office::MODALIDAD_HORA): Office
    {
        return Office::create([
            'medical_center_id' => $centroId,
            'medico_id'         => $medico->id,
            'reg_medico'        => $medico->reg_medico,
            'office_number'     => 'C1',
            'modalidad'         => $modalidad,
            'duracion_cita'     => 30,
            'activo'            => true,
        ]);
    }

    private function bloque(Office $sede, string $ini = '08:00', string $fin = '12:00'): OfficeSchedule
    {
        return OfficeSchedule::create([
            'office_id'   => $sede->id,
            'reg_medico'  => $sede->reg_medico,
            'dia_semana'  => Carbon::parse(self::HOY)->dayOfWeekIso,
            'hora_inicio' => $ini,
            'hora_fin'    => $fin,
        ]);
    }

    /** Un paciente del médico **con** su historia, que es como queda después del alta web (O-04). */
    private function pacienteConHistoria(Medico $medico, int $numhistoria, string $nombres = 'Ana'): int
    {
        $id = DB::table('pacientes')->insertGetId([
            'nombres'    => $nombres,
            'apellidos'  => 'Alvarez',
            'cedula'     => (string) random_int(10000000, 30000000),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('medico_pacientes')->insert([
            'medico_id'   => $medico->id,
            'paciente_id' => $id,
            'numhistoria' => (string) $numhistoria,
            'reg_medico'  => $medico->reg_medico,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        Historia::create([
            'numhistoria' => (string) $numhistoria,
            'reg_medico'  => $medico->reg_medico,
            'paciente_id' => $id,
            'medico_id'   => $medico->id,
        ]);

        return $id;
    }

    /** Un paciente **sin** historia (el que llegó del chatbot) con su vínculo. */
    private function pacienteSinHistoria(Medico $medico): int
    {
        $id = DB::table('pacientes')->insertGetId([
            'nombres'    => 'Sin',
            'apellidos'  => 'Historia',
            'cedula'     => (string) random_int(10000000, 30000000),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        MedicoPaciente::create([
            'medico_id'   => $medico->id,
            'paciente_id' => $id,
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => null,
        ]);

        return $id;
    }

    private function citaDeHoy(Medico $medico, ?int $centroId, int $numhistoria, string $hora = '08:00:00'): Cola
    {
        return Cola::create([
            'reg_medico'        => $medico->reg_medico,
            'fecha'             => self::HOY,
            'hora_ini'          => $hora,
            'medical_center_id' => $centroId,
            'numhistoria'       => $numhistoria,
            'numorden'          => 1,
            'atendido'          => 0,
            'estado'            => Cola::ESTADO_NO_CONFIRMADA,
        ]);
    }

    private function atender(User $user, Medico $medico, Specialty $specialty, int $paciente, ?int $officeId = null)
    {
        return $this->conContexto($user, $medico, $specialty, $officeId)
            ->post('/clinica/pacientes/' . $paciente . '/atender');
    }

    // ---------------------------------------------------------------------------- Con cita de hoy

    public function test_con_una_cita_de_hoy_abre_esa_consulta_y_no_crea_otra(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $centro = $this->centro();
        $sede = $this->sede($medico, $centro->id);
        $this->bloque($sede);
        $paciente = $this->pacienteConHistoria($medico, 2001);
        $cita = $this->citaDeHoy($medico, $centro->id, 2001, '09:00:00');

        $respuesta = $this->atender($user, $medico, $gineco, $paciente, $sede->id);

        $consulta = Consulta::where('numhistoria', 2001)->firstOrFail();
        $respuesta->assertRedirect(route('clinica.consulta', $consulta->id));

        // Es **la cita que ya había**: ni otra cita, ni otro número de consulta.
        $this->assertSame(1, Cola::where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(1, (int) $cita->fresh()->atendido);
        $this->assertSame(Cola::ESTADO_CONFIRMADA, (int) $cita->fresh()->estado);
        $this->assertSame(1, Consulta::where('numhistoria', 2001)->count());
    }

    // ---------------------------------------------------------------------------------- Sin cita hoy

    public function test_sin_cita_crea_la_cita_del_dia_con_la_sede_del_contexto_y_abre_la_consulta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $centro = $this->centro();
        $sede = $this->sede($medico, $centro->id);
        $this->bloque($sede, '08:00', '12:00');
        $paciente = $this->pacienteConHistoria($medico, 2001);

        $respuesta = $this->atender($user, $medico, $gineco, $paciente, $sede->id);

        $cita = Cola::where('reg_medico', $medico->reg_medico)->firstOrFail();
        $consulta = Consulta::where('numhistoria', 2001)->firstOrFail();

        $respuesta->assertRedirect(route('clinica.consulta', $consulta->id));
        $respuesta->assertSessionHas('estado', 'Se le creó la cita de hoy en el consultorio y la consulta N.º '
            . $consulta->nroconsulta . ' está abierta.');

        // La cita sale de la sede del contexto: la hora del bloque, el centro del consultorio y la historia.
        $this->assertSame(self::HOY, Carbon::parse($cita->fecha)->toDateString());
        $this->assertSame('08:00', substr((string) $cita->hora_ini, 0, 5));
        $this->assertSame($centro->id, (int) $cita->medical_center_id);
        $this->assertSame(2001, (int) $cita->numhistoria);
        $this->assertSame(1, (int) $cita->numorden);
        $this->assertSame('D', $cita->turno);

        // Nace confirmada (el paciente ya está en el consultorio) y queda atendida al abrir la consulta.
        $this->assertSame(Cola::ESTADO_CONFIRMADA, (int) $cita->estado);
        $this->assertSame(1, (int) $cita->atendido);

        // Y deja su rastro como cualquier cita de la web.
        $this->assertSame('web', SyncChange::where('table_name', 'cola')
            ->where('record_id', $cita->id)
            ->where('operation', 'created')
            ->value('source'));
    }

    public function test_dos_veces_seguidas_no_duplican_la_cita_del_dia(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $centro = $this->centro();
        $sede = $this->sede($medico, $centro->id);
        $this->bloque($sede);
        $paciente = $this->pacienteConHistoria($medico, 2001);

        $this->atender($user, $medico, $gineco, $paciente, $sede->id);
        // La segunda vez la cita ya existe (y está atendida): la consulta se reusa y no se crea otra cita.
        $this->atender($user, $medico, $gineco, $paciente, $sede->id);

        $this->assertSame(1, Cola::where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(1, Consulta::where('numhistoria', 2001)->count());
    }

    // ------------------------------------------------------------------- Cuando no se puede atender

    public function test_si_el_dia_no_es_laborable_no_crea_nada_y_avisa(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $centro = $this->centro();
        $sede = $this->sede($medico, $centro->id);
        $this->bloque($sede);
        $paciente = $this->pacienteConHistoria($medico, 2001);

        DiaNoLaborable::create([
            'reg_medico' => $medico->reg_medico,
            'dia'        => self::HOY,
            'tipo'       => DiaNoLaborable::FERIADO,
            'motivo'     => 'Feriado de prueba',
            'medico'     => $medico->id,
        ]);

        $respuesta = $this->atender($user, $medico, $gineco, $paciente, $sede->id);

        $respuesta->assertRedirect(route('clinica.pacientes.ver', $paciente));
        $respuesta->assertSessionHas('error');
        $this->assertStringContainsString('Feriado de prueba', session('error'));

        // No se inventó ni la cita ni la consulta.
        $this->assertSame(0, Cola::where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(0, Consulta::where('numhistoria', 2001)->count());
    }

    public function test_si_el_consultorio_no_tiene_horario_para_hoy_no_crea_nada_y_avisa(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $centro = $this->centro();
        $sede = $this->sede($medico, $centro->id);
        // Sin bloques: el consultorio abre, pero hoy no tiene horario configurado.
        $paciente = $this->pacienteConHistoria($medico, 2001);

        $respuesta = $this->atender($user, $medico, $gineco, $paciente, $sede->id);

        $respuesta->assertRedirect(route('clinica.pacientes.ver', $paciente));
        $respuesta->assertSessionHas('error');
        $this->assertStringContainsString('no tiene horario para hoy', session('error'));

        $this->assertSame(0, Cola::where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(0, Consulta::where('numhistoria', 2001)->count());
    }

    public function test_sin_consultorio_elegido_no_crea_nada_y_avisa(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $centro = $this->centro();
        $sede = $this->sede($medico, $centro->id);
        $this->bloque($sede);
        $paciente = $this->pacienteConHistoria($medico, 2001);

        // La barra superior sin consultorio: la cita no tiene lugar de dónde salir.
        $respuesta = $this->atender($user, $medico, $gineco, $paciente, null);

        $respuesta->assertRedirect(route('clinica.pacientes.ver', $paciente));
        $respuesta->assertSessionHas('error');
        $this->assertStringContainsString('consultorio', session('error'));
        $this->assertSame(0, Cola::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_un_paciente_sin_historia_no_abre_la_consulta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->pacienteSinHistoria($medico);

        $respuesta = $this->atender($user, $medico, $gineco, $paciente);

        $respuesta->assertRedirect(route('clinica.pacientes.ver', $paciente));
        $respuesta->assertSessionHas('error');
        $this->assertSame(0, Consulta::where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(0, Cola::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_la_secretaria_no_puede_atender(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->pacienteConHistoria($medico, 2001);

        // La secretaría no trabaja la consulta (WEB-4.9): el «sí» del diálogo le responde 403 y no escribe.
        $secretaria = User::create([
            'name'                 => 'Secretaria',
            'email'                => 'atender-secre-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        $this->conContexto($secretaria, $medico, $gineco)
            ->post('/clinica/pacientes/' . $paciente . '/atender')
            ->assertForbidden();

        $this->assertSame(0, Consulta::where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(0, Cola::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_un_paciente_de_otro_medico_da_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $ajeno] = $this->medicoConAcceso();
        $deOtro = $this->pacienteConHistoria($ajeno, 77);

        $this->atender($user, $medico, $gineco, $deOtro)->assertNotFound();
        $this->assertSame(0, Consulta::where('reg_medico', $medico->reg_medico)->count());
    }

    // -------------------------------------------------------------- El «después» del alta web (O-04)

    public function test_despues_del_alta_no_se_crea_nada_hasta_que_el_medico_contesta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $centro = $this->centro();
        $sede = $this->sede($medico, $centro->id);
        $this->bloque($sede);

        $alta = $this->conContexto($user, $medico, $gineco, $sede->id)->post('/clinica/pacientes', [
            'cedula'    => '24680135',
            'apellidos' => 'Mendoza',
            'nombres'   => 'Luisa',
        ]);

        $paciente = DB::table('pacientes')->where('cedula', '24680135')->first();
        $alta->assertRedirect(route('clinica.pacientes.ver', $paciente->id));

        // El paciente quedó con historia, pero **no** se atendió solo: la pregunta está en la ficha y hasta
        // que el médico no la contesta no hay cita ni consulta. Eso es «después».
        $this->assertSame('1', DB::table('medico_pacientes')->where('paciente_id', $paciente->id)->value('numhistoria'));
        $this->assertSame(0, Cola::where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(0, Consulta::where('reg_medico', $medico->reg_medico)->count());

        $ficha = $this->conContexto($user, $medico, $gineco, $sede->id)->get('/clinica/pacientes/' . $paciente->id);
        $ficha->assertOk()
            ->assertSee('¿Atender ahora?', false)
            ->assertSee(route('clinica.pacientes.atender', $paciente->id), false)
            ->assertSee('Ahora no');

        // Cerrar el diálogo («Ahora no») no hace ningún POST: el paciente sigue sin cita ni consulta.
        $this->assertSame(0, Cola::where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(0, Consulta::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_el_si_del_dialogo_despues_del_alta_crea_la_cita_y_abre_la_consulta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $centro = $this->centro();
        $sede = $this->sede($medico, $centro->id);
        $this->bloque($sede);

        $this->conContexto($user, $medico, $gineco, $sede->id)->post('/clinica/pacientes', [
            'cedula'    => '24680135',
            'apellidos' => 'Mendoza',
            'nombres'   => 'Luisa',
        ])->assertSessionHas('ofrecer_atender');

        $paciente = DB::table('pacientes')->where('cedula', '24680135')->first();

        $respuesta = $this->atender($user, $medico, $gineco, $paciente->id, $sede->id);

        $consulta = Consulta::where('numhistoria', 1)->firstOrFail();
        $respuesta->assertRedirect(route('clinica.consulta', $consulta->id));

        $cita = Cola::where('reg_medico', $medico->reg_medico)->firstOrFail();
        $this->assertSame(1, (int) $cita->numhistoria);
        $this->assertSame(1, (int) $cita->atendido);
    }

    // -------------------------------------- Lo que la ficha NO ofrece cuando no corresponde (O-05)

    public function test_la_ficha_no_ofrece_atender_fuera_del_momento_del_alta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->pacienteConHistoria($medico, 2001);

        // Abrir la ficha de un paciente que ya tenía historia no es el momento del flujo: no se pregunta.
        $this->conContexto($user, $medico, $gineco)
            ->get('/clinica/pacientes/' . $paciente)
            ->assertOk()
            ->assertDontSee('¿Atender ahora?', false);
    }

    public function test_asignar_la_historia_desde_la_ficha_tambien_ofrece_atender(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->pacienteSinHistoria($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)
            ->post('/clinica/pacientes/' . $paciente . '/historia');

        $respuesta->assertRedirect(route('clinica.pacientes.ver', $paciente));
        $respuesta->assertSessionHas('ofrecer_atender', $paciente);

        $this->conContexto($user, $medico, $gineco)
            ->get('/clinica/pacientes/' . $paciente)
            ->assertOk()
            ->assertSee('¿Atender ahora?', false);
    }

    public function test_la_asignacion_de_historia_sigue_siendo_la_action_compartida(): void
    {
        // El número no se reimplementa: el diálogo no lo calcula, lo da `AsignarHistoria`. Este test fija
        // que el número que muestra la ficha es el que esa Action dejó (correlativo del médico).
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->pacienteConHistoria($medico, 4);
        $paciente = $this->pacienteSinHistoria($medico);

        $this->conContexto($user, $medico, $gineco)
            ->post('/clinica/pacientes/' . $paciente . '/historia')
            ->assertSessionHas('estado', 'Se le asignó la historia H. 5 y ya se lo puede atender.');

        $this->assertSame('5', MedicoPaciente::where('paciente_id', $paciente)->value('numhistoria'));
        $this->assertSame('5', Historia::where('paciente_id', $paciente)->value('numhistoria'));
    }
}
