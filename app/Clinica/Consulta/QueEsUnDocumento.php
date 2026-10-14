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

    /**
     * **Orden de laboratorio** (WEB-5.4): es una orden de verdad si tiene **al menos un examen** (`examen_paciente`) **o** una observación
     * con texto (`examen_obs.observacion`, el «Resumen clínico»). La fila de `examen_obs` vacía que el escritorio crea al agregar un examen
     * no cuenta por sí sola.
     *
     * @param  int  $examenes  cuántas filas de `examen_paciente` tiene la consulta
     */
    public static function laboratorio(int $examenes, $observacion): bool
    {
        return $examenes > 0 || TextoDeLaConsulta::limpio($observacion) !== null;
    }

    /**
     * **Solicitud de estudios** (WEB-5.4): al menos un estudio (`radiologia_paciente`) **o** una observación con texto
     * (`radiologia_obs.observacion`). Misma vara que `laboratorio`.
     *
     * @param  int  $estudios  cuántas filas de `radiologia_paciente` tiene la consulta
     */
    public static function estudios(int $estudios, $observacion): bool
    {
        return $estudios > 0 || TextoDeLaConsulta::limpio($observacion) !== null;
    }

    /**
     * **Estudio ecográfico** (WEB-6.1): es un documento si trae **algún dato** —cualquiera de las columnas
     * que edita la pantalla, sin contar la fecha del estudio—.
     *
     * El escritorio **no** crea la fila de `eco_pelvico` al abrir la consulta (a diferencia de la
     * constancia, el reposo o el informe): la crea cuando el médico entra al estudio, con la fecha del día
     * (`w_cargar_visualizar_ecografia.srw:221-224`). Pero su `dw_1.Update()` guarda la fila **aunque no se
     * haya cargado nada**, así que una fila con sólo la fecha existe y no es el informe de nadie: no tiene
     * nada que imprimir. Misma vara para la pantalla (la pastilla de estado), la historia y la impresión.
     *
     * @param  int  $camposConDato  cuántas columnas del estudio traen algo (sin contar `fecha`)
     * @param  bool  $textoLibre  el escritorio dejó un informe en texto libre (`puro_texto`, el modo "puro
     *                            texto" de la ventana independiente): un estudio sin campos pero con ese
     *                            texto **sí** es un documento, porque es lo que se imprime
     */
    public static function ecografia(int $camposConDato, bool $textoLibre = false): bool
    {
        return $camposConDato > 0 || $textoLibre;
    }

    /**
     * **Colposcopía** (WEB-6.5): es un documento si tiene **texto** (`consultas.observaciones`, que es lo que
     * imprime la hoja «INFORME DE COLPOSCOPÍA»).
     *
     * El escritorio **no** crea la fila al abrir la consulta (a diferencia de la constancia, el reposo o el
     * informe): el texto vive en la columna de una consulta que siempre existe, así que la única pregunta es
     * si tiene algo escrito. Con el literal `none` del legado, `TextoDeLaConsulta::limpio` ya dice que no.
     */
    public static function colposcopia($texto): bool
    {
        return TextoDeLaConsulta::limpio($texto) !== null;
    }
}
