<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Avance de una tabla dentro de una carga inicial (punto de reanudación). */
class SyncCargaTabla extends Model
{
    protected $table = 'sync_carga_tablas';

    protected $fillable = [
        'sync_carga_id', 'tabla', 'filas_esperadas', 'filas_recibidas', 'filas_omitidas', 'columnas_ignoradas',
    ];
}
