<?php

namespace App\Actions\Agenda;

use App\Models\Cola;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Registrar el pago de una cita, con abono parcial.
 *
 * El **estado de pago no se guarda**: se deriva de `monto` y `monto_pagado`
 * (`Cola::estadoPago()`), así un abono parcial no necesita un campo aparte que se pueda
 * desincronizar de las cifras. `monto` viene NULL en casi todas las citas legadas (el médico no
 * siempre le pone precio), y eso no es una deuda: es `sin_monto`.
 *
 * Dos entradas, a propósito, porque las dos superficies saben cosas distintas:
 *
 * - `recibir()` — la **web**. El mostrador cobra un abono: se **suma** a lo ya pagado (dos abonos
 *   de 20 son 40, no 20). El precio solo se fija si la cita no lo tenía.
 * - `fijarMonto()` / `fijarPagado()` — el **sync**. El teléfono ya calculó el total localmente
 *   (funciona sin conexión) y manda el valor absoluto; sumar otra vez acá duplicaría el abono
 *   cuando la respuesta se pierde y el cliente reintenta.
 */
final class CobrarCita
{
    /**
     * Cobra un abono sobre la cita.
     *
     * @param  float  $recibido  Lo que entra ahora; se suma a lo ya pagado.
     * @param  float|null  $monto  Precio a fijar **solo si la cita no tenía**: si ya tenía precio, se ignora.
     */
    public function recibir(Cola $cita, float $recibido, ?float $monto = null, ?Carbon $cuando = null, string $origen = 'web'): bool
    {
        $cuando = $cuando ?: Carbon::now();
        $aplicado = false;

        if ((float) ($cita->monto ?? 0) <= 0 && $monto !== null) {
            $aplicado = $this->fijarMonto($cita, $monto, $cuando, $origen) || $aplicado;
        }

        $pagado = (float) ($cita->monto_pagado ?? 0) + $recibido;

        return $this->fijarPagado($cita, $pagado, $cuando, $origen) || $aplicado;
    }

    /** Fija el precio de la cita (el valor absoluto que llega del cliente en el sync). */
    public function fijarMonto(Cola $cita, ?float $monto, ?Carbon $cuando = null, string $origen = 'web'): bool
    {
        return $this->escribir($cita, 'monto', $monto, $cuando, $origen);
    }

    /** Fija lo pagado (el valor absoluto que llega del cliente en el sync). */
    public function fijarPagado(Cola $cita, ?float $montoPagado, ?Carbon $cuando = null, string $origen = 'web'): bool
    {
        return $this->escribir($cita, 'monto_pagado', $montoPagado, $cuando, $origen);
    }

    private function escribir(Cola $cita, string $columna, ?float $valor, ?Carbon $cuando, string $origen): bool
    {
        if ($valor !== null && $valor < 0) {
            throw new InvalidArgumentException("El campo {$columna} no puede ser negativo.");
        }

        $cuando = $cuando ?: Carbon::now();

        if (! RegistroDeCambios::permite('cola', $cita->id, $columna, $cuando)) {
            return false;
        }

        // Un `null` explícito se escribe: es la forma de dejar la cita sin precio (`sin_monto`).
        $cita->{$columna} = $valor;
        $cita->save();

        RegistroDeCambios::registrar('cola', $cita->id, $cita->reg_medico, $columna, $valor, $cuando, $origen);

        return true;
    }
}
