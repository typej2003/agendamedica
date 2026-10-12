<?php

namespace App\Actions\Configuracion;

use App\Clinica\Archivos\ImagenesDelMembrete;
use App\Models\Medico;
use App\Models\RecipeFormato;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Throwable;

/**
 * **Guardar el formato de impresión del médico** (`recipe_formatos`): cómo se ve cada bloque del membrete (alineación,
 * fuente, negrita, itálica, subrayado, borde y visibilidad), el color de la línea, la **firma** y el **sello** con su
 * posición (WEB-8.2; antes sólo el API del móvil lo escribía).
 *
 * Es **la** regla de escritura de esa configuración y la usan las dos superficies (PLAN-WEB.md, R3): el API
 * (`RecipeFormatoController`, "Formato del récipe") y la pantalla "Formato de impresión" de la web. Las reglas de
 * validación y la limpieza de `elementos` viven en `ReglasDelFormato`.
 *
 * ## Actualización parcial
 *
 * Lo que no viene queda como estaba, **también dentro de `elementos`**: mandar sólo `elementos[rif][negrita]` no resetea
 * el resto del RIF ni los demás bloques (`array_replace_recursive`).
 *
 * ## Qué ve el teléfono y cuándo
 *
 * `recipe_formatos` no pasa por la cola de `sync-app-data`: el teléfono la recibe **completa en cada sincronización**
 * (`formato_recipe`). Para saber si tiene que volver a bajar la firma o el sello compara **la URL** de cada imagen con la
 * que bajó la última vez (`recursos_impresion.dart`), y cada subida arma un nombre nuevo, así que una firma reemplazada
 * desde la web llega en la sincronización siguiente. `updated_at` se mueve con cualquier cambio real (Eloquent no lo toca
 * si no cambió nada), igual que cuando guardaba el API.
 *
 * ## Los archivos
 *
 * Firma y sello van al disco `public` (`recipe/firmas/…`, `recipe/sellos/…`: las rutas del API). Al reemplazarlos o
 * quitarlos se borra el anterior **si nadie más lo usa**, y si la fila no queda se borran los recién subidos.
 */
final class GuardarFormatoDelRecipe
{
    public const SECCION_LOGO = 'logo';

    public const SECCION_TEXTOS = 'textos';

    public const SECCION_SELLO = 'sello';

    public const SECCIONES = [self::SECCION_LOGO, self::SECCION_TEXTOS, self::SECCION_SELLO];

    /**
     * @param  array<string,mixed>  $datos  lo validado con `ReglasDelFormato::reglas()`: `elementos`, `color_linea`,
     *                                      `sello_posicion` (las claves que no vengan no se tocan)
     * @param  bool  $quitarFirma  quitar la firma actual; si viene `$firma`, gana el archivo
     *
     * @throws InvalidArgumentException  si el médico no tiene un registro principal bajo el cual guardar
     */
    public function ejecutar(
        Medico $medico,
        array $datos,
        ?UploadedFile $firma = null,
        ?UploadedFile $sello = null,
        bool $quitarFirma = false,
        bool $quitarSello = false,
    ): RecipeFormato {
        $formato = RecipeFormato::firstOrNew(['reg_medico' => $this->registroDe($medico)]);

        if (isset($datos['elementos'])) {
            $formato->elementos = array_replace_recursive(
                $formato->elementos ?? [],
                ReglasDelFormato::soloOpcionesConocidas($datos['elementos']),
            );
        }

        foreach (['color_linea', 'sello_posicion'] as $campo) {
            if (array_key_exists($campo, $datos)) {
                $formato->$campo = $datos[$campo];
            }
        }

        $nuevos = [];
        $viejos = [];

        try {
            foreach (['firma' => [$firma, $quitarFirma, ImagenesDelMembrete::CARPETA_FIRMA], 'sello' => [$sello, $quitarSello, ImagenesDelMembrete::CARPETA_SELLO]] as $imagen => [$archivo, $quitar, $carpeta]) {
                if ($archivo !== null) {
                    $viejos[] = $formato->$imagen;
                    $formato->$imagen = $nuevos[] = ImagenesDelMembrete::guardar($archivo, $carpeta);
                } elseif ($quitar) {
                    $viejos[] = $formato->$imagen;
                    $formato->$imagen = null;
                }
            }

            $formato->save();
        } catch (Throwable $e) {
            // La fila no quedó: lo que se acababa de subir no lo referencia nadie.
            foreach ($nuevos as $ruta) {
                ImagenesDelMembrete::descartar($ruta);
            }

            throw $e;
        }

        foreach ($viejos as $ruta) {
            ImagenesDelMembrete::borrarSiNadieLoUsa($ruta);
        }

        return $formato;
    }

    /**
     * **Restablecer valores**: vuelve una sección del formato a los valores del legado (`RecipeFormato::elementosPorDefecto()`).
     *
     *  - `logo`: tamaño, alineación, borde y visibilidad del logo (la imagen no se toca).
     *  - `textos`: el estilo de médico, especialidad, RIF y pie, y el color de la línea.
     *  - `sello`: la posición del sello (las imágenes no se tocan).
     *
     * Se quitan los valores guardados en vez de escribir los de por defecto: la fila queda como la de un médico que nunca
     * configuró esa sección, y si ya estaba así no se escribe nada (`updated_at` no se mueve). Sin fila no se crea ninguna.
     *
     * @return bool  `true` si había algo que restablecer
     *
     * @throws InvalidArgumentException  si la sección no existe o el médico no tiene registro principal
     */
    public function restablecer(Medico $medico, string $seccion): bool
    {
        if (! in_array($seccion, self::SECCIONES, true)) {
            throw new InvalidArgumentException('La sección que se quiere restablecer no existe.');
        }

        $formato = RecipeFormato::where('reg_medico', $this->registroDe($medico))->first();

        if ($formato === null) {
            return false;
        }

        $elementos = $formato->elementos ?? [];

        switch ($seccion) {
            case self::SECCION_LOGO:
                unset($elementos['logo']);
                break;
            case self::SECCION_TEXTOS:
                foreach (RecipeFormato::ELEMENTOS_TEXTO as $elemento) {
                    unset($elementos[$elemento]);
                }
                $formato->color_linea = null;
                break;
            case self::SECCION_SELLO:
                $formato->sello_posicion = null;
                break;
        }

        $formato->elementos = $elementos === [] ? null : $elementos;

        if (! $formato->isDirty()) {
            return false;
        }

        $formato->save();

        return true;
    }

    private function registroDe(Medico $medico): string
    {
        $registro = $medico->regMedicoPrincipal();

        if ($registro === null || trim($registro) === '') {
            throw new InvalidArgumentException('Este médico no tiene un número de registro principal bajo el cual guardar el formato.');
        }

        return $registro;
    }
}
