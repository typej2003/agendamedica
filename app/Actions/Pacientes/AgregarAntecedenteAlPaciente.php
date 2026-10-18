<?php

namespace App\Actions\Pacientes;

use App\Actions\Consulta\EscribeConRastro;
use App\Clinica\Consulta\AntecedentesDelPaciente;
use App\Models\AntecePaciente;

/**
 * **Agregar UN antecedente del catálogo a un paciente** (`antece_paciente`), el alta puntual que necesita
 * el teléfono (A4b de `PLAN-MOVIL.md`).
 *
 * ## Por qué es una Action aparte de la de la web
 *
 * La regla de la pantalla es `GuardarAntecedentes`, y sigue siendo la única que usa la web: recibe **el
 * conjunto de la pantalla** (las filas que ya estaban con su detalle y su casilla, los códigos a agregar y
 * los códigos a quitar) y devuelve nombres para el aviso, no filas. El teléfono, en cambio, manda **una**
 * creación suelta —el antecedente que el médico acaba de marcar en la consulta, con su detalle y su casilla
 * de Alerta— y necesita el **id de la fila** para reemplazar su id temporal (`creados`). Por eso no encaja
 * el conjunto, y por eso esta Action existe.
 *
 * Lo que **sí** comparte con aquella, y es lo que respeta al pie de la letra:
 *
 *  - El código tiene que estar en el catálogo **`antecedentes` del médico**: si no, no se escribe nada (la
 *    pantalla no lo ofrece y aceptarlo dejaría una fila huérfana sin nombre ni tipo). La lectura es
 *    `AntecedentesDelPaciente::delCatalogo()` —el mapa del dato, sin respaldo de otro médico—, no un
 *    `where` suelto.
 *  - Al crear se **copian el nombre y el tipo del catálogo**, que es exactamente lo que hace el `INSERT`
 *    del escritorio (`w_consulta_principal.srw:7754-7764`): el DataWindow de la pestaña no cruza con el
 *    catálogo, y una fila sin `descripcion` se ve **vacía** en el escritorio. La pertenencia al tipo queda
 *    en la fila, así el paciente no cambia de grupo si el catálogo se edita después.
 *  - La fila es **del paciente, no de la consulta**: se llavea por `(reg_medico, numhistoria,
 *    codeantecedente)` y el `detalles` nace vacío como en el escritorio (`''`, no `null`).
 *  - **Agregar dos veces el mismo código al mismo paciente no lo duplica**: devuelve la fila que ya
 *    estaba, como `AgregarDiagnosticoALaConsulta` y `AgregarMotivoALaConsulta`.
 *
 * ## La casilla de Alerta (`tipo2`)
 *
 * `tipo2` es la casilla: `'A'` = alerta, `'B'` = sin alerta y `null` = sin marcar (las filas del botón
 * *Pasar* del escritorio). La web **no** escribe `'B'` al destildar —deja `null`, que ya significa "sin
 * marcar"—, pero acá se guarda lo que manda el teléfono **si viene**: el teléfono refleja lo que bajó del
 * servidor, y el que decidió escribir `'B'` fue el escritorio. Si no viene, la fila nace en `null` y
 * guardarla no la ensucia.
 *
 * El rastro lo pone el trait `EscribeConRastro` (un `created` con `source` según quién escribe), que es lo
 * que hace que el delta del móvil y el escritorio se enteren de la fila nueva (PLAN-WEB.md, R3).
 */
final class AgregarAntecedenteAlPaciente
{
    /** El largo del `detalles` que acepta el formulario (la columna del legado es un `text` largo). */
    public const LARGO_DETALLE = GuardarAntecedentes::LARGO_DETALLE;

    /** El largo de la copia de la descripción: `antece_paciente.descripcion` es `varchar(50)`. */
    public const LARGO_DESCRIPCION = 50;

    use EscribeConRastro;

    public function __construct(private AntecedentesDelPaciente $lectura)
    {
    }

