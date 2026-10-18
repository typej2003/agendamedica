<?php

namespace Tests\Unit\Support;

use App\Support\FechaDeFormulario;
use PHPUnit\Framework\TestCase;

/**
 * **La conversión de fechas del formulario** (O-18 y O-19): una sola regla para las dos formas en que una
 * fecha llega y se muestra.
 *
 * Lo que fija: que lo que se teclea (`dd/mm/aaaa`, con uno o dos dígitos) y lo que ya viene en el formato de
 * la base (`AAAA-MM-DD`, el del `<input type="date">`, las plantillas y el sync) terminen en **el mismo**
 * `AAAA-MM-DD`; que una fecha imposible del calendario **no** se convierta en otra; y que lo que se lee de
 * una columna y no es una fecha se muestre **vacío** en vez de inventarse.
 *
 * Sin base de datos: es una cuenta.
 */
class FechaDeFormularioTest extends TestCase
{
    public function test_lo_que_se_teclea_se_convierte_al_formato_de_la_base(): void
    {
        $this->assertSame('2026-05-09', FechaDeFormulario::aBase('09/05/2026'));
        $this->assertSame('2026-05-09', FechaDeFormulario::aBase('9/5/2026'));
        $this->assertSame('2026-12-31', FechaDeFormulario::aBase('31/12/2026'));
        $this->assertSame('2026-01-01', FechaDeFormulario::aBase('1/1/2026'));
        // La barra es la de la pantalla; el guion es como el legado guardó algunas fechas.
        $this->assertSame('2026-05-09', FechaDeFormulario::aBase('09-05-2026'));
    }

    public function test_lo_que_ya_viene_en_el_formato_de_la_base_se_acepta_tal_cual(): void
    {
        $this->assertSame('2026-05-09', FechaDeFormulario::aBase('2026-05-09'));
        $this->assertSame('2024-02-29', FechaDeFormulario::aBase('2024-02-29'));
    }

    public function test_vacia_o_el_none_del_legado_es_sin_dato(): void
    {
        $this->assertNull(FechaDeFormulario::aBase(null));
        $this->assertNull(FechaDeFormulario::aBase(''));
        $this->assertNull(FechaDeFormulario::aBase('   '));
        $this->assertNull(FechaDeFormulario::aBase('none'));
        $this->assertNull(FechaDeFormulario::aBase([]));
    }

    public function test_una_fecha_imposible_no_se_convierte_en_otra(): void
    {
        // El 32 de día y el 13 de mes no existen; el 30 de febrero tampoco; 2025 no es bisiesto.
        $this->assertNull(FechaDeFormulario::aBase('32/13/2026'));
        $this->assertNull(FechaDeFormulario::aBase('2026-02-30'));
        $this->assertNull(FechaDeFormulario::aBase('31/02/2026'));
        $this->assertNull(FechaDeFormulario::aBase('29/02/2025'));
        $this->assertNull(FechaDeFormulario::aBase('00/05/2026'));
        $this->assertNull(FechaDeFormulario::aBase('09/00/2026'));
        // Y un texto cualquiera tampoco es una fecha.
        $this->assertNull(FechaDeFormulario::aBase('5 semanas'));
        $this->assertNull(FechaDeFormulario::aBase('09/05/26'));
    }

    public function test_lo_guardado_se_muestra_como_se_teclea(): void
    {
        $this->assertSame('09/05/2026', FechaDeFormulario::paraTeclear('2026-05-09'));
        // Lo que ya está en el formato de la pantalla (una base que guardó lo tecleado antes de esta regla)
        // se sigue leyendo igual: es la misma fecha que el escritorio interpreta con sus barras.
        $this->assertSame('09/05/2026', FechaDeFormulario::paraTeclear('09/05/2026'));
    }

    public function test_lo_que_no_es_una_fecha_se_muestra_vacio(): void
    {
        $this->assertSame('', FechaDeFormulario::paraTeclear('5 semanas'));
        $this->assertSame('', FechaDeFormulario::paraTeclear('32/13/2026'));
        $this->assertSame('', FechaDeFormulario::paraTeclear('none'));
        $this->assertSame('', FechaDeFormulario::paraTeclear(null));
    }

    public function test_lo_escrito_con_error_vuelve_tal_cual(): void
    {
        // Es lo que se ve en el campo después de un error de validación: no se pierde lo escrito…
        $this->assertSame('32/13/2026', FechaDeFormulario::comoSeTecleo('32/13/2026'));
        // …y una fecha buena se muestra en el formato de la pantalla, aunque haya vuelto en el de la base.
        $this->assertSame('09/05/2026', FechaDeFormulario::comoSeTecleo('2026-05-09'));
        $this->assertSame('', FechaDeFormulario::comoSeTecleo(null));
    }

    public function test_normalizar_pasa_a_la_base_solo_las_columnas_que_puede_leer(): void
    {
        $datos = FechaDeFormulario::normalizar([
            'fur' => '09/05/2026',
            'fecha' => '2026-05-09',
            'imposible' => '32/13/2026',
            'vacia' => '',
            'cuerpo' => '60',
        ], ['fur', 'fecha', 'imposible', 'vacia']);

        $this->assertSame('2026-05-09', $datos['fur']);
        $this->assertSame('2026-05-09', $datos['fecha']);
        // Lo que no es una fecha **queda como vino**: la validación `date_format` lo rechaza y el mensaje dice
        // cuál es el campo. Convertirlo «a la fuerza» sería inventar una fecha clínica.
        $this->assertSame('32/13/2026', $datos['imposible']);
        $this->assertNull($datos['vacia']);
        // Y lo que no es una columna de fecha no se toca.
        $this->assertSame('60', $datos['cuerpo']);
    }

    public function test_la_regla_y_el_mensaje(): void
    {
        $this->assertSame('nullable|date_format:Y-m-d', FechaDeFormulario::REGLA);
        $this->assertSame('date_format:Y-m-d', FechaDeFormulario::FORMATO);

        $this->assertStringContainsString('«FUR» no es una fecha válida', FechaDeFormulario::mensaje('FUR', true));
        $this->assertStringContainsString('dd/mm/aaaa', FechaDeFormulario::mensaje('FUR', true));
        $this->assertStringContainsString('aaaa-mm-dd', FechaDeFormulario::mensaje('Fecha'));
    }
}
