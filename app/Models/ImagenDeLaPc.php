<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un **archivo de imagen que vivía en el disco de la PC del consultorio** y ya está en la nube (`subida`) o se decidió
 * no subir (`omitida`), WEB-T.1. Una fila por dato legado: (`reg_medico`, `tabla`, `fila_id`, `columna`).
 *
 * La migración `2026_10_12_150000_create_imagenes_de_la_pc_table` documenta cada columna. El mapa del proceso está en
 * `App\Actions\Imagenes\SubirImagenDeLaPc` y `App\Sync\ImagenesPc\ReferenciasDeImagenes`.
 */
class ImagenDeLaPc extends Model
{
    public const SUBIDA = 'subida';

    public const OMITIDA = 'omitida';

    protected $table = 'imagenes_de_la_pc';

    protected $fillable = [
        'reg_medico', 'tabla', 'fila_id', 'columna', 'ruta_pc', 'estado', 'motivo',
        'disco', 'ruta', 'tipo', 'tamano', 'sha256', 'equipo',
    ];

    protected $casts = [
        'fila_id' => 'integer',
        'tamano' => 'integer',
    ];

    public function estaSubida(): bool
    {
        return $this->estado === self::SUBIDA;
    }
}
