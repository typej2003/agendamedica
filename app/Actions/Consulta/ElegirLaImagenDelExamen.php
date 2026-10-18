<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\ImagenDelExamen;
use App\Models\Consulta;
use App\Models\EleccionDeImagenDelExamen;
use InvalidArgumentException;

/**
 * **Elegir qué imagen del examen se ve y se imprime** (O-09): el radiobutón entre la **imagen predeterminada** (el
 * esquema) y el **videocolposcopio** (la foto real), y —cuando hay más de una foto— cuál de ellas se está marcando.
 *
 * Es una escritura propia y no un detalle de la pantalla porque **la elección decide la hoja impresa**: si el médico marcó
 * la foto, el informe imprime la foto; si marcó el esquema, imprime el esquema, aunque el estudio tenga lo otro.
 *
 * Una fila por consulta y examen (`elecciones_de_imagen_del_examen`). El `imagen_id` se conserva al volver al esquema
 * para que el médico no pierda cuál estaba marcando, y se limpia solo si la foto se quita.
 */
final class ElegirLaImagenDelExamen
{
    /**
     * @param  string  $modo  `esquema` o `foto`
     * @param  int|null  $imagen  la foto elegida (sólo cuando hay fotos)
     *
     * @throws InvalidArgumentException modo inválido o examen sin imagen editable
     */
    public function ejecutar(Consulta $consulta, string $examen, string $modo, ?int $imagen = null): EleccionDeImagenDelExamen
    {
        if (! ImagenDelExamen::existe($examen)) {
            throw new InvalidArgumentException('Examen sin imagen editable.');
        }

        if (! in_array($modo, ImagenDelExamen::MODOS, true)) {
            throw new InvalidArgumentException('Modo de imagen inválido.');
        }

        return EleccionDeImagenDelExamen::updateOrCreate(
            [
                'reg_medico' => $consulta->reg_medico,
                'nrohistoria' => (int) $consulta->numhistoria,
                'nroconsulta' => (int) $consulta->nroconsulta,
                'examen' => $examen,
            ],
            ['modo' => $modo, 'imagen_id' => $imagen],
        );
    }
}
