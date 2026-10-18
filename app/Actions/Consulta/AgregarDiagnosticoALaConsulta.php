<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\DiagnosticosDeLaConsulta;
use App\Models\Consulta;
use App\Models\DiagnosticoPaciente;
use Illuminate\Support\Facades\DB;

/**
 * **Agregar UN diagnóstico del catálogo a una consulta** (`diagnostico_paciente`), el alta puntual que
 * necesita el teléfono (A4a de `PLAN-MOVIL.md`).
 *
 * ## Por qué es una Action aparte de la de la web
 *
 * La regla de la pantalla de la consulta es `GuardarDiagnosticosDeLaConsulta`, y sigue siendo la única
 * que usa la web: recibe **el conjunto marcado** de la pantalla (las filas que ya estaban con su detalle,
 * los códigos a agregar y los ids a quitar) y devuelve nombres para el aviso de la pantalla, no filas.
 * El teléfono, en cambio, manda **una** creación suelta y necesita el **id de la fila** que se acaba de
 * crear (es el `remote_id` que reemplaza su id temporal), así que la forma del conjunto no le sirve.
 *
 * Lo que **sí** comparte con aquella, y es lo que esta Action respeta al pie de la letra:
 *
 *  - El código tiene que estar en el catálogo **`diagnosticos` del médico**: si no, no se escribe nada
 *    (la pantalla no lo ofrece y aceptarlo dejaría una fila sin nombre).
 *  - Al crear se **copia la descripción del catálogo** en `detalle_diagnostco`, que es exactamente lo que
 *    hace el `INSERT` del escritorio (`w_consulta_principal.srw:11860-11868`): el DataWindow de la
 *    pestaña *Diagnóstico* no cruza con el catálogo, así que una fila sin detalle se ve **vacía** en el
 *    escritorio.
 *  - La fila cuelga de la consulta por `nrohistoria` + `nroconsulta` + `codediagnostico`, con los
 *    **números** de la consulta y no su id (trampa del §7.4 de `PLAN-MOVIL.md`).
 *  - **Agregar dos veces el mismo código a la misma consulta no la duplica**: devuelve la fila que ya
 *    estaba, como `AgregarMotivoALaConsulta`.
 *
 * Se aparta en **una** cosa, a propósito: `orden` queda con el **siguiente número dentro de la consulta**
 * en vez de `null`. El escritorio nunca escribe `orden` (el DataWindow lo tiene oculto), pero el teléfono
 * agrega diagnósticos de a uno y en el orden en que el médico los va marcando, y la lectura los muestra
 * por `orden` con el id como desempate: sin número, todas las filas nuevas caerían siempre al final por
 * id y el orden en que se cargaron se perdería. Las filas que el escritorio dejó con `null` no se tocan.
 *
 * El rastro lo pone el trait `EscribeConRastro` (un `created` con `source` según quién escribe), que es lo
 * que hace que el delta del móvil y el escritorio se enteren de la fila nueva (PLAN-WEB.md, R3).
 */
final class AgregarDiagnosticoALaConsulta
{
    /** El largo de `detalle_diagnostco` (la columna es `varchar(100)`). */
    public const LARGO_DETALLE = 100;

    use EscribeConRastro;

    public function __construct(private DiagnosticosDeLaConsulta $lectura)
    {
    }

    /**
     * @param  Consulta  $consulta  la consulta ya resuelta contra el contexto (de ahí salen `reg_medico`,
     *                              `numhistoria` y `nroconsulta`: la clave de la fila)
     * @param  string  $codigo  el `codediagnostico` del catálogo
     * @param  string|null  $detalle  el detalle que mandó el cliente; vacío = se copia el del catálogo
     * @param  string  $origen  `web` o `mobile`: quién escribe, para el rastro de `sync_changes`
     * @return DiagnosticoPaciente|null  la fila (nueva o la que ya estaba), o `null` si el código no
     *                                   está en el catálogo de este médico (en ese caso no se escribe nada)
     */
    public function ejecutar(
        Consulta $consulta,
        string $codigo,
        ?string $detalle = null,
        string $origen = 'mobile',
    ): ?DiagnosticoPaciente {
        $regMedico = (string) $consulta->reg_medico;
        $codigo = trim($codigo);

        $delCatalogo = $this->lectura->delCatalogo($regMedico, $codigo);

        if ($delCatalogo === null) {
            return null;
        }

        $numeroCodigo = trim((string) $delCatalogo->codediagnostico);

        // La búsqueda de la fila que ya estaba va por la clave del legado, no por el `id`: es la misma
        // regla de idempotencia que `AgregarMotivoALaConsulta`.
        $fila = DiagnosticoPaciente::where('reg_medico', $regMedico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta)
            ->where('codediagnostico', $numeroCodigo)
            ->first();

        if ($fila !== null) {
            return $fila;
        }

        $nombre = trim((string) $delCatalogo->descripcion);
        $detalle = $detalle === null ? '' : trim($detalle);

        return $this->crearFila(DiagnosticoPaciente::class, 'diagnostico_paciente', [
            'reg_medico' => $regMedico,
            'nrohistoria' => (int) $consulta->numhistoria,
            'nroconsulta' => (int) $consulta->nroconsulta,
            'codediagnostico' => $numeroCodigo,
            // El detalle del cliente si lo mandó (el DataWindow lo deja editar); si no, la copia del
            // catálogo que hace el `INSERT` del escritorio.
            'detalle_diagnostco' => mb_substr($detalle === '' ? $nombre : $detalle, 0, self::LARGO_DETALLE),
            'orden' => $this->siguienteOrden($consulta),
        ], $this->ahora(null), $origen);
    }

    /**
     * El siguiente `orden` de esa consulta. `max()` sobre filas que el escritorio dejó en `null` da
     * `null`, y ahí el número arranca en 1: la primera fila que agrega el teléfono no queda "antes" que
     * las que ya estaban.
     */
    private function siguienteOrden(Consulta $consulta): int
    {
        $maximo = DB::table('diagnostico_paciente')
            ->where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta)
            ->max('orden');

        return (int) $maximo + 1;
    }
}
