<?php

namespace App\Clinica\Consulta\Interna;

use App\Clinica\Consulta\TextoDeLaConsulta;
use App\Models\Consulta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **La lectura de la consulta del internista** (MI-2 a MI-5): los valores de las solapas propias, leídos de `consultas` y de
 * las tablas de una fila por consulta del mapa (`MapaDelInternista::TABLAS`), y la evolución entre consultas del análisis
 * evolutivo (MI-4).
 *
 * Los vacíos del legado (`''`, `none`) se leen como `null`. Un número sin decimales significativos se muestra sin ellos
 * (`72` y no `72.00`): el escritorio guarda `decimal` y la pantalla los muestra así.
 */
final class DatosDelInternista
{
    /** La fila de una tabla del mapa para la consulta, o `null`. */
    public function fila(Consulta $consulta, string $tabla): ?Model
    {
        [$modelo, $historia, $nro] = MapaDelInternista::TABLAS[$tabla];

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

        foreach (MapaDelInternista::deLaPestana($pestana) as $campo) {
            $tabla = $campo['tabla'];

            if ($tabla === 'consultas') {
                $crudo = $consulta->getAttribute($campo['columna']);
            } else {
                $filas[$tabla] ??= $this->fila($consulta, $tabla) ?? false;
                $crudo = $filas[$tabla] === false ? null : $filas[$tabla]->getAttribute($campo['columna']);
            }

            $valores[$campo['clave']] = self::legible($crudo, $campo['tipo']);
        }

        return $valores;
    }

    /** ¿La pestaña tiene algún dato cargado en esta consulta? */
    public function tieneDatos(Consulta $consulta, string $pestana): bool
    {
        $valores = $this->valores($consulta, $pestana);

        foreach (MapaDelInternista::deLaPestana($pestana) as $campo) {
            $valor = $valores[$campo['clave']] ?? null;

            // Una casilla en `N` no es un dato: es la casilla sin marcar del escritorio.
            if ($valor !== null && ! ($campo['tipo'] === 'si_no' && $valor === 'N')) {
                return true;
            }
        }

        return false;
    }

    /**
     * **La evolución de la historia** para el análisis evolutivo (MI-4): una fila por consulta, de la más vieja a la más nueva,
     * con la fecha y los valores de signos y laboratorio que el escritorio grafica (`d_reporte_garficas`).
     *
     * @return Collection<int,array{nroconsulta:int, fecha:?string, valores:array<string,?float>}>
     */
    public function evolucion(Consulta $consulta): Collection
    {
        $campos = self::camposDeLaEvolucion();

        $consultas = DB::table('consultas')
            ->where('reg_medico', $consulta->reg_medico)
            ->where('numhistoria', $consulta->numhistoria)
            ->orderBy('nroconsulta')
            ->get();

        $graficas = DB::table('datos_grafica')
            ->where('reg_medico', $consulta->reg_medico)
            ->where('historia', (int) $consulta->numhistoria)
            ->get()
            ->keyBy(fn ($fila) => (int) $fila->consulta);

        return $consultas->map(function ($fila) use ($campos, $graficas) {
            $grafica = $graficas->get((int) $fila->nroconsulta);
            $valores = [];

            foreach ($campos as $campo) {
                $crudo = $campo['tabla'] === 'consultas' ? ($fila->{$campo['columna']} ?? null) : ($grafica?->{$campo['columna']} ?? null);
                $valores[$campo['clave']] = self::numero($crudo);
            }

            return [
                'nroconsulta' => (int) $fila->nroconsulta,
                'fecha' => $fila->fecha ? substr((string) $fila->fecha, 0, 10) : null,
                'valores' => $valores,
            ];
        })->filter(fn (array $fila) => array_filter($fila['valores'], fn ($v) => $v !== null) !== [])->values();
    }

    /**
     * Los valores que entran en el análisis evolutivo: los numéricos de los signos (peso, cintura, IMC, tensión sistólica no:
     * es texto) y todo el laboratorio.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function camposDeLaEvolucion(): array
    {
        $signos = array_values(array_filter(
            MapaDelInternista::deLaPestana('signos'),
            fn (array $campo) => in_array($campo['clave'], ['peso', 'cintura'], true),
        ));

        return array_merge(
            $signos,
            [['clave' => 'imc', 'etiqueta' => 'IMC', 'tabla' => 'datos_grafica', 'columna' => 'indice_masa', 'tipo' => 'numero', 'unidad' => 'kg/m²']],
            MapaDelInternista::deLaPestana('laboratorio'),
        );
    }

    /** Un valor crudo como lo muestra la pantalla. */
    public static function legible($crudo, string $tipo): ?string
    {
        $texto = TextoDeLaConsulta::limpio($crudo === null ? null : (string) $crudo);

        if ($texto === null || $tipo !== 'numero') {
            return $texto;
        }

        $numero = self::numero($texto);

        return $numero === null ? $texto : self::numeroLegible($numero);
    }

    /** Un número sin ceros de más y con la coma decimal de la web (`72.50` → `72,5`, `72.00` → `72`). */
    public static function numeroLegible(float $numero): string
    {
        return rtrim(rtrim(number_format($numero, 2, ',', ''), '0'), ',');
    }

    /** El valor como número, o `null` si no es un número (o es 0, que en el escritorio es «sin dato»). */
    public static function numero($crudo): ?float
    {
        if ($crudo === null) {
            return null;
        }

        $texto = str_replace(',', '.', trim((string) $crudo));

        if ($texto === '' || ! is_numeric($texto)) {
            return null;
        }

        $numero = (float) $texto;

        return $numero == 0.0 ? null : $numero;
    }
}
