<?php

namespace App\Pagos\Conciliacion;

use App\Models\CuentaBancaria;

/**
 * **El contrato de un banco que sabe confirmar pagos móviles y transferencias recibidas.**
 *
 * No cobra ni debita: sólo consulta si una transacción concreta llegó a la cuenta del comercio. La API es
 * la del **banco que recibe**, y se elige por el código de banco de la cuenta.
 */
interface Conciliador
{
    /**
     * Consulta **una** transacción: la referencia la da el pagador y no se puede adivinar.
     *
     * @throws \RuntimeException si el banco no responde o rechaza las credenciales
     */
    public function consultar(CuentaBancaria $cuenta, ConsultaDePago $consulta): ResultadoDeConsulta;

    /**
     * Prueba las credenciales sin lanzar: devuelve el resultado para la pantalla del consultorio.
     *
     * @return array{ok:bool, mensaje:string}
     */
    public function probar(CuentaBancaria $cuenta): array;
}
