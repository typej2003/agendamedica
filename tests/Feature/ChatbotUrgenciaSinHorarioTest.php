<?php

namespace Tests\Feature;

use App\Mail\AvisoDeUrgenciaParaSecretaria;
use App\Models\BloqueoDePaciente;
use App\Models\Evolucion;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Models\Paciente;
use App\Notificaciones\Telefono;
use App\Reservas\UrgenciaClinicaJev;
use App\Services\CuentaService;
use App\Services\PacienteAtencionContextService;
use App\Support\DisponibilidadEnTexto;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Urgencia afirmada por Jev y **sin horario hoy** (ahora + 90 min): la paciente recibe el teléfono del consultorio
 * y la secretaría recibe un aviso por correo (sin texto clínico), además de la oferta de agenda de siempre.
 *
 * Nada llama a Jev ni envía correos de verdad: doble de `UrgenciaClinicaJev` y `Mail::fake()`.
 *
 * Reloj: lunes 26 de octubre de 2026, 10:00 en Caracas. Agenda «sin horario hoy»: lunes 11:00 (a 60 min, no entra
 * a 90) y martes 10:30. Agenda «con horario hoy»: lunes 11:40 (a 100 min).
 */
class ChatbotUrgenciaSinHorarioTest extends TestCase
{
    use DatabaseTransactions;

    private const TELEFONO = '584165800403';

