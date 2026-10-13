<?php

namespace App\Actions\Embarazo;

use App\Actions\Consulta\EscribeConRastro;
use App\Actions\Consulta\ResultadoDeSolicitud;
use App\Clinica\Embarazo\Gesta;
use App\Models\PrenaExamenB;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Guardar un laboratorio del embarazo** (WEB-6.6, la pestaña *Laboratorio* del ítem 6).
 *
 * La fila es de `prena_exames_b` con la clave del DataWindow: **`historia` + `gesta_clave` + `fecha`**
 * (`d_pacientes_exa_b.srd:6-8`), o sea **una por fecha de laboratorio** dentro de una gesta. Se corrige
 * **sólo lo que cambió**, columna por columna, con el rastro en `sync_changes` (`source = web`) y el
 * last-write-wins del sync — el trait `EscribeConRastro`, el mismo de la gesta y del eco—.
 *
 * **Una fecha es obligatoria**: es parte de la clave (sin ella no se sabe qué laboratorio se está
 * guardando, y el escritorio pide la fecha al abrir la rutina: `w_agregar_nueva_gesta_rutina_examen.srw`).
 *
 * Las columnas que el DataWindow tiene **ocultas** (`PrenaExamenB::COLUMNAS_QUE_NO_SE_TOCAN`: plaquetas,
 * TSH, insulina…) quedan como estén: el escritorio las persiste pero el médico no las ve en esta pestaña.
 */
final class GuardarLaboratorioDelEmbarazo
{
    use EscribeConRastro;

    public const TABLA = 'prena_exames_b';

    /** Las columnas de número del laboratorio: lo que no sea número queda vacío. */
    private const NUMERICAS = [
        'hemoglobina', 'hematoc', 'glob_blanco', 'neut_linf', 'triglicer', 'colesterol', 'glicemia_basal',
        'ac_urico', 'progester', 'prolactina', 'urea', 'creatinina',
    ];

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

        foreach (PrenaExamenB::campos() as $campo) {
            $columna = $campo['columna'];
            $nuevos[$columna] = in_array($columna, self::NUMERICAS, true)
                ? $this->numero($valores[$columna] ?? null)
                : Gesta::texto($valores[$columna] ?? null);
        }

        if ($nuevos['fecha'] === null) {
            throw new InvalidArgumentException('El laboratorio necesita su fecha: es parte de su clave.');
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
                $this->crearFila(PrenaExamenB::class, self::TABLA, array_merge($nuevos, [
                    'reg_medico' => $regMedico,
                    'historia' => $numhistoria,
                    'gesta_clave' => $gestaClave,
                ]), $cuando, $origen);

                return new ResultadoDeSolicitud(creado: true);
            }

            [$escritas, $bloqueadas] = $this->corregirFila(
                PrenaExamenB::find($fila->id), self::TABLA, $nuevos, $cuando, $origen,
            );

            return new ResultadoDeSolicitud(columnas: $escritas, bloqueadas: $bloqueadas);
        });
    }

    /**
     * Un valor de laboratorio: el número con punto (el médico puede escribir coma) y en blanco si no es un
     * número —un "<0.5" o un "negativo" no entran en una columna de número, y la fuente no dice qué hacer:
     * se guarda en blanco en vez de inventar—.
     */
    private function numero($crudo): ?string
    {
        $texto = Gesta::texto($crudo);

        if ($texto === null) {
            return null;
        }

        $texto = str_replace(',', '.', $texto);

        return is_numeric($texto) ? $texto : null;
    }
}
