<?php

namespace App\Clinica\Ayuda;

use App\Models\Comentario;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Los comentarios de **"Ayúdanos a mejorar"** que escribió una cuenta (ROADMAP §9).
 *
 * Cada uno ve los suyos: el médico los suyos y la secretaría los suyos. No es una bandeja compartida —esa es
 * nuestra, y vive en `/admin`—.
 */
final class ComentariosDelUsuario
{
    /**
     * @return Collection<int, Comentario>
     */
    public function de(Authenticatable $usuario, int $limite = 30): Collection
    {
        return Comentario::where('user_id', $usuario->getAuthIdentifier())
            ->orderByDesc('created_at')
            ->limit($limite)
            ->get();
    }
}
