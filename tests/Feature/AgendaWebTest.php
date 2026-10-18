<?php

namespace Tests\Feature;

use App\Clinica\Agenda\ArmadorDeAgenda;
use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Evolucion;
use App\Models\Historia;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\MotivoCita;
use App\Models\NotificacionCita;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Models\Paciente;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Agenda y secretaría de la web (PLAN-WEB.md, F2 / WEB-2.1, WEB-2.4, WEB-2.5).
 *
 * Lo que se prueba es lo que el móvil ya tenía probado y la web **porta**: la jornada
 * (fecha + sede + bloque), la modalidad de la sede, el cupo como aviso, la posición calculada
 * —nunca `numorden`— y las cuatro acciones del mostrador. `DatabaseTransactions`, como el resto de
 * los tests de la web clínica.
 */
class AgendaWebTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $lunes;

    protected function setUp(): void
    {
        parent::setUp();

        // Un lunes cualquiera: los bloques se siembran con `dia_semana = 1`.
        $this->lunes = Carbon::parse('2026-10-05')->startOfWeek(Carbon::MONDAY);
    }

    protected function tearDown(): void
    {
        // Las pruebas de zona horaria fijan el reloj: que no se filtre a las demás.
        Carbon::setTestNow();

        parent::tearDown();
    }

    // --------------------------------------------------------------------------------------- Ayudas

    private function medicoConAcceso(string $rol = 'Medico'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'agenda-test-' . uniqid();

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'agenda-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Agenda',
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
        ]]);
    }

    /**
     * La secretaría del consultorio: **sin** ficha de médico (no la tiene) y con el `reg_medico` asignado,
     * que es como entra en la web real (`medico_registros`).
     */
    private function secretariaConAcceso(Medico $medico): User
    {
        $secretaria = User::create([
            'name'                 => 'Secretaria',
            'email'                => 'agenda-secre-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        return $secretaria;
    }

    private function centro(string $nombre): MedicalCenter
    {
        return MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id'   => DB::table('estados')->value('id'),
            'city_id'    => DB::table('cities')->value('id'),
            'name'       => $nombre,
            'address'    => 'Dirección de prueba',
        ]);
    }

    private function sede(Medico $medico, int $centroId, string $modalidad, string $numero = 'C1'): Office
    {
        return Office::create([
            'medical_center_id' => $centroId,
            'medico_id'         => $medico->id,
            'reg_medico'        => $medico->reg_medico,
            'office_number'     => $numero,
            'modalidad'         => $modalidad,
            'duracion_cita'     => 30,
            'activo'            => true,
        ]);
    }

    private function bloque(Office $sede, string $ini, string $fin, ?int $cupo = null, ?int $dia = null): OfficeSchedule
    {
        return OfficeSchedule::create([
            'office_id'   => $sede->id,
            'reg_medico'  => $sede->reg_medico,
            'dia_semana'  => $dia ?? 1,
            'hora_inicio' => $ini,
            'hora_fin'    => $fin,
            'cupo'        => $cupo,
        ]);
    }

    private function pacienteConHistoria(Medico $medico, int $numhistoria, string $nombres, string $apellidos = 'Paciente', array $extra = []): int
    {
        $id = DB::table('pacientes')->insertGetId(array_merge([
            'nombres'    => $nombres,
            'apellidos'  => $apellidos,
            'cedula'     => (string) random_int(10000000, 30000000),
            'sexo'       => 'F',
            'fnacimiento'=> '1990-05-04',
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));

        DB::table('medico_pacientes')->insert([
            'medico_id'   => $medico->id,
            'paciente_id' => $id,
            'numhistoria' => $numhistoria,
            'reg_medico'  => $medico->reg_medico,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return $id;
    }

    private function cita(Medico $medico, ?Office $sede, string $fecha, string $hora, int $numorden, array $extra = []): Cola
    {
        return Cola::create(array_merge([
            'reg_medico'        => $medico->reg_medico,
            'fecha'             => $fecha,
            'hora_ini'          => $hora,
            'medical_center_id' => $sede->medical_center_id ?? null,
            'numorden'          => $numorden,
            'atendido'          => 0,
            'estado'            => 0,
        ], $extra));
    }

    // --------------------------------------------------------------------------------- Lectura (día)

    public function test_un_invitado_no_entra_a_la_agenda(): void
    {
        $this->get('/clinica/agenda')->assertRedirect(route('login'));
    }

    public function test_la_vista_de_dia_agrupa_por_jornada_con_modalidad_y_cupo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $sanJose = $this->centro('San José');
        $clinica = $this->centro('Clínica Metropolitana');
        $porOrden = $this->sede($medico, $sanJose->id, Office::MODALIDAD_ORDEN, 'C701');
        $this->bloque($porOrden, '08:00', '12:00', 2);
        $conHora = $this->sede($medico, $clinica->id, Office::MODALIDAD_HORA, 'C3B');
        $this->bloque($conHora, '14:00', '18:00');

        $this->pacienteConHistoria($medico, 1001, 'Ana', 'Alvarez');
        $this->pacienteConHistoria($medico, 1002, 'Beto', 'Blanco');
        $this->pacienteConHistoria($medico, 1003, 'Carla', 'Castro');

        $this->cita($medico, $porOrden, $this->lunes->toDateString(), '08:00:00', 1, ['numhistoria' => 1001]);
        $this->cita($medico, $porOrden, $this->lunes->toDateString(), '09:00:00', 2, ['numhistoria' => 1002]);
        $this->cita($medico, $conHora, $this->lunes->toDateString(), '14:00:00', 1, ['numhistoria' => 1003]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get(
            '/clinica/agenda?vista=dia&fecha=' . $this->lunes->toDateString() . '&sede=todas'
        );

        $respuesta->assertOk();
        $respuesta->assertSee('San José');
        $respuesta->assertSee('Orden de llegada');
        $respuesta->assertSee('Hora de cita');
        $respuesta->assertSee('cupo 2/2');
        $respuesta->assertSee('Cupo completo');   // el cupo avisa, no bloquea (sigue habiendo 2 citas)
        $respuesta->assertSee('sin cupo configurado');
        $respuesta->assertSee('Alvarez');
        $respuesta->assertSee('Blanco');
        $respuesta->assertSee('Castro');

        // El menú de módulos tiene que seguir estando fuera del shell (lo comparte el middleware):
        // desde la agenda se navega a Pacientes sin volver al inicio.
        $respuesta->assertSee(route('clinica.pacientes'), false);
    }

    public function test_la_posicion_es_por_jornada_y_no_se_renumera_al_filtrar_la_sede(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $a = $this->sede($medico, $this->centro('Sede A')->id, Office::MODALIDAD_ORDEN, 'A');
        $this->bloque($a, '08:00', '12:00');
        $b = $this->sede($medico, $this->centro('Sede B')->id, Office::MODALIDAD_ORDEN, 'B');
        $this->bloque($b, '14:00', '18:00');

        $this->pacienteConHistoria($medico, 1001, 'Ana', 'Alvarez');
        $this->pacienteConHistoria($medico, 1002, 'Beto', 'Blanco');
        $this->pacienteConHistoria($medico, 1003, 'Carla', 'Castro');

        // `numorden` del legado que no arranca en 1: la posición mostrada sí.
        $this->cita($medico, $a, $this->lunes->toDateString(), '08:00:00', 5, ['numhistoria' => 1001]);
        $this->cita($medico, $a, $this->lunes->toDateString(), '09:00:00', 8, ['numhistoria' => 1002]);
        $this->cita($medico, $b, $this->lunes->toDateString(), '14:00:00', 3, ['numhistoria' => 1003]);

        $armador = app(ArmadorDeAgenda::class);
        $jornadas = $armador->jornadas(
            $armador->citas($medico->reg_medico, $this->lunes->copy(), $this->lunes->copy()),
            $armador->sedes($medico->reg_medico, $medico->id),
        );
        $posiciones = $armador->posiciones($jornadas);

        // Cada jornada numera desde 1, sin importar el `numorden` guardado (5, 8 y 3).
        $this->assertSame([1, 2, 1], array_values($posiciones));

        // Y al mirar una sola sede, la posición de sus pacientes no cambia ni se renuman las demás.
        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get(
            '/clinica/agenda?vista=dia&fecha=' . $this->lunes->toDateString() . '&sede=' . $a->id
        );
        $respuesta->assertOk();
        $respuesta->assertSee('Alvarez');
        $respuesta->assertSee('Blanco');
        $respuesta->assertDontSee('Castro'); // la de la otra sede no se lista
    }

    public function test_por_orden_de_llegada_manda_numorden_y_con_hora_manda_la_hora(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $porOrden = $this->sede($medico, $this->centro('Hospital')->id, Office::MODALIDAD_ORDEN, 'H');
        $this->bloque($porOrden, '08:00', '12:00');
        $conHora = $this->sede($medico, $this->centro('Clínica')->id, Office::MODALIDAD_HORA, 'C');
        $this->bloque($conHora, '14:00', '18:00');

        $this->pacienteConHistoria($medico, 2001, 'Zulay', 'Ultima');
        $this->pacienteConHistoria($medico, 2002, 'Primero', 'Llegada');
        $this->pacienteConHistoria($medico, 2003, 'Temprano', 'Hora');
        $this->pacienteConHistoria($medico, 2004, 'Tarde', 'Hora');

        // Orden de llegada: numorden 1 es el de las 11:00, no el de las 08:00.
        $this->cita($medico, $porOrden, $this->lunes->toDateString(), '11:00:00', 1, ['numhistoria' => 2002]);
        $this->cita($medico, $porOrden, $this->lunes->toDateString(), '08:00:00', 2, ['numhistoria' => 2001]);
        // Hora de cita: numorden 1 es el de las 15:00, pero manda la hora.
        $this->cita($medico, $conHora, $this->lunes->toDateString(), '15:00:00', 1, ['numhistoria' => 2004]);
        $this->cita($medico, $conHora, $this->lunes->toDateString(), '14:00:00', 2, ['numhistoria' => 2003]);

        $delRango = app(ArmadorDeAgenda::class)->citas($medico->reg_medico, $this->lunes->copy(), $this->lunes->copy());
        $jornadas = app(ArmadorDeAgenda::class)->jornadas(
            $delRango,
            app(ArmadorDeAgenda::class)->sedes($medico->reg_medico, $medico->id)
        );

        $porSede = $jornadas->keyBy(fn ($jornada) => $jornada->sede->id);

        $this->assertSame(
            ['Llegada, Primero', 'Ultima, Zulay'],
            $porSede[$porOrden->id]->ordenadas()->pluck('cita.paciente')->all()
        );
        $this->assertSame(
            ['Hora, Temprano', 'Hora, Tarde'],
            $porSede[$conHora->id]->ordenadas()->pluck('cita.paciente')->all()
        );
    }

    public function test_la_agenda_no_muestra_las_citas_de_otro_registro(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $sede = $this->sede($medico, $this->centro('Propia')->id, Office::MODALIDAD_ORDEN);
        $this->bloque($sede, '08:00', '12:00');
        $this->cita($medico, $sede, $this->lunes->toDateString(), '08:00:00', 1, ['numhistoria' => 3001]);

        // Cita de otro médico, el mismo día.
        $ajeno = Medico::create(['name' => 'Otro', 'lastname' => 'Médico', 'reg_medico' => 'agenda-ajeno-' . uniqid()]);
        Cola::create([
            'reg_medico' => $ajeno->reg_medico,
            'fecha' => $this->lunes->toDateString(),
            'hora_ini' => '09:00:00',
            'numorden' => 1,
            'atendido' => 0,
            'estado' => 0,
            'numhistoria' => 9999,
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get(
            '/clinica/agenda?vista=dia&fecha=' . $this->lunes->toDateString() . '&sede=todas'
        );

        $respuesta->assertOk();
        // Solo la propia: la del otro registro no aparece ni en el listado ni en el conteo.
        $respuesta->assertSee('1 cita');
    }

    public function test_las_vistas_de_semana_y_mes_se_pintan(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $sede = $this->sede($medico, $this->centro('Semanal')->id, Office::MODALIDAD_HORA);
        $this->bloque($sede, '08:00', '12:00');
        $this->cita($medico, $sede, $this->lunes->toDateString(), '08:00:00', 1);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda?vista=semana&fecha=' . $this->lunes->toDateString() . '&sede=todas')
            ->assertOk()
            ->assertSee('Semanal');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda?vista=mes&fecha=' . $this->lunes->toDateString() . '&sede=todas')
            ->assertOk()
            ->assertSee('Lun'); // la cabecera del calendario
    }

    // --------------------------------------------------------------------------------- Acciones

    public function test_una_cita_movida_por_el_escritorio_se_muestra_marcada_y_no_cuenta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $sede = $this->sede($medico, $this->centro('Movidas')->id, Office::MODALIDAD_HORA);
        $this->bloque($sede, '08:00', '12:00', 2);

        $this->pacienteConHistoria($medico, 4001, 'Viva', 'EnLaFecha');
        $this->pacienteConHistoria($medico, 4002, 'Movida', 'AlOtroDia');

        // La cita viva del día y la que el escritorio postergó (`atendido = 2` → `movida_escritorio`).
        $this->cita($medico, $sede, $this->lunes->toDateString(), '08:00:00', 1, ['numhistoria' => 4001]);
        $this->cita($medico, $sede, $this->lunes->toDateString(), '11:00:00', 2, [
            'numhistoria' => 4002, 'movida_escritorio' => true,
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get(
            '/clinica/agenda?vista=dia&fecha=' . $this->lunes->toDateString() . '&sede=todas'
        );

        $respuesta->assertOk();
        $respuesta->assertSee('Movida');                        // la fila no desaparece en silencio
        $respuesta->assertSee('1 movida por el escritorio');
        $respuesta->assertSee('Sin acciones: el escritorio la movió');
        $respuesta->assertSee('AlOtroDia');

        // Fuera del cupo: 1 sola cita cuenta (no “cupo 2/2”).
        $respuesta->assertSee('cupo 1/2');
        $respuesta->assertDontSee('Cupo completo');

        // Y sin número de posición en la cola del día.
        $jornadas = app(ArmadorDeAgenda::class)->jornadas(
            app(ArmadorDeAgenda::class)->citas($medico->reg_medico, $this->lunes->copy(), $this->lunes->copy()),
            app(ArmadorDeAgenda::class)->sedes($medico->reg_medico, $medico->id),
        );
        $filaMovida = $jornadas->first()->ordenadas()->firstWhere('cita.numHistoria', 4002);
        $this->assertNotNull($filaMovida, 'la movida se sigue listando');
        $this->assertNull($filaMovida['posicion'], 'la movida no ocupa un número de la cola');

        // Y ninguna acción se puede ejecutar sobre ella (ni desde la UI ni por POST directo).
        $movida = Cola::where('numhistoria', 4002)->firstOrFail();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda/' . $movida->id . '/atender')
            ->assertRedirect();

        $this->assertSame(0, (int) $movida->fresh()->atendido,
            'una cita movida no se puede atender desde la web');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda/' . $movida->id . '/confirmar', ['estado' => Cola::ESTADO_CONFIRMADA])
            ->assertRedirect();

        $this->assertSame(Cola::ESTADO_NO_CONFIRMADA, (int) $movida->fresh()->estado);
    }

    public function test_confirmar_marca_la_cita_y_deja_rastro_para_el_sync(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $cita = $this->cita($medico, null, $this->lunes->toDateString(), '08:00:00', 1);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda/' . $cita->id . '/confirmar', ['estado' => Cola::ESTADO_CONFIRMADA])
            ->assertRedirect();

        $this->assertSame(Cola::ESTADO_CONFIRMADA, (int) $cita->fresh()->estado);
        $this->assertSame(1, SyncChange::where('table_name', 'cola')
            ->where('record_id', $cita->id)
            ->where('column_name', 'estado')
            ->where('source', 'web')
            ->count());
    }

    public function test_atender_implica_confirmar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        // Un paciente del médico **con su historia**: sin historia no se atiende (el caso está en
        // `ConsultaWebTest`), así que acá se prueba el camino normal completo.
        $pacienteId = $this->pacienteConHistoria($medico, 4001, 'Ana', 'Alvarez');
        Historia::create([
            'numhistoria' => '4001',
            'reg_medico'  => $medico->reg_medico,
            'paciente_id' => $pacienteId,
            'medico_id'   => $medico->id,
        ]);

        $cita = $this->cita($medico, null, $this->lunes->toDateString(), '08:00:00', 1, [
            'numhistoria' => 4001, 'estado' => 0, 'atendido' => 0,
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda/' . $cita->id . '/atender')
            ->assertRedirect();

        $fresca = $cita->fresh();
        $this->assertSame(1, (int) $fresca->atendido);
        $this->assertSame(Cola::ESTADO_CONFIRMADA, (int) $fresca->estado);

        // Y "Atender" **abre la consulta del día** (WEB-4.1): la crea con el correlativo de la historia y
        // con el día de la cita.
        $consulta = Consulta::where('reg_medico', $medico->reg_medico)->where('numhistoria', 4001)->first();
        $this->assertNotNull($consulta, 'atender tiene que abrir la consulta');
        $this->assertSame(1, (int) $consulta->nroconsulta);
    }

    public function test_cobrar_suma_los_abonos_y_fija_el_monto_solo_si_no_habia(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $cita = $this->cita($medico, null, $this->lunes->toDateString(), '08:00:00', 1, ['monto' => null, 'monto_pagado' => null]);

        // Primer abono: fija el precio (no lo tenía) y suma.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda/' . $cita->id . '/cobrar', ['recibido' => 20, 'monto' => 50])
            ->assertRedirect();

        $this->assertSame(50.0, (float) $cita->fresh()->monto);
        $this->assertSame(20.0, (float) $cita->fresh()->monto_pagado);

        // Segundo abono: **suma** (20 + 30 = 50) y no pisa el precio ya fijado.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda/' . $cita->id . '/cobrar', ['recibido' => 30, 'monto' => 999])
            ->assertRedirect();

        $this->assertSame(50.0, (float) $cita->fresh()->monto);
        $this->assertSame(50.0, (float) $cita->fresh()->monto_pagado);
    }

    public function test_reordenar_es_una_sola_operacion_que_corre_a_las_demas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('Cola')->id, Office::MODALIDAD_ORDEN);
        $this->bloque($sede, '08:00', '12:00');

        $primera = $this->cita($medico, $sede, $this->lunes->toDateString(), '08:00:00', 1);
        $segunda = $this->cita($medico, $sede, $this->lunes->toDateString(), '08:30:00', 2);
        $tercera = $this->cita($medico, $sede, $this->lunes->toDateString(), '09:00:00', 3);

        // Mover la tercera al primer lugar: la movida toma el 1 y las de en medio suben uno.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->postJson('/clinica/agenda/reordenar', [
                'record_id' => $tercera->id,
                'from'      => 3,
                'to'        => 1,
                'fecha'     => $this->lunes->toDateString(),
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame(1, (int) $tercera->fresh()->numorden);
        $this->assertSame(2, (int) $primera->fresh()->numorden);
        $this->assertSame(3, (int) $segunda->fresh()->numorden);

        // Una sola fila de sync, no N updates: la operación es `reorder`.
        $this->assertSame(1, SyncChange::where('table_name', 'cola')
            ->where('operation', 'reorder')
            ->where('source', 'web')
            ->count());
    }

    public function test_una_cita_de_otro_registro_da_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $ajeno = Medico::create(['name' => 'Otro', 'lastname' => 'Médico', 'reg_medico' => 'agenda-otro-' . uniqid()]);
        $cita = Cola::create([
            'reg_medico' => $ajeno->reg_medico,
            'fecha'      => $this->lunes->toDateString(),
            'hora_ini'   => '09:00:00',
            'numorden'   => 1,
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda/' . $cita->id . '/confirmar', ['estado' => Cola::ESTADO_CONFIRMADA])
            ->assertNotFound();

        $this->assertSame(0, (int) $cita->fresh()->estado);
    }

    public function test_la_secretaria_entra_a_la_agenda(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = $this->secretariaConAcceso($medico);

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->get('/clinica/agenda?vista=dia&fecha=' . $this->lunes->toDateString())
            ->assertOk();
    }

    /**
     * **La secretaría conserva lo suyo** (WEB-4.9, decisión del usuario del 2026-10-11): agendar, **mover**
     * la cita (reagendar), cambiar el orden de la cola, **confirmar** y **cobrar**. Lo único que pierde es
     * "Atender" —su POST responde 403 y la cita no se marca: eso se fija en `ConsultaWebTest`, porque la
     * regla es de la consulta—, así que acá se comprueba que las otras cinco acciones sigan siendo suyas
     * por HTTP, que es la vara con la que el usuario lo dijo.
     */
    public function test_la_secretaria_conserva_confirmar_cobrar_agendar_mover_y_reordenar(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = $this->secretariaConAcceso($medico);
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_ORDEN, 'C1');
        $this->bloque($sede, '08:00', '12:00');
        $pacienteId = $this->pacienteConHistoria($medico, 2001, 'Ana', 'Alvarez');

        $cita = $this->cita($medico, $sede, $this->lunes->toDateString(), '08:00:00', 1, ['numhistoria' => 2001]);

        // 1) Confirmar: el paciente llegó, y es del mostrador (WEB-2.4).
        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->post('/clinica/agenda/' . $cita->id . '/confirmar', ['estado' => Cola::ESTADO_CONFIRMADA])
            ->assertRedirect();

        $this->assertSame(Cola::ESTADO_CONFIRMADA, (int) $cita->fresh()->estado);

        // 2) Cobrar: abono parcial, sumado a lo ya pagado.
        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->post('/clinica/agenda/' . $cita->id . '/cobrar', ['recibido' => 20, 'monto' => 50])
            ->assertRedirect();

        $this->assertSame(20.0, (float) $cita->fresh()->monto_pagado);

        // 3) Agendar (y con eso, reagendar): el alta y la edición de la cita son suyas (WEB-2.2/2.3).
        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->post('/clinica/agenda', $this->datosDeCita($sede, ['hora' => '09:30', 'paciente_id' => $pacienteId]))
            ->assertRedirect($this->urlDeLaAgenda($sede));

        $segunda = Cola::where('reg_medico', $medico->reg_medico)
            ->where('id', '!=', $cita->id)
            ->firstOrFail();
        $this->assertSame(2, Cola::where('reg_medico', $medico->reg_medico)->count());

        // 4) **Mover** una cita (reagendar): es la misma fila que cambia de fecha, con lo cobrado intacto
        // y lo que valía para la fecha vieja reiniciado (la regla de `EditarCita`, WEB-2.3). Se usa una
        // cita **pendiente** de verdad (de hoy en adelante): es la que el alta detecta y pregunta si
        // mover.
        $futura = $this->cita($medico, $sede, Carbon::today()->addDays(2)->toDateString(), '10:00:00', 9, [
            'numhistoria' => 2001, 'estado' => Cola::ESTADO_CONFIRMADA, 'monto' => 50, 'monto_pagado' => 30,
        ]);

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->post('/clinica/agenda', $this->datosDeCita($sede, [
                'hora'        => '11:00',
                'paciente_id' => $pacienteId,
                'decision'    => 'mover',
            ]))
            ->assertRedirect($this->urlDeLaAgenda($sede));

        $movida = $futura->fresh();
        $this->assertSame($this->lunes->toDateString(), $movida->fecha->toDateString());
        $this->assertSame(30.0, (float) $movida->monto_pagado);
        $this->assertSame(Cola::ESTADO_NO_CONFIRMADA, (int) $movida->estado);
        // Mover no inserta: la jornada queda con las mismas filas que antes del movimiento.
        $this->assertSame(3, Cola::where('reg_medico', $medico->reg_medico)->count());

        // 5) Reordenar la cola: el arrastre es **una** operación de sync, no N updates (WEB-2.5).
        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->postJson('/clinica/agenda/reordenar', [
                'record_id' => $segunda->id,
                'from'      => (int) $segunda->numorden,
                'to'        => 1,
                'fecha'     => $this->lunes->toDateString(),
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame(1, (int) $segunda->fresh()->numorden);
        $this->assertSame(2, (int) $cita->fresh()->numorden);
    }

    // ----------------------------------------------------------- Alta de cita (WEB-2.2) y edición (2.3)

    /** Lo mínimo del formulario de "Nueva cita": la sede elegida en el paso 1 y el día. */
    private function datosDeCita(Office $sede, array $extra = []): array
    {
        return array_merge([
            'sede'  => $sede->id,
            'fecha' => $this->lunes->toDateString(),
        ], $extra);
    }

    /** El redirect con el que el controlador devuelve a la agenda, para comparar. */
    private function urlDeLaAgenda(Office $sede, ?string $fecha = null): string
    {
        return route('clinica.agenda', [
            'vista' => 'dia',
            'fecha' => $fecha ?? $this->lunes->toDateString(),
            'sede'  => $sede->id,
        ]);
    }

    public function test_agendar_una_cita_de_un_paciente_con_historia(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $this->bloque($sede, '08:00', '12:00');
        $pacienteId = $this->pacienteConHistoria($medico, 2001, 'Ana', 'Alvarez');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda', $this->datosDeCita($sede, [
                'hora'        => '09:30',
                'paciente_id' => $pacienteId,
                'monto'       => '50',
            ]))
            ->assertRedirect($this->urlDeLaAgenda($sede));

        $cita = Cola::where('reg_medico', $medico->reg_medico)->firstOrFail();

        $this->assertSame(2001, (int) $cita->numhistoria);
        $this->assertSame('09:30', substr($cita->hora_ini, 0, 5));
        $this->assertSame('D', $cita->turno);            // derivado de la hora
        $this->assertSame(1, (int) $cita->numorden);     // el próximo de la jornada
        $this->assertSame(Cola::ESTADO_NO_CONFIRMADA, (int) $cita->estado);
        $this->assertSame(50.0, (float) $cita->monto);
        $this->assertSame('web', SyncChange::where('table_name', 'cola')
            ->where('record_id', $cita->id)
            ->where('operation', 'created')
            ->value('source'));
    }

    public function test_agendar_da_de_alta_al_paciente_nuevo_sin_historia(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $this->bloque($sede, '08:00', '12:00');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda', $this->datosDeCita($sede, [
                'hora'            => '09:00',
                'nuevo_cedula'    => '12345678',
                'nuevo_nombres'   => 'Nueva',
                'nuevo_apellidos' => 'Paciente',
                'nuevo_telefono'  => '0414-1234567',
            ]))
            ->assertRedirect($this->urlDeLaAgenda($sede));

        $paciente = Paciente::where('cedula', '12345678')->firstOrFail();
        $this->assertSame('Nueva', $paciente->nombres);

        // El vínculo nace **sin número de historia**: lo asigna el API cuando se complete la historia.
        $relacion = MedicoPaciente::where('medico_id', $medico->id)->where('paciente_id', $paciente->id)->firstOrFail();
        $this->assertNull($relacion->numhistoria);

        // Y la cita queda anclada al paciente, no a una historia que no existe.
        $cita = Cola::where('reg_medico', $medico->reg_medico)->firstOrFail();
        $this->assertNull($cita->numhistoria);
        $this->assertSame($paciente->id, (int) $cita->paciente_sinhistoria_id);
    }

    public function test_la_cedula_repetida_enlaza_la_ficha_y_no_pisa_sus_datos(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $this->bloque($sede, '08:00', '12:00');

        // Una ficha que ya existe (la cargó otro consultorio) con datos propios.
        $ajena = Paciente::create([
            'cedula'    => '99887766',
            'nombres'   => 'Vieja',
            'apellidos' => 'Ficha',
            'telefono'  => '0212-0000000',
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda', $this->datosDeCita($sede, [
                'hora'            => '09:00',
                'nuevo_cedula'    => '99887766',
                'nuevo_nombres'   => 'Otro',
                'nuevo_apellidos' => 'Nombre',
                'nuevo_telefono'  => '0414-9999999',
            ]))
            ->assertRedirect($this->urlDeLaAgenda($sede));

        // No se duplica la ficha y no se pisan los datos que ya tenía.
        $this->assertSame(1, Paciente::where('cedula', '99887766')->count());
        $this->assertSame('Vieja', $ajena->fresh()->nombres);
        $this->assertSame('0212-0000000', $ajena->fresh()->telefono);

        // Pero sí queda enlazada a este médico y la cita apunta a ella.
        $this->assertSame(1, MedicoPaciente::where('medico_id', $medico->id)->where('paciente_id', $ajena->id)->count());
        $this->assertSame($ajena->id, (int) Cola::where('reg_medico', $medico->reg_medico)->firstOrFail()->paciente_sinhistoria_id);
    }

    public function test_una_cita_pendiente_se_pregunta_antes_de_agendar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $this->bloque($sede, '08:00', '12:00');
        $pacienteId = $this->pacienteConHistoria($medico, 2001, 'Ana', 'Alvarez');

        // Una cita pendiente de hoy en adelante (las pasadas sin atender no cuentan).
        $this->cita($medico, $sede, Carbon::today()->addDay()->toDateString(), '09:00:00', 1, ['numhistoria' => 2001]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda', $this->datosDeCita($sede, [
                'hora'        => '10:00',
                'paciente_id' => $pacienteId,
            ]));

        // Vuelve el formulario con la pregunta y **no se escribió nada**.
        $respuesta->assertOk();
        $respuesta->assertSee('Ya tiene una cita pendiente');
        $respuesta->assertSee('Mover esa cita a la nueva fecha');
        $this->assertSame(1, Cola::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_se_puede_agendar_otra_cita_de_todos_modos(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $this->bloque($sede, '08:00', '12:00');
        $pacienteId = $this->pacienteConHistoria($medico, 2001, 'Ana', 'Alvarez');
        $this->cita($medico, $sede, Carbon::today()->addDay()->toDateString(), '09:00:00', 1, ['numhistoria' => 2001]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda', $this->datosDeCita($sede, [
                'hora'        => '10:00',
                'paciente_id' => $pacienteId,
                'decision'    => 'otra',
            ]))
            ->assertRedirect($this->urlDeLaAgenda($sede));

        // La excepción del tratamiento: dos citas del mismo paciente (masajistas, terapias).
        $this->assertSame(2, Cola::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_mover_la_cita_pendiente_conserva_lo_cobrado_y_reinicia_la_confirmacion(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $this->bloque($sede, '08:00', '12:00');
        $pacienteId = $this->pacienteConHistoria($medico, 2001, 'Ana', 'Alvarez');

        $pendiente = $this->cita($medico, $sede, Carbon::today()->addDay()->toDateString(), '09:00:00', 1, [
            'numhistoria' => 2001,
            'estado'      => Cola::ESTADO_CONFIRMADA,
            'sms_text'    => 'recordatorio enviado',
            'monto'       => 50,
            'monto_pagado'=> 30,
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda', $this->datosDeCita($sede, [
                'hora'        => '10:00',
                'paciente_id' => $pacienteId,
                'decision'    => 'mover',
            ]))
            ->assertRedirect($this->urlDeLaAgenda($sede));

        $fresca = $pendiente->fresh();

        // Es **la misma fila** movida, con lo cobrado intacto…
        $this->assertSame(1, Cola::where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame($this->lunes->toDateString(), $fresca->fecha->toDateString());
        $this->assertSame('10:00', substr($fresca->hora_ini, 0, 5));
        $this->assertSame(30.0, (float) $fresca->monto_pagado);

        // …y reiniciado lo que valía para la fecha vieja.
        $this->assertSame(Cola::ESTADO_NO_CONFIRMADA, (int) $fresca->estado);
        $this->assertNull($fresca->sms_text);
    }

    public function test_cancelar_la_pregunta_por_la_cita_pendiente_no_agenda_nada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $this->bloque($sede, '08:00', '12:00');
        $pacienteId = $this->pacienteConHistoria($medico, 2001, 'Ana', 'Alvarez');
        $this->cita($medico, $sede, Carbon::today()->addDay()->toDateString(), '09:00:00', 1, ['numhistoria' => 2001]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda', $this->datosDeCita($sede, [
                'hora'        => '10:00',
                'paciente_id' => $pacienteId,
                'decision'    => 'cancelar',
            ]))
            ->assertRedirect($this->urlDeLaAgenda($sede));

        $this->assertSame(1, Cola::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_por_orden_de_llegada_la_hora_la_pone_el_bloque(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_ORDEN, 'C1');
        $this->bloque($sede, '08:00', '12:00');
        $this->bloque($sede, '14:00', '18:00');
        $pacienteId = $this->pacienteConHistoria($medico, 2001, 'Ana', 'Alvarez');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda', $this->datosDeCita($sede, [
                'bloque'      => 1,
                'paciente_id' => $pacienteId,
            ]))
            ->assertRedirect($this->urlDeLaAgenda($sede));

        $cita = Cola::where('reg_medico', $medico->reg_medico)->firstOrFail();
        $this->assertSame('14:00', substr($cita->hora_ini, 0, 5));
        $this->assertSame('T', $cita->turno);
    }

    /**
     * Sin paciente, el alta de la cita decía "The nuevo cedula field is required when paciente id is not
     * present." (revisión de la web, 2026-10-11): inglés y con los nombres internos del formulario. Un
     * médico no tiene por qué saber qué es `paciente id`.
     */
    public function test_agendar_sin_paciente_pide_elegir_uno_en_espanol(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_ORDEN, 'C1');
        $this->bloque($sede, '08:00', '12:00');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda', $this->datosDeCita($sede))
            ->assertSessionHasErrors([
                'nuevo_cedula' => 'Elige un paciente de la lista o carga uno nuevo (con su cédula, nombres y apellidos).',
            ]);

        $this->assertSame(0, Cola::where('reg_medico', $medico->reg_medico)->count());
    }

    /**
     * Ninguna regla del formulario de la cita puede caer en el mensaje por defecto de Laravel: el `locale`
     * de la app es `en` (y la API comparte esa configuración, así que no se cambia), de modo que **cada
     * regla de `GuardarCitaRequest` necesita su texto**. Si se agrega una regla sin mensaje, esta prueba lo
     * dice. Se manda todo mal a la vez y se revisa que ningún aviso esté en inglés ni nombre un campo
     * interno.
     */
    public function test_todos_los_errores_del_formulario_de_cita_estan_en_espanol(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda', [
                'sede'            => 'no-es-un-numero',
                'fecha'           => 'ayer',
                'hora'            => '99',
                'bloque'          => -1,
                'motivo'          => str_repeat('M', 51),
                'monto'           => 'abc',
                'paciente_id'     => 'no-es-un-numero',
                'nuevo_cedula'    => str_repeat('1', 21),
                'nuevo_nombres'   => str_repeat('N', 101),
                'nuevo_apellidos' => str_repeat('A', 101),
                'nuevo_telefono'  => str_repeat('9', 31),
                'nuevo_email'     => 'no-es-un-correo',
                'nuevo_sexo'      => 'Z',
                'decision'        => 'quizas',
            ]);

        $errores = session('errors')->getBag('default')->toArray();

        $this->assertCount(14, $errores, 'Cada campo mal escrito tiene que dar su propio aviso.');

        foreach ($errores as $campo => $mensajes) {
            foreach ($mensajes as $mensaje) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\b(The|field|must|required|format|invalid|selected|greater|less)\b|_|\bid\b/i',
                    $mensaje,
                    "El aviso de «{$campo}» está en inglés o nombra un campo interno: {$mensaje}",
                );
            }
        }

        $respuesta->assertSessionHasErrors(['fecha', 'hora', 'monto', 'nuevo_email']);
    }

    // --------------------------------------------------------------------- Cobro: no cobrar de más

    /** Una cita de hoy en una sede por orden de llegada, con el precio y lo ya abonado que se pidan. */
    private function citaParaCobrar(array $extra): array
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_ORDEN, 'C1');
        $this->bloque($sede, '08:00', '12:00');
        $cita = $this->cita($medico, $sede, $this->lunes->toDateString(), '08:00:00', 1, $extra);

        return [$user, $medico, $gineco, $cita];
    }

    /**
     * El diálogo "Registrar cobro" aceptaba cualquier cifra: 99999 sobre una consulta de 50 la dejaba
     * "Pagada" con 99999 cobrados (revisión de la web, 2026-10-11). Lo recibido no puede pasar de lo que
     * falta: el abono se **suma** a lo ya pagado, así que el tope es `monto - monto_pagado`.
     */
    public function test_no_se_cobra_mas_de_lo_que_falta_y_el_aviso_dice_cuanto_falta(): void
    {
        [$user, $medico, $gineco, $cita] = $this->citaParaCobrar(['monto' => 50, 'monto_pagado' => 30]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda/' . $cita->id . '/cobrar', ['recibido' => 25])
            ->assertSessionHasErrors(['recibido' => 'Lo recibido (25,00) supera lo que falta por cobrar (20,00).'])
            ->assertSessionMissing('estado');

        $this->assertSame(30.0, (float) $cita->fresh()->monto_pagado);
    }

    public function test_cobrar_exactamente_lo_que_falta_salda_la_cita(): void
    {
        [$user, $medico, $gineco, $cita] = $this->citaParaCobrar(['monto' => 50, 'monto_pagado' => 30]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda/' . $cita->id . '/cobrar', ['recibido' => 20])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('estado', 'Cobro registrado.');

        $this->assertSame(50.0, (float) $cita->fresh()->monto_pagado);
        $this->assertSame(Cola::PAGO_PAGADA, $cita->fresh()->estadoPago());
    }

    public function test_una_cita_ya_pagada_no_admite_otro_cobro(): void
    {
        [$user, $medico, $gineco, $cita] = $this->citaParaCobrar(['monto' => 50, 'monto_pagado' => 50]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda/' . $cita->id . '/cobrar', ['recibido' => 5])
            ->assertSessionHasErrors(['recibido' => 'Lo recibido (5,00) supera lo que falta por cobrar (0,00).']);

        $this->assertSame(50.0, (float) $cita->fresh()->monto_pagado);
    }

    /** Si el precio se fija en el mismo cobro (el diálogo lo ofrece cuando la cita no tenía), ese precio es el tope. */
    public function test_el_precio_que_se_fija_en_el_mismo_cobro_tambien_pone_el_tope(): void
    {
        [$user, $medico, $gineco, $cita] = $this->citaParaCobrar([]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda/' . $cita->id . '/cobrar', ['monto' => 40, 'recibido' => 100])
            ->assertSessionHasErrors('recibido');

        $cita = $cita->fresh();
        $this->assertNull($cita->monto, 'Un cobro rechazado no fija el precio a medias.');
        $this->assertSame(0.0, (float) $cita->monto_pagado);
    }

    /**
     * Lo que **no** cambia, por diseño (`Cola::estadoPago`): una cita sin precio que se cobra queda
     * "Pagada". El médico no siempre le pone precio y aun así cobra; sin precio no hay tope que aplicar.
     */
    public function test_una_cita_sin_precio_se_puede_cobrar_sin_tope(): void
    {
        [$user, $medico, $gineco, $cita] = $this->citaParaCobrar([]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda/' . $cita->id . '/cobrar', ['recibido' => 35])
            ->assertSessionHasNoErrors();

        $this->assertSame(Cola::PAGO_PAGADA, $cita->fresh()->estadoPago());
    }

    // ------------------------------------------------- "Hoy" es el del consultorio, no el del servidor

    /**
     * El servidor corre en UTC (`config('app.timezone')`, que no se toca) y Venezuela es UTC-4: desde las 8 pm
     * locales la agenda, "Nueva cita", "Enviar a todos" y compañía abrían en **mañana** (revisión de la web,
     * 2026-10-11) mientras que Cumpleaños, que sí usa `FechaDelConsultorio`, decía hoy. Se fija el reloj a las
     * 00:30 UTC del 11/10 —las 20:30 del sábado 10/10 en Caracas— y todas tienen que decir sábado 10.
     */
    private function a_las_ocho_y_media_de_la_noche_en_caracas(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-11 00:30:00', 'UTC'));
    }

    public function test_la_agenda_abre_en_el_dia_del_consultorio_aunque_en_utc_ya_sea_manana(): void
    {
        $this->a_las_ocho_y_media_de_la_noche_en_caracas();
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/agenda')
            ->assertOk()
            ->assertSee('Sábado 10/10/2026')
            ->assertDontSee('Domingo 11/10/2026');

        // Y el botón "Hoy" lleva al mismo día.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/agenda?fecha=2026-10-08')
            ->assertOk()
            ->assertSee('fecha=2026-10-10', false);
    }

    public function test_la_semana_y_el_mes_marcan_como_hoy_el_dia_del_consultorio(): void
    {
        $this->a_las_ocho_y_media_de_la_noche_en_caracas();
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $semana = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda?vista=semana')->assertOk()->getContent();

        $this->assertSame(1, preg_match('#agenda-dia-titulo es-hoy">\s*<a [^>]*>([^<]+)</a>#u', $semana, $dia));
        $this->assertStringStartsWith('Sábado 10/10/2026', trim($dia[1]));

        $mes = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda?vista=mes')->assertOk()->getContent();

        $this->assertSame(1, preg_match('#calendario-dia es-hoy"[^>]*aria-label="(\d+):#u', $mes, $celda));
        $this->assertSame('10', $celda[1]);
    }

    public function test_nueva_cita_enviar_a_todos_y_las_pantallas_de_la_agenda_arrancan_en_el_dia_del_consultorio(): void
    {
        $this->a_las_ocho_y_media_de_la_noche_en_caracas();
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/agenda/nueva')
            ->assertOk()
            ->assertSee('10/10/2026')
            ->assertSee('value="2026-10-10"', false)
            ->assertDontSee('2026-10-11', false);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/agenda/envio')
            ->assertOk()
            ->assertSee('Sábado 10/10/2026');

        // (WEB-8.1c: la pantalla de motivos de cita pasó al catálogo y ya no lleva el enlace de regreso al día de la agenda.)
        foreach (['/clinica/agenda/no-laborables'] as $ruta) {
            $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($ruta)
                ->assertOk()
                ->assertSee('fecha=2026-10-10', false)
                ->assertDontSee('fecha=2026-10-11', false);
        }
    }

    public function test_los_dias_no_laborables_abren_en_el_mes_y_el_dia_del_consultorio(): void
    {
        // 00:30 UTC del 1/nov = 20:30 del 31/oct en Caracas: el mes en curso todavía es octubre.
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:30:00', 'UTC'));
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/agenda/no-laborables')
            ->assertOk()
            ->assertSee('Octubre 2026')
            ->assertSee('value="2026-10-31"', false);
    }

    /**
     * La cita pendiente que el alta detecta ("ya tiene una cita pendiente") cuenta desde **hoy del
     * consultorio**: a las 20:30 locales una cita de ese mismo día todavía es pendiente (con la fecha UTC
     * ya era "pasada" y se podía agendar otra sin el aviso).
     */
    public function test_la_cita_de_hoy_del_consultorio_sigue_siendo_pendiente_de_noche(): void
    {
        $this->a_las_ocho_y_media_de_la_noche_en_caracas();
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_ORDEN, 'C1');
        $this->bloque($sede, '08:00', '12:00', null, 6);
        $pacienteId = $this->pacienteConHistoria($medico, 2001, 'Ana', 'Alvarez');
        $this->cita($medico, $sede, '2026-10-10', '08:00:00', 1, ['numhistoria' => 2001]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda', $this->datosDeCita($sede, ['fecha' => '2026-10-13', 'paciente_id' => $pacienteId]))
            ->assertOk()
            ->assertSee('Ya tiene una cita pendiente')
            // La frase cierra pegada al nombre de la sede ("…en San José."), sin un espacio antes del punto.
            ->assertSee('en San José.', false)
            ->assertDontSee('en San José .', false);

        $this->assertSame(1, Cola::where('reg_medico', $medico->reg_medico)->count());
    }

    /**
     * El panel "Pacientes sin historia a atender" **no se pinta si está vacío** (revisión de Alexander,
     * 2026-10-17): viendo hoy o cualquier otro día, sin pacientes sin historia no queda ni la tarjeta, ni
     * el conteo, ni el estado vacío. Con uno, el panel está y muestra su fila.
     */
    public function test_el_panel_sin_historia_solo_aparece_cuando_hay_pacientes(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $this->bloque($sede, '08:00', '12:00');

        foreach (['2026-10-10', '2026-10-08'] as $fecha) {
            $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/agenda?fecha=' . $fecha)
                ->assertOk()
                ->assertDontSee('id="panel-sin-historia"', false)
                ->assertDontSee('Pacientes sin historia a atender')
                ->assertDontSee('no hay pacientes sin historia');
        }

        // Un paciente dado de alta sin número, con su cita anclada por `paciente_sinhistoria_id`.
        $pacienteId = DB::table('pacientes')->insertGetId([
            'nombres'    => 'Prueba',
            'apellidos'  => 'SinHistoria',
            'cedula'     => '12345678',
            'sexo'       => 'F',
            'fnacimiento'=> '1990-05-04',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('medico_pacientes')->insert([
            'medico_id'   => $medico->id,
            'paciente_id' => $pacienteId,
            'numhistoria' => null,
            'reg_medico'  => $medico->reg_medico,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        $this->cita($medico, $sede, '2026-10-10', '08:00:00', 1, ['paciente_sinhistoria_id' => $pacienteId]);

        $conUno = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/agenda?fecha=2026-10-10');

        $conUno->assertOk()
            ->assertSee('id="panel-sin-historia"', false)
            ->assertSee('Pacientes sin historia a atender')
            ->assertSee('SinHistoria, Prueba')
            ->assertSee('1 paciente')
            ->assertSee('Pasar a cita formal');
    }

    public function test_con_hora_de_cita_la_hora_es_obligatoria(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $this->bloque($sede, '08:00', '12:00');
        $pacienteId = $this->pacienteConHistoria($medico, 2001, 'Ana', 'Alvarez');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda', $this->datosDeCita($sede, ['paciente_id' => $pacienteId]))
            ->assertSessionHasErrors('hora');

        $this->assertSame(0, Cola::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_reagendar_mueve_la_misma_cita_y_reinicia_lo_que_valia_para_la_fecha_vieja(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $this->bloque($sede, '08:00', '12:00');
        $pacienteId = $this->pacienteConHistoria($medico, 2001, 'Ana', 'Alvarez');

        $cita = $this->cita($medico, $sede, $this->lunes->toDateString(), '08:00:00', 1, [
            'numhistoria'  => 2001,
            'estado'       => Cola::ESTADO_CONFIRMADA,
            'sms_text'     => 'recordatorio enviado',
            'monto'        => 50,
            'monto_pagado' => 30,
        ]);

        $otroDia = $this->lunes->copy()->addWeek();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda/' . $cita->id, [
                'sede'        => $sede->id,
                'fecha'       => $otroDia->toDateString(),
                'hora'        => '09:00',
                'paciente_id' => $pacienteId,
                'monto'       => '50',
            ])
            ->assertRedirect($this->urlDeLaAgenda($sede, $otroDia->toDateString()));

        $fresca = $cita->fresh();

        $this->assertSame(1, Cola::where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame($otroDia->toDateString(), $fresca->fecha->toDateString());
        $this->assertSame('09:00', substr($fresca->hora_ini, 0, 5));
        $this->assertSame(30.0, (float) $fresca->monto_pagado);
        $this->assertSame(Cola::ESTADO_NO_CONFIRMADA, (int) $fresca->estado);
        $this->assertNull($fresca->sms_text);

        // Y queda el rastro que el sync necesita para no pisar la edición desde el teléfono.
        $this->assertSame(1, SyncChange::where('table_name', 'cola')
            ->where('record_id', $cita->id)
            ->where('column_name', 'fecha')
            ->where('source', 'web')
            ->count());
    }

    public function test_una_cita_atendida_no_se_reagenda_ni_se_elimina(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $this->bloque($sede, '08:00', '12:00');
        $pacienteId = $this->pacienteConHistoria($medico, 2001, 'Ana', 'Alvarez');

        $cita = $this->cita($medico, $sede, $this->lunes->toDateString(), '08:00:00', 1, [
            'numhistoria' => 2001,
            'atendido'    => 1,
        ]);

        $peticion = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        $peticion->post('/clinica/agenda/' . $cita->id, [
            'sede'        => $sede->id,
            'fecha'       => $this->lunes->copy()->addWeek()->toDateString(),
            'hora'        => '09:00',
            'paciente_id' => $pacienteId,
        ])->assertStatus(302)->assertSessionHas('error');

        $peticion->post('/clinica/agenda/' . $cita->id . '/eliminar')
            ->assertStatus(302)->assertSessionHas('error');

        $fresca = $cita->fresh();
        $this->assertNotNull($fresca);
        $this->assertSame($this->lunes->toDateString(), $fresca->fecha->toDateString());
    }

    public function test_eliminar_la_cita_la_borra_y_deja_rastro_para_el_sync(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $cita = $this->cita($medico, null, $this->lunes->toDateString(), '08:00:00', 1);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda/' . $cita->id . '/eliminar')
            ->assertStatus(302)
            ->assertSessionHas('estado');

        $this->assertNull(Cola::find($cita->id));
        $this->assertSame(1, SyncChange::where('table_name', 'cola')
            ->where('record_id', $cita->id)
            ->where('operation', 'deleted')
            ->count());
    }

    public function test_una_cita_con_pago_registrado_no_se_elimina(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $cita = $this->cita($medico, null, $this->lunes->toDateString(), '08:00:00', 1, [
            'monto'        => 50,
            'monto_pagado' => 20,
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda/' . $cita->id . '/eliminar')
            ->assertStatus(302)
            ->assertSessionHas('error');

        $this->assertNotNull($cita->fresh());
    }

    public function test_el_buscador_de_pacientes_devuelve_solo_los_del_medico(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->pacienteConHistoria($medico, 3001, 'Buscada', 'Persona');

        // Otro médico con un paciente que se llama igual: no tiene que aparecer.
        $otro = Medico::create(['name' => 'Otro', 'lastname' => 'Médico', 'reg_medico' => 'agenda-otro-' . uniqid()]);
        $this->pacienteConHistoria($otro, 3002, 'Buscada', 'Ajena');

        $peticion = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        $peticion->getJson('/clinica/pacientes/buscar?q=Buscada')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['nombre' => 'Persona, Buscada']);

        // Con una sola letra no devuelve nada: sería el consultorio entero.
        $peticion->getJson('/clinica/pacientes/buscar?q=B')->assertOk()->assertJsonCount(0);
    }

    public function test_la_agenda_muestra_la_razon_del_catalogo_y_no_el_nombre_del_paciente(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->pacienteConHistoria($medico, 2001, 'Ana', 'Alvarez');
        MotivoCita::create([
            'reg_medico'    => $medico->reg_medico,
            'codigo'        => 'CONS',
            'tipo_atencion' => 'Consulta general',
        ]);

        // El legado escribe `tipo` (escritorio) y `motivo` con el nombre del paciente.
        $this->cita($medico, null, $this->lunes->toDateString(), '08:00:00', 1, [
            'numhistoria' => 2001,
            'tipo'        => 'CONS',
            'motivo'      => 'ATENDER A: Ana Alvarez',
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda?vista=dia&fecha=' . $this->lunes->toDateString() . '&sede=todas');

        $respuesta->assertOk();
        $respuesta->assertSee('Consulta general');
        $respuesta->assertDontSee('ATENDER A');
    }

    public function test_no_se_agenda_en_la_sede_de_otro_medico(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        // Una sede propia (con sedes configuradas hay que elegir una de ellas) y una ajena.
        $this->sede($medico, $this->centro('Propia')->id, Office::MODALIDAD_HORA, 'C1');
        $otro = Medico::create(['name' => 'Otro', 'lastname' => 'Médico', 'reg_medico' => 'agenda-otro-' . uniqid()]);
        $ajena = $this->sede($otro, $this->centro('Ajena')->id, Office::MODALIDAD_HORA, 'C9');
        $pacienteId = $this->pacienteConHistoria($medico, 2001, 'Ana', 'Alvarez');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda', [
                'sede'        => $ajena->id,
                'fecha'       => $this->lunes->toDateString(),
                'hora'        => '09:00',
                'paciente_id' => $pacienteId,
            ])
            ->assertSessionHasErrors('sede');

        $this->assertSame(0, Cola::where('reg_medico', $medico->reg_medico)->count());
    }

    // --------------------------------------------- Recordatorios y envíos del día (WEB-2.6 / WEB-2.7)

    /** @return array<string,string> credenciales de Twilio de prueba (son del servidor, no del médico). */
    private function twilio(): array
    {
        return [
            'services.twilio.sid'   => 'ACprueba',
            'services.twilio.token' => 'token-de-prueba',
            'services.twilio.from'  => '+15005550006',
        ];
    }

    public function test_la_jornada_ofrece_el_recordatorio_con_el_texto_del_medico_de_la_cita(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $this->bloque($sede, '08:00', '12:00');
        $this->pacienteConHistoria($medico, 4001, 'Ana', 'Alvarez', [
            'telefono' => '0414-1234567',
            'email'    => 'ana@example.com',
        ]);

        // La plantilla es la del médico **de la cita** (`evolucion.clave` = `cola.medico`), no la de
        // quien tiene la sesión abierta.
        Evolucion::create([
            'reg_medico'     => $medico->reg_medico,
            'clave'          => 7,
            'correo_med'     => $medico->email,
            'plantilla_cita' => 'Hola {paciente}: cita el {fecha} a las {hora} con el {doctor}.',
        ]);
        $this->cita($medico, $sede, $this->lunes->toDateString(), '09:30:00', 1, [
            'numhistoria' => 4001,
            'medico'      => 7,
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda?vista=dia&fecha=' . $this->lunes->toDateString() . '&sede=todas');

        $respuesta->assertOk();
        $respuesta->assertSee('data-dialogo="recordatorio"', false);
        // Las etiquetas ya sustituidas, en el texto que el diálogo ofrece de arranque.
        $respuesta->assertSee('Hola Alvarez, Ana: cita el 05/10/2026 a las 09:30 con el Dr. de prueba Agenda.', false);
        $respuesta->assertSee('data-tiene-telefono="1"', false);
        $respuesta->assertSee('data-tiene-correo="1"', false);
    }

    public function test_el_recordatorio_se_manda_y_deja_la_constancia_en_la_cita(): void
    {
        config($this->twilio());
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1', 'status' => 'queued'], 201)]);

        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $this->bloque($sede, '08:00', '12:00');
        $this->pacienteConHistoria($medico, 4002, 'Beto', 'Blanco', ['telefono' => '0414-9999999']);
        $cita = $this->cita($medico, $sede, $this->lunes->toDateString(), '08:00:00', 1, ['numhistoria' => 4002]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda/' . $cita->id . '/notificar', ['canal' => 'sms', 'mensaje' => 'Hola Beto, te esperamos.'])
            ->assertRedirect()
            ->assertSessionHas('estado');

        $this->assertSame('Hola Beto, te esperamos.', $cita->fresh()->sms_text);
        $this->assertSame(1, NotificacionCita::where('cola_id', $cita->id)->where('canal', 'sms')->count());
    }

    public function test_no_se_le_recuerda_a_una_cita_ya_confirmada(): void
    {
        config($this->twilio());
        Http::fake();

        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $this->bloque($sede, '08:00', '12:00');
        $this->pacienteConHistoria($medico, 4003, 'Carla', 'Castro', ['telefono' => '0414-1111111']);
        $cita = $this->cita($medico, $sede, $this->lunes->toDateString(), '08:00:00', 1, [
            'numhistoria' => 4003,
            'estado'      => Cola::ESTADO_CONFIRMADA,
        ]);

        $peticion = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        // Ni el botón en la jornada —ya confirmó, no hay nada que recordarle— ni el POST a mano.
        $peticion->get('/clinica/agenda?vista=dia&fecha=' . $this->lunes->toDateString() . '&sede=todas')
            ->assertOk()
            ->assertDontSee('data-dialogo="recordatorio"', false);

        $peticion->post('/clinica/agenda/' . $cita->id . '/notificar', ['canal' => 'sms'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, NotificacionCita::count());
        Http::assertNothingSent();
    }

    public function test_sin_el_dato_del_canal_el_envio_avisa_que_falta_y_no_registra_nada(): void
    {
        config($this->twilio());
        Http::fake();

        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $this->bloque($sede, '08:00', '12:00');
        $this->pacienteConHistoria($medico, 4004, 'Dora', 'Díaz', ['telefono' => '12345']);
        $cita = $this->cita($medico, $sede, $this->lunes->toDateString(), '08:00:00', 1, ['numhistoria' => 4004]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda/' . $cita->id . '/notificar', ['canal' => 'sms'])
            ->assertRedirect()
            ->assertSessionHas('error', 'El paciente no tiene un teléfono válido registrado.');

        $this->assertSame(0, NotificacionCita::count());
    }

    public function test_la_pagina_de_envio_lista_los_pacientes_del_dia_y_marca_el_dato_que_falta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $this->bloque($sede, '08:00', '12:00');
        $this->pacienteConHistoria($medico, 4005, 'Eva', 'Estévez', ['telefono' => '12345', 'email' => 'eva@example.com']);
        $this->pacienteConHistoria($medico, 4006, 'Fina', 'Fernández', ['telefono' => '0414-3333333']);

        $this->cita($medico, $sede, $this->lunes->toDateString(), '08:00:00', 1, ['numhistoria' => 4005]);
        $this->cita($medico, $sede, $this->lunes->toDateString(), '09:00:00', 2, ['numhistoria' => 4006]);
        // Una ya confirmada no entra en la lista: no hay nada que recordarle.
        $this->cita($medico, $sede, $this->lunes->toDateString(), '10:00:00', 3, [
            'numhistoria' => 4006,
            'estado'      => Cola::ESTADO_CONFIRMADA,
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda/envio?fecha=' . $this->lunes->toDateString() . '&sede=todas');

        $respuesta->assertOk();
        $respuesta->assertSee('Enviar a todos');
        $respuesta->assertSee('Estévez');
        $respuesta->assertSee('Fernández');
        $respuesta->assertSee('Sin celular válido');
        // Dos pacientes en la lista, cada uno con la marca de qué dato tiene (el JS del canal arma la
        // selección con eso); la tercera cita, ya confirmada, no se lista.
        $respuesta->assertSee('2 pacientes');
        $respuesta->assertSee('data-telefono="0"', false);
        $respuesta->assertSee('Enviar a los seleccionados');
        // `@disabled` es de Blade 9: si se cuela, la página muestra el texto de la directiva.
        $respuesta->assertDontSee('@disabled', false);
        $this->assertSame(2, substr_count($respuesta->content(), 'name="citas[]"'));
    }

    public function test_el_envio_del_dia_manda_a_los_seleccionados_y_reporta_los_que_fallan(): void
    {
        config($this->twilio());
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM2', 'status' => 'queued'], 201)]);

        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $this->bloque($sede, '08:00', '12:00');
        $this->pacienteConHistoria($medico, 4007, 'Gloria', 'Gómez', ['telefono' => '0414-4444444']);
        $this->pacienteConHistoria($medico, 4008, 'Hugo', 'Hernández', ['telefono' => '12345']);

        $buena = $this->cita($medico, $sede, $this->lunes->toDateString(), '08:00:00', 1, ['numhistoria' => 4007]);
        $mala = $this->cita($medico, $sede, $this->lunes->toDateString(), '09:00:00', 2, ['numhistoria' => 4008]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda/envio', [
                'fecha'   => $this->lunes->toDateString(),
                'sede'    => $sede->id,
                'canal'   => 'sms',
                'mensaje' => 'Aviso del consultorio.',
                'citas'   => [$buena->id, $mala->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('estado')
            ->assertSessionHas('error');

        // El que no tiene teléfono no rompe el envío del resto.
        $this->assertSame(1, NotificacionCita::where('cola_id', $buena->id)->count());
        $this->assertSame(0, NotificacionCita::where('cola_id', $mala->id)->count());
        $this->assertSame('Aviso del consultorio.', $buena->fresh()->sms_text);
    }

    public function test_el_envio_del_dia_no_toca_las_citas_de_otro_medico(): void
    {
        config($this->twilio());
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM3'], 201)]);

        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $this->bloque($sede, '08:00', '12:00');

        $otro = Medico::create(['name' => 'Otro', 'lastname' => 'Médico', 'reg_medico' => 'agenda-otro-' . uniqid()]);
        $this->pacienteConHistoria($otro, 4009, 'Ajena', 'Persona', ['telefono' => '0414-5555555']);
        $ajena = $this->cita($otro, null, $this->lunes->toDateString(), '08:00:00', 1, ['numhistoria' => 4009]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda/envio', [
                'fecha'   => $this->lunes->toDateString(),
                'sede'    => 'todas',
                'canal'   => 'sms',
                'mensaje' => 'Aviso.',
                'citas'   => [$ajena->id],
            ])
            ->assertRedirect();

        $this->assertSame(0, NotificacionCita::count());
        Http::assertNothingSent();
    }

    public function test_el_listado_que_se_imprime_trae_el_dia_y_deja_afuera_las_citas_movidas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $this->bloque($sede, '08:00', '12:00');
        $this->pacienteConHistoria($medico, 4010, 'Iris', 'Ibáñez', ['telefono' => '0414-6666666']);
        $this->pacienteConHistoria($medico, 4011, 'Juan', 'Jiménez');

        $this->cita($medico, $sede, $this->lunes->toDateString(), '08:00:00', 1, ['numhistoria' => 4010]);
        $this->cita($medico, $sede, $this->lunes->toDateString(), '09:00:00', 2, ['numhistoria' => 4010, 'atendido' => 1]);
        // El escritorio la movió a otro día: no está en la jornada.
        $this->cita($medico, $sede, $this->lunes->toDateString(), '10:00:00', 3, [
            'numhistoria'       => 4011,
            'movida_escritorio' => true,
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda/imprimir?fecha=' . $this->lunes->toDateString() . '&sede=todas');

        $respuesta->assertOk();
        $respuesta->assertSee('Listado del día');
        $respuesta->assertSee('Ibáñez');
        $respuesta->assertSee('Pendiente');
        $respuesta->assertSee('Atendido');
        $respuesta->assertSee('2 Pacientes para el 05/10/2026');
        $respuesta->assertDontSee('Jiménez');
    }

    // ------------------------------------------- Filtro por razón y buscador del día (WEB-2.14)

    public function test_el_filtro_por_razon_deja_solo_las_citas_de_esa_razon_en_las_dos_columnas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $this->bloque($sede, '08:00', '12:00');

        MotivoCita::create(['reg_medico' => $medico->reg_medico, 'codigo' => 'ECO', 'tipo_atencion' => 'Ecografía']);
        MotivoCita::create([
            'reg_medico' => $medico->reg_medico, 'codigo' => 'PAP', 'tipo_atencion' => 'Papanicolau',
        ]);

        $this->pacienteConHistoria($medico, 5001, 'Ana', 'Alvarez');
        $this->pacienteConHistoria($medico, 5002, 'Beto', 'Blanco');
        $this->pacienteConHistoria($medico, 5003, 'Carla', 'Castro');

        // La razón vive en `tipo` cuando la escribe el escritorio o la web…
        $this->cita($medico, $sede, $this->lunes->toDateString(), '08:00:00', 1, [
            'numhistoria' => 5001, 'tipo' => 'ECO',
        ]);
        $this->cita($medico, $sede, $this->lunes->toDateString(), '09:00:00', 2, [
            'numhistoria' => 5002, 'tipo' => 'PAP',
        ]);
        // …y en `motivo` cuando la escribe el móvil (WEB-2.16: la web busca el código en las dos).
        $this->cita($medico, $sede, $this->lunes->toDateString(), '10:00:00', 3, [
            'numhistoria' => 5003, 'motivo' => 'ECO',
        ]);

        $peticion = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        // Sin filtro están las tres.
        $peticion->get('/clinica/agenda?vista=dia&fecha=' . $this->lunes->toDateString() . '&sede=todas')
            ->assertOk()
            ->assertSee('3 citas');

        $respuesta = $peticion->get('/clinica/agenda?vista=dia&fecha=' . $this->lunes->toDateString()
            . '&sede=todas&razon=ECO');

        $respuesta->assertOk();
        // El filtro es por el **código** de la razón, no por su texto: el listado muestra el catálogo.
        // (El texto del motivo sigue apareciendo en el `<select>`, por eso lo que se mira acá es el
        // paciente de la cita que no corresponde a la razón elegida.)
        $respuesta->assertSee('Ecografía');
        $respuesta->assertSee('Alvarez');
        $respuesta->assertSee('Castro');
        $respuesta->assertDontSee('Blanco');
        // El encabezado y el conteo del día reflejan lo filtrado, y el chip avisa cuál está puesto.
        $respuesta->assertSee('2 citas');
        $respuesta->assertSee('Razón: Ecografía');
        $respuesta->assertSee('Quitar el filtro de razón');
        // El `<select>` viene con la razón elegida (no hay `@selected` en Blade 8: es el atributo).
        $respuesta->assertSee('value="ECO" selected', false);
    }

    public function test_una_razon_que_no_esta_en_el_catalogo_no_filtra_nada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $this->bloque($sede, '08:00', '12:00');

        MotivoCita::create(['reg_medico' => $medico->reg_medico, 'codigo' => 'ECO', 'tipo_atencion' => 'Ecografía']);
        $this->pacienteConHistoria($medico, 5001, 'Ana', 'Alvarez');
        $this->pacienteConHistoria($medico, 5002, 'Beto', 'Blanco');
        $this->cita($medico, $sede, $this->lunes->toDateString(), '08:00:00', 1, [
            'numhistoria' => 5001, 'tipo' => 'ECO',
        ]);
        $this->cita($medico, $sede, $this->lunes->toDateString(), '09:00:00', 2, [
            'numhistoria' => 5002, 'tipo' => 'PAP',
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda?vista=dia&fecha=' . $this->lunes->toDateString() . '&sede=todas&razon=NOEXISTE');

        $respuesta->assertOk();
        // Un código inventado se comporta como "Todas las razones" (`TODOS` de `ddlb_1` del escritorio):
        // la agenda no queda en blanco y tampoco aparece un chip de un filtro que no se aplicó.
        $respuesta->assertSee('2 citas');
        $respuesta->assertSee('Alvarez');
        $respuesta->assertSee('Blanco');
        $respuesta->assertDontSee('Razón:');
    }

    public function test_el_filtro_por_razon_no_cambia_la_posicion_calculada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_ORDEN, 'C1');
        $this->bloque($sede, '08:00', '12:00', 10);

        MotivoCita::create(['reg_medico' => $medico->reg_medico, 'codigo' => 'ECO', 'tipo_atencion' => 'Ecografía']);

        // El séptimo de la jornada es el de la razón filtrada: su posición no puede renumerarse.
        for ($i = 1; $i <= 7; $i++) {
            $this->pacienteConHistoria($medico, 6000 + $i, 'Paciente' . $i, 'Numero');
        }
        for ($i = 1; $i <= 6; $i++) {
            $this->cita($medico, $sede, $this->lunes->toDateString(), '08:00:00', $i, [
                'numhistoria' => 6000 + $i, 'tipo' => 'CTRL',
            ]);
        }
        $this->cita($medico, $sede, $this->lunes->toDateString(), '09:00:00', 7, [
            'numhistoria' => 6007, 'tipo' => 'ECO',
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda?vista=dia&fecha=' . $this->lunes->toDateString() . '&sede=todas&razon=ECO');

        $respuesta->assertOk();
        $respuesta->assertSee('Numero, Paciente7');
        // Sigue siendo el 7 aunque sea la única fila de la lista (la posición se calcula sobre todo el
        // rango, antes de filtrar): si se renumerara, acá habría un `#1`.
        $respuesta->assertSee('turno">#7', false);
        $respuesta->assertDontSee('turno">#1<', false);
    }

    public function test_la_vista_de_dia_ofrece_el_buscador_de_pacientes_de_la_agenda(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $this->bloque($sede, '08:00', '12:00');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda?vista=dia&fecha=' . $this->lunes->toDateString() . '&sede=' . $sede->id);

        $respuesta->assertOk();
        // El buscador es el listado que el escritorio tiene al lado de la agenda: input con el endpoint
        // JSON del alta y el contenedor de resultados.
        $respuesta->assertSee('data-url="' . route('clinica.pacientes.buscar') . '"', false);
        $respuesta->assertSee('id="agenda-paciente-resultados"', false);
        $respuesta->assertSee('Hacer cita');
        $respuesta->assertSee('Pacientes de la agenda');
        // El "Hacer cita" de cada fila va al alta con la fecha y la sede que se están mirando; el
        // `paciente_id` lo agrega `agenda-pacientes.js` con el paciente elegido.
        $respuesta->assertSee(
            'data-nueva="' . e(route('clinica.agenda.nueva', ['fecha' => $this->lunes->toDateString(), 'sede' => $sede->id])) . '"',
            false
        );
        // Es de la vista de **día**: la semana y el mes no lo llevan.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda?vista=semana&fecha=' . $this->lunes->toDateString() . '&sede=todas')
            ->assertOk()
            ->assertDontSee('id="agenda-paciente-busqueda"', false);
    }

    public function test_el_hacer_cita_del_buscador_abre_el_alta_con_el_paciente_elegido(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $pacienteId = $this->pacienteConHistoria($medico, 5001, 'Ana', 'Alvarez');

        // Es el `href` que arma `agenda-pacientes.js` con el paciente de la fila elegida.
        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda/nueva?fecha=' . $this->lunes->toDateString() . '&paciente_id=' . $pacienteId);

        $respuesta->assertOk();
        $respuesta->assertSee('Alvarez, Ana');
        $respuesta->assertSee('Elegido:', false);
        $respuesta->assertSee('name="paciente_id" id="paciente-id" value="' . $pacienteId . '"', false);
    }

    public function test_el_paciente_elegido_no_se_pierde_al_cambiar_la_sede_del_alta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        // Con **dos** sedes no hay ninguna que proponer (O-03b: la única del médico se propone sola), así que
        // el alta abre pidiendo elegir, que es el caso de este test.
        $this->sede($medico, $this->centro('La Trinidad')->id, Office::MODALIDAD_HORA, 'C2');
        $pacienteId = $this->pacienteConHistoria($medico, 5001, 'Ana', 'Alvarez');

        // Sin sede elegida el alta no guarda (lo dice el aviso de arriba): si al elegirla se perdiera el
        // paciente, el buscador de la agenda obligaría a elegirlo dos veces.
        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda/nueva?fecha=' . $this->lunes->toDateString() . '&paciente_id=' . $pacienteId);

        $respuesta->assertOk();
        $respuesta->assertSee('— Elige una sede —');
        // El paso 1 es un GET sobre esta misma pantalla: el elegido viaja con él.
        $respuesta->assertSee('<input type="hidden" name="paciente_id" value="' . $pacienteId . '">', false);

        $conSede = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda/nueva?fecha=' . $this->lunes->toDateString()
                . '&paciente_id=' . $pacienteId . '&sede=' . $sede->id);

        $conSede->assertOk();
        $conSede->assertSee('Elegido:');
        $conSede->assertSee('Alvarez, Ana');
        $conSede->assertSee('name="paciente_id" id="paciente-id" value="' . $pacienteId . '"', false);
    }

    // -------------------------------------- La sede por defecto de "Nueva cita" es la del contexto (O-03)

    public function test_el_alta_de_cita_sin_sede_en_la_url_toma_la_del_contexto(): void
    {
        // O-03 (2026-10-17): si no viene `sede` en la URL, el paso 1 abre con la sede del consultorio que
        // se eligió en la barra superior. Antes abría en «— Elige una sede —» aunque hubiera una elegida.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');

        $respuesta = $this->conContexto($user, $medico, $gineco, $sede->id)->actingAs($user)
            ->get('/clinica/agenda/nueva?fecha=' . $this->lunes->toDateString());

        $respuesta->assertOk();
        $respuesta->assertSee('<option value="' . $sede->id . '" selected>', false);
        // Y el aviso de «elegí la sede» deja de aparecer en el camino normal.
        $respuesta->assertDontSee('— Elige una sede —');
        $respuesta->assertDontSee('Elige la sede para ver su jornada');
    }

    public function test_el_alta_de_cita_respeta_la_sede_pedida_en_la_url(): void
    {
        // Con `sede=<id>` manda la URL, aunque el contexto esté en otra: es la agenda de esa sede la que
        // está agendando.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $delContexto = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $pedida = $this->sede($medico, $this->centro('La Trinidad')->id, Office::MODALIDAD_HORA, 'C2');

        $respuesta = $this->conContexto($user, $medico, $gineco, $delContexto->id)->actingAs($user)
            ->get('/clinica/agenda/nueva?fecha=' . $this->lunes->toDateString() . '&sede=' . $pedida->id);

        $respuesta->assertOk();
        $respuesta->assertSee('<option value="' . $pedida->id . '" selected>', false);
        $respuesta->assertDontSee('<option value="' . $delContexto->id . '" selected>', false);
    }

    public function test_el_alta_de_cita_con_sede_todas_no_fuerza_la_del_contexto(): void
    {
        // `sede=todas` es una elección explícita de la agenda (mirar todas las sedes juntas): no se pisa
        // con la del contexto y la cita —que siempre es en una sede— sigue pidiendo que se elija.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');

        $respuesta = $this->conContexto($user, $medico, $gineco, $sede->id)->actingAs($user)
            ->get('/clinica/agenda/nueva?fecha=' . $this->lunes->toDateString() . '&sede=todas');

        $respuesta->assertOk();
        $respuesta->assertSee('— Elige una sede —');
        $respuesta->assertDontSee('<option value="' . $sede->id . '" selected>', false);
    }

    public function test_el_buscador_de_pacientes_de_la_agenda_lleva_la_sede_del_contexto(): void
    {
        // El "Hacer cita" de cada fila del buscador (y el de los récipes) llega sin `sede`: tiene que
        // quedar la del contexto igual. Acá se comprueba el camino completo desde la agenda.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $pacienteId = $this->pacienteConHistoria($medico, 5001, 'Ana', 'Alvarez');

        $respuesta = $this->conContexto($user, $medico, $gineco, $sede->id)->actingAs($user)
            ->get('/clinica/agenda/nueva?fecha=' . $this->lunes->toDateString() . '&paciente_id=' . $pacienteId);

        $respuesta->assertOk();
        $respuesta->assertSee('<option value="' . $sede->id . '" selected>', false);
        $respuesta->assertSee('Alvarez, Ana');
    }

    public function test_el_alta_rapida_del_paso_2_propone_el_sexo_de_la_especialidad(): void
    {
        // O-01: la otra forma de dar de alta un paciente en la web —el alta mínima del paso 2 de «Nueva
        // cita»— usa la misma regla que la ficha: en ginecología arranca en Femenino.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $url = '/clinica/agenda/nueva?fecha=' . $this->lunes->toDateString();

        $this->conContexto($user, $medico, $gineco, $sede->id)->actingAs($user)->get($url)
            ->assertOk()
            ->assertSee('<option value="F" selected>Femenino</option>', false);

        // En una especialidad que no declara la clave queda el «Sin dato» de siempre.
        $pediatria = Specialty::where('slug', 'pediatria')->firstOrFail();
        $medico->specialties()->attach($pediatria->id);

        $this->conContexto($user, $medico, $pediatria, $sede->id)->actingAs($user)->get($url)
            ->assertOk()
            ->assertSee('<option value="F" >Femenino</option>', false);
    }

    // ------------------------- La única sede se propone en «Todos mis consultorios» (O-03b, 2026-10-17)

    public function test_el_alta_de_cita_sin_contexto_propone_la_unica_sede_del_medico(): void
    {
        // O-03b: sin consultorio elegido (la barra en «Todos mis consultorios») y con **una sola** sede
        // vigente, el paso 1 la propone. Antes abría en «— Elige una sede —».
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda/nueva?fecha=' . $this->lunes->toDateString());

        $respuesta->assertOk();
        $respuesta->assertSee('<option value="' . $sede->id . '" selected>', false);
        $respuesta->assertDontSee('— Elige una sede —');
    }

    public function test_el_alta_de_cita_sin_contexto_con_varias_sedes_sigue_pidiendo_elegir(): void
    {
        // Con más de una sede no hay nada que proponer: la sede decide la jornada y el lugar.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $primera = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $segunda = $this->sede($medico, $this->centro('La Trinidad')->id, Office::MODALIDAD_HORA, 'C2');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda/nueva?fecha=' . $this->lunes->toDateString());

        $respuesta->assertOk();
        $respuesta->assertSee('— Elige una sede —');
        $respuesta->assertDontSee('<option value="' . $primera->id . '" selected>', false);
        $respuesta->assertDontSee('<option value="' . $segunda->id . '" selected>', false);
    }

    public function test_el_alta_de_cita_con_sede_todas_no_propone_la_unica_sede(): void
    {
        // `sede=todas` es una elección explícita (mirar todas las sedes juntas): no se pisa con la única.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/agenda/nueva?fecha=' . $this->lunes->toDateString() . '&sede=todas');

        $respuesta->assertOk();
        $respuesta->assertSee('— Elige una sede —');
        $respuesta->assertDontSee('<option value="' . $sede->id . '" selected>', false);
    }

    // ------------------------- El alta rápida de la cita deja la fecha de ingreso (O-02b, 2026-10-17)

    public function test_el_alta_rapida_del_paso_2_deja_la_fecha_de_ingreso_del_consultorio(): void
    {
        // O-02b: el alta mínima del paso 2 no tiene el campo `fingreso`, y sin él el paciente no aparecía en
        // «pacientes por rango». Un paciente **nuevo** nace con **hoy**, y el hoy es el del consultorio: las
        // 20:30 del sábado 10/10/2026 en Caracas ya son el domingo 11 en UTC (trampa 59).
        $this->a_las_ocho_y_media_de_la_noche_en_caracas();

        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $this->bloque($sede, '08:00', '12:00');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda', $this->datosDeCita($sede, [
                'hora'            => '09:00',
                'nuevo_cedula'    => '11223344',
                'nuevo_nombres'   => 'Nueva',
                'nuevo_apellidos' => 'Paciente',
            ]))
            ->assertRedirect($this->urlDeLaAgenda($sede));

        $paciente = Paciente::where('cedula', '11223344')->firstOrFail();
        $this->assertSame('2026-10-10', $paciente->fingreso?->toDateString(), 'la fecha de ingreso es la del consultorio, no la de UTC');
    }

    public function test_el_alta_rapida_que_enlaza_por_cedula_no_toca_la_fecha_de_ingreso(): void
    {
        // O-02b: si al guardar **enlaza** con una ficha que ya existía (de este médico o de otro), la fecha no
        // se toca: «sólo completa vacíos», y estampar hoy una ficha vieja sería un dato falso. Vale también
        // cuando la ficha existente la tenía en `null`.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, $this->centro('San José')->id, Office::MODALIDAD_HORA, 'C1');
        $this->bloque($sede, '08:00', '12:00');

        $sinFecha = Paciente::create(['cedula' => '55667788', 'nombres' => 'Vieja', 'apellidos' => 'Sin fecha']);
        $conFecha = Paciente::create(['cedula' => '55667799', 'nombres' => 'Vieja', 'apellidos' => 'Con fecha', 'fingreso' => '2020-03-15']);

        foreach (['55667788' => 'Sin fecha', '55667799' => 'Con fecha'] as $cedula => $apellidos) {
            $this->conContexto($user, $medico, $gineco)->actingAs($user)
                ->post('/clinica/agenda', $this->datosDeCita($sede, [
                    'hora'            => '09:00',
                    'nuevo_cedula'    => (string) $cedula,
                    'nuevo_nombres'   => 'Otra',
                    'nuevo_apellidos' => $apellidos,
                ]))
                ->assertRedirect($this->urlDeLaAgenda($sede));
        }

        // La que estaba en `null` sigue en `null`; la que tenía fecha conserva la suya.
        $this->assertNull($sinFecha->fresh()->fingreso);
        $this->assertSame('2020-03-15', $conFecha->fresh()->fingreso?->toDateString());
        // Y no se duplicó ninguna ficha.
        $this->assertSame(1, Paciente::where('cedula', '55667788')->count());
        $this->assertSame(1, Paciente::where('cedula', '55667799')->count());
    }
}
