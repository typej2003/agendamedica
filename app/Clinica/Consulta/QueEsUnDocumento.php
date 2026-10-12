<?php

namespace App\Clinica\Consulta;

use App\Clinica\Pacientes\CamposDePaciente;

/**
 * **Qué es un documento de verdad** y qué es una plantilla vacía del legado (WEB-5.3a).
 *
 * Al abrir una consulta el escritorio **crea una fila de plantilla** en cada una de estas tablas —una
 * constancia sin nada propio, un reposo de 1 día desde hoy sin observación, un informe con el texto `.`—
 * (`w_consulta_principal.srw:3135-3142`, `:3221-3236` y `:3337-3352`). Son casi todas las filas y no son el
 * documento de nadie. La pregunta "¿esta fila es un documento?" tiene **una sola respuesta**, y esta clase
 * es el lugar donde vive para que no se escriba dos veces (PLAN-WEB.md, R3):
 *
 *  - **la historia del paciente** (`HistoriaDelPaciente::documentos`) decide con esto qué lista;
 *  - **la sección de solicitudes** de la consulta decide con esto qué pastilla de estado pone;
 *  - **la impresión** decide con esto qué ofrece imprimir en la agenda (la secretaría);
 *  - y es **la misma vara que el móvil** (`DoctorisimoMobile/lib/features/reportes/`,
 *    `ReposoMedico.esDocumento` / `InformeMedico.esDocumento`), así que un documento que la web dice que
 *    existe es uno que el teléfono también imprime.
 *
 * Las reglas son las de la plantilla, no una invención: lo que el escritorio crea al abrir la consulta es
 * **exactamente** lo que se descarta.
 *
 * Es una clase **sin estado y sin base de datos**: texto de entrada, booleano de salida.
 */
final class QueEsUnDocumento
{
    /** El texto con el que el escritorio crea el informe vacío al abrir la consulta (`:3348`). */
    public const TEXTO_DE_INFORME_VACIO = '.';

    /**
     * **Reposo**: es un documento si tiene **más de un día** o una **observación**. Un reposo de 1 día sin
     * observación es la plantilla que el legado crea (código `M`, 1 día, desde hoy): no se distingue de uno
     * real escrito a propósito, y la decisión del usuario (2026-09-27) fue dejarlo afuera. Por eso la web
     * no deja *guardar* un reposo de un solo día sin justificación (ver `GuardarReposoDeLaConsulta`).
     */
    public static function reposo($dias, $observacion): bool
    {
        $dias = $dias === null || $dias === '' ? null : (int) $dias;

        return ($dias !== null && $dias > 1) || CamposDePaciente::texto($observacion) !== null;
    }

    /**
     * **Informe**: es un documento si su texto no está vacío ni es el `.` de la plantilla.
     */
    public static function informe($descripcion): bool
    {
        $texto = CamposDePaciente::texto($descripcion);

        return $texto !== null && $texto !== self::TEXTO_DE_INFORME_VACIO;
    }

    /**
     * **Constancia**: su texto base es fijo y existe para **toda** consulta, así que la fila sola no dice
     * nada. Es un documento *propio* cuando trae algo que el médico escribió: la observación
     * (`observacion01`), los diagnósticos que editó (`observacion`) o el `titulo`. Una constancia sin nada
     * propio no se lista en la historia ni se ofrece a la secretaría (no dice nada que la consulta no diga).
     *
     * @param  mixed  ...$textos  las columnas con texto propio de `constancia_obs`
     */
    public static function constancia(...$textos): bool
    {
        foreach ($textos as $texto) {
            if (CamposDePaciente::texto($texto) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * **Hospitalización** (WEB-5.3b): es una orden de verdad si tiene **algo** de lo que la hoja imprime: el lugar a hospitalizar
     * (`hospital`), el diagnóstico provisional (`diagprev`) o las recomendaciones (`dieta`). El escritorio crea la fila **vacía**
     * al abrir la consulta (`w_consulta_principal.srw:3329-3334`): en la base de desarrollo son 114.499 de 119.299 filas, más
     * unas pocas con cadenas vacías. `indiprev` y `habitacion` no cuentan: ninguna pantalla las escribe ni la hoja
     * (`d_hospitalizacion_1`) las imprime, así que no hacen de una fila vacía un documento.
     */
    public static function hospitalizacion($hospital, $diagprev, $dieta): bool
    {
        foreach ([$hospital, $diagprev, $dieta] as $texto) {
            if (TextoDeLaConsulta::limpio($texto) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * **Dieta / recomendaciones** (WEB-5.3b): es un documento si tiene texto (`descripcion`). El escritorio sólo crea la fila al
     * "pasar" una plantilla (`w_consulta_principal.srw:19689-19702`), así que casi nunca hay vacías, pero una sin texto no dice
     * nada que imprimir (un título solo no es una dieta).
     */
    public static function dieta($descripcion): bool
    {
        return TextoDeLaConsulta::limpio($descripcion) !== null;
    }
}
