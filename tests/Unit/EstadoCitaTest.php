<?php

namespace Tests\Unit;

use App\Models\Cola;
use App\Sync\Escritorio\EstadoCita;
use PHPUnit\Framework\TestCase;

/**
 * La traducción de `cola.estado` entre el escritorio y AppDDR (WEB-2.12), aislada.
 *
 * El sentido de subida y la carga inicial además se prueban de punta a punta en
 * `CambiosEscritorioTest` y `CargaInicialTest`; el de bajada solo se puede probar acá mientras la
 * bajada de la Fase 2.B no exista (bridge/DISENO-FASE-2.md § 3).
 */
class EstadoCitaTest extends TestCase
{
    public function test_de_escritorio_a_appddr_la_factura_se_guarda_aparte(): void
    {
        $fila = EstadoCita::aAppDdr(['fecha' => '2026-10-01', 'hora_ini' => '08:00:00', 'estado' => 1]);

        $this->assertArrayNotHasKey('estado', $fila, 'la estado del escritorio no se escribe en la de AppDDR');
        $this->assertTrue($fila['facturada_escritorio']);
    }

    public function test_de_escritorio_a_appddr_sin_factura_no_marca_nada(): void
    {
        $fila = EstadoCita::aAppDdr(['fecha' => '2026-10-01', 'hora_ini' => '08:00:00', 'estado' => 0]);

        $this->assertArrayNotHasKey('estado', $fila);
        $this->assertArrayNotHasKey('facturada_escritorio', $fila, 'un 0 no des-factura lo que AppDDR ya sabe');
    }

    public function test_una_fila_nueva_del_escritorio_nace_sin_confirmar_pero_facturada(): void
    {
        $fila = EstadoCita::nuevaDeEscritorio(['fecha' => '2026-10-01', 'hora_ini' => '08:00:00', 'estado' => 1]);

        $this->assertSame(Cola::ESTADO_NO_CONFIRMADA, $fila['estado']);
        $this->assertTrue($fila['facturada_escritorio']);
    }

    public function test_de_appddr_a_escritorio_la_confirmacion_llega_como_sin_factura(): void
    {
        foreach (Cola::ESTADOS as $estado) {
            $fila = EstadoCita::aEscritorio(['estado' => $estado, 'facturada_escritorio' => false, 'motivo' => 'CONTROL']);

            $this->assertSame(EstadoCita::ESCRITORIO_SIN_FACTURA, $fila['estado'], "estado {$estado} no puede bloquear facturación");
            $this->assertArrayNotHasKey('facturada_escritorio', $fila, 'el escritorio no conoce la columna nueva');
            $this->assertSame('CONTROL', $fila['motivo'], 'el resto de la fila viaja igual');
        }
    }

    public function test_de_appddr_a_escritorio_una_factura_sigue_facturada(): void
    {
        $fila = EstadoCita::aEscritorio(['estado' => Cola::ESTADO_CONFIRMADA_PACIENTE, 'facturada_escritorio' => true]);

        $this->assertSame(EstadoCita::ESCRITORIO_FACTURADA, $fila['estado'], 'el escritorio vuelve a negarse a facturar, como hoy');
    }
}