    private const MENSAJE = 'Tengo un sangrado fuerte y mucho dolor';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-26 10:00:00', 'America/Caracas'));
        Cache::flush();
        Mail::fake();
        config(['services.chatbot.urgencia_aviso_minutos' => 60]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_urgencia_sin_horario_hoy_da_el_contacto_y_avisa_una_vez_a_la_secretaria_sin_texto_clinico(): void
    {
        $medico = $this->medicoSinHorarioHoy();
        $this->consultorio($medico, '0212-5551234');
        $this->secretaria($medico, 'secre@consultorio.test');
        $this->jevDice(0.95);
        $this->paciente();

        $oferta = $this->servicio(self::MENSAJE)->consultarProximoDiaDisponible($medico->reg_medico);

        $this->assertTrue($oferta['disponible']);
        $this->assertSame('2026-10-27', $oferta['opciones'][0]['fecha'], 'La oferta de otro día se mantiene.');
        $this->assertStringContainsString('0212-5551234', $oferta['aviso_urgencia']);
        $this->assertStringContainsString('avisamos a la secretaría', $oferta['aviso_urgencia']);
        $this->assertStringNotContainsString('seguro', mb_strtolower($oferta['aviso_urgencia']), 'No promete atención.');

        $texto = DisponibilidadEnTexto::de($oferta);
        $this->assertStringContainsString('0212-5551234', $texto);
        $this->assertStringContainsString('Próximos días con disponibilidad', $texto, 'El contacto se suma a la agenda.');

        Mail::assertSent(AvisoDeUrgenciaParaSecretaria::class, 1);
        Mail::assertSent(AvisoDeUrgenciaParaSecretaria::class, function (AvisoDeUrgenciaParaSecretaria $mail) {
            $html = view('emails.aviso-urgencia-secretaria', $mail->buildViewData())->render();

            return $mail->hasTo('secre@consultorio.test')
                && str_contains($html, 'Ana Pérez')
                && str_contains($html, self::TELEFONO)
                && str_contains($html, '26/10/2026 10:00')
                && ! str_contains(mb_strtolower($html), 'sangrado')
                && ! str_contains(mb_strtolower($html), 'dolor');
        });
    }

    public function test_con_horario_hoy_no_hay_aviso_ni_contacto(): void
    {
        $medico = $this->medicoConHorarioHoy();
        $this->consultorio($medico, '0212-5551234');
        $this->secretaria($medico, 'secre@consultorio.test');
        $this->jevDice(0.95);
        $this->paciente();

        $oferta = $this->servicio(self::MENSAJE)->consultarProximoDiaDisponible($medico->reg_medico);

        $this->assertSame('11:40', $oferta['opciones'][0]['hora']);
        $this->assertArrayNotHasKey('aviso_urgencia', $oferta);
        Mail::assertNothingSent();
    }

    public function test_un_segundo_mensaje_urgente_dentro_de_la_ventana_da_el_contacto_sin_re_avisar(): void
    {
        $medico = $this->medicoSinHorarioHoy();
        $this->consultorio($medico, '0212-5551234');
        $this->secretaria($medico, 'secre@consultorio.test');
        $this->jevDice(0.95);
        $this->paciente();

        $primera = $this->servicio(self::MENSAJE)->consultarProximoDiaDisponible($medico->reg_medico);
        $segunda = $this->servicio('Sigo mal, por favor')->consultarProximoDiaDisponible($medico->reg_medico);

        $this->assertStringContainsString('0212-5551234', $primera['aviso_urgencia']);
        $this->assertStringContainsString('0212-5551234', $segunda['aviso_urgencia']);
        Mail::assertSent(AvisoDeUrgenciaParaSecretaria::class, 1);

        // Pasada la ventana (config en minutos) vuelve a avisar.
        Cache::flush();
        $this->servicio('Sigo igual')->consultarProximoDiaDisponible($medico->reg_medico);
        Mail::assertSent(AvisoDeUrgenciaParaSecretaria::class, 2);
    }

    public function test_dentro_del_mismo_mensaje_no_se_avisa_dos_veces(): void
    {
        $medico = $this->medicoSinHorarioHoy();
        $this->consultorio($medico, '0212-5551234');
        $this->secretaria($medico, 'secre@consultorio.test');
        $this->jevDice(0.95);
        $this->paciente();
        $servicio = $this->servicio(self::MENSAJE);

        $servicio->consultarProximoDiaDisponible($medico->reg_medico);
        $servicio->consultarProximoDiaDisponible($medico->reg_medico);

        Mail::assertSent(AvisoDeUrgenciaParaSecretaria::class, 1);
    }

    public function test_sin_urgencia_no_hay_aviso(): void
    {
        $medico = $this->medicoSinHorarioHoy();
        $this->consultorio($medico, '0212-5551234');
        $this->secretaria($medico, 'secre@consultorio.test');
        $this->jevDice(0.05);
        $this->paciente();

        $oferta = $this->servicio('Quiero una cita para control')->consultarProximoDiaDisponible($medico->reg_medico);

        $this->assertArrayNotHasKey('aviso_urgencia', $oferta);
        Mail::assertNothingSent();
    }

    public function test_sin_contacto_configurado_no_inventa_uno_ni_avisa_y_lo_registra(): void
    {
        $medico = $this->medicoSinHorarioHoy();
        $this->secretaria($medico, 'secre@consultorio.test');
        $this->jevDice(0.95);
        $this->paciente();
        Log::spy();

        $oferta = $this->servicio(self::MENSAJE)->consultarProximoDiaDisponible($medico->reg_medico);

        $this->assertTrue($oferta['disponible'], 'La agenda de otros días se ofrece igual.');
        $this->assertArrayNotHasKey('aviso_urgencia', $oferta);
        Mail::assertNothingSent();
        Log::shouldHaveReceived('info')->withArgs(
            fn ($titulo, $contexto = []) => str_contains($titulo, 'aviso de urgencia') && ($contexto['motivo'] ?? null) === 'sin_contacto'
        )->once();
    }

    public function test_si_jev_falla_no_hay_aviso(): void
    {
        $medico = $this->medicoSinHorarioHoy();
        $this->consultorio($medico, '0212-5551234');
        $this->secretaria($medico, 'secre@consultorio.test');
        $this->jevDice(null);
        $this->paciente();

        $oferta = $this->servicio(self::MENSAJE)->consultarProximoDiaDisponible($medico->reg_medico);

        $this->assertTrue($oferta['disponible']);
        $this->assertArrayNotHasKey('aviso_urgencia', $oferta);
        Mail::assertNothingSent();
    }

    public function test_una_paciente_bloqueada_no_dispara_aviso_ni_consulta_a_jev(): void
    {
        $medico = $this->medicoSinHorarioHoy();
        $this->consultorio($medico, '0212-5551234');
        $this->secretaria($medico, 'secre@consultorio.test');
        $jev = $this->jevDice(0.95);
        $this->paciente();
        BloqueoDePaciente::create(['telefono' => Telefono::internacional(self::TELEFONO), 'motivo' => 'prueba']);

        $oferta = $this->servicio(self::MENSAJE)->consultarProximoDiaDisponible($medico->reg_medico);

        $this->assertArrayNotHasKey('aviso_urgencia', $oferta);
        $this->assertSame(0, $jev->llamadas);
        Mail::assertNothingSent();
    }

    public function test_si_el_canal_esta_caido_la_respuesta_sale_igual_sin_prometer_el_aviso(): void
    {
        $medico = $this->medicoSinHorarioHoy();
        $this->consultorio($medico, '0212-5551234');
        $this->secretaria($medico, 'secre@consultorio.test');
        $this->jevDice(0.95);
        $this->paciente();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP caído'));

        $oferta = $this->servicio(self::MENSAJE)->consultarProximoDiaDisponible($medico->reg_medico);

        $this->assertTrue($oferta['disponible']);
        $this->assertStringContainsString('0212-5551234', $oferta['aviso_urgencia']);
        $this->assertStringNotContainsString('avisamos', $oferta['aviso_urgencia']);
        $this->assertSame('2026-10-27', $oferta['opciones'][0]['fecha']);
    }

    public function test_sin_secretaria_ni_correo_del_consultorio_da_el_contacto_sin_prometer_el_aviso(): void
    {
        $medico = $this->medicoSinHorarioHoy();
        $this->consultorio($medico, '0212-5551234');
        $this->jevDice(0.95);
        $this->paciente();

        $oferta = $this->servicio(self::MENSAJE)->consultarProximoDiaDisponible($medico->reg_medico);

        $this->assertStringContainsString('0212-5551234', $oferta['aviso_urgencia']);
        $this->assertStringNotContainsString('avisamos', $oferta['aviso_urgencia']);
        Mail::assertNothingSent();
    }

    public function test_sin_secretaria_el_aviso_va_al_correo_del_consultorio(): void
    {
        $medico = $this->medicoSinHorarioHoy();
        $this->consultorio($medico, '0212-5551234', 'consultorio@clinica.test');
        $this->jevDice(0.95);
        $this->paciente();

        $oferta = $this->servicio(self::MENSAJE)->consultarProximoDiaDisponible($medico->reg_medico);

        $this->assertStringContainsString('avisamos a la secretaría', $oferta['aviso_urgencia']);
        Mail::assertSent(AvisoDeUrgenciaParaSecretaria::class, fn ($m) => $m->hasTo('consultorio@clinica.test'));
    }

    public function test_el_contacto_cae_al_telefono_de_la_sede_y_luego_al_del_medico(): void
    {
        $medico = $this->medicoSinHorarioHoy();
        $this->jevDice(0.95);
        $this->paciente();

        Office::where('reg_medico', $medico->reg_medico)->update(['phone' => '0414-7778899']);
        $oferta = $this->servicio(self::MENSAJE)->consultarProximoDiaDisponible($medico->reg_medico);
        $this->assertStringContainsString('0414-7778899', $oferta['aviso_urgencia']);

        Office::where('reg_medico', $medico->reg_medico)->update(['phone' => null]);
        $medico->update(['phone' => '0424-1112233']);
        $oferta = $this->servicio(self::MENSAJE)->consultarProximoDiaDisponible($medico->reg_medico);
        $this->assertStringContainsString('0424-1112233', $oferta['aviso_urgencia']);
    }

    public function test_el_contacto_es_el_del_consultorio_de_la_conversacion_y_no_el_de_otro(): void
    {
        $medico = $this->medicoSinHorarioHoy();
        $otro = $this->medicoSinHorarioHoy();
        $this->consultorio($medico, '0212-1111111');
        $this->consultorio($otro, '0212-9999999');
        $this->jevDice(0.95);
        $this->paciente();

        $oferta = $this->servicio(self::MENSAJE)->consultarProximoDiaDisponible($medico->reg_medico);

        $this->assertStringContainsString('0212-1111111', $oferta['aviso_urgencia']);
        $this->assertStringNotContainsString('0212-9999999', $oferta['aviso_urgencia']);
    }

    public function test_el_log_del_aviso_no_trae_el_mensaje_ni_el_telefono_completo(): void
    {
        $medico = $this->medicoSinHorarioHoy();
        $this->consultorio($medico, '0212-5551234');
        $this->secretaria($medico, 'secre@consultorio.test');
        $this->jevDice(0.95);
        $paciente = $this->paciente();
        Log::spy();

        $this->servicio(self::MENSAJE)->consultarProximoDiaDisponible($medico->reg_medico);

        Log::shouldHaveReceived('info')->withArgs(function ($titulo, $contexto = []) use ($paciente) {
            if (! str_contains($titulo, 'aviso de urgencia')) {
                return false;
            }
            $json = json_encode($contexto) . $titulo;

            return ($contexto['resultado'] ?? null) === 'enviado'
                && ($contexto['paciente'] ?? null) === 'ficha:' . $paciente->id
                && ! str_contains($json, self::TELEFONO)
                && ! str_contains(mb_strtolower($json), 'sangrado');
        })->once();
    }

    // ----------------------------------------------------------------------------------------------- Ayudas

    private function servicio(string $texto): PacienteAtencionContextService
    {
        $servicio = new PacienteAtencionContextService();
        $servicio->iniciarMensajeWhatsApp($texto, self::TELEFONO);

        return $servicio;
    }

    /** Un doble de `UrgenciaClinicaJev`: responde esa probabilidad, o falla (`null`) como si Jev no contestara. */
    private function jevDice(?float $probabilidad): UrgenciaClinicaJev
    {
        $doble = new class($probabilidad) extends UrgenciaClinicaJev {
            public int $llamadas = 0;

            public function __construct(private ?float $probabilidad)
            {
            }

            public function evaluar(string $mensaje, string $referencia): array
            {
                $this->llamadas++;
                $umbral = (float) config('services.jev.umbral', 0.7);

                if ($this->probabilidad === null) {
                    return ['urgente' => false, 'probabilidad' => null, 'umbral' => $umbral, 'motivo' => 'error'];
                }

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

    private function consultorio(Medico $medico, string $telefono, ?string $correo = null): void
    {
        Evolucion::create(['reg_medico' => $medico->reg_medico, 'clave' => random_int(1000, 999999), 'telefono' => $telefono, 'correo_med' => $correo]);
    }

    private function secretaria(Medico $medico, string $correo): void
    {
        app(CuentaService::class)->crearSecretaria('Secretaria de prueba', $correo, $medico->reg_medico);
    }

    private function medicoSinHorarioHoy(): Medico
    {
        return $this->medicoConAgenda(['11:00' => '12:00'], '10:30');
    }

    private function medicoConHorarioHoy(): Medico
    {
        return $this->medicoConAgenda(['11:40' => '13:00'], '10:30');
    }

    /** @param  array<string,string>  $lunes */
    private function medicoConAgenda(array $lunes, string $martesInicio): Medico
    {
        $reg = 'urgsin-' . substr(uniqid(), -6) . random_int(10, 99);
        $medico = Medico::create(['name' => 'Dr. de prueba', 'lastname' => 'Sin horario', 'reg_medico' => $reg]);
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
