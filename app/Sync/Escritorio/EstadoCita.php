<?php

namespace App\Sync\Escritorio;

use App\Models\Cola;

/**
 * Traduce `cola.estado` entre las dos semánticas que tiene, en los dos sentidos (WEB-2.12).
 *
 * El escritorio PowerBuilder usa `estado` para **"factura elaborada"**: facturación escribe `1`
 * (`w_factura_principal.srw:889-894`) y `w_pacientes_atendidos_hoy.srw:64-69` se niega a facturar de
 * nuevo cuando lo lee. Toda la agenda del escritorio escribe `0`, y borrar una factura no lo vuelve a
 * 0 (`w_factura_principal_eliminar.srw:1109-1115`). AppDDR usa la misma columna para la
 * **confirmación** (`Cola::ESTADO_*`: 0 no confirmada, 1 consultorio, 2 paciente).
 *
 * Decisión de Alexander (2026-10-08, decisión 8 de PLAN-WEB.md §3): **ninguna superficie adopta la
 * convención de la otra**; la discrepancia se resuelve acá, en la capa de sincronización. Este es el
 * único lugar que conoce las dos semánticas:
 *
 *  - **Subida / carga inicial (escritorio → API):** la `estado` del escritorio **nunca** se escribe
 *    en `cola.estado`; cuando vale `1` se marca `cola.facturada_escritorio`. Así una cita facturada
 *    en el escritorio no aparece "Confirmada" en la web ni en el móvil, y una confirmación hecha en
 *    AppDDR sobrevive a cualquier edición del escritorio.
 *  - **Bajada (API → escritorio, Fase 2.B):** el escritorio recibe `estado = 1` solo si la cita está
 *    facturada, y `0` en cualquier otro caso. Una cita confirmada desde AppDDR llega como "sin
 *    factura" y el escritorio la puede facturar. El caso `estado = 2` (confirmada por el paciente)
 *    queda definido igual: para el escritorio es `0`.
 */
class EstadoCita
{
    /** `cola.estado` en el escritorio: la factura ya se elaboró. */
    public const ESCRITORIO_FACTURADA = 1;

    /** `cola.estado` en el escritorio: sin factura (lo que escribe toda su agenda). */
    public const ESCRITORIO_SIN_FACTURA = 0;

    /**
     * Fila del escritorio -> columnas del API. Saca `estado` y lo traduce a `facturada_escritorio`.
     *
     * `estado = 1` marca facturada; cualquier otro valor **no** la desmarca (el escritorio nunca
     * des-factura, y una fila vieja con `0` no puede borrar lo que AppDDR ya sabe).
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    public static function aAppDdr(array $fila): array
    {
        if (! array_key_exists('estado', $fila)) {
            return $fila;
        }

        $estado = $fila['estado'];
        unset($fila['estado']);

        if ((int) $estado === self::ESCRITORIO_FACTURADA) {
            $fila['facturada_escritorio'] = true;
        }

        return $fila;
    }

    /**
     * Fila del API -> fila para el escritorio (bajada, Fase 2.B). Fuerza `estado` al de la factura y
     * saca la columna nueva, que el escritorio no conoce.
     *
     * La `estado` de AppDDR (confirmación) se descarta a propósito: es justamente lo que el escritorio
     * no puede ver, porque cualquier valor distinto de 0 lo dejaría sin poder facturar.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    public static function aEscritorio(array $fila): array
    {
        $facturada = ! empty($fila['facturada_escritorio']);
        unset($fila['facturada_escritorio']);

        $fila['estado'] = $facturada ? self::ESCRITORIO_FACTURADA : self::ESCRITORIO_SIN_FACTURA;

        return $fila;
    }

    /**
     * Fila **nueva** venida del escritorio: las columnas del API listas para insertar. El escritorio
     * no confirma nada, así que la cita nace sin confirmar; si estaba facturada, eso se conserva.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    public static function nuevaDeEscritorio(array $fila): array
    {
        $fila = self::aAppDdr($fila);
        $fila['estado'] = Cola::ESTADO_NO_CONFIRMADA;

        return $fila;
    }
}
