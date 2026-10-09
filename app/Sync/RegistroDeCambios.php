<?php

namespace App\Sync;

use App\Models\SyncChange;
use Carbon\Carbon;

/**
 * La parte del sync que **no es de negocio**: resolver conflictos ("gana la última edición", por
 * columna) y dejar rastro de cada escritura en `sync_changes`.
 *
 * Por qué vive acá y no dentro del controlador del sync: **la web también escribe**. Si la web
 * cambiara una cita sin dejar rastro, una edición vieja del teléfono llegaría más tarde, no tendría
 * con qué compararse, y pisaría el cambio nuevo. Con el registro compartido, las dos superficies
 * resuelven el mismo conflicto con la misma regla (PLAN-WEB.md, R3).
 */
final class RegistroDeCambios
{
    /**
     * ¿Esta edición se aplica? Falso cuando ya hay un cambio **igual o más nuevo** registrado para
     * esa columna: es el last-write-wins del sync, por columna (no por fila), así dos escrituras
     * sobre campos distintos de la misma cita no se pisan.
     */
    public static function permite(string $tabla, int $registro, string $columna, Carbon $cuando): bool
    {
        $ultimo = SyncChange::where('table_name', $tabla)
            ->where('record_id', $registro)
            ->where('column_name', $columna)
            ->orderByDesc('occurred_at')
            ->first();

        return $ultimo === null || $ultimo->occurred_at->lessThan($cuando);
    }

    /** Un eliminado le gana a cualquier edición posterior, sin importar el timestamp. */
    public static function eliminado(string $tabla, int $registro): bool
    {
        return SyncChange::where('table_name', $tabla)
            ->where('record_id', $registro)
            ->where('operation', 'deleted')
            ->exists();
    }

    /** Registra la edición de **una** columna (`operation = updated`). */
    public static function registrar(
        string $tabla,
        int $registro,
        ?string $regMedico,
        string $columna,
        $valor,
        Carbon $cuando,
        string $origen
    ): void {
        SyncChange::create([
            'reg_medico'  => $regMedico,
            'table_name'  => $tabla,
            'record_id'   => $registro,
            'operation'   => 'updated',
            'column_name' => $columna,
            'value'       => $valor,
            'occurred_at' => $cuando,
            'source'      => $origen,
        ]);
    }

    /**
     * Registra una operación que no es "cambió una columna": hoy la usa el reordenamiento
     * (`operation = reorder`, con el movimiento `desde->hasta` como valor informativo).
     */
    public static function registrarOperacion(
        string $tabla,
        int $registro,
        ?string $regMedico,
        string $operacion,
        ?string $columna,
        $valor,
        Carbon $cuando,
        string $origen,
        ?int $tempId = null
    ): void {
        SyncChange::create([
            'reg_medico'     => $regMedico,
            'table_name'     => $tabla,
            'record_id'      => $registro,
            'client_temp_id' => $tempId,
            'operation'      => $operacion,
            'column_name'    => $columna,
            'value'          => $valor,
            'occurred_at'    => $cuando,
            'source'         => $origen,
        ]);
    }
}
