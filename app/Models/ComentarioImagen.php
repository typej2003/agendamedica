<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * **Una imagen adjunta a un comentario** de "Ayúdanos a mejorar" (O-25): la captura que el usuario pegó del
 * portapapeles o eligió de su equipo.
 *
 * La fila cuelga del comentario (`comentario_imagenes.comentario_id`, con cascada) y el archivo vive en el disco
 * **privado** `comentarios` (`storage/app/comentarios`, fuera de `public/`): una captura puede traer datos de un
 * paciente y no se sirve desde el servidor web. El único camino al archivo es la ruta autorizada, que comprueba
 * quién pide: **el autor** del comentario (en «Mis comentarios») o **quien atiende la bandeja** (`/admin`).
 *
 * La política de lo que se acepta y con qué nombre se guarda es la de `App\Clinica\Archivos\ArchivosClinicos`,
 * en su perfil de comentario (ver `App\Clinica\Ayuda\ArchivosDeLosComentarios`).
 */
class ComentarioImagen extends Model
{
    protected $table = 'comentario_imagenes';

    protected $fillable = [
        'comentario_id', 'disco', 'ruta', 'tipo', 'tamano', 'nombre_original',
    ];

    protected $casts = [
        'tamano' => 'integer',
    ];

    public function comentario(): BelongsTo
    {
        return $this->belongsTo(Comentario::class, 'comentario_id');
    }

    /** El nombre que se muestra y se pone en la descarga: el del archivo del usuario, nunca la ruta del sistema. */
    public function nombreVisible(): string
    {
        $nombre = trim((string) $this->nombre_original);

        return $nombre === '' ? basename((string) $this->ruta) : basename(str_replace('\\', '/', $nombre));
    }
}
