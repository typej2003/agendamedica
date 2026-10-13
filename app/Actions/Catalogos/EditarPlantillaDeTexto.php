<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\EscribeConRastro;
use App\Models\FormatoPrint;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Editar una plantilla de texto** (WEB-8.4): el guardado de `w_agregar_dietas` sobre un código que ya existe. **El código no se
 * edita** (el campo se deshabilita al cargar la fila); se editan el **título** (mayúsculas), el **texto** y el **tipo**.
 *
 * Escribe **sólo lo que cambió**, columna por columna, con el last-write-wins del sync y su `updated` en `sync_changes`
 * (`source = web`); la fila cambia su `updated_at`.
 *
 * ## Qué pasa con lo que ya se escribió
 *
 * Una plantilla **se copia** al campo de la consulta al elegirla: no hay referencia viva. Editarla (o quitarla) **no cambia** la
 * evolución, el informe ni las dietas ya escritas; sólo cambia lo que se ofrece de ahí en adelante.
 *
 * ## No duplicar
 *
 * El título (sin tildes ni mayúsculas) no puede ser el de **otra** plantilla del mismo tipo. Un duplicado histórico se puede seguir
 * editando mientras no se le ponga la identidad de otra.
 */
final class EditarPlantillaDeTexto
{
    use EscribeConRastro;

    /**
     * @param  array{tipo?:?string, titulo?:?string, descripcion?:?string}  $datos
     *
     * @throws InvalidArgumentException si falta algo, pasa del largo, el tipo no es de la lista o ya hay otra igual en ese tipo
     */
    public function ejecutar(
        FormatoPrint $fila,
        array $datos,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeEdicion {
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($fila, $datos, $cuando, $origen) {
            // Se relee la fila bajo candado: dos ediciones o una baja al mismo tiempo se turnan.
            $bloqueada = FormatoPrint::whereKey($fila->id)->lockForUpdate()->firstOrFail();

            $tipo = ReglasDeLaPlantillaDeTexto::tipo($datos['tipo'] ?? null, $bloqueada->tipo);
            $titulo = ReglasDeLaPlantillaDeTexto::titulo($datos['titulo'] ?? null);
            $texto = ReglasDeLaPlantillaDeTexto::texto($datos['descripcion'] ?? null);

            $this->noDuplicar($bloqueada, $tipo, $titulo);

            $nuevos = ['tipo' => $tipo, 'titulo' => $titulo];

            // Los mismos renglones con otro salto de línea no son un cambio.
            if (ReglasDeLaPlantillaDeTexto::textoComparable($texto) !== ReglasDeLaPlantillaDeTexto::textoComparable($bloqueada->descripcion)) {
                $nuevos['descripcion'] = $texto;
            }

            [$escritas, $bloqueadas] = $this->corregirFila($bloqueada, 'formato_print', $nuevos, $cuando, $origen);

            return new ResultadoDeEdicion($bloqueada, $escritas, $bloqueadas);
        });
    }

    private function noDuplicar(FormatoPrint $fila, string $tipo, string $titulo): void
    {
        // La identidad no cambió: no hay duplicado nuevo que evitar (el histórico se limpia quitando).
        if (trim((string) $fila->tipo) === $tipo && ReglasDeLaPlantillaDeTexto::clave($fila->titulo) === ReglasDeLaPlantillaDeTexto::clave($titulo)) {
            return;
        }

        $otra = FormatoPrint::where('reg_medico', $fila->reg_medico)
            ->where('id', '<>', $fila->id)
            ->get()
            ->first(fn (FormatoPrint $candidata) => trim((string) $candidata->tipo) === $tipo
                && ReglasDeLaPlantillaDeTexto::clave($candidata->titulo) === ReglasDeLaPlantillaDeTexto::clave($titulo));

        if ($otra !== null) {
            throw new InvalidArgumentException(CrearPlantillaDeTexto::mensajeDeDuplicado($otra));
        }
    }
}
