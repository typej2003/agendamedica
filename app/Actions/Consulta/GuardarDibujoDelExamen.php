<?php

namespace App\Actions\Consulta;

use App\Clinica\Archivos\ArchivosClinicos;
use App\Clinica\Consulta\ImagenDelExamen;
use App\Models\Consulta;
use App\Models\DibujoDelExamen;
use App\Models\EleccionDeImagenDelExamen;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * **Guardar el trazo de la imagen de un examen** (A-13, generalizado en O-09): el dibujo de color que el médico marca
 * sobre el **esquema** (por variante) o sobre la **foto real** del estudio, por consulta.
 *
 * Las reglas, y las tres son decisiones de la tarea:
 *
 *  1. Se guarda **sólo el trazo** —un PNG transparente en el disco privado `clinica`—, no una captura con la imagen de
 *     abajo: así cambiar la base del esquema no pierde el dibujo y la foto no se duplica.
 *  2. La fila del **esquema** se queda aunque no haya trazo: es donde vive la base elegida, que tiene que sobrevivir al
 *     borrado del dibujo y a una recarga de la pantalla. La de una **foto** no: sin trazo no hay nada que recordar de
 *     ella (la foto elegida vive en `elecciones_de_imagen_del_examen`).
 *  3. Se puede guardar **la base sola** (sin archivo): es lo que hace la pantalla al cambiar de imagen base, y no toca el
 *     trazo que ya estaba guardado.
 *
 * La usan la web y —cuando dibuje— el móvil (táctil). La política del archivo es la de `ArchivosClinicos`, pero acá sólo
 * se acepta `image/png`.
 */
final class GuardarDibujoDelExamen
{
    /**
     * Guarda el trazo y/o la base del **esquema** de una variante.
     *
     * @param  string|null  $base  `vector` o `escritorio`; `null` para no tocar la que estaba
     * @param  UploadedFile|null  $archivo  el PNG del trazo; `null` para guardar sólo la base
     *
     * @throws InvalidArgumentException variante, base o examen inválido, o el archivo no es un PNG
     * @throws RuntimeException no se pudo escribir en el disco
     */
    public function guardarEsquema(
        Consulta $consulta,
        string $examen,
        string $variante,
        ?string $base = null,
        ?UploadedFile $archivo = null,
    ): DibujoDelExamen {
        $this->comprobarExamen($examen);

        if (! ImagenDelExamen::esVariante($examen, $variante)) {
            throw new InvalidArgumentException('Variante inválida para este examen.');
        }

        $base = $base === null ? null : ImagenDelExamen::base($examen, $base);

        if ($base !== null && ! in_array($base, array_keys(ImagenDelExamen::bases($examen)), true)) {
            throw new InvalidArgumentException('Imagen base inválida.');
        }

        return $this->guardar(
            $consulta,
            $examen,
            ImagenDelExamen::claveDelEsquema($variante),
            ['variante' => $variante, 'imagen_id' => null],
            $base,
            $archivo,
        );
    }

    /**
     * Guarda —o quita— el trazo de una **foto** del estudio. Un trazo vacío no deja fila: la foto sin marcar no necesita
     * nada guardado.
     *
     * @throws InvalidArgumentException examen inválido, o el archivo no es un PNG
     * @throws RuntimeException no se pudo escribir en el disco
     */
    public function guardarFoto(Consulta $consulta, string $examen, int $imagen, ?UploadedFile $archivo = null): ?DibujoDelExamen
    {
        $this->comprobarExamen($examen);

        if ($archivo === null) {
            $this->quitarFoto($consulta, $examen, $imagen);

            return null;
        }

        return $this->guardar(
            $consulta,
            $examen,
            ImagenDelExamen::claveDeLaFoto($imagen),
            ['variante' => null, 'imagen_id' => $imagen],
            null,
            $archivo,
        );
    }

