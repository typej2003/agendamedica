<?php

namespace App\Actions\Comentarios;

use App\Models\Comentario;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Responde un comentario de **"Ayúdanos a mejorar"** (ROADMAP §9).
 *
 * Una sola respuesta por comentario: se guarda el texto, quién lo respondió y cuándo, y el estado pasa a
 * `respondido` —que es lo que hace que el usuario la vea en su pantalla—.
 */
class ResponderComentario
{
    public function ejecutar(Comentario $comentario, Authenticatable $quienResponde, string $respuesta): Comentario
    {
        $comentario->update([
            'respuesta' => trim($respuesta),
            'estado' => Comentario::RESPONDIDO,
            'respondido_por' => $quienResponde->getAuthIdentifier(),
            'respondido_el' => now(),
        ]);

        return $comentario;
    }
}
