<?php

namespace App\Actions\Embarazo;

use App\Actions\Consulta\EscribeConRastro;
use App\Actions\Consulta\ResultadoDeSolicitud;
use App\Clinica\Embarazo\Gesta;
use App\Models\PreNatalDesarrolloFino;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Guardar un control del embarazo** (WEB-6.6, la pestaña *Controles* del ítem 6).
 *
 * La fila es de `pre_natal_desarrollo_fino` con la clave del DataWindow: **`historia` + `gesta_clave` +
 * `fecha`** (`d_pre_natal_rutin_eco_multiple.srd:6`, `:7` y `:22`), o sea **una por fecha de control**
 * dentro de una gesta — el mismo patrón que el laboratorio—. Corrige **sólo lo que cambió**, con el rastro
 * por columna en `sync_changes` (`source = web`) y el last-write-wins del sync (el trait
 * `EscribeConRastro`).
 *
 * **La fecha es obligatoria** (es parte de la clave). Las columnas que el DataWindow tiene ocultas
 * (`PreNatalDesarrolloFino::COLUMNAS_QUE_NO_SE_TOCAN`) quedan como estén.
 *
 * Todas las columnas editables son de **texto** en la tabla (`char`), así que no hay nada que convertir: el
 * médico escribe «120/80», «20s + 6d» y lo que la columna guarda es eso.
 */
final class GuardarControlDelEmbarazo
{
    use EscribeConRastro;

    public const TABLA = 'pre_natal_desarrollo_fino';

    /**
     * @param  array<string,mixed>  $valores  las columnas del formulario
     * @param  string|null  $origen  `web`: quién escribe, para el rastro. `null` no anota rastro
     *
     * @throws InvalidArgumentException si no viene la fecha
     */
    public function ejecutar(
        string $regMedico,
        int $numhistoria,
        int $gestaClave,
        array $valores,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeSolicitud {
        $nuevos = ['fecha' => Gesta::texto($valores['fecha'] ?? null)];

        foreach (PreNatalDesarrolloFino::campos() as $campo) {
            $nuevos[$campo['columna']] = Gesta::texto($valores[$campo['columna']] ?? null);
        }

        if ($nuevos['fecha'] === null) {
            throw new InvalidArgumentException('El control necesita su fecha: es parte de su clave.');
        }

        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($regMedico, $numhistoria, $gestaClave, $nuevos, $cuando, $origen) {
            $fila = DB::table(self::TABLA)
                ->where('reg_medico', $regMedico)
                ->where('historia', $numhistoria)
                ->where('gesta_clave', $gestaClave)
                ->where('fecha', $nuevos['fecha'])
                ->first();

            if ($fila === null) {
                $this->crearFila(PreNatalDesarrolloFino::class, self::TABLA, array_merge($nuevos, [
                    'reg_medico' => $regMedico,
                    'historia' => $numhistoria,
                    'gesta_clave' => $gestaClave,
                ]), $cuando, $origen);

                return new ResultadoDeSolicitud(creado: true);
            }

            [$escritas, $bloqueadas] = $this->corregirFila(
                PreNatalDesarrolloFino::find($fila->id), self::TABLA, $nuevos, $cuando, $origen,
            );

            return new ResultadoDeSolicitud(columnas: $escritas, bloqueadas: $bloqueadas);
        });
    }
}
