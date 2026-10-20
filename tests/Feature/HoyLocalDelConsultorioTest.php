<?php

namespace Tests\Feature;

use App\Clinica\FechaDelConsultorio;
use App\Http\Livewire\Components\DaySchedule;
use App\Http\Livewire\Components\ViewCalendar;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Services\GeminiService;
use App\Services\PacienteAtencionContextService;
use App\Services\ServicioService;
use App\Support\FechaClinica;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Hoy» y «ahora» de negocio son los del consultorio (Caracas, UTC-4), no los del servidor (UTC).
 * Se fija 2026-10-21 02:00 UTC = 20/10/2026 22:00 en Caracas: en UTC ya es mañana, en el consultorio no.
 */
class HoyLocalDelConsultorioTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config(['clinica.zona' => 'America/Caracas']);
        Carbon::setTestNow(Carbon::parse('2026-10-21 02:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_el_helper_comparte_el_dia_local(): void
    {
        $this->assertSame('2026-10-20', FechaDelConsultorio::hoyComoTexto());
        $this->assertTrue(FechaDelConsultorio::esHoy('2026-10-20'));
        $this->assertTrue(FechaDelConsultorio::esHoy(Carbon::parse('2026-10-20 00:00:00')));
        $this->assertFalse(FechaDelConsultorio::esHoy('2026-10-21'));
        $this->assertTrue(FechaDelConsultorio::esAnteriorAHoy('2026-10-19'));
        $this->assertFalse(FechaDelConsultorio::esAnteriorAHoy('2026-10-20'));
    }

    public function test_day_schedule_marca_hoy_el_20_y_la_hora_pasada_es_la_local(): void
    {
        $medico = $this->medico();

        $componente = Livewire::test(DaySchedule::class, ['medicoId' => $medico->id, 'fecha' => '2026-10-20']);
        $componente->assertSet('fecha', '2026-10-20');

        $slots = $componente->get('itemsSlots');
        $this->assertNotEmpty($slots);

        foreach ($slots as $slot) {
            if ($slot['hora_ini'] === null) {
                // Por cupos: el corte es 18:00 locales y son las 22:00.
                $this->assertTrue($slot['pasado']);
                continue;
            }

            $esperado = substr($slot['hora_ini'], 0, 5) < '22:00';
            $this->assertSame($esperado, $slot['pasado'], 'Slot ' . $slot['hora_ini']);
        }

        $manana = Livewire::test(DaySchedule::class, ['medicoId' => $medico->id, 'fecha' => '2026-10-21']);
        foreach ($manana->get('itemsSlots') as $slot) {
            $this->assertFalse($slot['pasado']);
        }
    }

    public function test_day_schedule_por_cupos_corta_a_las_18_locales(): void
    {
        $medico = $this->medico();

        Carbon::setTestNow(Carbon::parse('2026-10-20 21:30:00', 'UTC')); // 17:30 en Caracas
        $antes = Livewire::test(DaySchedule::class, ['medicoId' => $medico->id, 'fecha' => '2026-10-20'])->get('itemsSlots');

        Carbon::setTestNow(Carbon::parse('2026-10-20 22:30:00', 'UTC')); // 18:30 en Caracas
        $despues = Livewire::test(DaySchedule::class, ['medicoId' => $medico->id, 'fecha' => '2026-10-20'])->get('itemsSlots');

        foreach ($antes as $slot) {
            if ($slot['hora_ini'] === null) {
                $this->assertFalse($slot['pasado']);
            }
        }
        foreach ($despues as $slot) {
            if ($slot['hora_ini'] === null) {
                $this->assertTrue($slot['pasado']);
            }
        }
    }

    public function test_view_calendar_resalta_el_20(): void
    {
        $medico = $this->medico();

        $componente = Livewire::test(ViewCalendar::class, ['medicoId' => $medico->id]);
        $dias = collect($componente->get('calendarWeeks'))->flatten(1);

        $this->assertSame(['2026-10-20'], $dias->where('isToday', true)->pluck('dateString')->values()->all());
        $this->assertFalse($dias->firstWhere('dateString', '2026-10-20')['isPastDay']);
        $this->assertTrue($dias->firstWhere('dateString', '2026-10-19')['isPastDay']);
    }

    public function test_el_chatbot_ofrece_el_20_como_primer_dia(): void
    {
        $medico = $this->medico();
        $disponibilidad = app(PacienteAtencionContextService::class)->consultarProximoDiaDisponible($medico->reg_medico);

        $this->assertTrue($disponibilidad['disponible']);
        $this->assertSame('2026-10-20', $disponibilidad['opciones'][0]['fecha']);
    }

    public function test_el_prompt_de_gemini_dice_el_hoy_local(): void
    {
        $servicio = app(GeminiService::class);
        $metodo = new \ReflectionMethod($servicio, 'construirSystemInstruction');
        $metodo->setAccessible(true);

        $prompt = $metodo->invoke($servicio, ['existe' => false, 'telefono' => '04120000000']);

        $this->assertStringContainsString('martes 20 de octubre de 2026', mb_strtolower($prompt));
        $this->assertStringNotContainsString('21 de octubre', $prompt);
    }

    public function test_un_servicio_que_vence_hoy_local_sigue_vigente(): void
    {
        $r = app(ServicioService::class)->clasificar(Carbon::parse('2026-10-20'));

        $this->assertSame(ServicioService::VIGENTE, $r['estado']);
        $this->assertSame(0, $r['dias']);
    }

    public function test_la_edad_se_cuenta_con_el_dia_local(): void
    {
        // Cumple 5 años el 21: el 20 local todavía tiene 4.
        $this->assertSame(4, FechaClinica::edad('2021-10-21'));
        $this->assertSame(5, FechaClinica::edad('2021-10-20'));
    }

    private function medico(): Medico
    {
        $regMedico = 'hoy-' . substr(uniqid(), -6);

        $medico = Medico::create(['name' => 'Dr. Hoy', 'lastname' => 'Local', 'reg_medico' => $regMedico]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return $medico;
    }
}
