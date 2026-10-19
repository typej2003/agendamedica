<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\NeumologiaDeLaConsulta;
use PHPUnit\Framework\TestCase;

/**
 * **Neumología pediátrica** (postgrado, 2026-10-19): el mapa del dato de sus tres informes.
 *
 * Lo que fija: cada solapa va a **su** columna de `consultas` y la espirometría va a `faringe`, que en ginecología
 * es la citología (el escritorio reusa la columna con dos significados). Si algún día se separa, este test es el
 * que hay que cambiar.
 */
class NeumologiaDeLaConsultaTest extends TestCase
{
    public function test_las_tres_solapas_y_sus_columnas(): void
    {
        $campos = NeumologiaDeLaConsulta::campos();

        $this->assertSame(['rayosx', 'espirometria', 'broncoscopia'], array_keys($campos));
        $this->assertSame('rayosx', $campos['rayosx']['columna']);
        $this->assertSame('faringe', $campos['espirometria']['columna']);
        $this->assertSame('broncoscopia', $campos['broncoscopia']['columna']);

        // Las etiquetas son las del escritorio.
        $this->assertSame('R.X. de tórax', $campos['rayosx']['etiqueta']);
        $this->assertSame('Espirometría', $campos['espirometria']['etiqueta']);
        $this->assertSame('Broncoscopía', $campos['broncoscopia']['etiqueta']);
    }

    public function test_la_ayuda_de_la_espirometria_avisa_de_la_columna_compartida(): void
    {
        $this->assertStringContainsString('citología', NeumologiaDeLaConsulta::campos()['espirometria']['ayuda']);
    }
}
