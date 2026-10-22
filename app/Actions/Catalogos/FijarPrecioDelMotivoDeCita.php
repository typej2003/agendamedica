<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\EscribeConRastro;
use App\Models\Medico;
use App\Models\MotivoCita;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * **Fijar el precio de un motivo desde el formulario de la cita** (el motivo no tenía precio, `precio` NULL, y quien
 * agenda lo escribió en el monto).
 *
 * - Si el motivo **es de una sede** (o no se agenda en ninguna sede), se fija ahí con `EditarMotivoDeCita`.
 * - Si es un motivo **general** (sin sede), **no se toca**: el precio se guarda sólo para la sede de la cita, en el motivo de
 *   esa sede. Si ya existe uno (mismo código o misma razón), se usa; si no, se crea con el **mismo código** y los mismos
 *   datos del general. Con el mismo código no se duplica nada visible: `MotivosDeCita::de()` deja, por código, el de la
 *   sede sobre el general, y `cola.tipo` (que guarda el código) sigue apuntando al mismo motivo.
 *
 * Devuelve el motivo que quedó con el precio; su `codigo` es el que la cita debe guardar. Deja el rastro de
 * `sync_changes` (`created` o `updated`, `source = web`) con las mismas Actions y reglas que el catálogo.
 */
final class FijarPrecioDelMotivoDeCita
{
    use EscribeConRastro;

    public function __construct(private EditarMotivoDeCita $editar)
    {
    }

    public function ejecutar(
        MotivoCita $motivo,
        Medico $medico,
        string $regMedico,
        ?int $sedeId,
        float $precio,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): MotivoCita {
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($motivo, $medico, $regMedico, $sedeId, $precio, $cuando, $origen) {
            if ($motivo->office_id !== null || $sedeId === null) {
                $this->editar->ejecutar($motivo, $medico, ['precio' => $precio], $cuando, $origen);

                return $motivo->refresh();
            }

            $catalogo = MotivoCita::where('reg_medico', $regMedico)->where('office_id', $sedeId)->lockForUpdate()->get();
            $clave = ReglasDelMotivoDeCita::clave($motivo->tipo_atencion);
            $propio = $catalogo->first(fn (MotivoCita $fila) => (string) $fila->codigo === (string) $motivo->codigo)
                ?? $catalogo->first(fn (MotivoCita $fila) => ReglasDelMotivoDeCita::clave($fila->tipo_atencion) === $clave);

            if ($propio !== null) {
                $this->editar->ejecutar($propio, $medico, ['precio' => $precio], $cuando, $origen);

                return $propio->refresh();
            }

            return $this->crearFila(MotivoCita::class, 'motivo_cita', [
                'reg_medico' => $regMedico,
                'codigo' => $motivo->codigo,
                'tipo_atencion' => $motivo->tipo_atencion,
                'precio' => ReglasDelMotivoDeCita::precio($precio),
                'office_id' => $sedeId,
                'admite_varias_citas_pendientes' => $motivo->admite_varias_citas_pendientes ? true : null,
            ], $cuando, $origen);
        });
    }
}
