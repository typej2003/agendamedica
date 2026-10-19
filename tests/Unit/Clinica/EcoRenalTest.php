<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\EcoRenal;
use PHPUnit\Framework\TestCase;

/**
 * **Eco renal** (nefrología pediátrica, 2026-10-19): el mapa del dato.
 *
 * Lo que se fija: la tabla `ultra_renal` (una fila por consulta) y que las medidas de los dos riñones van con su
 * prefijo —`rd_` el derecho y `ri_` el izquierdo—, con el mismo juego de campos.
 */
class EcoRenalTest extends TestCase
{
    public function test_los_dos_rinones_van_con_su_prefijo(): void
    {
        $columnas = EcoRenal::columnas();

        foreach (['longitud', 'transverso', 'anteroporteior', 'ha_litiasis_medida', 'ha_dilatacion_sc'] as $medida) {
            $this->assertContains('rd_' . $medida, $columnas, 'Falta la medida derecha ' . $medida . '.');
            $this->assertContains('ri_' . $medida, $columnas, 'Falta la medida izquierda ' . $medida . '.');
        }

        $this->assertSame('ultra_renal', EcoRenal::TABLA);
    }

    public function test_los_grupos_son_estudio_los_dos_rinones_y_conclusiones(): void
    {
        $this->assertSame(
            ['Estudio', 'Riñón derecho', 'Riñón izquierdo', 'Conclusiones'],
            array_keys(EcoRenal::grupos()),
        );

        // La fecha y la conclusión están en los extremos, como el informe.
        $this->assertSame('fecha', array_key_first(EcoRenal::campos()));
        $this->assertArrayHasKey('conlusion', EcoRenal::campos());
        $this->assertArrayHasKey('sugerencia', EcoRenal::campos());
    }
}
