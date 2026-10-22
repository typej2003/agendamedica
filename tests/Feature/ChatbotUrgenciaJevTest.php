<?php

namespace Tests\Feature;

use App\Models\BloqueoDePaciente;
use App\Models\Cola;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Models\Paciente;
use App\Notificaciones\Telefono;
use App\Reservas\DisponibilidadPublica;
use App\Reservas\UrgenciaClinicaJev;
use App\Services\PacienteAtencionContextService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * La urgencia en el chatbot de WhatsApp: si **Jev** afirma que el mensaje es una urgencia clínica, se puede
 * agendar con 90 minutos de anticipación en vez de 24 h. Todo lo demás —fallos, dudas, bloqueo, tope de
 * citas, portal, reserva pública— sigue con las 24 h.
 *
 * Nada llama a Jev de verdad: o hay un doble de `UrgenciaClinicaJev` o `Http::fake()`.
 *
 * Reloj: lunes 26 de octubre de 2026, 10:00 en Caracas. Agenda del médico: lunes 11:00 (a 60 min: no entra
 * ni para una urgencia), lunes 11:40 (a 100 min: entra sólo para una urgencia) y martes 10:30 (a 24 h 30 min:
 * el primer lugar con la regla normal).
 */
class ChatbotUrgenciaJevTest extends TestCase
{
    use DatabaseTransactions;

    private const TELEFONO = '584165800403';

    private Medico $medico;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-26 10:00:00', 'America/Caracas'));

        // Fijos acá y no heredados del `.env`: con una clave real cargada en la máquina los tests no deben salir a la red.
        config([
            'services.jev.api_key' => 'sk_de_prueba',
            'services.jev.base_url' => 'https://thejevai.com',
            'services.jev.protocolo' => 'systemone',
            'services.jev.umbral' => 0.7,
            'services.jev.urgencia_timeout' => 4,
        ]);

        $this->medico = $this->medicoConAgenda(['11:00' => '12:00', '11:40' => '13:00'], '10:30');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------- El camino feliz y sus contrarios

    public function test_urgencia_con_confianza_alta_agenda_a_100_minutos_y_no_a_60(): void
    {
        $jev = $this->jevDice(0.92);
        $paciente = $this->paciente();

        $resultado = $this->servicioConMensaje('Tengo un sangrado fuerte, necesito cita hoy')
            ->reservarCitaEnCola(self::TELEFONO, $this->medico->reg_medico, '2026-10-26');

        $this->assertTrue($resultado['exito'], $resultado['mensaje'] ?? $resultado['error'] ?? '');
        $cita = Cola::where('paciente_sinhistoria_id', $paciente->id)->firstOrFail();
        $this->assertSame('2026-10-26', Carbon::parse($cita->fecha)->toDateString());
        $this->assertSame('11:40', substr((string) $cita->hora_ini, 0, 5), 'Se agenda a 100 min, no a las 11:00 (60 min).');
        $this->assertSame(1, $jev->llamadas);
    }

    public function test_la_oferta_de_dias_trae_el_horario_de_la_ventana_y_lo_marca(): void
    {
        $this->jevDice(0.9);
        $this->paciente();

        $oferta = $this->servicioConMensaje('Fiebre alta en mi bebé')->consultarProximoDiaDisponible($this->medico->reg_medico);

        $this->assertTrue($oferta['disponible']);
        $this->assertSame('2026-10-26', $oferta['opciones'][0]['fecha']);
        $this->assertSame('11:40', $oferta['opciones'][0]['hora']);
        $this->assertSame(90, $oferta['anticipacion_minutos']);
    }

    public function test_no_urgencia_mantiene_las_24_horas(): void
    {
        $this->jevDice(0.05);
        $paciente = $this->paciente();

        $resultado = $this->servicioConMensaje('Quiero una cita para control')->reservarCitaEnCola(self::TELEFONO, $this->medico->reg_medico);

        $this->assertTrue($resultado['exito']);
        $cita = Cola::where('paciente_sinhistoria_id', $paciente->id)->firstOrFail();
        $this->assertSame('2026-10-27', Carbon::parse($cita->fecha)->toDateString());
        $this->assertSame('10:30', substr((string) $cita->hora_ini, 0, 5));
    }

