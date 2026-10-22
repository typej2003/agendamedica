<?php

namespace Tests\Feature;

use App\Clinica\Agenda\AgendamientoDeCitas;
use App\Models\Cola;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Models\Paciente;
use App\Models\User;
use App\Notificaciones\Telefono;
use App\Paciente\DescartarCodigoDeTelefono;
use App\Reservas\CodigoDeVerificacion;
use App\Reservas\LimitesDeReserva;
use App\Services\PacienteAtencionContextService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **Límites preventivos de reserva** (`LimitesDeReserva`): 3 citas pendientes por persona y 5 códigos por hora
 * por teléfono, para el portal, la reserva pública y el chatbot; la secretaría y el médico agendan sin tope.
 * Además, «Usar otro número» (`DescartarCodigoDeTelefono`).
 */
class LimitesDeReservaTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $fecha;

    private Medico $medico;

    private Office $sede;

    protected function setUp(): void
    {
        parent::setUp();

        // Domingo 18 de octubre de 2026: el bloque del lunes 19 a las 08:00 queda dentro de la anticipación del portal.
        Carbon::setTestNow(Carbon::create(2026, 10, 18, 7, 0, 0));
        $this->fecha = Carbon::createFromFormat('!Y-m-d', '2026-10-19');

        $this->medico = Medico::create([
            'name' => 'Carlos',
            'lastname' => 'Limites',
            'email' => 'limites-' . substr(uniqid(), -6) . '@example.com',
            'reg_medico' => 'test-limites' . substr(uniqid(), -6),
        ]);
        MedicoRegistro::create(['medico_id' => $this->medico->id, 'reg_medico' => $this->medico->reg_medico]);

        $centro = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id' => DB::table('estados')->value('id'),
            'city_id' => DB::table('cities')->value('id'),
            'name' => 'Sede de límites',
            'address' => 'Calle de prueba',
        ]);

        $this->sede = Office::create([
            'medical_center_id' => $centro->id,
            'medico_id' => $this->medico->id,
            'reg_medico' => $this->medico->reg_medico,
            'office_number' => '1',
            'modalidad' => Office::MODALIDAD_HORA,
            'duracion_cita' => 30,
            'activo' => true,
        ]);

        OfficeSchedule::create([
            'office_id' => $this->sede->id,
            'reg_medico' => $this->medico->reg_medico,
            'dia_semana' => 1,
            'hora_inicio' => '08:00',
            'hora_fin' => '12:00',
            'cupo' => 20,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------ Ayudas

    private function telefonoUnico(): string
    {
        return '0414' . random_int(1000000, 9999999);
    }

    private function ficha(string $telefono, ?string $cedula = null, ?int $userId = null): Paciente
    {
        return Paciente::create([
            'cedula' => $cedula ?? (string) random_int(10000000, 30000000),
            'nombres' => 'Lia',
            'apellidos' => 'Limite',
            'telefono' => $telefono,
            'user_id' => $userId,
        ]);
    }

    private function cuenta(string $nombre): User
    {
        $user = User::create([
            'name' => $nombre,
            'email' => 'lim-' . substr(uniqid(), -8) . '@example.com',
            'password' => Hash::make('secreto123'),
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('Paciente');

        return $user;
    }

    /** Una cita de la ficha con otro consultorio (para no chocar con la regla de una pendiente por médico). */
    private function cita(Paciente $ficha, string $fecha = '2026-10-21', int $atendido = 0): Cola
    {
        return Cola::create([
            'reg_medico' => 'otro-' . substr(uniqid(), -8),
            'fecha' => $fecha,
            'hora_ini' => '09:00',
            'atendido' => $atendido,
            'estado' => 2,
            'paciente_sinhistoria_id' => $ficha->id,
        ]);
    }

    private function pendientes(Paciente $ficha, int $cuantas): void
    {
        for ($i = 0; $i < $cuantas; $i++) {
            $this->cita($ficha);
        }
    }

    /** @return array<string,string> */
    private function datos(string $telefono, ?string $cedula = null): array
    {
        return [
            'horario' => $this->fecha->toDateString() . '|08:00|' . $this->sede->id,
            'cedula' => $cedula ?? (string) random_int(10000000, 30000000),
            'nombres' => 'Lia',
            'apellidos' => 'Limite',
            'telefono' => $telefono,
            'email' => 'lia.limite@example.com',
        ];
    }

    /** Reserva por el portal público: envía el código y lo confirma. */
    private function reservar(array $datos): \Illuminate\Testing\TestResponse
    {
        $this->post(route('reservas.codigo.enviar', $this->medico), $datos);

        return $this->post(route('reservas.confirmar', $this->medico), ['codigo' => (string) session('reservas.pendiente.codigo')]);
    }

    private function citasDelMedico(): int
    {
        return Cola::where('reg_medico', $this->medico->reg_medico)->count();
    }

    // ------------------------------------------------------------------------------ Citas pendientes

    public function test_la_reserva_publica_permite_tres_pendientes_y_rechaza_la_cuarta(): void
    {
        $telefono = $this->telefonoUnico();
        $ficha = $this->ficha($telefono, '31000001');
        $this->pendientes($ficha, 2);

        $this->reservar($this->datos($telefono, '31000001'))->assertRedirect(route('reservas.confirmada'));
        $this->assertSame(1, $this->citasDelMedico(), 'La tercera pendiente sí se permite.');
        $this->assertSame(3, app(LimitesDeReserva::class)->citasPendientes(null, $telefono));

        $this->flushSession();
        $otraHora = array_merge($this->datos($telefono, '31000001'), ['horario' => $this->fecha->toDateString() . '|08:30|' . $this->sede->id]);
        $resultado = $this->reservar($otraHora);

        $resultado->assertSessionHas('error', LimitesDeReserva::mensajeDeCitas());
        $this->assertSame(1, $this->citasDelMedico(), 'La cuarta no se crea.');
        $this->assertSame('Ya tienes 3 citas pendientes. Atiéndelas o cancélalas antes de reservar otra.', LimitesDeReserva::mensajeDeCitas());
    }

    public function test_las_canceladas_las_pasadas_y_las_atendidas_no_cuentan(): void
    {
        $telefono = $this->telefonoUnico();
        $ficha = $this->ficha($telefono, '31000002');

        $this->cita($ficha, '2026-10-10');                 // pasada
        $this->cita($ficha, '2026-10-17');                 // pasada
        $this->cita($ficha, '2026-10-21', 1);              // atendida
        $this->cita($ficha)->delete();                     // cancelada: cancelar es borrar la fila (EliminarCita)
        $this->pendientes($ficha, 2);

        $this->assertSame(2, app(LimitesDeReserva::class)->citasPendientes(null, $telefono));
        $this->reservar($this->datos($telefono, '31000002'))->assertRedirect(route('reservas.confirmada'));
    }

    public function test_sin_sesion_cuenta_por_el_telefono_validado_y_no_por_la_cedula_tecleada(): void
    {
        $telefono = $this->telefonoUnico();
        $this->pendientes($this->ficha($telefono, '31000003'), 3);

        // Otra cédula (sin ficha) pero el mismo teléfono validado por el código: el tope sigue ahí.
        $this->reservar($this->datos($telefono, '31000099'))->assertSessionHas('error', LimitesDeReserva::mensajeDeCitas());
        $this->assertSame(0, $this->citasDelMedico());

        // La cédula de las tres citas, con OTRO teléfono, no las hereda: la persona es el teléfono.
        $this->flushSession();
        $otro = $this->telefonoUnico();
        $this->assertSame(0, app(LimitesDeReserva::class)->citasPendientes(null, $otro));
    }

    public function test_con_sesion_cuenta_las_de_su_ficha_aunque_reserve_con_otro_telefono(): void
    {
        $user = $this->cuenta('Cuenta Límite');
        $ficha = $this->ficha($this->telefonoUnico(), '31000004', $user->id);
        $this->pendientes($ficha, 3);

        $this->actingAs($user);
        $this->assertSame(3, app(LimitesDeReserva::class)->citasPendientes($user));

        $this->reservar($this->datos($this->telefonoUnico()))->assertSessionHas('error', LimitesDeReserva::mensajeDeCitas());
        $this->assertSame(0, $this->citasDelMedico());
    }

    public function test_el_conteo_sigue_el_vinculo_por_historia(): void
    {
        $telefono = $this->telefonoUnico();
        $ficha = $this->ficha($telefono, '31000005');
        $reg = 'hist-' . substr(uniqid(), -8);
        DB::table('medico_pacientes')->insert([
            'medico_id' => $this->medico->id,
            'paciente_id' => $ficha->id,
            'reg_medico' => $reg,
            'numhistoria' => 777001,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Cola::create(['reg_medico' => $reg, 'fecha' => '2026-10-22', 'hora_ini' => '10:00', 'atendido' => 0, 'estado' => 0, 'numhistoria' => 777001]);
        Cola::create(['reg_medico' => $reg, 'fecha' => '2026-10-23', 'hora_ini' => '10:00', 'atendido' => 0, 'estado' => 0, 'numhistoria' => 777002]);

        $this->assertSame(1, app(LimitesDeReserva::class)->citasPendientes(null, $telefono));
    }

    public function test_la_secretaria_y_el_medico_agendan_igual_con_tres_pendientes(): void
    {
        $telefono = $this->telefonoUnico();
        $ficha = $this->ficha($telefono, '31000006');
        $this->pendientes($ficha, 3);

        // El agendamiento interno es el de la web clínica: no consulta `LimitesDeReserva`.
        $resultado = app(AgendamientoDeCitas::class)->agendar(
            ['reg_medico' => $this->medico->reg_medico, 'medico' => $this->medico],
            [
                'sede' => $this->sede->id,
                'fecha' => $this->fecha->toDateString(),
                'hora' => '08:00',
                'nuevo_cedula' => '31000006',
                'nuevo_nombres' => 'Lia',
                'nuevo_apellidos' => 'Limite',
                'nuevo_telefono' => $telefono,
            ],
            [$this->medico->reg_medico],
        );

        $this->assertNotNull($resultado->cita);
        $this->assertSame(1, $this->citasDelMedico());
        $this->assertSame(4, app(LimitesDeReserva::class)->citasPendientes(null, $telefono));
    }

    public function test_el_chatbot_rechaza_la_cuarta_y_acepta_la_tercera(): void
    {
        $telefono = '58' . substr($this->telefonoUnico(), 1);
        $ficha = $this->ficha($telefono, '31000007');
        $this->pendientes($ficha, 2);

        $servicio = app(PacienteAtencionContextService::class);

        $tercera = $servicio->reservarCitaEnCola($telefono, $this->medico->reg_medico, $this->fecha->toDateString());
        $this->assertTrue($tercera['exito'], $tercera['mensaje'] ?? '');

        $cuarta = $servicio->reservarCitaEnCola($telefono, $this->medico->reg_medico, $this->fecha->copy()->addDay()->toDateString());
        $this->assertFalse($cuarta['exito']);
        $this->assertTrue($cuarta['limite_citas']);
        $this->assertSame(LimitesDeReserva::mensajeDeCitas(), $cuarta['mensaje']);
        $this->assertSame(1, $this->citasDelMedico());
    }

    // ------------------------------------------------------------------------------- Códigos por hora

    public function test_cinco_codigos_por_hora_por_telefono_la_sexta_se_rechaza_y_pasada_la_hora_vuelve(): void
    {
        $telefono = $this->telefonoUnico();
        $codigos = app(CodigoDeVerificacion::class);

        for ($i = 1; $i <= 5; $i++) {
            $this->assertTrue($codigos->enviar($telefono, '123456')['ok'], "El código {$i} se envía.");
        }

        $sexto = $codigos->enviar($telefono, '123456');
        $this->assertFalse($sexto['ok']);
        $this->assertSame('Pediste demasiados códigos. Intenta de nuevo en una hora.', $sexto['error']);

        // Otro teléfono no se ve afectado.
        $this->assertTrue($codigos->enviar($this->telefonoUnico(), '123456')['ok']);

        Carbon::setTestNow(now()->addMinutes(59));
        $this->assertFalse($codigos->enviar($telefono, '123456')['ok'], 'A los 59 minutos sigue el tope.');

        Carbon::setTestNow(now()->addMinutes(2));
        $this->assertTrue($codigos->enviar($telefono, '123456')['ok'], 'Pasada la hora vuelve a funcionar.');
    }

    public function test_el_cambio_de_telefono_se_frena_con_el_mensaje_del_limite(): void
    {
        $user = $this->cuenta('Cuenta Teléfono');
        $telefono = $this->telefonoUnico();
        $this->ficha($telefono, '31000008', $user->id);
        $this->actingAs($user);

        for ($i = 1; $i <= 5; $i++) {
            $this->post(route('paciente.telefono.codigo'), ['telefono' => $telefono, 'reenviar' => 1])->assertSessionHasNoErrors();
            Carbon::setTestNow(now()->addSeconds(61));
        }

        $this->post(route('paciente.telefono.codigo'), ['telefono' => $telefono, 'reenviar' => 1])
            ->assertSessionHasErrors(['telefono' => LimitesDeReserva::mensajeDeCodigos()]);
    }

    // ------------------------------------------------------------------------------ Usar otro número

    public function test_usar_otro_numero_descarta_el_codigo_en_curso_sin_gastar_otro(): void
    {
        $user = $this->cuenta('Cuenta Otro');
        $telefono = $this->telefonoUnico();
        $this->ficha($telefono, '31000009', $user->id);
        $this->actingAs($user);

        $this->post(route('paciente.telefono.codigo'), ['telefono' => $telefono]);
        $this->assertNotNull(session('telefono.pendiente'));

        $this->get(route('paciente.telefono'))->assertOk()->assertSee('Usar otro número');

        $this->post(route('paciente.telefono.otro'))->assertRedirect(route('paciente.telefono'));
        $this->assertNull(session('telefono.pendiente'));

        // Vuelve al paso de elegir el número, al instante, y no se envió ningún código extra.
        $this->get(route('paciente.telefono'))->assertOk()->assertDontSee('Usar otro número')->assertSee('Enviar código');
        $this->assertCount(1, (array) Cache::get('limite-codigos:' . Telefono::internacional($telefono)));
    }

    public function test_la_accion_de_descartar_solo_olvida_el_tramite_de_la_propia_cuenta(): void
    {
        $user = $this->cuenta('A');
        $otra = $this->cuenta('B');
        $accion = app(DescartarCodigoDeTelefono::class);

        $this->assertFalse($accion->ejecutar($user), 'Sin trámite no hay nada que descartar.');

        session(['telefono.pendiente' => ['user_id' => $otra->id, 'telefono' => '584141234567']]);
        $this->assertFalse($accion->ejecutar($user));
        $this->assertNotNull(session('telefono.pendiente'), 'El trámite de otra cuenta no se toca.');

        $this->assertTrue($accion->ejecutar($otra));
        $this->assertNull(session('telefono.pendiente'));
    }
}
