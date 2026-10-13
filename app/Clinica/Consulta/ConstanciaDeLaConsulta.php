<?php

namespace App\Clinica\Consulta;

use App\Models\ConstanciaObs;
use App\Models\Consulta;

/**
 * **La constancia de una consulta** (WEB-5.3a, pestaña *Constancias* de las *Solicitudes*, ítem 11 del panel).
 *
 * ## Qué es y dónde vive cada cosa (verificado en la fuente)
 *
 * La constancia es **una por consulta** y su texto es **fijo**: *«Por medio de la presente se hace constar que
 * la (el) paciente …, asistió a CONSULTA MÉDICA el día …»* (`datawin_reportes/d_constancia.srd`, el `compute_5_te`
 * del detalle), seguido de *IDX:* con los diagnósticos y de la observación del médico. Lo único que el escritorio
 * guarda es **la observación**: `constancia_obs.observacion01` (`ue_salvar_obs_contancia`,
 * `w_consulta_principal.srw:17326-17328`, un `UPDATE … WHERE numhistoria = :Historia AND numconsulta = :Consulta`).
 * La fila se crea **vacía** al abrir la consulta (`:3221-3236`, `VALUES (:Historia, :Consulta, NULL, NULL, NULL)`).
 *
 * Los **diagnósticos** son el otro campo de la pestaña (`mle_3`, «Diagnosticos»): se llenan al abrir la consulta con
 * `gfsDiagnosticos(Historia, Consulta)` (`:3132`; `utilitarios/gfsdiagnosticos.srf`: cada descripción del catálogo
 * seguida de «. ») y **se pueden editar, pero el escritorio no los guarda**: se pasan como argumento al imprimir
 * (`ldsData.Retrieve(Historia, Consulta, mle_3.text, mle_obs.text, …)`, `:17456`). La web sí los guarda cuando el
 * médico los edita, en la columna **`constancia_obs.observacion`**: existe en el esquema del legado, **ninguna
 * ventana del escritorio la escribe** (el `INSERT` de `:3231` la deja en `NULL`) y no tiene un solo valor en las
 * 119.403 filas de la base de desarrollo, así que no choca con nada. `titulo` tampoco se escribe y no se usa.
 *
 *  - `observacion` **vacía** = «los diagnósticos siguen a los de la consulta» (el texto se arma en el momento, así que
 *    si el médico agrega un diagnóstico después, la constancia lo trae sola);
 *  - `observacion` **con texto** = el médico lo editó y la constancia ya no sigue a la consulta (la pantalla lo dice
 *    y ofrece restaurar el automático).
 *
 * Es de **sólo lectura**: la escritura es `App\Actions\Consulta\GuardarConstanciaDeLaConsulta`.
 */
final class ConstanciaDeLaConsulta
{
    /** El texto fijo de la constancia, tal como lo imprime el escritorio (y el móvil). */
    public const TEXTO_BASE = 'Por medio de la presente se hace constar que la (el) paciente %s, asistió a CONSULTA MÉDICA el día %s.';

    public function __construct(private DiagnosticosDeLaConsulta $diagnosticos)
    {
    }

    /** La fila de `constancia_obs` de la consulta, o `null` si el escritorio no la creó y nadie escribió una. */
    public function fila(Consulta $consulta): ?ConstanciaObs
    {
        return ConstanciaObs::where('reg_medico', $consulta->reg_medico)
            ->where('numhistoria', (int) $consulta->numhistoria)
            ->where('numconsulta', (int) $consulta->nroconsulta)
            ->orderBy('id')
            ->first();
    }

    /**
     * Los diagnósticos de la consulta como la constancia los pone: la descripción de cada uno seguida de un punto,
     * separados por un espacio (`gfsDiagnosticos` del escritorio y `'$d.'` del móvil). Vacío si la consulta no tiene.
     */
    public function textoAutomatico(Consulta $consulta): string
    {
        $nombres = $this->diagnosticos
            ->deLaConsulta((string) $consulta->reg_medico, (string) $consulta->numhistoria, (int) $consulta->nroconsulta)
            ->map(fn ($diagnostico) => rtrim(trim((string) $diagnostico->nombre), '. '))
            ->filter()
            ->map(fn (string $nombre) => $nombre . '.')
            ->all();

        return implode(' ', $nombres);
    }

    /** Lo que el médico escribió en la observación (`observacion01`), o `null`. */
    public function observacion(?ConstanciaObs $fila): ?string
    {
        return $fila === null ? null : TextoDeLaConsulta::limpio($fila->observacion01);
    }

    /** Los diagnósticos que el médico editó (`observacion`), o `null` si la constancia sigue a los de la consulta. */
    public function diagnosticosEditados(?ConstanciaObs $fila): ?string
    {
        return $fila === null ? null : TextoDeLaConsulta::limpio($fila->observacion);
    }

    /** Los diagnósticos que salen en la constancia: los editados o, si no hay, los de la consulta. */
    public function diagnosticos(Consulta $consulta, ?ConstanciaObs $fila): string
    {
        return $this->diagnosticosEditados($fila) ?? $this->textoAutomatico($consulta);
    }

    /** ¿La constancia tiene algo propio del médico (observación o diagnósticos editados)? Entonces es un documento. */
    public function tieneContenidoPropio(?ConstanciaObs $fila): bool
    {
        return $fila !== null
            && QueEsUnDocumento::constancia($fila->observacion01, $fila->observacion, $fila->titulo);
    }

    /** El texto fijo, con el paciente y la fecha de la consulta ya puestos. */
    public static function textoBase(string $paciente, string $fecha): string
    {
        return sprintf(self::TEXTO_BASE, $paciente, $fecha);
    }
}