    public function test_confianza_baja_mantiene_las_24_horas(): void
    {
        $jev = $this->jevDice(0.65); // por debajo del umbral 0,70
        $this->paciente();

        $oferta = $this->servicioConMensaje('Me duele un poco la barriga')->consultarProximoDiaDisponible($this->medico->reg_medico);

        $this->assertSame('2026-10-27', $oferta['opciones'][0]['fecha']);
        $this->assertArrayNotHasKey('anticipacion_minutos', $oferta);
        $this->assertSame(1, $jev->llamadas);
    }

    public function test_si_jev_falla_se_cae_a_24_horas_sin_romper_el_chat(): void
    {
        $this->paciente();

        $casos = [
            'error 500' => fn () => Http::response(['message' => 'boom'], 500),
            'timeout' => function () {
                throw new ConnectionException('cURL error 28: timed out');
            },
            'sin respuesta utilizable' => fn () => Http::response(['answers' => ['es_urgencia' => ['type' => 'noul', 'noul' => 'quizá']]], 200),
            'fuera de rango' => fn () => Http::response(['answers' => ['es_urgencia' => ['type' => 'noul', 'noul' => 7]]], 200),
        ];

        foreach ($casos as $nombre => $respuesta) {
            Http::fake(['*' => $respuesta]);

            $oferta = $this->servicioConMensaje('Tengo un dolor muy fuerte')->consultarProximoDiaDisponible($this->medico->reg_medico);

            $this->assertTrue($oferta['disponible'], $nombre);
            $this->assertSame('2026-10-27', $oferta['opciones'][0]['fecha'], "{$nombre}: rige la regla de 24 h");
        }
    }

    public function test_sin_clave_de_jev_se_cae_a_24_horas_sin_llamar_a_la_red(): void
    {
        config(['services.jev.api_key' => null]);
        Http::fake();
        $this->paciente();

        $oferta = $this->servicioConMensaje('Estoy sangrando')->consultarProximoDiaDisponible($this->medico->reg_medico);

        $this->assertSame('2026-10-27', $oferta['opciones'][0]['fecha']);
        Http::assertNothingSent();
    }

    public function test_el_cliente_real_manda_un_solo_intento_con_la_pregunta_del_triaje(): void
    {
        Http::fake(['https://thejevai.com/v1/systemone' => Http::response(['code' => 0, 'data' => ['result' => [
            'answers' => ['es_urgencia' => ['type' => 'noul', 'noul' => 0.81]],
        ]]], 200)]);

        $resultado = app(UrgenciaClinicaJev::class)->evaluar('Contracciones cada 3 minutos', 'ficha:1');

        $this->assertTrue($resultado['urgente']);
        $this->assertSame('urgencia', $resultado['motivo']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['state'] === 'Contracciones cada 3 minutos'
            && array_keys($r['questions']) === ['es_urgencia']
            && $r['questions']['es_urgencia'] === UrgenciaClinicaJev::pregunta());
    }

    public function test_ante_un_429_no_reintenta_y_cae_a_no_urgente(): void
    {
        Http::fake(['*' => Http::response(['message' => 'límite'], 429)]);
        $limite = app(UrgenciaClinicaJev::class)->evaluar('Contracciones', 'ficha:1');
        $this->assertFalse($limite['urgente']);
        $this->assertSame('error', $limite['motivo']);
        Http::assertSentCount(1);
    }

    // ------------------------------------------------------------------------------- Cuándo se consulta a Jev

