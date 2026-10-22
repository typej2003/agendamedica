<?php

namespace App\Sync;

use App\Models\SyncCarga;
use App\Models\SyncCargaTabla;
use Carbon\Carbon;

/**
 * ¿Este consultorio está subiendo ahora mismo su **carga inicial** desde el escritorio?
 *
 * Mientras el escritorio vuelca toda la base (`POST sync/carga-inicial/*`), el API está **insertando
 * pacientes, historias, consultas y catálogos** de ese médico. Si en ese rato el app móvil sube sus
 * cambios, los dos escriben sobre las mismas tablas y el resultado es una mezcla que nadie pidió: el
 * teléfono puede crear una consulta sobre una historia que la carga todavía no insertó, o pisar una fila
 * que la carga está a punto de duplicar.
 *
 * Por eso el endpoint del app responde un error **temporal y silencioso** (`sincronizacion_en_curso`)
 * hasta que la carga termina: el teléfono conserva su cola de pendientes y reintenta solo. Ver
 * `SyncAppDataController` y, del lado del app, `SyncService` (que lo trata como «todavía no», no como
 * fallo).
 *
 * **Cuándo deja de bloquear.** Una carga que se cortó (la PC se apagó, se cortó internet) queda
 * `en_curso` para siempre, y con el escritorio retomándola cuando vuelva. Bloquear el app hasta entonces
 * dejaría al médico sin sincronizar para siempre, así que una carga sin ningún lote reciente se considera
 * abandonada: manda la última actividad de sus tablas, no `sync_cargas.updated_at` (que no se mueve mientras
 * la carga avanza — el avance vive en `sync_carga_tablas`).
 */
final class CargaInicialEnCurso
{
    /**
     * Horas sin recibir un lote a partir de las cuales la carga se considera abandonada y no bloquea nada.
     *
     * Es holgada a propósito: una base de 300.000 filas tarda unos 5 minutos, y un consultorio con una
     * conexión mala puede pasar horas entre lotes sin haber muerto.
     */
    public const HORAS_DE_ABANDONO = 3;

    /**
     * La carga en curso que bloquea el sync del app, o `null` si no hay ninguna.
     *
     * @param  array<int,string>  $regsMedico  todos los registros del médico (un médico puede trabajar en varios consultorios)
     */
    public function de(array $regsMedico): ?SyncCarga
    {
        if ($regsMedico === []) {
            return null;
        }

        $cargas = SyncCarga::whereIn('reg_medico', $regsMedico)
            ->where('estado', SyncCarga::EN_CURSO)
            ->get();

        foreach ($cargas as $carga) {
            if ($this->sigueViva($carga)) {
                return $carga;
            }
        }

        return null;
    }

    /** ¿La carga está avanzando (o empezó hace poco)? */
    public function sigueViva(SyncCarga $carga): bool
    {
        $referencia = $this->ultimaActividad($carga);

        return $referencia !== null && $referencia->greaterThan(now()->subHours(self::HORAS_DE_ABANDONO));
    }

    /** Lo último que se supo de la carga: el último lote, o el momento en que arrancó. */
    public function ultimaActividad(SyncCarga $carga): ?Carbon
    {
        $ultimo = SyncCargaTabla::where('sync_carga_id', $carga->id)->max('updated_at');

        $referencia = $ultimo ? Carbon::parse($ultimo) : ($carga->iniciada_at ?? $carga->created_at);

        return $referencia;
    }
}
