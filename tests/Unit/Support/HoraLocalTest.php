<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use App\Support\HoraLocal;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * **Cómo se muestran las horas**: en 12 h con AM/PM y, si son instantes guardados en UTC, en la hora del
 * consultorio (Caracas, UTC-4 todo el año). Las horas de las citas y de los bloques ya son locales: sólo se
 * formatean, no se convierten de zona.
 */
class HoraLocalTest extends TestCase
{
    #[DataProvider('horas')]
    public function test_hora12_formatea_una_hora_local(string $entrada, string $esperada): void
    {
        $this->assertSame($esperada, HoraLocal::hora12($entrada));
    }

    public static function horas(): array
    {
        return [
            'medianoche'          => ['00:00', '12:00 AM'],
            'pasada la medianoche' => ['00:30', '12:30 AM'],
            'mañana'              => ['08:00', '8:00 AM'],
            'mediodía'            => ['12:00', '12:00 PM'],
            'mediodía y media'    => ['12:30', '12:30 PM'],
            'tarde'               => ['13:00', '1:00 PM'],
            'con segundos'        => ['13:00:00', '1:00 PM'],
            'sin cero a la izq.'  => ['8:05', '8:05 AM'],
            'último minuto'       => ['23:59', '11:59 PM'],
        ];
    }

    public function test_hora12_con_algo_que_no_es_una_hora_devuelve_el_valor_de_respaldo(): void
    {
        $this->assertSame('', HoraLocal::hora12(null));
        $this->assertSame('—', HoraLocal::hora12('', '—'));
        $this->assertSame('', HoraLocal::hora12('25:00'));
        $this->assertSame('', HoraLocal::hora12('basura'));
    }

    public function test_hora24_es_lo_que_lleva_el_campo_oculto(): void
    {
        $this->assertSame('08:00', HoraLocal::hora24('8:00'));
        $this->assertSame('13:30', HoraLocal::hora24('13:30:00'));
        $this->assertSame('', HoraLocal::hora24('1:00 PM'));
        $this->assertSame('', HoraLocal::hora24(null));
    }

    public function test_rango(): void
    {
        $this->assertSame('8:00 AM a 1:00 PM', HoraLocal::rango('08:00:00', '13:00:00'));
    }

    public function test_un_instante_utc_se_convierte_a_la_hora_del_consultorio(): void
    {
        // 17:00 UTC son las 13:00 en Caracas (UTC-4).
        $this->assertSame('1:00 PM', HoraLocal::hora('2026-10-20 17:00:00'));
        $this->assertSame('20/10/2026 1:00 PM', HoraLocal::fechaHora('2026-10-20 17:00:00'));
        $this->assertSame('20/10/2026 1:00:05 PM', HoraLocal::fechaHoraConSegundos('2026-10-20 17:00:05'));
        $this->assertSame('20/10/2026 1:00 PM', HoraLocal::fechaHora(Carbon::parse('2026-10-20 17:00:00', 'UTC')));
    }

    public function test_un_instante_cercano_a_la_medianoche_cambia_de_dia(): void
    {
        // 02:30 UTC del 21 son las 22:30 del 20 en Caracas.
        $this->assertSame('20/10/2026 10:30 PM', HoraLocal::fechaHora('2026-10-21 02:30:00'));
        // 04:00 UTC del 21 son las 00:00 del 21.
        $this->assertSame('21/10/2026 12:00 AM', HoraLocal::fechaHora('2026-10-21 04:00:00'));
    }

    public function test_la_zona_sale_de_la_configuracion_del_consultorio(): void
    {
        config(['clinica.zona' => 'America/Bogota']);

        $this->assertSame('12:00 PM', HoraLocal::hora('2026-10-20 17:00:00'));
    }

    public function test_un_instante_vacio_o_ilegible_no_inventa_nada(): void
    {
        $this->assertNull(HoraLocal::instante(null));
        $this->assertSame('Nunca', HoraLocal::fechaHora(null, 'Nunca'));
        $this->assertNull(HoraLocal::instante('esto no es una fecha'));
    }
}
