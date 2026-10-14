<?php

namespace App\Clinica\Ayuda\Vistas;

use App\Models\Comentario;
use Illuminate\Database\Eloquent\Collection;

/**
 * La pantalla **"Ayúdanos a mejorar"** de `/clinica`: el formulario para mandarnos un comentario y la lista de
 * los que ya mandó esa cuenta, con nuestra respuesta cuando la hay (ROADMAP §9).
 *
 * El ViewModel no consulta nada: recibe los comentarios ya leídos y los convierte en lo que se imprime —
 * textos, tono del estado y la respuesta—.
 */
final class MisComentariosVista
{
    /**
     * @param  Collection<int, Comentario>  $comentarios
     */
    public function __construct(private Collection $comentarios)
    {
    }

    /** Los tipos que se pueden elegir en el formulario: valor => lo que se lee. */
    public function tipos(): array
    {
        return Comentario::TIPOS;
    }

    public function hayComentarios(): bool
    {
        return $this->comentarios->isNotEmpty();
    }

    /**
     * Una fila por comentario, ya lista para pintar: de lo más nuevo a lo más viejo (lo ordena la consulta).
     *
     * @return array<int, array<string, mixed>>
     */
    public function filas(): array
    {
        return $this->comentarios->map(fn (Comentario $comentario): array => [
            'tipo' => $comentario->tipoLegible(),
            'mensaje' => $comentario->mensaje,
            'cuando' => $comentario->created_at?->format('d/m/Y H:i') ?? '',
            'estado' => $this->estadoTexto($comentario),
            'tono' => $this->estadoTono($comentario),
            'respuesta' => $comentario->respuesta,
            'respondido' => $comentario->fueRespondido()
                ? 'Respondido el ' . ($comentario->respondido_el?->format('d/m/Y') ?? '')
                : null,
            // Lo que el usuario tiene que entender de un descartado: lo leímos y decidimos no avanzar (no es
            // que se haya perdido).
            'explicacion' => $comentario->fueDescartado()
                ? 'Lo revisamos y por ahora no vamos a avanzar con esto. Gracias igual: nos sirve para priorizar.'
                : null,
        ])->all();
    }

    private function estadoTexto(Comentario $comentario): string
    {
        if ($comentario->fueRespondido()) {
            return 'Respondido';
        }
        if ($comentario->fueDescartado()) {
            return 'Revisado';
        }

        return 'Recibido';
    }

    private function estadoTono(Comentario $comentario): string
    {
        if ($comentario->fueRespondido()) {
            return 'exito';
        }
        if ($comentario->fueDescartado()) {
            return 'neutro';
        }

        return 'info';
    }
}
