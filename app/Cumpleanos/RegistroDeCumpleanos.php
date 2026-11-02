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
     * **El intento de hoy**, sea exitoso o fallido: la fila que existe para ese paciente y ese cumpleaños.
     *
     * Es lo que usa el envío para no inventar un estado cuando el índice único choca (O-51): devuelve lo
     * que hay registrado, que puede ser un intento fallido, en vez de fabricar un «enviada».
     */
    public function intentoDe(int $pacienteId, CarbonInterface $fecha): ?NotificacionCumple
    {
        return NotificacionCumple::query()
            ->where('paciente_id', $pacienteId)
            ->whereDate('fecha_cumple', $fecha->toDateString())
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
     * **El último intento fallido** de cada paciente en esa fecha, con su motivo ya legible (O-51): es lo
     * que la pantalla muestra debajo del estado para que un saludo que no salió no se vea igual que uno
     * que nunca se mandó.
     *
     * Se toma el `id` más alto de los fallidos —el intento más reciente del día— y se traduce la
     * `respuesta` cruda con `MotivoDelFallo`. El motivo puede venir en `null` si el proveedor no dijo nada
     * útil: la fila igual dice que falló y a qué hora.
     *
     * @param  iterable<int>  $pacienteIds
     * @return array<int,array{momento:string, canal:string, motivo:?string}>
     */
    public function fallidosDe(CarbonInterface $fecha, iterable $pacienteIds): array
    {
        $ids = Collection::make($pacienteIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $filas = NotificacionCumple::query()
            ->whereDate('fecha_cumple', $fecha->toDateString())
            ->where('estado', NotificacionCumple::ESTADO_FALLIDA)
            ->whereIn('paciente_id', $ids)
            ->orderBy('id')
            ->get(['paciente_id', 'canal', 'respuesta', 'updated_at']);

        $fallidos = [];

        // Ordenado por `id`: el último que se escribió es el que manda. La hora es la del último intento
        // (`updated_at`), que en la primera escritura es la misma que la de la fila.
        foreach ($filas as $fila) {
            $fallidos[(int) $fila->paciente_id] = [
                'momento' => (string) $fila->updated_at,
                'canal'   => (string) $fila->canal,
                'motivo'  => MotivoDelFallo::de($fila->respuesta),
            ];
        }

        return $fallidos;
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
