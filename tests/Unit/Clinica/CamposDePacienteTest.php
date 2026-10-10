<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Pacientes\CamposDePaciente;
use PHPUnit\Framework\TestCase;

/**
 * La limpieza de los campos sucios del legado: `null`, vacíos, espacios y marcadores (`S.C.`), sin base
 * de datos ni HTTP. Es lo que hace que la ficha muestre `—` donde no hay dato.
 */
class CamposDePacienteTest extends TestCase
{
    public function test_el_texto_del_legado_queda_sin_espacios_o_en_null(): void
    {
        $this->assertNull(CamposDePaciente::texto(null));
        $this->assertNull(CamposDePaciente::texto(''));
        $this->assertNull(CamposDePaciente::texto('   '));
        $this->assertSame('Ana', CamposDePaciente::texto('  Ana '));
        $this->assertSame('0', CamposDePaciente::texto('0'));
    }

    public function test_el_numero_de_historia_es_texto_y_conserva_los_ceros_del_legado(): void
    {
        $this->assertSame('01001', CamposDePaciente::numeroDeHistoria(' 01001 '));
        $this->assertSame('1', CamposDePaciente::numeroDeHistoria(1));
        $this->assertNull(CamposDePaciente::numeroDeHistoria(''));
        $this->assertNull(CamposDePaciente::numeroDeHistoria(null));
    }

    public function test_el_numero_se_vuelve_entero_solo_si_es_un_numero(): void
    {
        $this->assertSame(1001, CamposDePaciente::historiaNumerica('01001'));
        $this->assertSame(1001, CamposDePaciente::historiaNumerica(' 1001 '));
        $this->assertSame(1001, CamposDePaciente::historiaNumerica(1001));

        // Una historia escrita a mano no puede volverse `0`: esa consulta traería otro paciente.
        $this->assertNull(CamposDePaciente::historiaNumerica('S/H'));
        $this->assertNull(CamposDePaciente::historiaNumerica(''));
        $this->assertNull(CamposDePaciente::historiaNumerica(null));
    }

    public function test_la_cedula_marcada_como_sin_cedula_no_es_un_dato(): void
    {
        $this->assertNull(CamposDePaciente::cedula('S.C.'));
        $this->assertNull(CamposDePaciente::cedula(' s.c '));
        $this->assertNull(CamposDePaciente::cedula('SC'));
        $this->assertNull(CamposDePaciente::cedula('  '));
        $this->assertNull(CamposDePaciente::cedula(null));
        $this->assertSame('12345678', CamposDePaciente::cedula(' 12345678 '));
    }

    public function test_el_nombre_aguanta_una_ficha_sin_nombre(): void
    {
        $this->assertSame('Pérez, Ana María', CamposDePaciente::nombre('Pérez', 'Ana María'));
        $this->assertSame('Pérez', CamposDePaciente::nombre(' Pérez ', null));
        $this->assertSame('Ana', CamposDePaciente::nombre(null, 'Ana'));
        $this->assertSame('Paciente sin nombre', CamposDePaciente::nombre(null, null));
        $this->assertSame('Paciente sin nombre', CamposDePaciente::nombre('   ', ''));
    }
}
