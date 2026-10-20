<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Crecimiento\EdadEnTexto;
use PHPUnit\Framework\TestCase;

/**
 * **La edad compacta de los impresos de pediatría** (2026-10-20): `21A 10M 16D`, la forma en que el escritorio escribe la
 * edad en la cabecera del récipe.
 *
 * Fija la regla de `EdadEnTexto::compacta()`: años, meses y días; los años y los meses salen sólo si son mayores que cero
 * (un bebé de días no dice «0A 0M 5D») y los días van siempre —también en `0D`, el recién nacido del día del documento—.
 * Sin base de datos ni fechas de hoy: las dos fechas se pasan siempre.
 */
class EdadEnTextoTest extends TestCase
{
    public function test_la_edad_compacta_lleva_anios_meses_y_dias(): void
    {
        // La captura del escritorio: 21 años, 10 meses y 16 días.
        $this->assertSame('21A 10M 16D', EdadEnTexto::compacta('2004-12-03', '2026-10-19'));
        $this->assertSame('2A 4M 3D', EdadEnTexto::compacta('2024-01-01', '2026-05-04'));
        $this->assertSame('1A 1M 2D', EdadEnTexto::compacta('2025-03-02', '2026-04-04'));
    }

    public function test_un_bebe_no_lleva_los_ceros_de_los_anios_ni_de_los_meses(): void
    {
        // Cinco días de nacido: sólo los días.
        $this->assertSame('5D', EdadEnTexto::compacta('2026-05-04', '2026-05-09'));
        // Nacido el mismo día del documento.
        $this->assertSame('0D', EdadEnTexto::compacta('2026-05-04', '2026-05-04'));
        // Dos meses y medio, sin años: «2M 3D».
        $this->assertSame('2M 3D', EdadEnTexto::compacta('2026-03-01', '2026-05-04'));
        // Un año justo: los días van siempre, aunque sean cero.
        $this->assertSame('1A 0D', EdadEnTexto::compacta('2025-05-04', '2026-05-04'));
    }

    public function test_sin_fechas_que_sirvan_no_hay_edad(): void
    {
        $this->assertNull(EdadEnTexto::compacta(null, '2026-05-04'));
        $this->assertNull(EdadEnTexto::compacta('2026-05-04', null));
        $this->assertNull(EdadEnTexto::compacta('', '2026-05-04'));
        // La fecha del documento es anterior al nacimiento.
        $this->assertNull(EdadEnTexto::compacta('2026-05-05', '2026-05-04'));
        $this->assertNull(EdadEnTexto::compacta('no es una fecha', '2026-05-04'));
    }

    public function test_la_edad_larga_de_la_consulta_no_cambia(): void
    {
        // `entre()` es otra forma de la misma cuenta y sigue diciendo lo de siempre.
        $this->assertSame('2 años 4 meses 3 días', EdadEnTexto::entre('2024-01-01', '2026-05-04'));
        $this->assertSame('1 año', EdadEnTexto::entre('2025-05-04', '2026-05-04'));
        $this->assertSame('0 días', EdadEnTexto::entre('2026-05-04', '2026-05-04'));
        $this->assertNull(EdadEnTexto::entre('2026-05-05', '2026-05-04'));
    }
}
