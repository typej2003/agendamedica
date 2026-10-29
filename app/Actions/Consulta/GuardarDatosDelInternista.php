<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\Interna\DatosDelInternista;
use App\Clinica\Consulta\Interna\MapaDelInternista;
use App\Clinica\Consulta\TextoDeLaConsulta;
use App\Models\Consulta;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * **Guardar una pestaña de la consulta del internista** (MI-2 a MI-5): signos vitales, examen funcional, examen físico, resumen
 * físico, EKG, Rx de tórax, laboratorio, observaciones y las estimaciones pre-operatorias.
 *
 * Una sola regla para todas, porque todas tienen la misma forma: columnas de `consultas` (la fila ya existe) y columnas de
 * tablas de **una fila por consulta** (`MapaDelInternista::TABLAS`). Guarda **sólo lo que cambió** (columna que no viaja, no se
 * toca; vacía, se borra), con el rastro por columna en `sync_changes` (`source = web`) y el last-write-wins del sync, y **no crea
 * una fila en blanco**: una tabla sin ningún dato no nace (una casilla en `N` no cuenta como dato).
 *
 * Las casillas `S`/`N` (los bloqueos del EKG, la emergencia) viajan como el resto: el formulario manda `N` en un oculto antes de
 * la casilla, así que una casilla desmarcada llega como `N` y no se confunde con «no viajó».
 *
 * Los **signos** calculan el IMC, como el escritorio (`gffIMC(peso, talla)` con la talla en metros, guardado en
 * `datos_grafica.indice_masa`): si la talla viene en centímetros (más de 3), se pasa a metros para la cuenta.
 */
final class GuardarDatosDelInternista
{
    use EscribeConRastro;

    /**
     * @param  array<string,mixed>  $valores  clave del campo => lo que viajó
     */
    public function ejecutar(Consulta $consulta, string $pestana, array $valores, ?CarbonInterface $cuando = null, ?string $origen = 'web'): ResultadoDeSolicitud
    {
        $porTabla = [];

        foreach (MapaDelInternista::deLaPestana($pestana) as $campo) {
            if (! array_key_exists($campo['clave'], $valores)) {
                continue;
            }

            $porTabla[$campo['tabla']][$campo['columna']] = self::normalizar($campo, $valores[$campo['clave']]);
        }

        if ($pestana === 'signos' && (array_key_exists('peso', $valores) || array_key_exists('talla', $valores))) {
            $porTabla['datos_grafica']['indice_masa'] = self::imc(
                $porTabla['consultas']['peso'] ?? $consulta->peso,
                $porTabla['consultas']['talla'] ?? $consulta->talla,
            );
        }

        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($consulta, $porTabla, $cuando, $origen) {
            $creado = false;
            $escritas = [];
            $bloqueadas = [];

            foreach ($porTabla as $tabla => $nuevos) {
                if ($tabla === 'consultas') {
                    [$e, $b] = $this->corregirFila($consulta->newQuery()->lockForUpdate()->findOrFail($consulta->id), 'consultas', $nuevos, $cuando, $origen);
                    array_push($escritas, ...$e);
                    array_push($bloqueadas, ...$b);
                    continue;
                }

                [$modelo, $historia, $nro] = MapaDelInternista::TABLAS[$tabla];

                $fila = $modelo::where('reg_medico', $consulta->reg_medico)
                    ->where($historia, (int) $consulta->numhistoria)->where($nro, (int) $consulta->nroconsulta)
                    ->lockForUpdate()->orderBy('id')->first();

                if ($fila === null) {
                    if (array_filter($nuevos, fn ($v) => $v !== null && $v !== 'N') === []) {
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

        return match ($campo['tipo']) {
            'numero' => DatosDelInternista::numero($texto),
            'si_no' => $texto === 'S' ? 'S' : 'N',
            'opcion' => $texto !== null && isset($campo['opciones'][$texto]) ? $texto : null,
            default => $texto === null ? null : mb_substr($texto, 0, $campo['max'] ?? 65535),
        };
    }

    /** El IMC con dos decimales, o `null` si falta el peso o la talla. */
    public static function imc($peso, $talla): ?float
    {
        $peso = DatosDelInternista::numero($peso);
        $talla = DatosDelInternista::numero($talla);

        if ($peso === null || $talla === null) {
            return null;
        }

        $metros = $talla > 3 ? $talla / 100 : $talla;

        return round($peso / ($metros ** 2), 2);
    }
}
