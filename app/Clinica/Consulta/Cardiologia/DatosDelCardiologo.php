<?php

namespace App\Clinica\Consulta\Cardiologia;

use App\Clinica\Consulta\TextoDeLaConsulta;
use App\Models\Consulta;
use Illuminate\Database\Eloquent\Model;

/**
 * **La lectura de los estudios cardiológicos de una consulta** (CAR-2): los valores de la pestaña abierta, leídos de la
 * tabla de una fila por consulta que declara `MapaDelCardiologo::TABLAS`.
 *
 * Los vacíos del legado (`''`, `none`) se leen como `null`; el resto se devuelve tal como está guardado (las medidas del
 * eco son texto en el escritorio).
 */
final class DatosDelCardiologo
{
    /** La fila de una tabla del mapa para la consulta, o `null`. */
    public function fila(Consulta $consulta, string $tabla): ?Model
    {
        [$modelo, $historia, $nro] = MapaDelCardiologo::TABLAS[$tabla];

        return $modelo::where('reg_medico', $consulta->reg_medico)
            ->where($historia, (int) $consulta->numhistoria)
            ->where($nro, (int) $consulta->nroconsulta)
            ->orderBy('id')->first();
    }

    /**
     * Los valores de una pestaña: clave del campo => texto o `null`.
     *
     * @return array<string,?string>
     */
    public function valores(Consulta $consulta, string $pestana): array
    {
        $filas = [];
        $valores = [];

        foreach (MapaDelCardiologo::deLaPestana($pestana) as $campo) {
            $tabla = $campo['tabla'];

            $filas[$tabla] ??= $this->fila($consulta, $tabla);
            $crudo = $filas[$tabla]?->getAttribute($campo['columna']);

            $valores[$campo['clave']] = self::legible($crudo, $campo['tipo'] ?? 'texto');
        }

        return $valores;
    }

    /** Un valor crudo como lo muestra la pantalla: los vacíos del legado como `null` y los números con la coma decimal. */
    public static function legible($crudo, string $tipo): ?string
    {
        $texto = TextoDeLaConsulta::limpio($crudo === null ? null : (string) $crudo);

        if ($texto === null || $tipo !== 'numero') {
            return $texto;
        }

        $numero = str_replace(',', '.', $texto);

        return is_numeric($numero) ? self::numeroLegible((float) $numero) : $texto;
    }

    /** Un número sin ceros de más y con la coma decimal de la web (`72.50` → `72,5`, `72.00` → `72`). */
    public static function numeroLegible(float $numero): string
    {
        return rtrim(rtrim(number_format($numero, 2, ',', ''), '0'), ',');
    }

    /** ¿La pestaña tiene algún dato cargado en esta consulta? */
    public function tieneDatos(Consulta $consulta, string $pestana): bool
    {
        foreach ($this->valores($consulta, $pestana) as $valor) {
            if ($valor !== null) {
                return true;
            }
        }

        return false;
    }
}
