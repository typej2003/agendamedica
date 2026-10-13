<?php

namespace App\Actions\Imagenes;

use App\Clinica\Archivos\ArchivosClinicos;
use App\Models\ImagenDeLaPc;
use App\Sync\ImagenesPc\CatalogoDeImagenes;
use App\Sync\ImagenesPc\ReferenciasDeImagenes;
use Illuminate\Support\Facades\Storage;

/**
 * **Registrar que una imagen de la PC no se va a subir** (WEB-T.1): la herramienta no la encontró, no es una imagen o pesa
 * demasiado. Queda como `omitida` con su `motivo` para que `pendientes` no la vuelva a pedir cada vez (se reintenta con
 * `reintentar_omitidas`). **No toca la fila legada**: sigue con su ruta de la PC, sin romperse.
 *
 * Nunca baja de `subida` a `omitida` un archivo que ya está en la nube para el valor actual de la fila.
 */
final class OmitirImagenDeLaPc
{
    /** Los motivos que acepta el API; cualquier otro se guarda como `otro`. Son los mismos que usa la herramienta. */
    public const MOTIVOS = ['no_existe', 'tipo_no_permitido', 'demasiado_grande', 'no_legible', 'ruta_no_valida', 'otro'];

    public function __construct(
        private CatalogoDeImagenes $catalogo,
        private ReferenciasDeImagenes $referencias,
    ) {
    }

    public function ejecutar(
        string $regMedico,
        string $tabla,
        int $filaId,
        string $columna,
        string $rutaPc,
        string $motivo,
        ?string $equipo = null,
    ): ResultadoDeImagenDeLaPc {
        $rutaPc = trim($rutaPc);
        $motivo = in_array($motivo, self::MOTIVOS, true) ? $motivo : 'otro';

        if (! $this->catalogo->permite($tabla, $columna)) {
            return ResultadoDeImagenDeLaPc::error('tabla_no_permitida', 422, 'Esa tabla o columna no guarda imágenes de la PC.');
        }

        $celda = $this->referencias->celda($regMedico, $tabla, $filaId, $columna);
        if ($celda === null) {
            return ResultadoDeImagenDeLaPc::error('fila_inexistente', 404, 'No hay una fila así para este médico.');
        }
        $previo = ImagenDeLaPc::where('reg_medico', $regMedico)->where('tabla', $tabla)
            ->where('fila_id', $filaId)->where('columna', $columna)->first();

        // Lo ya subido para este mismo valor no se degrada (incluida una fila ya migrada, que hoy guarda la ruta de la nube).
        if ($previo !== null && $previo->estaSubida() && ($previo->ruta_pc === $rutaPc || $previo->ruta === $rutaPc)
            && ((string) $celda->ruta === $previo->ruta_pc || (string) $celda->ruta === $previo->ruta)) {
            return ResultadoDeImagenDeLaPc::exito('ya_estaba_subida', [], 'Esa imagen ya está subida: no se omite.');
        }

        if ((string) $celda->ruta !== $rutaPc) {
            return ResultadoDeImagenDeLaPc::error('fila_cambio', 409, 'La ruta de la fila cambió desde que se pidió la lista.');
        }

        // Un archivo subido para una ruta anterior de la fila ya no corresponde a nada.
        if ($previo !== null && $previo->estaSubida() && $previo->ruta !== null && ArchivosClinicos::esRutaDelDisco($previo->ruta)) {
            Storage::disk($previo->disco ?: ArchivosClinicos::DISCO)->delete($previo->ruta);
        }

        $registro = $previo ?? new ImagenDeLaPc([
            'reg_medico' => $regMedico, 'tabla' => $tabla, 'fila_id' => $filaId, 'columna' => $columna,
        ]);
        $registro->fill([
            'ruta_pc' => $rutaPc,
            'estado' => ImagenDeLaPc::OMITIDA,
            'motivo' => $motivo,
            'disco' => null,
            'ruta' => null,
            'tipo' => null,
            'tamano' => null,
            'sha256' => null,
            'equipo' => $equipo !== null ? mb_substr($equipo, 0, 100) : null,
        ])->save();

        return ResultadoDeImagenDeLaPc::exito('omitida', ['tabla' => $tabla, 'fila_id' => $filaId, 'columna' => $columna, 'motivo' => $motivo], 'Registrada como omitida.');
    }
}