    public function test_sin_horarios_hoy_se_consulta_a_jev_pero_la_oferta_de_agenda_es_la_misma(): void
    {
        // Agenda sin lugares entre 90 min y 24 h: lunes 11:00 (60 min) y martes 10:30. Sin lugar hoy, Jev sí se
        // consulta (para conectar con el consultorio, ver `ChatbotUrgenciaSinHorarioTest`), pero la agenda no cambia.
        $medico = $this->medicoConAgenda(['11:00' => '12:00'], '10:30');
        $jev = $this->jevDice(0.99);
        $this->paciente();

        $oferta = $this->servicioConMensaje('Estoy sangrando mucho')->consultarProximoDiaDisponible($medico->reg_medico);
        $reserva = $this->servicioConMensaje('Estoy sangrando mucho')->reservarCitaEnCola(self::TELEFONO, $medico->reg_medico);

        $this->assertSame('2026-10-27', $oferta['opciones'][0]['fecha']);
        $this->assertSame('10:30', $oferta['opciones'][0]['hora']);
        $this->assertTrue($reserva['exito']);
        $this->assertSame(2, $jev->llamadas, 'Una consulta por mensaje, y hubo dos mensajes.');
    }

    public function test_sin_mensaje_en_curso_no_hay_excepcion_ni_llamada(): void
    {
        $jev = $this->jevDice(0.99);
        $this->paciente();

        $oferta = (new PacienteAtencionContextService())->consultarProximoDiaDisponible($this->medico->reg_medico);

        $this->assertSame('2026-10-27', $oferta['opciones'][0]['fecha']);
        $this->assertSame(0, $jev->llamadas);
    }

    public function test_jev_se_consulta_una_sola_vez_por_mensaje(): void
    {
        $jev = $this->jevDice(0.9);
        $this->paciente();
        $servicio = $this->servicioConMensaje('Sangrado abundante');

        $servicio->consultarProximoDiaDisponible($this->medico->reg_medico);
        $servicio->reservarCitaEnCola(self::TELEFONO, $this->medico->reg_medico, '2026-10-26');

        $this->assertSame(1, $jev->llamadas);
    }

    public function test_una_oferta_confirmada_conserva_su_regla_y_no_vuelve_a_preguntarle_a_jev(): void
    {
        $jev = $this->jevDice(0.99);
        $this->paciente();

        // «Sí» al confirmar una oferta normal: 24 h explícitas, sin Jev aunque el mensaje en curso sea cualquiera.
        $normal = $this->servicioConMensaje('sí')->reservarCitaEnCola(
            self::TELEFONO, $this->medico->reg_medico, '2026-10-26', null, PacienteAtencionContextService::ANTICIPACION_MINIMA_MINUTOS
        );
        $this->assertTrue($normal['exito']);
        $this->assertSame('10:30', $this->horaDeLaUltimaCita());
        $this->assertSame(0, $jev->llamadas);

        // La oferta que sí fue de urgencia (90) se confirma a las 11:40.
        Cola::query()->delete();
        $urgente = $this->servicioConMensaje('sí')->reservarCitaEnCola(self::TELEFONO, $this->medico->reg_medico, '2026-10-26', null, 90);
        $this->assertTrue($urgente['exito']);
        $this->assertSame('11:40', $this->horaDeLaUltimaCita());
        $this->assertSame(0, $jev->llamadas);

        // Un valor cualquiera no es una puerta abierta: sólo 90 relaja; 5 minutos vale 24 h.
        Cola::query()->delete();
        $this->servicioConMensaje('sí')->reservarCitaEnCola(self::TELEFONO, $this->medico->reg_medico, '2026-10-26', null, 5);
        $this->assertSame('10:30', $this->horaDeLaUltimaCita());
    }

    public function test_no_se_ofrece_un_horario_pasado_ni_a_menos_de_90_minutos(): void
    {
        $this->jevDice(0.99);
        $this->paciente();

        $opciones = $this->servicioConMensaje('Urgencia, sangrado')->consultarProximoDiaDisponible($this->medico->reg_medico, 30, 5)['opciones'];

        $this->assertNotEmpty($opciones);
        foreach ($opciones as $opcion) {
            $momento = Carbon::createFromFormat('Y-m-d H:i', $opcion['fecha'] . ' ' . $opcion['hora'], 'America/Caracas');
            $this->assertTrue($momento->gte(Carbon::now()->addMinutes(90)), "{$opcion['fecha']} {$opcion['hora']} queda a menos de 90 min");
        }
    }

