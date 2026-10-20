<?php

namespace App\Clinica\Impresion;

use App\Clinica\Pacientes\CamposDePaciente;
use App\Clinica\Pacientes\FamiliaDelPaciente;
use App\Clinica\Pacientes\RepresentanteDelPaciente;

/**
 * **El representante que sale en la cabecera de un impreso de pediatría** (2026-10-20): el «Rep.: ELDRIS MENDOZA» del
 * récipe del escritorio.
 *
 * Las fuentes están en orden, y la primera que tenga un nombre gana:
 *
 *  1. **`representante.nombre`**, la tabla de la web (WEB-3.4, `RepresentanteDelPaciente`): es lo que carga la ficha del
 *     paciente y manda sobre lo que haya en el escritorio.
 *  2. La **familia del escritorio de pediatría** (`familia_bebe`, `FamiliaDelPaciente`): `nom_madre` o `nom_padre` según
 *     `status` (`'F'` la madre, `'M'` el padre), que es la misma convención de `RepresentantePorCedula`. Un `status` que no
 *     diga quién es el representante no elige ninguno de los dos nombres: no se inventa.
 *
 * Si no hay nombre en ninguna de las dos, devuelve `null` y la hoja **no imprime el rótulo** (no queda un «Rep.:»
 * colgando).
 *
 * Es de **sólo lectura** y no sabe de HTTP ni de hojas: devuelve el nombre para que lo ponga la cabecera.
 */
final class RepresentanteParaLaHoja
{
    public function __construct(
        private RepresentanteDelPaciente $representantes,
        private FamiliaDelPaciente $familias,
    ) {
    }

    /**
     * El nombre del representante de una historia, o `null` si no hay ninguno cargado.
     *
     * @param  mixed  $numeroDeHistoria  el número de historia del paciente (el de la consulta)
     */
    public function nombre(string $regMedico, $numeroDeHistoria): ?string
    {
        $historia = CamposDePaciente::texto($numeroDeHistoria);

        if ($historia === null) {
            return null;
        }

        $deLaWeb = CamposDePaciente::texto($this->representantes->de($regMedico, $historia)?->nombre);

        if ($deLaWeb !== null) {
            return $deLaWeb;
        }

        $familia = $this->familias->de($regMedico, $historia)['familia'] ?? null;

        if ($familia === null) {
            return null;
        }

        $status = mb_strtoupper(trim((string) $familia->status));

        return match ($status) {
            'F' => CamposDePaciente::texto($familia->nom_madre),
            'M' => CamposDePaciente::texto($familia->nom_padre),
            default => null,
        };
    }
}