    /**
     * Quita **el trazo** de una variante del esquema (borra el archivo y deja `archivo = null`) pero **conserva la fila y
     * la base elegida**: el médico puede volver a dibujar mañana y la imagen que eligió sigue siendo la suya.
     */
    public function quitarEsquema(Consulta $consulta, string $examen, string $variante, ?string $base = null): void
    {
        $this->comprobarExamen($examen);

        if (! ImagenDelExamen::esVariante($examen, $variante)) {
            throw new InvalidArgumentException('Variante inválida para este examen.');
        }

        $base = $base === null ? null : ImagenDelExamen::base($examen, $base);
        $clave = $this->clave($consulta, $examen, ImagenDelExamen::claveDelEsquema($variante));
        $dibujo = DibujoDelExamen::where($clave)->first();

        if ($dibujo === null) {
            // Sin fila no hay trazo: si la pantalla mandó la base (el médico la cambió sin dibujar), se recuerda.
            if ($base !== null) {
                DibujoDelExamen::create($clave + [
                    'examen' => $examen,
                    'clave' => ImagenDelExamen::claveDelEsquema($variante),
                    'variante' => $variante,
                    'base' => $base,
                    'archivo' => null,
                ]);
            }

            return;
        }

        $this->borrarArchivo($dibujo);
        $dibujo->archivo = null;

        if ($base !== null) {
            $dibujo->base = $base;
        }

        $dibujo->save();
    }

    /**
     * Quita el trazo de una foto. La foto **no** se toca: sigue en `imagen_consulta` (esto sólo borra la marca). Lo usa
     * la pantalla cuando el médico borra todo el trazo de la foto, y el borrado de la foto para no dejar huérfanos.
     */
    public function quitarFoto(Consulta $consulta, string $examen, int $imagen): void
    {
        $dibujo = DibujoDelExamen::where($this->clave($consulta, $examen, ImagenDelExamen::claveDeLaFoto($imagen)))->first();

        if ($dibujo === null) {
            return;
        }

        $this->borrarArchivo($dibujo);
        $dibujo->delete();
    }

    /**
     * El guardado común: escribe el PNG (si vino), y actualiza o crea la fila del lienzo.
     *
     * @param  array<string,mixed>  $extra  las columnas propias del lienzo (`variante`, `imagen_id`)
     */
    private function guardar(
        Consulta $consulta,
        string $examen,
        string $lienzo,
        array $extra,
        ?string $base,
        ?UploadedFile $archivo,
    ): DibujoDelExamen {
        $disco = Storage::disk(ArchivosClinicos::DISCO);
        $clave = $this->clave($consulta, $examen, $lienzo);
        $anterior = DibujoDelExamen::where($clave)->first();

        $ruta = null;

        if ($archivo !== null) {
            if ($archivo->getMimeType() !== 'image/png') {
                throw new InvalidArgumentException('El dibujo tiene que ser un PNG.');
            }

            $ruta = 'dibujos-del-examen/' . $consulta->reg_medico . '/' . (int) $consulta->numhistoria . '-'
                . (int) $consulta->nroconsulta . '-' . $examen . '-' . str_replace(':', '-', $lienzo) . '-'
                . bin2hex(random_bytes(6)) . '.png';

            if (! $disco->putFileAs(dirname($ruta), $archivo, basename($ruta), ['visibility' => 'private'])) {
                throw new RuntimeException('No se pudo guardar el dibujo.');
            }
        }

        $valores = [];

        // Sin base no se toca la que estaba (la columna tiene su valor por defecto al crear).
        if ($base !== null) {
            $valores['base'] = $base;
        }

        // Sin archivo se conserva el trazo que ya estaba: cambiar de base no puede borrarlo.
        if ($ruta !== null) {
            $valores['archivo'] = $ruta;
        }

        $dibujo = DibujoDelExamen::updateOrCreate($clave, $valores + $extra);

        if ($ruta !== null && $anterior !== null && $anterior->archivo !== null && $anterior->archivo !== $ruta) {
            $disco->delete($anterior->archivo);
        }

        return $dibujo;
    }

    private function borrarArchivo(DibujoDelExamen $dibujo): void
    {
        if ($dibujo->archivo !== null && $dibujo->archivo !== '') {
            Storage::disk(ArchivosClinicos::DISCO)->delete($dibujo->archivo);
        }
    }

    private function comprobarExamen(string $examen): void
    {
        if (! ImagenDelExamen::existe($examen)) {
            throw new InvalidArgumentException('Examen sin imagen editable.');
        }
    }

    /** @return array<string,mixed> */
    private function clave(Consulta $consulta, string $examen, string $lienzo): array
    {
        return [
            'reg_medico' => $consulta->reg_medico,
            'nrohistoria' => (int) $consulta->numhistoria,
            'nroconsulta' => (int) $consulta->nroconsulta,
            'examen' => $examen,
            'clave' => $lienzo,
        ];
    }
}
