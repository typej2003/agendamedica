<?php

namespace App\Clinica\Impresion;

use App\Models\Evolucion;
use App\Models\Medico;
use App\Models\RecipeFormato;

/**
 * **Lee el formato de impresión del médico** para la pantalla que lo edita (WEB-8.2): sus filas de `evolucion` y de
 * `recipe_formatos` bajo el **registro principal** y el membrete resuelto.
 *
 * Es la misma lectura que hace la hoja (`MembreteDelMedico`) —mismas filas, mismo registro—, así que lo que la pantalla
 * muestra y lo que se imprime no pueden divergir. Sólo agrega las filas crudas, que el formulario necesita para saber si
 * un texto está vacío o si el médico ya guardó su formato alguna vez.
 *
 * Es de **sólo lectura**.
 */
final class FormatoDelMedico
{
    public function __construct(private MembreteDelMedico $membretes)
    {
    }

    public function de(Medico $medico): FormatoGuardado
    {
        $registro = (string) $medico->regMedicoPrincipal();

        return new FormatoGuardado(
            $registro,
            $registro === '' ? null : Evolucion::where('reg_medico', $registro)->orderBy('id')->first(),
            $registro === '' ? null : RecipeFormato::where('reg_medico', $registro)->first(),
            $this->membretes->de($medico),
        );
    }
}