    // -------------------------------------------------------------------- Las reglas de siempre siguen mandando

    public function test_un_paciente_bloqueado_no_agenda_ni_se_consulta_a_jev(): void
    {
        $jev = $this->jevDice(0.99);
        $this->paciente();
        BloqueoDePaciente::create(['telefono' => Telefono::internacional(self::TELEFONO), 'motivo' => 'prueba']);

        $reserva = $this->servicioConMensaje('Estoy sangrando')->reservarCitaEnCola(self::TELEFONO, $this->medico->reg_medico, '2026-10-26');

        $this->assertFalse($reserva['exito']);
        $this->assertTrue($reserva['bloqueado'] ?? false);
        $this->assertSame(0, $jev->llamadas);
        $this->assertSame(0, Cola::where('reg_medico', $this->medico->reg_medico)->count());
    }

    public function test_el_tope_de_citas_pendientes_manda_con_la_urgencia(): void
    {
        $jev = $this->jevDice(0.99);
        $paciente = $this->paciente();
        for ($i = 0; $i < 3; $i++) {
            Cola::create([
                'reg_medico' => 'otro-' . $i . substr(uniqid(), -6),
                'fecha' => '2026-11-0' . ($i + 2),
                'hora_ini' => '09:00',
                'atendido' => 0,
                'estado' => 2,
                'paciente_sinhistoria_id' => $paciente->id,
            ]);
        }

        $reserva = $this->servicioConMensaje('Estoy sangrando')->reservarCitaEnCola(self::TELEFONO, $this->medico->reg_medico, '2026-10-26');

        $this->assertFalse($reserva['exito']);
        $this->assertTrue($reserva['limite_citas'] ?? false);
        $this->assertSame(0, $jev->llamadas);
    }

    public function test_la_reserva_publica_y_el_portal_no_usan_la_excepcion(): void
    {
        $jev = $this->jevDice(0.99);
        $this->paciente();

        // Aunque haya un mensaje urgente en curso en el servicio, la reserva pública usa sólo la regla normal.
        $this->servicioConMensaje('Estoy sangrando, es urgente');
        $opciones = app(DisponibilidadPublica::class)->opciones($this->medico, 5);

        $this->assertNotEmpty($opciones);
        foreach ($opciones as $opcion) {
            $this->assertNotSame('2026-10-26', $opcion->fecha, 'Nada del lunes: la ventana de 24 h sigue.');
        }
        $this->assertSame('2026-10-27', $opciones[0]->fecha);
        $this->assertSame(0, $jev->llamadas);
    }

    // ----------------------------------------------------------------------------------------------- Auditoría

    public function test_el_log_no_trae_el_mensaje_ni_el_telefono_completo(): void
    {
        Log::spy();
        Http::fake(['*' => Http::response(['answers' => ['es_urgencia' => ['type' => 'noul', 'noul' => 0.9]]], 200)]);
        $paciente = $this->paciente();
        $mensaje = 'Mi hija tiene fiebre de 40 y convulsiones, el doctor Pérez me dijo que viniera';

        $oferta = $this->servicioConMensaje($mensaje)->consultarProximoDiaDisponible($this->medico->reg_medico);

        $this->assertSame(90, $oferta['anticipacion_minutos']);
        Log::shouldHaveReceived('info')->withArgs(function ($titulo, $contexto = []) use ($paciente, $mensaje) {
            $volcado = json_encode([$titulo, $contexto], JSON_UNESCAPED_UNICODE);

            return str_contains($titulo, 'urgencia')
                && ($contexto['paciente'] ?? null) === 'ficha:' . $paciente->id
                && ($contexto['urgente'] ?? null) === true
                && isset($contexto['probabilidad'], $contexto['umbral'], $contexto['motivo'])
                && ! str_contains($volcado, 'fiebre')
                && ! str_contains($volcado, $mensaje)
                && ! str_contains($volcado, self::TELEFONO);
        })->once();
    }