    /**
     * @param  string  $regMedico  el de la historia del paciente (el tenant de la fila)
     * @param  int  $numhistoria  el número de historia del paciente: la clave de la tabla
     * @param  string  $codigo  el `codeantecedente` del catálogo
     * @param  string|null  $detalle  el detalle que mandó el cliente (el DataWindow lo deja editar)
     * @param  string|null  $tipo2  la casilla de Alerta tal como vino: `'A'`, `'B'` o `null`
     * @param  string|null  $descripcion  la copia del nombre, si el cliente la manda (normalmente se copia
     *                                    del catálogo)
     * @param  string|null  $tipo  el `codtipo`, si el cliente lo manda (normalmente se copia del catálogo)
     * @param  string  $origen  `web` o `mobile`: quién escribe, para el rastro de `sync_changes`
     * @return AntecePaciente|null  la fila (nueva o la que ya estaba), o `null` si el código no está en el
     *                              catálogo de este médico (en ese caso no se escribe nada)
     */
    public function ejecutar(
        string $regMedico,
        int $numhistoria,
        string $codigo,
        ?string $detalle = null,
        ?string $tipo2 = null,
        ?string $descripcion = null,
        ?string $tipo = null,
        string $origen = 'mobile',
    ): ?AntecePaciente {
        $codigo = trim($codigo);

        $delCatalogo = $this->lectura->delCatalogo($regMedico, $codigo);

        if ($delCatalogo === null) {
            return null;
        }

        $numeroCodigo = trim((string) $delCatalogo->codigo);

        // La búsqueda de la fila que ya estaba va por la clave del legado, no por el `id`: es la misma
        // regla de idempotencia que el alta de motivos y de diagnósticos.
        $fila = AntecePaciente::where('reg_medico', $regMedico)
            ->where('numhistoria', $numhistoria)
            ->where('codeantecedente', $numeroCodigo)
            ->first();

        if ($fila !== null) {
            return $fila;
        }

        // La copia del nombre y del tipo del catálogo es la del `INSERT` del escritorio; lo que mande el
        // cliente, si lo manda, gana (es el mismo criterio que el detalle del diagnóstico).
        $nombre = $this->texto($descripcion) ?? trim((string) $delCatalogo->nombre);
        $tipoCodigo = $this->texto($tipo) ?? $this->texto($delCatalogo->tipoCodigo);

        return $this->crearFila(AntecePaciente::class, 'antece_paciente', [
            'reg_medico' => $regMedico,
            'numhistoria' => $numhistoria,
            'codeantecedente' => $numeroCodigo,
            // El escritorio inserta `detalles` vacío, no `null`: la fila nace "sin detalle" pero lista
            // para que el DataWindow la edite.
            'detalles' => mb_substr($this->texto($detalle) ?? '', 0, self::LARGO_DETALLE),
            'descripcion' => mb_substr($nombre, 0, self::LARGO_DESCRIPCION),
            'tipo' => $tipoCodigo,
            'tipo2' => $this->tipo2Valido($tipo2),
        ], $this->ahora(null), $origen);
    }

    /** El `""` del formulario y el `null` de la columna son el mismo estado: sin dato. */
    private function texto(?string $valor): ?string
    {
        $texto = $valor === null ? null : trim($valor);

        return $texto === '' ? null : $texto;
    }

    /**
     * La casilla de Alerta sólo acepta los dos valores del DataWindow
     * (`d_antecedentes_consulta_bueno.srd`: `checkbox.on="A" checkbox.off="B"`); cualquier otra cosa es
     * "sin marcar", que es `null`.
     */
    private function tipo2Valido(?string $valor): ?string
    {
        $texto = $valor === null ? null : strtoupper(trim($valor));

        if (! in_array($texto, [AntecedentesDelPaciente::TIPO2_ALERTA, AntecedentesDelPaciente::TIPO2_SIN_ALERTA], true)) {
            return null;
        }

        return $texto;
    }
}
