<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Embarazo\EdadGestacional;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * **La edad gestacional y la FPP** (WEB-6.6): las dos cuentas del control prenatal, copiadas de la fuente.
 *
 * La fórmula es la del escritorio, verbatim: `int(daysafter(fur, today()) / 7)` semanas y
 * `mod(daysafter(fur, today()), 7)` días (`d_pre_natal_observa.srd:21` y `d_eco_obstetrico.srd:36`). La FPP
 * suma **270** días en el control prenatal (`d_pre_natal_observa.srd:20`) y **283** en el eco obstétrico
 * (`d_eco_obstetrico_trimestral_nueva.srd:183`): la clase no unifica los dos números a propósito.
 *
 * Sin base de datos: entra una FUR y un día, sale la cuenta.
 */
class EdadGestacionalTest extends TestCase
{
    private Carbon $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hoy = Carbon::parse('2026-10-13 09:00:00');
    }

    public function test_las_semanas_y_los_dias_salen_de_la_fur_como_en_el_escritorio(): void
    {
        // 45 días = 6 semanas y 3 días (45 = 6 * 7 + 3).
        $eg = EdadGestacional::de($this->hoy->copy()->subDays(45)->toDateString(), $this->hoy);

        $this->assertNotNull($eg);
        $this->assertSame(45, $eg->diasTotales());
        $this->assertSame(6, $eg->semanas());
        $this->assertSame(3, $eg->dias());
        $this->assertSame('6 semanas y 3 días', $eg->texto());
        $this->assertSame($this->hoy->copy()->subDays(45)->toDateString(), $eg->fur());

        // Los bordes de la semana: 6 días son "0 semanas y 6 días"; 7, "1 semana y 0 días".
        $this->assertSame('0 semanas y 6 días', EdadGestacional::de($this->hoy->copy()->subDays(6)->toDateString(), $this->hoy)->texto());
        $this->assertSame('1 semana y 0 días', EdadGestacional::de($this->hoy->copy()->subDays(7)->toDateString(), $this->hoy)->texto());
        // El singular del texto (el escritorio pega los rótulos del DataWindow y diría "1 Semanas y 1 días").
        $this->assertSame('1 semana y 1 día', EdadGestacional::de($this->hoy->copy()->subDays(8)->toDateString(), $this->hoy)->texto());
        // El día de la FUR es el día 0.
        $this->assertSame('0 semanas y 0 días', EdadGestacional::de($this->hoy->toDateString(), $this->hoy)->texto());
    }

    public function test_una_fur_que_no_sirve_no_da_edad_gestacional(): void
    {
        foreach ([null, '', '   ', 'none', 'no es una fecha'] as $fur) {
            $this->assertNull(EdadGestacional::de($fur, $this->hoy), 'FUR: ' . var_export($fur, true));
        }

        // Una FUR en el futuro es un dato imposible: el escritorio mostraría días negativos.
        $this->assertNull(EdadGestacional::de($this->hoy->copy()->addDay()->toDateString(), $this->hoy));
    }

    public function test_acepta_las_fechas_con_barras_del_legado(): void
    {
        $eg = EdadGestacional::de('13/10/2026', $this->hoy);

        $this->assertNotNull($eg);
        $this->assertSame('0 semanas y 0 días', $eg->texto());
    }

    public function test_la_fpp_suma_los_dias_de_cada_pantalla(): void
    {
        // 2026-01-01 + 281 días = 2026-10-09 (el 281 de la pestaña *Prenatal* viva del control prenatal).
        $this->assertSame('2026-10-09', EdadGestacional::fpp('2026-01-01', EdadGestacional::DIAS_DE_LA_FPP_DEL_CONTROL_PRENATAL));

        // + 283 días = 2026-10-11 (el 283 del eco obstétrico y de los reportes, WEB-6.2): no son el mismo
        // número, a propósito.
        $this->assertSame('2026-10-11', EdadGestacional::fpp('2026-01-01', EdadGestacional::DIAS_DE_LA_FPP_DEL_ECO_OBSTETRICO));

        // Y sin FUR no hay FPP.
        $this->assertNull(EdadGestacional::fpp(null, EdadGestacional::DIAS_DE_LA_FPP_DEL_CONTROL_PRENATAL));
        $this->assertNull(EdadGestacional::fpp('', EdadGestacional::DIAS_DE_LA_FPP_DEL_CONTROL_PRENATAL));
    }

    public function test_los_dos_numeros_de_la_fpp_son_los_de_la_fuente(): void
    {
        // 281 es el de la pestaña VIVA (`d_pre_natal_observa_2.srd:38`, la que asigna
        // `w_consulta_principal.srw:24725`). El 270 que se creía el del control prenatal vive en
        // `d_pre_natal_observa.srd:20`, un DataWindow **huérfano** que ninguna ventana usa: es la trampa
        // de este módulo. 283 es el de los ecos y los reportes.
        $this->assertSame(281, EdadGestacional::DIAS_DE_LA_FPP_DEL_CONTROL_PRENATAL);
        $this->assertSame(283, EdadGestacional::DIAS_DE_LA_FPP_DEL_ECO_OBSTETRICO);
    }

    public function test_una_fur_absurda_del_legado_da_lo_que_el_dato_dice(): void
    {
        // El dump tiene años 1012 y 2090: la cuenta no se corrige sola (quien desconfía del dato lo dice en
        // la pantalla; la clase no inventa un límite que la fuente no tiene).
        $eg = EdadGestacional::de('1012-05-25', $this->hoy);

        $this->assertNotNull($eg);
        $this->assertGreaterThan(50000, $eg->semanas());
    }
}
