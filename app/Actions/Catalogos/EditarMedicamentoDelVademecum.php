<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\EscribeConRastro;
use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Models\Vademecum;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Editar un medicamento del vademécum** (WEB-8.1a): el guardado del ABM de `w_agregar_vademecum` sobre un código
 * que ya existe (`d_a_vademecum_go.srd`: `dw_1.update()`).
 *
 * Escribe **sólo lo que cambió**, columna por columna, con el last-write-wins del sync (`RegistroDeCambios`): una
 * columna con una edición más nueva —del teléfono o del escritorio— no se pisa, pero eso no impide escribir las
 * otras. Cada columna escrita deja su `updated` en `sync_changes` (`source = web`) y la fila cambia su
 * `updated_at`, que es lo que el delta del teléfono mira.
 *
 * ## Qué pasa con lo que ya se recetó
 *
 * Un récipe resuelve el nombre del medicamento **por `codemedicina`** contra el vademécum (la fila de `recipes` sólo
 * guarda el código y, si la creó la web, una copia del nombre en `descripcion` que se usa de respaldo). Cambiar el
 * nombre comercial o la presentación **cambia lo que ven el historial y la impresión de los récipes viejos**: es lo
 * esperado (es el mismo medicamento corregido) y no se hace nada para impedirlo. El código nunca se edita.
 *
 * ## No duplicar
 *
 * El nombre comercial **y** la presentación juntos no pueden ser los de **otro** medicamento del médico (`ADEVIT/jarabe`
 * y `ADEVIT/gotas` son distintos): es la misma comparación que hace el alta (`AgregarMedicamentoAlVademecum`).
 */
final class EditarMedicamentoDelVademecum
{
    use EscribeConRastro;

    /**
     * @param  array<int,string>  $registrosMedicos  los registros del médico, para comparar contra todo su vademécum
     * @param  array<string,mixed>  $datos  los campos del formulario (`CamposDelMedicamento::COLUMNAS`)
     *
     * @throws InvalidArgumentException si falta el nombre, algo pasa del largo de su columna o ya hay otro igual
     */
    public function ejecutar(
        Vademecum $medicamento,
        array $registrosMedicos,
        array $datos,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeMedicamento {
        $campos = CamposDelMedicamento::normalizar($datos, false);
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($medicamento, $registrosMedicos, $campos, $cuando, $origen) {
            // Se relee la fila bajo candado: dos ediciones o una baja al mismo tiempo se turnan.
            $fila = Vademecum::whereKey($medicamento->id)->lockForUpdate()->firstOrFail();

            $this->noDuplicar($fila, $registrosMedicos, $campos);

            $nuevos = [];
            foreach ($campos as $columna => $valor) {
                // `sico` es `S` o `N`: una fila que nunca tuvo la casilla (`NULL`) y sigue sin tildar no es un cambio.
                if ($columna === 'sico' && $valor === 'N' && $fila->sico !== 'S') {
                    continue;
                }
                // Un navegador manda los saltos de línea de un `<textarea>` como `\r\n` y el escritorio guarda `\r\n` o
                // `\n` según quién escribió: los mismos renglones no son un cambio.
                if (in_array($columna, ['uso', 'dosificacion'], true)
                    && str_replace("\r\n", "\n", (string) $valor) === str_replace("\r\n", "\n", trim((string) $fila->{$columna}))) {
                    continue;
                }
                $nuevos[$columna] = $valor;
            }

            [$escritas, $bloqueadas] = $this->corregirFila($fila, 'vademecum', $nuevos, $cuando, $origen);

            return new ResultadoDeMedicamento($fila, $escritas, $bloqueadas);
        });
    }

    /** @param  array<string,?string>  $campos */
    private function noDuplicar(Vademecum $fila, array $registrosMedicos, array $campos): void
    {
        $comercial = $campos['nombrecomercial'];

        if ($comercial === null) {
            return;
        }

        $clave = fn (?string $presentacion) => BusquedaDePacientes::normalizar(trim((string) $presentacion));

        $otro = Vademecum::whereIn('reg_medico', array_values(array_unique(array_merge($registrosMedicos, [(string) $fila->reg_medico]))))
            ->where('id', '<>', $fila->id)
            ->get()
            ->first(fn (Vademecum $candidato) => mb_strtoupper(trim((string) $candidato->nombrecomercial)) === $comercial
                && $clave($candidato->presentacion) === $clave($campos['presentacion']));

        if ($otro !== null) {
            throw new InvalidArgumentException(
                'Ya tienes «' . $comercial . ($campos['presentacion'] === null ? '' : '/' . $campos['presentacion'])
                . '» en tu vademécum (código ' . trim((string) $otro->codemedicina) . '). Cambia la presentación o edita ese.'
            );
        }
    }
}
