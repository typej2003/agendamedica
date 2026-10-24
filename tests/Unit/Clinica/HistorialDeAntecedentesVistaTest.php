<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\Vistas\HistorialDeAntecedentesVista;
use Tests\TestCase;

/**
 * La fecha de cada movimiento del historial de antecedentes sale en la hora del consultorio y en 12 h con AM/PM
 * (2026-10-23). Cubre un fallo real: la vista usaba `HoraLocal` sin importarla y la pantalla de antecedentes daba 500.
 */
class HistorialDeAntecedentesVistaTest extends TestCase
{
    public function test_la_fecha_del_movimiento_sale_en_la_hora_del_consultorio_en_12_horas(): void
    {
        $movimiento = (object) [
            'operacion' => 'agregado',
            'ocurrido_en' => '2026-10-23 17:30:00', // UTC
            'descripcion' => 'Alergia a la penicilina',
            'codeantecedente' => '0001',
            'detalles' => null,
            'alerta' => 0,
            'consulta_id' => null,
            'origen' => 'web',
        ];

        $vista = new HistorialDeAntecedentesVista(collect([$movimiento]), null, fn () => '#');

        $fila = $vista->filas()[0];

        $this->assertSame('23/10/2026 1:30 PM', $fila['fecha']);
    }

    public function test_un_movimiento_sin_fecha_dice_sin_fecha(): void
    {
        $movimiento = (object) [
            'operacion' => 'agregado', 'ocurrido_en' => null, 'descripcion' => 'X', 'codeantecedente' => '0002',
            'detalles' => null, 'alerta' => 0, 'consulta_id' => null, 'origen' => 'web',
        ];

        $fila = (new HistorialDeAntecedentesVista(collect([$movimiento]), null, fn () => '#'))->filas()[0];

        $this->assertSame('Sin fecha', $fila['fecha']);
    }
}
