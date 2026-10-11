<?php

namespace App\Clinica\Impresion;

use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Evolucion;
use App\Models\Medico;
use App\Models\RecipeFormato;
use Illuminate\Support\Facades\Storage;

/**
 * **Lee el membrete del médico** para imprimir (WEB-5.2): el formato de `recipe_formatos` (alineación, fuente,
 * negrita, itálica, subrayado, borde y visibilidad de cada bloque; color de la línea; firma y sello) y los datos
 * de `evolucion` (especialidad, RIF, logo y las tres líneas de pie), que son la misma configuración que el
 * móvil edita en "Diseño de reportes" y "Formato del récipe".
 *
 * Ni el formato ni los datos se guardan bajo el `reg_medico` que se esté mirando sino bajo el **principal**
 * del médico (`Medico::regMedicoPrincipal()`), como lo hacen los controladores del API que los escriben: así
 * un médico con varios registros imprime con **un** solo membrete, y su asistente también.
 *
 * Las imágenes (logo, firma y sello) viven en el disco `public` y se sirven por `/storage/…`; si el archivo ya no
 * está en el disco no se pinta (una imagen rota en una receta no sirve a nadie). La configuración **se edita
 * desde el móvil o el API** (la pantalla web es WEB-8.2): esta clase sólo la lee.
 */
final class MembreteDelMedico
{
    public function de(Medico $medico): Membrete
    {
        $registro = $medico->regMedicoPrincipal();

        $formato = $registro === null ? null : RecipeFormato::where('reg_medico', $registro)->first();
        $evolucion = $registro === null ? null : Evolucion::where('reg_medico', $registro)->orderBy('id')->first();

        return new Membrete(
            $medico->nombreMostrar,
            CamposDePaciente::texto($evolucion->especialidad ?? null),
            CamposDePaciente::texto($evolucion->rif ?? null),
            $this->urlDe($evolucion->logo ?? null),
            array_values(array_filter([
                CamposDePaciente::texto($evolucion->linea_1 ?? null),
                CamposDePaciente::texto($evolucion->linea_2 ?? null),
                CamposDePaciente::texto($evolucion->linea_3 ?? null),
            ])),
            ($formato ?? new RecipeFormato())->elementosResueltos(),
            in_array($formato->color_linea ?? null, RecipeFormato::COLORES_LINEA, true) ? $formato->color_linea : 'negro',
            $this->urlDe($formato->firma ?? null),
            $this->urlDe($formato->sello ?? null),
            in_array($formato->sello_posicion ?? null, RecipeFormato::POSICIONES_SELLO, true) ? $formato->sello_posicion : 'centro',
        );
    }

    /** Las opciones de impresión que dejó la última vez cualquiera que imprimió con este médico. */
    public function opciones(Medico $medico): OpcionesDeRecipe
    {
        $registro = $medico->regMedicoPrincipal();

        return OpcionesDeRecipe::desde($registro === null ? null : RecipeFormato::where('reg_medico', $registro)->first());
    }

    /** `/storage/<ruta>` si el archivo está en el disco público; `null` si no hay ruta o el archivo no existe. */
    private function urlDe(?string $ruta): ?string
    {
        $ruta = CamposDePaciente::texto($ruta);

        if ($ruta === null || ! Storage::disk('public')->exists($ruta)) {
            return null;
        }

        return '/storage/' . ltrim($ruta, '/');
    }
}
