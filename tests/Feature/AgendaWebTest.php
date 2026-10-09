<?php

namespace Tests\Feature;

use App\Clinica\Agenda\ArmadorDeAgenda;
use App\Models\Cola;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\MotivoCita;
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

    private function pacienteConHistoria(Medico $medico, int $numhistoria, string $nombres, string $apellidos = 'Paciente'): int
    {
        $id = DB::table('pacientes')->insertGetId([
            'nombres'    => $nombres,
            'apellidos'  => $apellidos,
            'cedula'     => (string) random_int(10000000, 30000000),
            'sexo'       => 'F',
            'fnacimiento'=> '1990-05-04',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
        $cita = $this->cita($medico, null, $this->lunes->toDateString(), '08:00:00', 1, ['estado' => 0, 'atendido' => 0]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post('/clinica/agenda/' . $cita->id . '/atender')
            ->assertRedirect();

        $fresca = $cita->fresh();
        $this->assertSame(1, (int) $fresca->atendido);
        $this->assertSame(Cola::ESTADO_CONFIRMADA, (int) $fresca->estado);
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
        $this->seed(EspecialidadesYModulosSeeder::class);
        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();

        $medico = Medico::create(['name' => 'Dr. Dueño', 'lastname' => 'Datos', 'reg_medico' => 'agenda-secre-' . uniqid()]);
        $medico->specialties()->attach($gineco->id);

        $secretaria = User::create([
            'name'                 => 'Secretaria',
            'email'                => 'agenda-secre-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->get('/clinica/agenda?vista=dia&fecha=' . $this->lunes->toDateString())
            ->assertOk();
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
}
