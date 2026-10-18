<?php

namespace Tests\Unit\Support;

use App\Support\NumeroDeFormulario;
use PHPUnit\Framework\TestCase;

/**
 * **La conversión de los números del formulario** (O-08): una sola regla para las dos formas en que una medida
 * llega del formulario.
 *
 * Lo que fija: que lo que se teclea con **coma** (`1,62`, la de la pantalla) y lo que ya viene con **punto**
 * (`1.62`, el de la base, las plantillas y el sync) terminen en el mismo `1.62`; que lo que no es un número
 * **no** se convierta en otro (no se inventa una medida clínica); y que el vacío siga siendo «sin dato».
 *
 * Sin base de datos: es una cuenta.
 */
class NumeroDeFormularioTest extends TestCase
{
    public function test_la_coma_de_la_pantalla_se_guarda_con_el_punto_de_la_base(): void
    {
        $this->assertSame('1.62', NumeroDeFormulario::aBase('1,62'));
        $this->assertSame('70.5', NumeroDeFormulario::aBase('70,5'));
        $this->assertSame('88', NumeroDeFormulario::aBase('88'));
        // El formato de la base se acepta tal cual (las plantillas, el sync y el `<input>` viejo).
        $this->assertSame('1.62', NumeroDeFormulario::aBase('1.62'));
        $this->assertSame('1.62', NumeroDeFormulario::aBase(' 1,62 '));
    }

    public function test_un_texto_que_no_es_un_numero_no_se_corrige(): void
    {
        // Dos separadores, letras, unidades pegadas o un signo: no es una medida.
        $this->assertNull(NumeroDeFormulario::aBase('1,6,2'));
        $this->assertNull(NumeroDeFormulario::aBase('1.6.2'));
        $this->assertNull(NumeroDeFormulario::aBase('ochenta'));
        $this->assertNull(NumeroDeFormulario::aBase('70 kg'));
        $this->assertNull(NumeroDeFormulario::aBase('-70'));
        $this->assertNull(NumeroDeFormulario::aBase(''));
        $this->assertNull(NumeroDeFormulario::aBase(null));
        $this->assertNull(NumeroDeFormulario::aBase([]));
    }

    public function test_el_vacio_es_sin_dato_y_lo_demas_queda_como_vino(): void
    {
        $normalizado = NumeroDeFormulario::normalizar([
            'peso' => '70,5',
            'talla' => '1,62',
            'fc' => '  ',
            'pp' => 'ochenta',
            'abdomen' => 'Blando',
        ], ['peso', 'talla', 'fc', 'pp']);

        $this->assertSame('70.5', $normalizado['peso']);
        $this->assertSame('1.62', $normalizado['talla']);
        // Vacío es «sin dato», como el resto de los vacíos del escritorio.
        $this->assertNull($normalizado['fc']);
        // Lo que no se puede leer queda como vino para que la validación lo rechace con su mensaje.
        $this->assertSame('ochenta', $normalizado['pp']);
        // Una columna que no es numérica no se toca.
        $this->assertSame('Blando', $normalizado['abdomen']);
    }

    public function test_una_columna_que_no_vino_no_se_agrega(): void
    {
        $normalizado = NumeroDeFormulario::normalizar(['peso' => '70,5'], ['peso', 'talla']);

        $this->assertSame(['peso' => '70.5'], $normalizado);
    }
}
