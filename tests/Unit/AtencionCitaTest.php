<?php

namespace Tests\Unit;

use App\Sync\Escritorio\AtencionCita;
use PHPUnit\Framework\TestCase;

/**
 * La traducción de `cola.atendido` entre el escritorio y AppDDR (WEB-2.13), aislada.
 *
 * El sentido de subida y la carga inicial además se prueban de punta a punta en
 * `CambiosEscritorioTest` y `CargaInicialTest`; el de bajada solo se puede probar acá mientras la
 * bajada de la Fase 2.B no exista (bridge/DISENO-FASE-2.md § 3).
 */
class AtencionCitaTest extends TestCase
{
    public function test_una_cita_movida_por_el_escritorio_no_queda_pendiente_ni_atendida(): void
    {
        $fila = AtencionCita::aAppDdr(['fecha' => '2026-10-01', 'hora_ini' => '08:00:00', 'atendido' => 2]);

        $this->assertSame(0, $fila['atendido'], 'el 2 no es un valor de AppDDR');
        $this->assertTrue($fila['movida_escritorio'], 'la movida se conserva aparte');
        $this->assertSame('2026-10-01', $fila['fecha'], 'el resto de la fila viaja igual');
    }

    public function test_pendiente_y_atendido_se_traducen_directo(): void
    {
        $pendiente = AtencionCita::aAppDdr(['atendido' => 0]);
        $this->assertSame(0, $pendiente['atendido']);
        $this->assertFalse($pendiente['movida_escritorio']);

        $atendida = AtencionCita::aAppDdr(['atendido' => 1]);
        $this->assertSame(1, $atendida['atendido']);
        $this->assertFalse($atendida['movida_escritorio']);
    }

    public function test_realizada_cuenta_como_atendida(): void
    {
        // `3 = Realizada` está declarado en el DataWindow y ninguna ventana lo escribe; si aparece,
        // es una consulta hecha (decisión 2026-10-09).
        $fila = AtencionCita::aAppDdr(['atendido' => 3]);

        $this->assertSame(1, $fila['atendido']);
        $this->assertFalse($fila['movida_escritorio'], 'realizada no es una cita movida');
    }

    public function test_un_cero_del_escritorio_des_marca_la_movida(): void
    {
        // A diferencia de `estado`, esta marca no es de un solo sentido: `w_nueva_cita_7` resetea
        // `atendido` a 0 al reagendar en el mismo día.
        $fila = AtencionCita::aAppDdr(['atendido' => 0, 'movida_escritorio' => true]);

        $this->assertFalse($fila['movida_escritorio']);
    }

    public function test_una_fila_sin_atendido_no_se_toca(): void
    {
        $fila = AtencionCita::aAppDdr(['fecha' => '2026-10-01', 'hora_ini' => '08:00:00']);

        $this->assertArrayNotHasKey('atendido', $fila);
        $this->assertArrayNotHasKey('movida_escritorio', $fila);
    }

    public function test_de_appddr_a_escritorio_la_movida_vuelve_como_dos(): void
    {
        $fila = AtencionCita::aEscritorio(['atendido' => 0, 'movida_escritorio' => true, 'motivo' => 'CONTROL']);

        $this->assertSame(AtencionCita::ESCRITORIO_MOVIDA, $fila['atendido']);
        $this->assertArrayNotHasKey('movida_escritorio', $fila, 'el escritorio no conoce la columna nueva');
        $this->assertSame('CONTROL', $fila['motivo'], 'el resto de la fila viaja igual');
    }

    public function test_de_appddr_a_escritorio_nunca_devuelve_realizada(): void
    {
        foreach ([0, 1] as $atendido) {
            $fila = AtencionCita::aEscritorio(['atendido' => $atendido, 'movida_escritorio' => false]);

            $this->assertSame($atendido, $fila['atendido'], 'sin movida, el booleano se devuelve tal cual');
        }

        $sinNada = AtencionCita::aEscritorio(['fecha' => '2026-10-01']);
        $this->assertSame(AtencionCita::ESCRITORIO_PENDIENTE, $sinNada['atendido']);
    }
}
