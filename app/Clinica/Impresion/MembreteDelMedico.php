<?php

namespace App\Clinica\Impresion;

use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Evolucion;
use App\Models\Medico;
use App\Models\RecipeFormato;
use Illuminate\Support\Facades\Storage;

/**
 * **Lee el membrete con el que se imprime** (WEB-5.2 y Fase 5 del cambio de `reg_medico`): el formato de
 * `recipe_formatos` (alineación, fuente, negrita, itálica, subrayado, borde y visibilidad de cada bloque; color de
 * la línea; firma y sello) y los datos de `evolucion` (especialidad, RIF, logo y las tres líneas de pie), que son
 * la misma configuración que el móvil edita en "Diseño de reportes" y "Formato del récipe".
 *
 * Tiene **dos lecturas**, y las dos viven acá para que lo que se configura y lo que se imprime no pueda divergir:
 *
 *  - `de()` — **el formato del médico en su consultorio personal** (y el de la pantalla «Formato de impresión»):
 *    sus filas bajo su registro principal (`Medico::registroDelFormato()`), con su firma y su sello.
 *  - `deLaImpresion()` — **el formato de una impresión del contexto o de la cita** (Fase 5): si ese `reg_medico`
 *    es el de un **centro** (`medicos.es_centro = 1`) y el centro eligió `formato_reportes = 'centro'`, el
 *    membrete, el logo, el pie y el aspecto de los bloques salen de **las filas del centro** (bajo su propio
 *    `reg_medico`, como las del doctor bajo el suyo) y **la firma, el sello y el nombre de la línea de la firma
 *    son del doctor que atendió**. Si el centro eligió `'medico'` —o el consultorio es personal— sale el formato
 *    del doctor. Es la decisión del usuario: el membrete puede ser del centro, la firma nunca.
 *
 * Las imágenes (logo, firma y sello) viven en el disco `public` y se sirven por `/storage/…`; si el archivo ya no
 * está en el disco no se pinta (una imagen rota en una receta no sirve a nadie). La configuración **se edita desde
 * el móvil, el API y la pantalla «Formato de impresión» de la web** (WEB-8.2, `App\Actions\Configuracion\`): esta
 * clase sólo la lee.
 */
final class MembreteDelMedico
{
    /** El formato del médico en su consultorio personal (y el de la pantalla «Formato de impresión»). */
    public function de(Medico $medico): Membrete
    {
        $registro = $medico->registroDelFormato();

        return $this->arma($medico->nombreMostrar, null, $registro, $registro);
    }

    /**
     * El membrete de lo que se imprime en un **contexto o una cita**: el del centro si ese `reg_medico` es un
     * centro con `formato_reportes = 'centro'`, y el del doctor que firma en cualquier otro caso.
     *
     * @param  Medico  $firmante  el doctor que atendió: de él salen la firma, el sello y el nombre que firma
     * @param  string|null  $regMedico  el `reg_medico` del contexto/de la cita (el centro o el personal)
     */
    public function deLaImpresion(Medico $firmante, ?string $regMedico): Membrete
    {
        $centro = $this->centroConSuPropioFormato($regMedico);

        if ($centro === null) {
            return $this->de($firmante);
        }

        return $this->arma(
            $centro->nombreMostrar,
            $firmante->nombreMostrar,
            $centro->registroDelFormato(),
            $firmante->registroDelFormato(),
        );
    }

    /**
     * Las opciones de impresión que dejó la última vez cualquiera que imprimió con este médico.
     *
     * Son **del médico y no del membrete**: se guardan bajo su registro principal (`GuardarOpcionesDeImpresion`),
     * así que se leen de ahí aunque la hoja salga con el membrete del centro.
     */
    public function opciones(Medico $medico): OpcionesDeRecipe
    {
        $registro = $medico->regMedicoPrincipal();

        return OpcionesDeRecipe::desde($registro === null ? null : RecipeFormato::where('reg_medico', $registro)->first());
    }

    // ----------------------------------------------------------------------------------------- Adentro

    /**
     * El **centro** dueño de ese `reg_medico`, sólo si eligió imprimir con su propio formato. `null` cuando el
     * consultorio es personal o el centro dejó `formato_reportes` en `medico` (o sin elegir).
     */
    private function centroConSuPropioFormato(?string $regMedico): ?Medico
    {
        $regMedico = CamposDePaciente::texto($regMedico);

        if ($regMedico === null) {
            return null;
        }

        $centro = Medico::where('reg_medico', $regMedico)
            ->where('es_centro', true)
            ->orderBy('id')
            ->first();

        return $centro !== null && $centro->formato_reportes === Medico::FORMATO_DEL_CENTRO ? $centro : null;
    }

    /**
     * Arma el membrete con **dos fichas**: la del formato (nombre, datos, logo, pie y aspecto de los bloques) y la
     * de la firma (la imagen de la firma, el sello y su posición). Con una sola ficha —el caso normal— las dos son
     * la misma y se lee una sola vez.
     *
     * @param  string|null  $nombreDelFirmante  el nombre que firma, si es otro que el del membrete
     */
    private function arma(
        string $nombreDelMembrete,
        ?string $nombreDelFirmante,
        ?string $registroDelFormato,
        ?string $registroDeLaFirma,
    ): Membrete {
        $formato = $this->formatoDe($registroDelFormato);
        $evolucion = $registroDelFormato === null ? null : Evolucion::where('reg_medico', $registroDelFormato)->orderBy('id')->first();

        $deLaFirma = $registroDeLaFirma === $registroDelFormato ? $formato : $this->formatoDe($registroDeLaFirma);

        return new Membrete(
            $nombreDelMembrete,
            CamposDePaciente::texto($evolucion->especialidad ?? null),
            CamposDePaciente::texto($evolucion->rif ?? null),
            $this->urlDe($evolucion->logo ?? null),
            array_values(array_filter([
                CamposDePaciente::texto($evolucion->linea_1 ?? null),
                CamposDePaciente::texto($evolucion->linea_2 ?? null),
                CamposDePaciente::texto($evolucion->linea_3 ?? null),
            ])),
            (($formato ?? new RecipeFormato()))->elementosResueltos(),
            in_array($formato->color_linea ?? null, RecipeFormato::COLORES_LINEA, true) ? $formato->color_linea : 'negro',
            $this->urlDe($deLaFirma->firma ?? null),
            $this->urlDe($deLaFirma->sello ?? null),
            in_array($deLaFirma->sello_posicion ?? null, RecipeFormato::POSICIONES_SELLO, true) ? $deLaFirma->sello_posicion : 'centro',
            $nombreDelFirmante,
        );
    }

    private function formatoDe(?string $registro): ?RecipeFormato
    {
        return $registro === null ? null : RecipeFormato::where('reg_medico', $registro)->first();
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
