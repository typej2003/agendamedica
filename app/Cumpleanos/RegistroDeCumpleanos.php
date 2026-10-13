<?php

namespace App\Cumpleanos;

use App\Models\NotificacionCumple;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * La lectura del registro de saludos de cumpleaños (`notificaciones_cumple`): a quién **ya se le mandó**
 * el saludo del cumpleaños que se está saludando.
 *
 * Es la pieza que hace que el envío automático pueda correr **cada pocos minutos** sin mandar dos veces
 * el mismo mensaje, y la que le dice a la pantalla a quién le llegó. La pregunta es siempre la misma
 * —"¿hay un envío **exitoso** de este paciente en esta fecha de cumpleaños?"— y por eso vive en un solo
 * lugar: el comando y la pantalla no pueden contestarla distinto.
 *
 * **Solo cuenta lo enviado**: un intento fallido no bloquea el reintento (el proveedor puede haber
 * estado caído). Los intentos fallidos quedan igual en la tabla, con su motivo, para poder mirarlos.
 */
class RegistroDeCumpleanos
{
    /**
     * Los `paciente_id` a los que ya se les mandó el saludo de esta fecha, de una lista de pacientes.
     *
     * Se consulta en lote —una sola consulta para toda la pantalla o para todo el lote del comando— y
     * no fila por fila: el envío del día recorre a todos los que cumplen años.
     *
     * @param  iterable<int>  $pacienteIds
     * @return array<int,true>  `paciente_id` => true, para preguntar con `isset`
     */
    public function yaSaludados(CarbonInterface $fecha, iterable $pacienteIds): array
    {
        $ids = Collection::make($pacienteIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return NotificacionCumple::query()
            ->whereDate('fecha_cumple', $fecha->toDateString())
            ->where('estado', NotificacionCumple::ESTADO_ENVIADA)
            ->whereIn('paciente_id', $ids)
            ->pluck('paciente_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /** El último envío exitoso a un paciente en esa fecha, o `null`. */
    public function envioDe(int $pacienteId, CarbonInterface $fecha): ?NotificacionCumple
    {
        return NotificacionCumple::query()
            ->where('paciente_id', $pacienteId)
            ->whereDate('fecha_cumple', $fecha->toDateString())
            ->where('estado', NotificacionCumple::ESTADO_ENVIADA)
            ->latest('id')
            ->first();
    }

    /**
     * ¿Este error de base es el choque contra el índice único de `notificaciones_cumple`?
     *
     * Laravel 8 no tiene `UniqueConstraintViolationException` (llega en el 9), así que hay que mirar el
     * SQLSTATE: `23000` es el de MySQL/MariaDB y `23505` el de PostgreSQL. Que sea **este** índice y no
     * otra violación de integridad lo confirma el nombre del índice, que los dos motores incluyen —en el
     * mensaje (MySQL) o en el detalle de la consulta (SQLite: `UNIQUE constraint failed: ...`)—.
     *
     * Es la red de seguridad de la carrera más fina: entre la consulta del registro y el `insert` puede
     * entrar otra corrida del comando.
     */
    public static function esChoqueDeEnvioUnico(QueryException $excepcion): bool
    {
        if (! in_array((string) $excepcion->getCode(), ['23000', '23505'], true)) {
            return false;
        }

        $detalle = $excepcion->getMessage() . ' ' . ($excepcion->getSql() ?? '');

        return str_contains($detalle, 'notificaciones_cumple_envio_unico')
            // SQLite nombra las columnas del índice, no el índice.
            || str_contains($detalle, 'notificaciones_cumple.paciente_id');
    }

    /**
     * Cuándo se le mandó el saludo a cada uno de los que ya lo recibieron, para que la pantalla pueda
     * decir "enviado 09:03" en vez de un tilde sin contexto.
     *
     * @param  iterable<int>  $pacienteIds
     * @return array<int,string>  `paciente_id` => `created_at` crudo
     */
    public function cuandoSeSaludo(CarbonInterface $fecha, iterable $pacienteIds): array
    {
        $ids = Collection::make($pacienteIds)->map(fn ($id) => (int) $id)->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        return NotificacionCumple::query()
            ->whereDate('fecha_cumple', $fecha->toDateString())
            ->where('estado', NotificacionCumple::ESTADO_ENVIADA)
            ->whereIn('paciente_id', $ids)
            ->orderBy('id')
            ->get(['paciente_id', 'created_at'])
            ->mapWithKeys(fn (NotificacionCumple $notificacion) => [
                (int) $notificacion->paciente_id => (string) $notificacion->created_at,
            ])
            ->all();
    }

    /**
     * ¿Ya se le mandó? Es el mismo criterio que `yaSaludados()` para un solo paciente, y lo usa el
     * comando **justo antes de mandar** (no al armar la lista): entre que se lee la lista de
     * cumpleaños y que sale el mensaje puede haber pasado otro envío —dos corridas solapadas, o un
     * envío manual del consultorio— y el registro es lo único que lo ve.
     */
    public function yaSeLeMando(int $pacienteId, CarbonInterface $fecha): bool
    {
        return NotificacionCumple::query()
            ->where('paciente_id', $pacienteId)
            ->whereDate('fecha_cumple', $fecha->toDateString())
            ->where('estado', NotificacionCumple::ESTADO_ENVIADA)
            ->exists();
    }
}
