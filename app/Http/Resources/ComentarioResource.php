<?php

namespace App\Http\Resources;

use App\Models\Comentario;
use App\Models\ComentarioImagen;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un comentario de **"Ayúdanos a mejorar"** tal como lo ve el **app móvil** (ROADMAP §9).
 *
 * Es la contraparte de `MisComentariosVista`, que arma lo mismo para la web clínica: el app recibe el tipo ya
 * legible, el estado y la respuesta, y **las imágenes sin URL**. Sus archivos viven en el disco privado
 * `comentarios` (fuera de `public/`): el único camino a ellos es la ruta autorizada, que se pide con el id del
 * comentario y el de la imagen (`GET /api/app/comentarios/{comentario}/imagenes/{imagen}`). Mandar una URL
 * armada acá sería justamente lo que no se quiere (una captura puede traer datos de un paciente).
 *
 * @mixin Comentario
 */
class ComentarioResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'tipo_legible' => $this->tipoLegible(),
            'mensaje' => $this->mensaje,
            'estado' => $this->estado,
            'respuesta' => $this->respuesta,
            'respondido_el' => $this->respondido_el?->toIso8601String(),
            'creado_el' => $this->created_at?->toIso8601String(),
            // Las capturas adjuntas, en el orden en que se mandaron. `id` y `nombre` alcanzan: la miniatura se
            // pide por la ruta autorizada y el nombre es el del archivo del usuario, para mostrarlo.
            'imagenes' => $this->whenLoaded('imagenes', fn (): array => $this->imagenes
                ->map(fn (ComentarioImagen $imagen): array => [
                    'id' => $imagen->id,
                    'nombre' => $imagen->nombreVisible(),
                ])
                ->all()),
        ];
    }
}