    public function test_el_log_de_un_fallo_dice_que_cayo_a_24_horas_sin_el_mensaje(): void
    {
        Log::spy();
        Http::fake(['*' => function () {
            throw new ConnectionException('timed out');
        }]);
        $this->paciente();

        $this->servicioConMensaje('Tengo mucho dolor de pecho')->consultarProximoDiaDisponible($this->medico->reg_medico);

        Log::shouldHaveReceived('info')->withArgs(function ($titulo, $contexto = []) {
            return str_contains($titulo, '24 h')
                && ($contexto['urgente'] ?? null) === false
                && ($contexto['motivo'] ?? null) === 'error'
                && ! str_contains(json_encode($contexto), 'pecho');
        })->once();
    }

    // ----------------------------------------------------------------------------------------------- Ayudas

    private function servicioConMensaje(string $texto): PacienteAtencionContextService
    {
        $servicio = new PacienteAtencionContextService();
        $servicio->iniciarMensajeWhatsApp($texto, self::TELEFONO);

        return $servicio;
    }

    private function horaDeLaUltimaCita(): string
    {
        return substr((string) Cola::query()->latest('id')->value('hora_ini'), 0, 5);
    }

    /** Un doble de `UrgenciaClinicaJev` que responde esa probabilidad (con el umbral real) y cuenta las llamadas. */
    private function jevDice(float $probabilidad): UrgenciaClinicaJev
    {
        $doble = new class($probabilidad) extends UrgenciaClinicaJev {
            public int $llamadas = 0;

            public function __construct(private float $probabilidad)
            {
            }

            public function evaluar(string $mensaje, string $referencia): array
            {
                $this->llamadas++;
                $umbral = (float) config('services.jev.umbral', 0.7);
                $urgente = $this->probabilidad >= $umbral;

                return ['urgente' => $urgente, 'probabilidad' => $this->probabilidad, 'umbral' => $umbral, 'motivo' => $urgente ? 'urgencia' : 'no_urgencia'];
            }
        };

        $this->app->instance(UrgenciaClinicaJev::class, $doble);

        return $doble;
    }

    private function paciente(): Paciente
    {
        return Paciente::create([
            'nombres' => 'Ana',
            'apellidos' => 'Pérez',
            'cedula' => (string) random_int(10000000, 30000000),
            'telefono' => self::TELEFONO,
            'sexo' => 'F',
            'fnacimiento' => '1990-05-04',
        ]);
    }

    /**
     * Un médico con una sede por orden de llegada, bloques el lunes ($lunes: inicio => fin) y uno el martes.
     *
     * @param  array<string,string>  $lunes
     */
    private function medicoConAgenda(array $lunes, string $martesInicio): Medico
    {
        $reg = 'urg-test-' . substr(uniqid(), -6);
        $medico = Medico::create(['name' => 'Dr. de prueba', 'lastname' => 'Urgencia', 'reg_medico' => $reg]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        $centro = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id' => DB::table('estados')->value('id'),
            'city_id' => DB::table('cities')->value('id'),
            'name' => 'Centro ' . substr(uniqid(), -6),
            'address' => 'Dirección de prueba',
        ]);
        $sede = Office::create([
            'medical_center_id' => $centro->id,
            'medico_id' => $medico->id,
            'reg_medico' => $reg,
            'office_number' => 'U-1',
            'modalidad' => Office::MODALIDAD_ORDEN,
            'duracion_cita' => 30,
            'activo' => true,
        ]);

        foreach ($lunes as $inicio => $fin) {
            OfficeSchedule::create(['office_id' => $sede->id, 'reg_medico' => $reg, 'dia_semana' => 1, 'hora_inicio' => $inicio, 'hora_fin' => $fin]);
        }
        OfficeSchedule::create(['office_id' => $sede->id, 'reg_medico' => $reg, 'dia_semana' => 2, 'hora_inicio' => $martesInicio, 'hora_fin' => '12:00']);

        return $medico;
    }
}
