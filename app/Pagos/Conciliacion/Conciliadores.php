<?php

namespace App\Pagos\Conciliacion;

use App\Models\CuentaBancaria;

/**
 * **Elijo el conciliador por el banco de la cuenta que recibe.** No por el banco del pagador: un pago
 * móvil de Banco de Venezuela a una cuenta Bancaribe se confirma con Bancaribe.
 *
 * Un banco sin driver (todos menos Bancaribe y Bancamiga, hoy) devuelve `null`: la cuenta existe, el
 * paciente puede pagar, y lo confirma el consultorio.
 */
final class Conciliadores
{
    /** El driver del banco, tenga o no credenciales (para configurarlas y probarlas). */
    public static function delBanco(CuentaBancaria $cuenta): ?Conciliador
    {
        return match ($cuenta->driverDeConciliacion()) {
            'bancaribe' => new ConciliadorBancaribe(),
            // Bancamiga entra acá cuando tengamos su especificación; el contrato ya está.
            default => null,
        };
    }

    /** El driver listo para consultar: sólo si la cuenta eligió automática y tiene credenciales. */
    public static function para(CuentaBancaria $cuenta): ?Conciliador
    {
        return $cuenta->conciliaSola() ? self::delBanco($cuenta) : null;
    }
}
