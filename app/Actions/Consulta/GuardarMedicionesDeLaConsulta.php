<?php

namespace App\Actions\Consulta;

use App\Clinica\Crecimiento\MedicionesDeLaConsulta;
use App\Clinica\Crecimiento\MedidasAntropometricas;
use App\Models\Consulta;
use App\Models\PercentilesPaciente;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Guardar las mediciones de una consulta** (WEB-7.4.3a): talla, peso, perímetro cefálico, perímetro del brazo, pulso y presión arterial
 * del escritorio de pediatría (la pestaña *Mediciones* de `w_consulta_principal`), en **`percentiles_paciente`**.
 *
 * Las reglas:
 *
 *  - **Una fila por consulta**, con la clave del legado: `hhistoria` (el `numhistoria`) + `hconsulta` (el `nroconsulta`) y el `reg_medico`.
 *  - **Sólo lo que cambió**, con el rastro por columna en `sync_changes` (`source = web`) y el last-write-wins del sync (`EscribeConRastro`).
 *    Una columna que no viaja en `$valores` no se toca; una que viaja vacía se borra.
 *  - **El IMC se guarda** (el escritorio lo escribe en `imc` con `gffIMC`): se recalcula con el peso y la talla que queden. Sin uno de los
 *    dos queda `null`. El «AC» del escritorio no tiene columna: se calcula al mostrarlo.
 *  - **`fecha`** es la de la consulta (el escritorio guarda la fecha de la pantalla).
 *  - **Una fila sin ninguna medida no se crea**: guardar el formulario vacío no deja filas en blanco.
 *
 * Unidades: kg y cm, como el escritorio. La usa la web y la reutilizará el móvil (R3). El escritorio no verá nunca lo que se cargue acá.
 */
final class GuardarMedicionesDeLaConsulta
{
    use EscribeConRastro;

    /** Las columnas de `percentiles_paciente` que el formulario puede escribir. */
    public const CAMPOS = ['peso', 'talla', 'c_cefalica', 'c_brazo', 'pulso', 'p_a_sistolica', 'p_a_diastolica'];

    public function __construct(private MedicionesDeLaConsulta $lectura)
    {
    }

    /**
     * @param  array<string,mixed>  $valores  las claves de `CAMPOS` que viajaron (texto o número; la coma decimal se acepta)
     * @param  string|null  $origen  `web`: quién escribe, para el rastro. `null` no anota rastro
     *
     * @throws InvalidArgumentException si alguna medida no es un número positivo
     */
    public function ejecutar(Consulta $consulta, array $valores, ?CarbonInterface $cuando = null, ?string $origen = 'web'): ResultadoDeSolicitud
    {
        $nuevos = [];
        foreach (self::CAMPOS as $campo) {
            if (array_key_exists($campo, $valores)) {
                $nuevos[$campo] = self::numero($campo, $valores[$campo]);
            }
        }

        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($consulta, $nuevos, $cuando, $origen) {
            $fila = $this->lectura->de($consulta);

            if ($fila === null) {
                if (count(array_filter($nuevos, fn ($valor) => $valor !== null)) === 0) {
                    return new ResultadoDeSolicitud();
                }

                $datos = array_merge(array_fill_keys(self::CAMPOS, null), $nuevos, [
                    'reg_medico' => $consulta->reg_medico,
                    'hhistoria' => (int) $consulta->numhistoria,
                    'hconsulta' => (int) $consulta->nroconsulta,
                    'fecha' => $consulta->fecha?->toDateString(),
                    'imc' => self::imc($nuevos['peso'] ?? null, $nuevos['talla'] ?? null),
                ]);

                $this->crearFila(PercentilesPaciente::class, 'percentiles_paciente', $datos, $cuando, $origen);

                return new ResultadoDeSolicitud(creado: true);
            }

            $nuevos['imc'] = self::imc(
                array_key_exists('peso', $nuevos) ? $nuevos['peso'] : $fila->peso,
                array_key_exists('talla', $nuevos) ? $nuevos['talla'] : $fila->talla,
            );

            // La fecha de la consulta se completa si la fila vino sin ella (la del escritorio la trae siempre).
            if ($fila->fecha === null && $consulta->fecha !== null) {
                $nuevos['fecha'] = $consulta->fecha->toDateString();
            }

            [$escritas, $bloqueadas] = $this->corregirFila($fila, 'percentiles_paciente', $nuevos, $cuando, $origen);

            return new ResultadoDeSolicitud(columnas: $escritas, bloqueadas: $bloqueadas);
        });
    }

    /** El IMC que se guarda: el del escritorio (`gffIMC`) o `null` si falta el peso o la talla. */
    private static function imc(?float $peso, ?float $talla): ?float
    {
        $imc = MedidasAntropometricas::imc($peso, $talla);

        return $imc > 0 ? $imc : null;
    }

    private static function numero(string $campo, mixed $valor): ?float
    {
        if ($valor === null || trim((string) $valor) === '') {
            return null;
        }

        $texto = str_replace(',', '.', trim((string) $valor));

        if (! is_numeric($texto) || (float) $texto <= 0) {
            throw new InvalidArgumentException("La medida «{$campo}» tiene que ser un número mayor que cero.");
        }

        return round((float) $texto, 3);
    }
}
