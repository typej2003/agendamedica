<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;

/**
 * **Indicadores incidentes**: el panel de **nefrología pediátrica** (2026-10-19).
 *
 * Es el ítem 5 del árbol de PediaReport (`w_consulta_principal.srw:2656`), la pestaña `tabpage_35`. Lo que hace
 * el escritorio, medido en el fuente:
 *
 *  - el médico teclea unos **26 números** (sangre, orina y otros) en campos sueltos;
 *  - el botón *Actualizar* compone **un texto** con `ETIQUETA: valor.` por cada campo cargado
 *    (`ue_guard_exam`, `:25090-25204`);
 *  - al guardar la consulta, ese texto va a **`consultas.resultadoexamencomp`** (`:2821` y `:2850`), que es lo
 *    que imprimen el resumen médico y la evaluación pre-operatoria (`d_resumen_medico.srd`,
 *    `d_evaluacio_preoperatoria_pedia.srd`);
 *  - los números **no se guardan en ninguna parte**: no hay columnas para ellos y la pestaña no los vuelve a
 *    leer al abrir la consulta. Si el médico no vuelve a teclearlos, se pierden.
 *
 * ⚠️ **Lo que el escritorio descarta.** Los gases (`gu_ph`, `gu_pco`, `gv_hco`), el exceso de base (`em_19`), el
 * volumen urinario (`em_vu`) y el sodio urinario (`em_so_u`) se leen en el guardado pero **no entran** en el
 * texto: en el fuente las líneas que los agregaban están comentadas (`:25191-25200`). Por eso no están acá: la
 * web no ofrece campos que no se guarden.
 *
 * Es **pura** (sin base): el mapa del dato y el formateador. La lectura y la escritura viven en
 * `GuardarIndicadoresIncidentes`.
 */
final class IndicadoresIncidentes
{
    /** La columna de `consultas` donde el escritorio deja el resumen compuesto. */
    public const COLUMNA = 'resultadoexamencomp';

    /**
     * Los campos que **sí** entran en el texto, en el orden del escritorio (`EXA_RESU`, `:25203`), con la etiqueta
     * que usa cada uno y el grupo de la pantalla.
     *
     * @var array<string,array{etiqueta:string, grupo:string}>
     */
    private const CAMPOS = [
        // Sangre
        'urea'            => ['etiqueta' => 'UREA', 'grupo' => 'Sangre'],
        'creatinina'      => ['etiqueta' => 'Cr s', 'grupo' => 'Sangre'],
        'sodio'           => ['etiqueta' => 'Na', 'grupo' => 'Sangre'],
        'cloro'           => ['etiqueta' => 'Cl', 'grupo' => 'Sangre'],
        'potasio'         => ['etiqueta' => 'K', 'grupo' => 'Sangre'],
        'calcio'          => ['etiqueta' => 'Ca', 'grupo' => 'Sangre'],
        'fosforo'         => ['etiqueta' => 'P', 'grupo' => 'Sangre'],
        'magnesio'        => ['etiqueta' => 'MAGNESIO', 'grupo' => 'Sangre'],
        'paratohormona'   => ['etiqueta' => 'PARATOHORMONA', 'grupo' => 'Sangre'],
        'ferritina'       => ['etiqueta' => 'FERRITINA', 'grupo' => 'Sangre'],
        'proteinuria'     => ['etiqueta' => 'PROTEINURIA', 'grupo' => 'Sangre'],
        'transferrina'    => ['etiqueta' => 'TRANSFERRINA', 'grupo' => 'Sangre'],
        'depuracion'      => ['etiqueta' => 'DEPURACIÓN DE CREATININA', 'grupo' => 'Sangre'],
        'hemoglobina'     => ['etiqueta' => 'HEMOGLOBINA', 'grupo' => 'Sangre'],
        'hematocrito'     => ['etiqueta' => 'HEMATOCRITOS', 'grupo' => 'Sangre'],
        'hemoglobina_gli' => ['etiqueta' => 'HEMOGLOBINA GLICOSILADA', 'grupo' => 'Sangre'],
        // Orina
        'acido_urico'     => ['etiqueta' => 'Ac U', 'grupo' => 'Orina'],
        'acido_urico_u'   => ['etiqueta' => 'Ac Uu', 'grupo' => 'Orina'],
        'creatinina_u'    => ['etiqueta' => 'Cr u', 'grupo' => 'Orina'],
        'creatinina_u24'  => ['etiqueta' => 'Cr u 24h', 'grupo' => 'Orina'],
        'potasio_u'       => ['etiqueta' => 'K u', 'grupo' => 'Orina'],
        'cloro_u'         => ['etiqueta' => 'Cl u', 'grupo' => 'Orina'],
        'fosforo_u'       => ['etiqueta' => 'P u', 'grupo' => 'Orina'],
        'magnesio_u'      => ['etiqueta' => 'MAGNESIO u', 'grupo' => 'Orina'],
        'albumina'        => ['etiqueta' => 'ALBUMINA', 'grupo' => 'Orina'],
        'calcio_u'        => ['etiqueta' => 'Ca u', 'grupo' => 'Orina'],
    ];

    /**
     * Los campos del panel, en orden: `clave => ['etiqueta' => ..., 'grupo' => ...]`.
     *
     * @return array<string,array{etiqueta:string, grupo:string}>
     */
    public static function campos(): array
    {
        return self::CAMPOS;
    }

    /** Las claves de los campos (lo que acepta el formulario). */
    public static function claves(): array
    {
        return array_keys(self::CAMPOS);
    }

    /**
     * **El texto que guarda el escritorio**: `ETIQUETA: valor.` por cada campo cargado, en el orden de `EXA_RESU`.
     *
     * Sin ningún valor devuelve `''` (no hay nada que guardar). El valor se respeta tal como se escribió (con coma
     * o con punto decimal: es lo que el médico lee en el informe).
     *
     * @param  array<string,mixed>  $valores
     */
    public static function componer(array $valores): string
    {
        $partes = [];

        foreach (self::CAMPOS as $clave => $campo) {
            $valor = trim((string) ($valores[$clave] ?? ''));

            if ($valor === '') {
                continue;
            }

            $partes[] = $campo['etiqueta'] . ': ' . $valor . '.';
        }

        return implode(' ', $partes);
    }

    /**
     * El texto guardado en la consulta, o `null` si no hay (el `none` del DataWindow del escritorio cuenta como
     * vacío).
     */
    public static function guardado(Consulta $consulta): ?string
    {
        return TextoDeLaConsulta::limpio($consulta->{self::COLUMNA});
    }

    /**
     * Los grupos del formulario, en el orden en que se muestran: `grupo => [clave => etiqueta]`.
     *
     * @return array<string,array<string,string>>
     */
    public static function grupos(): array
    {
        $grupos = [];

        foreach (self::CAMPOS as $clave => $campo) {
            $grupos[$campo['grupo']][$clave] = $campo['etiqueta'];
        }

        return $grupos;
    }
}
