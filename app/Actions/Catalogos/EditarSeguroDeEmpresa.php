<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\EscribeConRastro;
use App\Models\SegEmp;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Editar un seguro / empresa del consultorio** (O-62): nombre, RIF, dirección y teléfono; el código no se edita.
 *
 * Escribe **sólo lo que cambió**, columna por columna, con el last-write-wins del sync y su `updated` por columna
 * (`source = web`); la fila cambia su `updated_at`. El código (`codesegemp`) es con el que el paciente guarda su
 * seguro (`pacientes.codesegemp`), así que no se toca: el escritorio tampoco lo edita (el `em_1` de
 * `w_agregar_seguro_empresa.srw` se deshabilita al cargar la fila).
 *
 * Cambiar el nombre **cambia lo que se lee en la ficha y en los listados** del paciente que lo tiene: se resuelve por
 * el código contra el catálogo. Es lo esperado.
 *
 * Un duplicado histórico se puede seguir editando mientras no se le ponga el nombre de otro (`noDuplicar` sólo se
 * pregunta si el nombre cambió).
 */
final class EditarSeguroDeEmpresa
{
    use EscribeConRastro;

    /**
     * @param  array<string,mixed>  $datos  `nombre`, `rif`, `direccion` y `telef` del formulario
     *
     * @throws InvalidArgumentException igual que el alta
     */
    public function ejecutar(
        SegEmp $fila,
        array $datos,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeEdicion {
        $campos = ReglasDelSeguroDeEmpresa::campos($datos);
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($fila, $campos, $cuando, $origen) {
            $bloqueada = SegEmp::whereKey($fila->getKey())->lockForUpdate()->firstOrFail();

            if (ReglasDelSeguroDeEmpresa::clave((string) $bloqueada->nombre) !== ReglasDelSeguroDeEmpresa::clave($campos['nombre'])) {
                ReglasDelSeguroDeEmpresa::noDuplicar((string) $bloqueada->reg_medico, $campos['nombre'], (int) $bloqueada->getKey());
            }

            // Un navegador manda los saltos de línea de un `<textarea>` como CRLF y el escritorio guarda CRLF o LF
            // según quién escribió: los mismos renglones no son un cambio.
            if (str_replace("\r\n", "\n", (string) $campos['direccion']) === str_replace("\r\n", "\n", trim((string) $bloqueada->direccion))) {
                unset($campos['direccion']);
            }

            [$escritas, $bloqueadas] = $this->corregirFila($bloqueada, 'seg_emp', $campos, $cuando, $origen);

            return new ResultadoDeEdicion($bloqueada, $escritas, $bloqueadas);
        });
    }
}
