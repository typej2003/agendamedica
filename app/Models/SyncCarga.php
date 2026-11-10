<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Carga inicial completa de un médico desde el escritorio PowerBuilder.
 * Ver App\Services\CargaInicialService.
 *
 * `modo` dice cómo se aplica: `limpia` (el consultorio no tenía datos) o `mezcla` (ya tenía y el
 * escritorio manda: lo que ocupaba un número se mueve al final). Ver App\Sync\Escritorio\Traslados.
 */
class SyncCarga extends Model
{
    public const EN_CURSO = 'en_curso';
    public const COMPLETA = 'completa';

    public const MODO_LIMPIA = 'limpia';
    public const MODO_MEZCLA = 'mezcla';

    protected $table = 'sync_cargas';

    protected $fillable = ['reg_medico', 'medico_id', 'estado', 'modo', 'iniciada_at', 'finalizada_at'];

    protected $casts = [
        'iniciada_at'   => 'datetime',
        'finalizada_at' => 'datetime',
    ];

    public function tablas(): HasMany
    {
        return $this->hasMany(SyncCargaTabla::class);
    }

    public function traslados(): HasMany
    {
        return $this->hasMany(SyncTraslado::class);
    }

    public function esMezcla(): bool
    {
        return $this->modo === self::MODO_MEZCLA;
    }

    /**
     * ¿La carga se cerró **con datos**? Una carga `completa` que no recibió ninguna fila es el síntoma de
     * un escritorio que leyó una base vacía (DSN o carpeta equivocada): no habilita la sincronización
     * periódica ni se muestra como completa. Se puede volver a intentar y `iniciar` la reabre.
     */
    public function completaConDatos(): bool
    {
        if ($this->estado !== self::COMPLETA) {
            return false;
        }

        // El resumen del panel trae el total con `withSum('tablas', 'filas_recibidas')`; en el resto de
        // los casos se consulta. El atributo ausente devuelve null, no lanza.
        $recibidas = $this->tablas_sum_filas_recibidas ?? $this->tablas()->sum('filas_recibidas');

        return (int) $recibidas > 0;
    }
}
