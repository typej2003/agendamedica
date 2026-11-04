<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\Cardiologia\MapaDelCardiologo;
use App\Clinica\Consulta\TextoDeLaConsulta;
use App\Models\Consulta;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * **Guardar una pestaña de los estudios cardiológicos** (CAR-2): el ecocardiograma transtorácico, y las pestañas que
 * sumen CAR-3 a CAR-5.
 *
 * Una sola regla para todas, como en medicina interna: una tabla de **una fila por consulta** (`MapaDelCardiologo::TABLAS`),
 * **sólo lo que cambió** (la columna que no viaja no se toca; vacía, se borra), rastro por columna en `sync_changes`
 * (`source = web`) y el last-write-wins del sync. **No crea una fila en blanco**: un estudio sin ningún dato no nace.
 */
final class GuardarDatosDelCardiologo
{
    use EscribeConRastro;

    /**
     * @param  array<string,mixed>  $valores  clave del campo => lo que viajó
     */
    public function ejecutar(Consulta $consulta, string $pestana, array $valores, ?CarbonInterface $cuando = null, ?string $origen = 'web'): ResultadoDeSolicitud
    {
        $porTabla = [];

        foreach (MapaDelCardiologo::deLaPestana($pestana) as $campo) {
            if (! array_key_exists($campo['clave'], $valores)) {
                continue;
            }

            $porTabla[$campo['tabla']][$campo['columna']] = self::normalizar($campo, $valores[$campo['clave']]);
        }

        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($consulta, $porTabla, $cuando, $origen) {
            $creado = false;
            $escritas = [];
            $bloqueadas = [];

            foreach ($porTabla as $tabla => $nuevos) {
                [$modelo, $historia, $nro] = MapaDelCardiologo::TABLAS[$tabla];

                $fila = $modelo::where('reg_medico', $consulta->reg_medico)
                    ->where($historia, (int) $consulta->numhistoria)->where($nro, (int) $consulta->nroconsulta)
                    ->lockForUpdate()->orderBy('id')->first();

                if ($fila === null) {
                    if (array_filter($nuevos, fn ($v) => $v !== null) === []) {
                        continue;
                    }

                    $this->crearFila($modelo, $tabla, $nuevos + [
                        'reg_medico' => $consulta->reg_medico,
                        $historia => (int) $consulta->numhistoria,
                        $nro => (int) $consulta->nroconsulta,
                    ], $cuando, $origen);
                    $creado = true;
                    continue;
                }

                [$e, $b] = $this->corregirFila($fila, $tabla, $nuevos, $cuando, $origen);
                array_push($escritas, ...$e);
                array_push($bloqueadas, ...$b);
            }

            return new ResultadoDeSolicitud(creado: $creado, columnas: $escritas, bloqueadas: $bloqueadas);
        });
    }

    /** El valor de un campo como se guarda. */
    public static function normalizar(array $campo, $valor)
    {
        $texto = TextoDeLaConsulta::limpio(is_array($valor) ? end($valor) : $valor);

        return match ($campo['tipo'] ?? 'texto') {
            // Las casillas S/N del escritorio (`farmacologico`): una desmarcada viaja como `N`.
            'si_no' => $texto === 'S' ? 'S' : 'N',
            'opcion' => $texto !== null && isset($campo['opciones'][$texto]) ? $texto : null,
            // Las medidas del Doppler: se acepta la coma decimal y se guarda como número.
            'numero' => $texto !== null && is_numeric(str_replace(',', '.', $texto)) ? (float) str_replace(',', '.', $texto) : null,
            default => $texto === null ? null : mb_substr($texto, 0, $campo['max'] ?? 65535),
        };
    }
}
