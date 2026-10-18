<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use App\Models\DibujoDelExamen;
use App\Models\EleccionDeImagenDelExamen;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * **El estado de la imagen de un examen** para una consulta (O-09): el **modo elegido** con el radiobutón, la **foto**
 * que se está marcando, los trazos del esquema y los de las fotos.
 *
 * Es la única clase que resuelve las tres reglas de la tarea, y por eso la usan la pantalla y la hoja impresa (así lo
 * que se ve es lo que se imprime):
 *
 *  1. **La elección manda.** Si el médico eligió un modo, ese es el que sale en el informe, aunque el estudio tenga
 *     datos del otro: lo no elegido no se imprime.
 *  2. **Abre en el modo que ya tiene datos** cuando todavía no eligió: si hay foto, el videocolposcopio; si no, el
 *     esquema.
 *  3. **Con varias fotos se trabaja sobre una**: la elegida, o la primera si la que estaba elegida ya no existe. El
 *     trazo de una foto nunca queda huérfano porque la clave del lienzo la referencia (`foto:<id>`) y quitarla se lleva
 *     su trazo (`App\Actions\Consulta\OlvidarElTrazoDeLaFoto`).
 *
 * Es de sólo lectura. El único archivo que mira es para saber el **tamaño de la foto** (el lienzo se dibuja en sus
 * proporciones); si no se puede leer, se usa el marco del esquema.
 */
final class ImagenDelExamenDeLaConsulta
{
    /** El marco del esquema y el que se usa cuando no se pueden leer las medidas de la foto (el de A-13). */
    private const MARCO_POR_DEFECTO = [520, 400];

    public function __construct(private ImagenesDeLaConsulta $imagenes)
    {
    }

    /**
     * Todo lo que la pantalla y la hoja necesitan, ya resuelto.
     *
     * @return object{
     *     examen:string, modo:string, foto:?object, fotos:Collection<int,object>,
     *     esquema:array<string,array{base:string, trazo:?string}>,
     *     trazos:array<int,string>, medidas:array<int,array{0:int,1:int}>
     * }
     */
    public function de(Consulta $consulta, string $examen): object
    {
        $filas = $this->dibujos($consulta, $examen);
        $fotos = $this->deUnExamen($consulta, $examen);

        $esquema = [];
        foreach (ImagenDelExamen::variantes($examen) as $variante) {
            $dibujo = $filas->get(ImagenDelExamen::claveDelEsquema($variante));

            $esquema[$variante] = [
                'base' => ImagenDelExamen::base($examen, $dibujo?->base),
                'trazo' => $dibujo?->archivo,
            ];
        }

        $trazos = [];
        foreach ($filas as $dibujo) {
            $imagen = ImagenDelExamen::imagenDeLaClave((string) $dibujo->clave);

            if ($imagen !== null && $dibujo->archivo !== null) {
                $trazos[$imagen] = $dibujo->archivo;
            }
        }

        $eleccion = $this->eleccion($consulta, $examen);
        $foto = $this->fotoElegida($fotos, $eleccion);

        return (object) [
            'examen' => $examen,
            'modo' => $this->modo($eleccion, $fotos, $esquema),
            'foto' => $foto,
            'fotos' => $fotos,
            'esquema' => $esquema,
            'trazos' => $trazos,
            'medidas' => $this->medidas($fotos),
        ];
    }

    /**
     * **Las fotos del examen en esta consulta** (las etiquetadas para él), en el orden del escritorio.
     *
     * @return Collection<int,object>
     */
    public function deUnExamen(Consulta $consulta, string $examen): Collection
    {
        return $this->imagenes->deUnExamen(
            (string) $consulta->reg_medico,
            (int) $consulta->numhistoria,
            (int) $consulta->nroconsulta,
            $examen,
        );
    }

    /**
     * Las filas de `dibujos_del_examen` de la consulta, por **clave del lienzo**.
     *
     * @return Collection<string,DibujoDelExamen>
     */
    public function dibujos(Consulta $consulta, string $examen): Collection
    {
        return DibujoDelExamen::where([
            'reg_medico' => $consulta->reg_medico,
            'nrohistoria' => (int) $consulta->numhistoria,
            'nroconsulta' => (int) $consulta->nroconsulta,
            'examen' => $examen,
        ])->get()->keyBy('clave');
    }

    /** La fila de la elección, o `null` si el médico todavía no eligió. */
    public function eleccion(Consulta $consulta, string $examen): ?EleccionDeImagenDelExamen
    {
        return EleccionDeImagenDelExamen::where([
            'reg_medico' => $consulta->reg_medico,
            'nrohistoria' => (int) $consulta->numhistoria,
            'nroconsulta' => (int) $consulta->nroconsulta,
            'examen' => $examen,
        ])->first();
    }

    /**
     * El modo que se ve y se imprime: el que eligió el médico y —si nunca eligió— el que ya tiene datos (la foto si hay
     * foto; el esquema si no).
     *
     * @param  array<string,array{base:string, trazo:?string}>  $esquema
     */
    private function modo(?EleccionDeImagenDelExamen $eleccion, Collection $fotos, array $esquema): string
    {
        if ($eleccion !== null && in_array($eleccion->modo, ImagenDelExamen::MODOS, true)) {
            return (string) $eleccion->modo;
        }

        return $fotos->isNotEmpty() ? ImagenDelExamen::MODO_FOTO : ImagenDelExamen::MODO_ESQUEMA;
    }

    /**
     * La foto que se está marcando: la elegida si sigue estando entre las del examen y, si no, la primera. `null` cuando
     * el estudio no tiene fotos.
     */
    private function fotoElegida(Collection $fotos, ?EleccionDeImagenDelExamen $eleccion): ?object
    {
        if ($fotos->isEmpty()) {
            return null;
        }

        $elegida = $eleccion?->imagen_id === null ? null : $fotos->firstWhere('id', (int) $eleccion->imagen_id);

        return $elegida ?? $fotos->first();
    }

    /**
     * Las medidas (ancho y alto en píxeles) de cada foto que el navegador puede mostrar: es el tamaño con el que se
     * dibuja su lienzo. Las que no se pueden leer (un HEIC, un archivo que ya no está) caen al marco del esquema.
     *
     * @param  Collection<int,object>  $fotos
     * @return array<int,array{0:int,1:int}>
     */
    private function medidas(Collection $fotos): array
    {
        $medidas = [];

        foreach ($fotos as $foto) {
            $medidas[(int) $foto->id] = $this->medidaDe($foto) ?? self::MARCO_POR_DEFECTO;
        }

        return $medidas;
    }

    /** @return array{0:int,1:int}|null */
    private function medidaDe(object $foto): ?array
    {
        if (! $foto->enElServidor || $foto->descarga) {
            return null;
        }

        try {
            $ruta = Storage::disk((string) $foto->disco)->path((string) $foto->archivo);
            $medida = @getimagesize($ruta);
        } catch (Throwable $e) {
            return null;
        }

        return is_array($medida) && $medida[0] > 0 && $medida[1] > 0 ? [(int) $medida[0], (int) $medida[1]] : null;
    }
}
