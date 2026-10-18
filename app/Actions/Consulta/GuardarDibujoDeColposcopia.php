<?php

namespace App\Actions\Consulta;

use App\Clinica\Archivos\ArchivosClinicos;
use App\Models\Consulta;
use App\Models\DibujoDeColposcopia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * **Guardar el dibujo de la colposcopía** (A-13): el trazo de color que el médico marca sobre la imagen del cuello
 * uterino, **por consulta y por variante** (`nulipara` / `multipara`), con la **imagen base** elegida para esa
 * variante (`vector` / `escritorio`).
 *
 * Tres reglas, y las tres son decisiones de la tarea:
 *
 *  1. Se guarda **sólo el trazo** —un PNG transparente en el disco privado `clinica`—, no una captura con la imagen
 *     base adentro: así cambiar la base no pierde el dibujo (misma decisión que `GuardarDibujoDeMama`).
 *  2. La fila **se queda** aunque no haya trazo: es donde vive la base elegida, que tiene que sobrevivir al borrado
 *     del dibujo y a una recarga de la pantalla.
 *  3. Se puede guardar **la base sola** (sin archivo): es lo que hace la pantalla al cambiar de imagen base, y no
 *     toca el trazo que ya estaba guardado.
 *
 * La usan la web y —cuando dibuje— el móvil (táctil). La política del archivo es la de `ArchivosClinicos`, pero acá
 * sólo se acepta `image/png`.
 */
final class GuardarDibujoDeColposcopia
{
    /**
     * Guarda el trazo y/o la base de una variante.
     *
     * @param  string  $base  `vector` o `escritorio`
     * @param  UploadedFile|null  $archivo  el PNG del trazo; `null` para guardar sólo la base
     *
     * @throws InvalidArgumentException variante o base inválida, o el archivo no es un PNG
     * @throws RuntimeException no se pudo escribir en el disco
     */
    public function ejecutar(Consulta $consulta, string $variante, string $base, ?UploadedFile $archivo = null): DibujoDeColposcopia
    {
        $this->comprobarVariante($variante);
        $this->comprobarBase($base);

        $disco = Storage::disk(ArchivosClinicos::DISCO);
        $clave = $this->clave($consulta, $variante);
        $anterior = DibujoDeColposcopia::where($clave)->first();

        $ruta = null;

        if ($archivo !== null) {
            if ($archivo->getMimeType() !== 'image/png') {
                throw new InvalidArgumentException('El dibujo tiene que ser un PNG.');
            }

            $ruta = 'colposcopia/' . $consulta->reg_medico . '/' . (int) $consulta->numhistoria . '-'
                . (int) $consulta->nroconsulta . '-' . $variante . '-' . bin2hex(random_bytes(6)) . '.png';

            if (! $disco->putFileAs(dirname($ruta), $archivo, basename($ruta), ['visibility' => 'private'])) {
                throw new RuntimeException('No se pudo guardar el dibujo.');
            }
        }

        $valores = ['base' => $base];

        // Sin archivo se conserva el trazo que ya estaba: cambiar de base no puede borrarlo.
        if ($ruta !== null) {
            $valores['archivo'] = $ruta;
        }

        $dibujo = DibujoDeColposcopia::updateOrCreate($clave, $valores);

        if ($ruta !== null && $anterior !== null && $anterior->archivo !== null && $anterior->archivo !== $ruta) {
            $disco->delete($anterior->archivo);
        }

        return $dibujo;
    }

    /**
     * Quita **el trazo** de una variante (borra el archivo y deja `archivo = null`) pero **conserva la base elegida**:
     * el médico puede volver a dibujar mañana y la imagen que eligió sigue siendo la suya.
     *
     * @param  string|null  $base  la base a recordar; `null` para no tocarla
     */
    public function quitar(Consulta $consulta, string $variante, ?string $base = null): void
    {
        $this->comprobarVariante($variante);

        if ($base !== null) {
            $this->comprobarBase($base);
        }

        $clave = $this->clave($consulta, $variante);
        $dibujo = DibujoDeColposcopia::where($clave)->first();

        if ($dibujo === null) {
            // Sin fila no hay trazo: si la pantalla mandó la base (el médico la cambió sin dibujar), se recuerda.
            if ($base !== null) {
                DibujoDeColposcopia::create($clave + ['base' => $base, 'archivo' => null]);
            }

            return;
        }

        if ($dibujo->archivo !== null) {
            Storage::disk(ArchivosClinicos::DISCO)->delete($dibujo->archivo);
            $dibujo->archivo = null;
        }

        if ($base !== null) {
            $dibujo->base = $base;
        }

        $dibujo->save();
    }

    private function comprobarVariante(string $variante): void
    {
        if (! in_array($variante, DibujoDeColposcopia::VARIANTES, true)) {
            throw new InvalidArgumentException('Variante de colposcopía inválida.');
        }
    }

    private function comprobarBase(string $base): void
    {
        if (! in_array($base, DibujoDeColposcopia::BASES, true)) {
            throw new InvalidArgumentException('Imagen base inválida.');
        }
    }

    /** @return array<string,mixed> */
    private function clave(Consulta $consulta, string $variante): array
    {
        return [
            'reg_medico' => $consulta->reg_medico,
            'nrohistoria' => (int) $consulta->numhistoria,
            'nroconsulta' => (int) $consulta->nroconsulta,
            'variante' => $variante,
        ];
    }
}
