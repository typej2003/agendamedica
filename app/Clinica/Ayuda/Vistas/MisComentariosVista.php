<?php

namespace App\Clinica\Ayuda\Vistas;

use App\Clinica\Ayuda\ArchivosDeLosComentarios;
use App\Models\Comentario;
use App\Models\ComentarioImagen;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\ViewErrorBag;

/**
 * La pantalla **"Ayúdanos a mejorar"** de `/clinica`: el formulario para mandarnos un comentario y la lista de
 * los que ya mandó esa cuenta, con nuestra respuesta cuando la hay (ROADMAP §9).
 *
 * El ViewModel no consulta nada: recibe los comentarios ya leídos y los convierte en lo que se imprime —
 * textos, tono del estado y la respuesta—. Desde O-25 también las **miniaturas de las capturas adjuntas**, con
 * la URL autorizada que las sirve (la del autor del comentario; la del disco privado no es alcanzable).
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

    /** Cuántas imágenes admite un comentario (O-25): lo que dice la ayuda del campo. */
    public function maximoDeImagenes(): int
    {
        return Comentario::MAXIMO_IMAGENES;
    }

    /** "PNG, JPG o WEBP": los formatos que acepta la política de los adjuntos. */
    public function formatosAceptados(): string
    {
        return ArchivosDeLosComentarios::textosAceptados();
    }

    /** "5 MB": el tope de cada captura. */
    public function tamanoMaximo(): string
    {
        return ArchivosDeLosComentarios::textoMaximo();
    }

    /**
     * Los mensajes de error de las **imágenes** (O-25), que Laravel guarda por índice (`imagenes.0`) y por eso
     * no alcanza con `@error('imagenes')`: la pantalla los muestra todos juntos debajo del campo.
     *
     * @return array<int,string>
     */
    public function erroresDeImagenes(ViewErrorBag $errores): array
    {
        $mensajes = [];

        foreach ($errores->getBag('default')->getMessages() as $campo => $lista) {
            if (str_starts_with((string) $campo, 'imagenes.')) {
                foreach ((array) $lista as $mensaje) {
                    $mensajes[] = $mensaje;
                }
            }
        }

        return array_values(array_unique($mensajes));
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
            'cuando' => \App\Support\HoraLocal::fechaHora($comentario->created_at),
            'estado' => $this->estadoTexto($comentario),
            'tono' => $this->estadoTono($comentario),
            'respuesta' => $comentario->respuesta,
            'respondido' => $comentario->fueRespondido()
                ? 'Respondido el ' . (\App\Support\HoraLocal::instante($comentario->respondido_el)?->format('d/m/Y') ?? '')
                : null,
            // Lo que el usuario tiene que entender de un descartado: lo leímos y decidimos no avanzar (no es
            // que se haya perdido).
            'explicacion' => $comentario->fueDescartado()
                ? 'Lo revisamos y por ahora no vamos a avanzar con esto. Gracias igual: nos sirve para priorizar.'
                : null,
            // Las capturas que adjuntó, con la URL del autor (O-25). Se muestran como miniaturas abribles.
            'imagenes' => $this->imagenes($comentario),
        ])->all();
    }

    /**
     * Las miniaturas de un comentario, en el orden en que se adjuntaron.
     *
     * @return array<int, array{url: string, nombre: string}>
     */
    private function imagenes(Comentario $comentario): array
    {
        return $comentario->imagenes->map(fn (ComentarioImagen $imagen): array => [
            'url' => route('clinica.ayuda.imagen', ['comentario' => $comentario->id, 'imagen' => $imagen->id]),
            'nombre' => $imagen->nombreVisible(),
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
