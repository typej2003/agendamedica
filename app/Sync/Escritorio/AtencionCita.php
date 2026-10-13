<?php

namespace App\Sync\Escritorio;

/**
 * Traduce `cola.atendido` entre las dos semánticas que tiene, en los dos sentidos (WEB-2.13).
 *
 * El escritorio PowerBuilder usa la columna con **cuatro** valores, declarados en el DataWindow de la
 * agenda (`d_pacientes_consulta_cita.srd:10`): `0` pendiente · `1` atendido · `3` *Realizada*
 * (declarada y **sin escritor** en ninguna fuente) — y el `2`, que no está en la lista pero el código
 * escribe, significa **"la cita se movió a otro día"** (`w_calendar.srw:300-303`,
 * `w_pacientes_a_atender_hoy.srw:395-398`: marcan la vieja con `2` e insertan la nueva en la fecha
 * destino). Casi todos sus DataWindows de agenda filtran `atendido <> 2`, así que para el escritorio
 * esas citas **no existen** en el día.
 *
 * AppDDR usa la misma columna como **booleano** (0 pendiente / 1 atendido) y no filtra por `2`: una
 * cita movida aparecía como pendiente, con "Confirmar"/"Atender" habilitados, mientras el mismo
 * paciente ya estaba en su fecha nueva.
 *
 * Decisión de Alexander (2026-10-08, decisión 8 de PLAN-WEB.md §3): **ninguna superficie adopta la
 * convención de la otra**; la discrepancia se resuelve acá, en la capa de sincronización. El escritorio
 * conserva sus `2/3` y la traducción la hace el sync del escritorio, que es el único punto que conoce
 * las dos semánticas:
 *
 *  - **Subida / carga inicial (escritorio → API):** `atendido` se normaliza a `0/1` y el `2` se guarda
 *    en `cola.movida_escritorio`. El `3` pasa a `1` (es una consulta hecha, aunque el valor no tenga
 *    escritor; queda documentado en las constantes).
 *  - **Bajada (API → escritorio, Fase 2.B):** el escritorio recibe `atendido = 2` solo si la cita está
 *    marcada como movida, y `0/1` en el resto. **Nunca** se le devuelve `3`: no lo escribe ni lo
 *    muestra bien.
 *
 * ⚠️ A diferencia de `cola.estado` (ver `EstadoCita`), esta marca **no** es de un solo sentido: el
 * escritorio **resetea** `atendido` a 0 al reagendar en el mismo día (`w_nueva_cita_7`), así que un `0`
 * del escritorio des-marca la movida.
 */
class AtencionCita
{
    /** `cola.atendido` en el escritorio: la cita sigue pendiente. */
    public const ESCRITORIO_PENDIENTE = 0;

    /** `cola.atendido` en el escritorio: el paciente fue atendido. */
    public const ESCRITORIO_ATENDIDA = 1;

    /** `cola.atendido` en el escritorio: la cita se movió a otro día (no está en la lista del día). */
    public const ESCRITORIO_MOVIDA = 2;

    /**
     * `cola.atendido` en el escritorio: *Realizada*. Está declarado en el DataWindow y **ninguna
     * ventana lo escribe**; se acepta como "atendida" por si una instalación lo usa.
     */
    public const ESCRITORIO_REALIZADA = 3;

    /**
     * Fila del escritorio -> columnas del API. Saca `atendido` y lo deja en el dominio booleano de
     * AppDDR, guardando la movida aparte.
     *
     * A diferencia de `EstadoCita::aAppDdr`, siempre escribe `atendido` (incluido el `0`): el
     * escritorio sí puede volver a 0 una cita que había movido.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    public static function aAppDdr(array $fila): array
    {
        if (! array_key_exists('atendido', $fila)) {
            return $fila;
        }

        $atendido = (int) $fila['atendido'];
        $fila['atendido'] = self::aAppDdrBool($atendido);
        $fila['movida_escritorio'] = $atendido === self::ESCRITORIO_MOVIDA;

        return $fila;
    }

    /**
     * Fila del API -> fila para el escritorio (bajada, Fase 2.B). Fuerza `atendido` al valor del
     * escritorio y saca la columna nueva, que el escritorio no conoce.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    public static function aEscritorio(array $fila): array
    {
        $movida = ! empty($fila['movida_escritorio']);
        unset($fila['movida_escritorio']);

        if ($movida) {
            $fila['atendido'] = self::ESCRITORIO_MOVIDA;
        } else {
            $fila['atendido'] = (int) ($fila['atendido'] ?? 0) === 1
                ? self::ESCRITORIO_ATENDIDA
                : self::ESCRITORIO_PENDIENTE;
        }

        return $fila;
    }

    /** El `atendido` del escritorio como el booleano de AppDDR: solo `1` y `3` están atendidos. */
    public static function aAppDdrBool(int $atendido): int
    {
        return in_array($atendido, [self::ESCRITORIO_ATENDIDA, self::ESCRITORIO_REALIZADA], true) ? 1 : 0;
    }
}
