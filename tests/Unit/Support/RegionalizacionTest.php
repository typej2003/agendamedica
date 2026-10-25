<?php

namespace Tests\Unit\Support;

use App\Support\FechaClinica;
use App\Support\FechaDeFormulario;
use App\Support\HoraLocal;
use App\Support\Regionalizacion;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * **Fechas y horas con la regionalización del dispositivo** (WEB-13.5): el orden de la fecha, su separador y
 * el reloj de 12 o 24 h. Sin dispositivo vale la del consultorio (`dmy|/|12`), que es lo que ve hoy toda la
 * suite; con uno, `HoraLocal`, `FechaClinica` y `FechaDeFormulario` pintan y leen en su orden.
 */
class RegionalizacionTest extends TestCase
{
    private function usar(string $texto): void
    {
        app()->instance(Regionalizacion::class, Regionalizacion::desdeTexto($texto));
    }

    public function test_sin_dispositivo_vale_la_del_consultorio(): void
    {
        $region = Regionalizacion::actual();

        $this->assertSame('dmy|/|12', $region->comoTexto());
        $this->assertSame('d/m/Y', $region->formatoDeFecha());
        $this->assertSame('1:05 PM', $region->hora(13, 5));
        $this->assertSame('12:00 AM', $region->hora(0, 0));
    }

    public static function textosInvalidos(): array
    {
        return [[''], ['ymd|/|12'], ['dmy|/|13'], ['dmy| |12'], ['dmy|/'], ['mdy|/|24|x'], ['<script>|/|12'], ['DMY|/|12']];
    }

    #[DataProvider('textosInvalidos')]
    public function test_un_texto_que_no_es_exactamente_una_combinacion_valida_se_descarta(string $texto): void
    {
        $this->assertNull(Regionalizacion::desdeTexto($texto));
    }

    public function test_mes_dia_y_24_horas(): void
    {
        $this->usar('mdy|/|24');

        $this->assertSame('05/09/2026', Regionalizacion::actual()->fecha(Carbon::create(2026, 5, 9)));
        $this->assertSame('13:05', HoraLocal::reloj('13:05:00'));
        $this->assertSame('08:00 a 13:00', HoraLocal::rango('08:00', '13:00'));
        // Instante UTC → Caracas (UTC-4), en el orden y el reloj del dispositivo.
        $this->assertSame('05/09/2026 13:00', HoraLocal::fechaHora('2026-05-09 17:00:00'));
        $this->assertSame('05/09/2026 13:00:07', HoraLocal::fechaHoraConSegundos('2026-05-09 17:00:07'));
        $this->assertSame('05/09/2026', HoraLocal::fecha('2026-05-09 17:00:00'));
        $this->assertSame('05/09/2026', FechaClinica::formato('2026-05-09'));
        // Un formato explícito se respeta: los mensajes al paciente lo fijan.
        $this->assertSame('09/05/2026', FechaClinica::formato('2026-05-09', 'd/m/Y'));
        // `hora12` sigue siendo de 12 h fija (los mensajes al paciente no tienen dispositivo).
        $this->assertSame('1:05 PM', HoraLocal::hora12('13:05'));
    }

    public function test_el_separador_del_dispositivo(): void
    {
        $this->usar('dmy|-|12');

        $this->assertSame('09-05-2026', FechaClinica::formato('2026-05-09'));
        $this->assertSame('dd-mm-aaaa', Regionalizacion::actual()->patronDeTecleo());
    }

    public function test_una_fecha_tecleada_se_lee_en_el_orden_del_dispositivo(): void
    {
        $this->usar('mdy|/|12');

        $this->assertSame('2026-05-09', FechaDeFormulario::aBase('05/09/2026'));
        $this->assertNull(FechaDeFormulario::aBase('31/12/2026'));
        $this->assertSame('05/09/2026', FechaDeFormulario::paraTeclear('2026-05-09'));
        $this->assertStringContainsString('mm/dd/aaaa, por ejemplo 05/09/2026', FechaDeFormulario::mensaje('FUR', true));
        // El ISO no depende del orden.
        $this->assertSame('2026-05-09', FechaDeFormulario::aBase('2026-05-09'));
    }

    public function test_el_orden_que_manda_el_formulario_gana_al_de_la_cookie(): void
    {
        // La pantalla se pintó mes-día y la cookie cambió antes de enviar: se lee como se tecleó.
        $this->usar('dmy|/|12');

        $datos = FechaDeFormulario::normalizar(['fur' => '05/09/2026', '_fecha_orden' => 'mdy'], ['fur']);
        $this->assertSame('2026-05-09', $datos['fur']);

        // Un orden que no es uno de los dos no se acepta: vale el del dispositivo.
        $datos = FechaDeFormulario::normalizar(['fur' => '05/09/2026', '_fecha_orden' => 'ymd'], ['fur']);
        $this->assertSame('2026-09-05', $datos['fur']);
    }

    public function test_los_atributos_del_html_escapan_y_dicen_el_reloj(): void
    {
        $this->usar('mdy|.|24');

        $this->assertSame(
            'data-region="mdy|.|24" data-fecha-orden="mdy" data-fecha-separador="." data-hora-ciclo="24"',
            Regionalizacion::actual()->atributosHtml(),
        );
    }
}
