<?php

namespace App\Actions\Comentarios;

use App\Models\Comentario;
use App\Models\Medico;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Guarda un comentario de **"Ayúdanos a mejorar"** (ROADMAP §9).
 *
 * Es una Action y no código del controlador porque el **app móvil** también va a mandar comentarios por su
 * propio endpoint: la regla de qué queda guardado —de quién es, con qué rol y con qué contexto— tiene que
 * estar en un solo lugar, como el resto de lo que comparten la web y el sync.
 */
class EnviarComentario
{
    /**
     * @param  array{tipo: string, mensaje: string, contexto?: array|null}  $datos
     * @param  string  $origen  `web` (hoy) o `app` (cuando el móvil lo mande)
     */
    public function ejecutar(Authenticatable $usuario, array $datos, string $origen = 'web'): Comentario
    {
        $medico = $this->medicoDe($usuario);

        return Comentario::create([
            'user_id' => $usuario->getAuthIdentifier(),
            'medico_id' => $medico?->id,
            // El `reg_medico` se copia: sirve para saber de qué consultorio salió el comentario aunque después
            // la cuenta cambie de médico.
            'reg_medico' => $medico?->regMedicoPrincipal() ?? $medico?->reg_medico,
            'rol' => $usuario->getRoleNames()->first(),
            'origen' => $origen,
            'tipo' => $datos['tipo'],
            'mensaje' => $datos['mensaje'],
            'contexto' => $datos['contexto'] ?? null,
            'estado' => Comentario::NUEVO,
        ]);
    }

    /**
     * El médico de la cuenta, si es una cuenta de médico. La secretaría no tiene: su comentario queda con el
     * usuario y el rol, que es lo que necesitamos para responderle.
     *
     * Mismo criterio que el sync del app: por `user_id` o por el correo (hay cuentas viejas sin el vínculo).
     */
    private function medicoDe(Authenticatable $usuario): ?Medico
    {
        return Medico::where('user_id', $usuario->getAuthIdentifier())
            ->orWhere('email', $usuario->email ?? null)
            ->first();
    }
}
