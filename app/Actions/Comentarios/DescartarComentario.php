<?php

namespace App\Actions\Comentarios;

use App\Models\Comentario;

/**
 * Descarta un comentario de **"Ayúdanos a mejorar"** (ROADMAP §9): lo leímos y decidimos no avanzar.
 *
 * **No se borra**: el comentario queda guardado —es el registro de lo que nos dijeron y de que lo
 * atendimos—, sólo sale de la lista de los que esperan respuesta. La respuesta se limpia: un descartado no
 * lleva respuesta, y la pantalla del usuario explica el descarte con su propio texto.
 *
 * Desde O-25 **sí se borran sus imágenes adjuntas** (filas y archivos): una captura puede traer datos de un
 * paciente y no sirve para nada cuando el reporte no se va a atender. El orden y el porqué están en
 * `BorrarImagenesDelComentario`.
 */
class DescartarComentario
{
    public function ejecutar(Comentario $comentario): Comentario
    {
        $comentario->update([
            'estado' => Comentario::DESCARTADO,
            'respuesta' => null,
            'respondido_por' => null,
            'respondido_el' => null,
        ]);

        app(BorrarImagenesDelComentario::class)->ejecutar($comentario);

        return $comentario;
    }
}
