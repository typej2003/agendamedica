<?php

namespace App\Actions\Consulta;

use App\Clinica\Archivos\ArchivosClinicos;
use App\Models\Consulta;
use App\Models\DibujoDeMama;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * **Guardar el dibujo de una mama** (WEB-11.10): el trazo de color que el médico marca sobre la imagen base de la mama derecha o
 * izquierda, **por consulta** (cada visita tiene su dibujo y se ve la evolución). Se guarda sólo el trazo, como un PNG
 * transparente en el disco privado `clinica`; **no se imprime** (Alexander, 2026-10-15). Un dibujo nuevo reemplaza al anterior del
 * mismo lado. La usa la web y la usará el móvil (táctil).
 *
 * La política del archivo (tamaño, disco) es la de `ArchivosClinicos`, pero acá sólo se acepta `image/png`.
 */
final class GuardarDibujoDeMama
{
    /**
     * @throws InvalidArgumentException lado inválido o el archivo no es un PNG
     * @throws RuntimeException no se pudo escribir en el disco
     */
    public function ejecutar(Consulta $consulta, string $lado, UploadedFile $archivo): DibujoDeMama
    {
        if (! in_array($lado, DibujoDeMama::LADOS, true)) {
            throw new InvalidArgumentException('Lado de mama inválido.');
        }
        if ($archivo->getMimeType() !== 'image/png') {
            throw new InvalidArgumentException('El dibujo tiene que ser un PNG.');
        }

        $disco = Storage::disk(ArchivosClinicos::DISCO);
        $ruta = 'mamas/' . $consulta->reg_medico . '/' . (int) $consulta->numhistoria . '-' . (int) $consulta->nroconsulta
            . '-' . $lado . '-' . bin2hex(random_bytes(6)) . '.png';

        if (! $disco->putFileAs(dirname($ruta), $archivo, basename($ruta), ['visibility' => 'private'])) {
            throw new RuntimeException('No se pudo guardar el dibujo.');
        }

        $clave = $this->clave($consulta, $lado);
        $anterior = DibujoDeMama::where($clave)->first();
        $dibujo = DibujoDeMama::updateOrCreate($clave, ['archivo' => $ruta]);

        if ($anterior && $anterior->archivo !== $ruta) {
            $disco->delete($anterior->archivo);
        }

        return $dibujo;
    }

    /** Quita el dibujo de un lado (el archivo y la fila). */
    public function quitar(Consulta $consulta, string $lado): void
    {
        $dibujo = DibujoDeMama::where($this->clave($consulta, $lado))->first();

        if ($dibujo) {
            Storage::disk(ArchivosClinicos::DISCO)->delete($dibujo->archivo);
            $dibujo->delete();
        }
    }

    /** @return array<string,mixed> */
    private function clave(Consulta $consulta, string $lado): array
    {
        return [
            'reg_medico' => $consulta->reg_medico,
            'nrohistoria' => (int) $consulta->numhistoria,
            'nroconsulta' => (int) $consulta->nroconsulta,
            'lado' => $lado,
        ];
    }
}
