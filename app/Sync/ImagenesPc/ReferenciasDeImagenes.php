<?php

namespace App\Sync\ImagenesPc;

use App\Models\ImagenDeLaPc;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * **Las referencias a archivos de la PC que tiene un médico y en qué estado están** (WEB-T.1). Lee las tablas legadas y
 * `imagenes_de_la_pc`; no escribe nada.
 *
 * Una *referencia* es una celda (tabla, fila, columna) de la lista blanca con un valor no vacío (el `''`, el `NULL` y el
 * literal `none` del legado son "sin imagen"). Está:
 *
 *  - **hecha** si `imagenes_de_la_pc` tiene una fila `subida` —o `omitida`, salvo que se pida reintentarlas— para esa
 *    celda **cuyo `ruta_pc` es el valor actual** (o cuya `ruta` de la nube es el valor actual: es lo que queda en
 *    `imagen_consulta` después de migrar). Si el escritorio cambió la ruta, el archivo anterior ya no es el de la fila y
 *    la referencia vuelve a ser pendiente;
 *  - **pendiente** en cualquier otro caso.
 *
 * Todo el SQL es el mismo en SQLite y en MySQL (`TRIM`/`COALESCE`/`LOWER`, `NOT EXISTS`): la comparación del valor se
 * hace sobre `TRIM(columna)` porque el legado (`char(256)`) puede traer relleno de espacios.
 */
final class ReferenciasDeImagenes
{
    public function __construct(private CatalogoDeImagenes $catalogo)
    {
    }

    /**
     * Las referencias con valor de este médico en (tabla, columna), con `id` y `ruta` (recortada).
     */
    public function conValor(string $regMedico, string $tabla, string $columna): Builder
    {
        $this->asegurar($tabla, $columna);
        $c = $this->col($tabla, $columna);

        $consulta = DB::table($tabla)
            ->select("{$tabla}.id", DB::raw("TRIM({$c}) as ruta"))
            ->whereRaw("COALESCE(TRIM({$c}), '') <> ''")
            ->whereRaw("LOWER(TRIM({$c})) <> 'none'");

        if ($this->catalogo->dueno($tabla) === 'paciente') {
            // `pacientes` no tiene `reg_medico`: el dueño es el médico que lo atiende.
            $consulta->whereExists(function ($s) use ($tabla, $regMedico) {
                $s->select(DB::raw(1))->from('medico_pacientes as mp')
                    ->whereColumn('mp.paciente_id', "{$tabla}.id")
                    ->where('mp.reg_medico', $regMedico);
            });
        } else {
            $consulta->where("{$tabla}.reg_medico", $regMedico);
        }

        return $consulta;
    }

    /**
     * Las referencias que **faltan**: sin archivo subido ni omitido para el valor actual.
     */
    public function pendientes(string $regMedico, string $tabla, string $columna, bool $reintentarOmitidas = false): Builder
    {
        $consulta = $this->conValor($regMedico, $tabla, $columna);

        return $consulta->whereNotExists(
            $this->registro($regMedico, $tabla, $columna, $reintentarOmitidas ? [ImagenDeLaPc::SUBIDA] : [ImagenDeLaPc::SUBIDA, ImagenDeLaPc::OMITIDA])
        );
    }

    /**
     * Las referencias que **ya tienen** un registro en `estado` para el valor actual.
     */
    public function conEstado(string $regMedico, string $tabla, string $columna, string $estado, ?string $motivo = null): Builder
    {
        return $this->conValor($regMedico, $tabla, $columna)
            ->whereExists($this->registro($regMedico, $tabla, $columna, [$estado], $motivo));
    }

    /**
     * Una página de pendientes, recorriendo los pares (tabla, columna) en orden y cada uno por `id`.
     *
     * El cursor es `<posición del par>:<último id>`; es opaco para el cliente. Devuelve también, la primera vez, el
     * total que falta.
     *
     * @return array{items:array<int,array{tabla:string,fila_id:int,columna:string,ruta_pc:string}>,siguiente:?string,pendientes_total:?int}
     */
    public function pagina(string $regMedico, ?string $cursor, int $limite, bool $reintentarOmitidas, ?string $soloTabla = null): array
    {
        $pares = $this->catalogo->pares($soloTabla);
        [$inicio, $ultimoId] = $this->leerCursor($cursor);

        $items = [];
        for ($i = $inicio; $i < count($pares) && count($items) < $limite; $i++) {
            $faltan = $limite - count($items);
            $filas = $this->pendientes($regMedico, $pares[$i]['tabla'], $pares[$i]['columna'], $reintentarOmitidas)
                ->where($pares[$i]['tabla'] . '.id', '>', $i === $inicio ? $ultimoId : 0)
                ->orderBy($pares[$i]['tabla'] . '.id')
                ->limit($faltan)
                ->get();

            foreach ($filas as $fila) {
                $items[] = [
                    'tabla' => $pares[$i]['tabla'],
                    'fila_id' => (int) $fila->id,
                    'columna' => $pares[$i]['columna'],
                    'ruta_pc' => (string) $fila->ruta,
                ];
            }
        }

        $siguiente = null;
        if (count($items) >= $limite) {
            $ultimo = $items[count($items) - 1];
            $posicion = array_search(
                [$ultimo['tabla'], $ultimo['columna']],
                array_map(fn ($p) => [$p['tabla'], $p['columna']], $pares),
                true
            );
            $siguiente = $posicion . ':' . $ultimo['fila_id'];
        }

        return [
            'items' => $items,
            'siguiente' => $siguiente,
            'pendientes_total' => $cursor === null || $cursor === '' ? $this->totalPendientes($regMedico, $reintentarOmitidas, $soloTabla) : null,
        ];
    }

    public function totalPendientes(string $regMedico, bool $reintentarOmitidas = false, ?string $soloTabla = null): int
    {
        $total = 0;
        foreach ($this->catalogo->pares($soloTabla) as $par) {
            $total += $this->pendientes($regMedico, $par['tabla'], $par['columna'], $reintentarOmitidas)->count();
        }

        return $total;
    }

    /**
     * Resumen por (tabla, columna) para `imagenes:estado`.
     *
     * @return array<int,array{tabla:string,columna:string,referencias:int,subidas:int,omitidas:array<string,int>,omitidas_total:int,pendientes:int,peso:int}>
     */
    public function resumen(string $regMedico): array
    {
        $filas = [];
        foreach ($this->catalogo->pares() as $par) {
            ['tabla' => $tabla, 'columna' => $columna] = $par;

            $referencias = $this->conValor($regMedico, $tabla, $columna)->count();
            $subidas = $this->conEstado($regMedico, $tabla, $columna, ImagenDeLaPc::SUBIDA)->count();

            $omitidas = [];
            $motivos = ImagenDeLaPc::where('reg_medico', $regMedico)->where('tabla', $tabla)->where('columna', $columna)
                ->where('estado', ImagenDeLaPc::OMITIDA)->distinct()->pluck('motivo');
            foreach ($motivos as $motivo) {
                $n = $this->conEstado($regMedico, $tabla, $columna, ImagenDeLaPc::OMITIDA, $motivo)->count();
                if ($n > 0) {
                    $omitidas[(string) ($motivo ?: 'otro')] = $n;
                }
            }
            $omitidasTotal = array_sum($omitidas);

            $peso = (int) ImagenDeLaPc::where('reg_medico', $regMedico)->where('tabla', $tabla)->where('columna', $columna)
                ->where('estado', ImagenDeLaPc::SUBIDA)->sum('tamano');

            if ($referencias === 0 && $peso === 0 && $omitidasTotal === 0) {
                continue;
            }

            $filas[] = [
                'tabla' => $tabla,
                'columna' => $columna,
                'referencias' => $referencias,
                'subidas' => $subidas,
                'omitidas' => $omitidas,
                'omitidas_total' => $omitidasTotal,
                'pendientes' => max(0, $referencias - $subidas - $omitidasTotal),
                'peso' => $peso,
            ];
        }

        return $filas;
    }

    /**
     * La celda (tabla, fila, columna) **de este médico**, con su valor actual recortado; `null` si no existe, no es suya o
     * está vacía.
     *
     * @return object{id:int,ruta:string}|null
     */
    public function celda(string $regMedico, string $tabla, int $filaId, string $columna): ?object
    {
        if (! $this->catalogo->permite($tabla, $columna)) {
            return null;
        }

        $celda = DB::table($tabla)->select("{$tabla}.id", DB::raw('TRIM(' . $this->col($tabla, $columna) . ') as ruta'))->where("{$tabla}.id", $filaId);

        if ($this->catalogo->dueno($tabla) === 'paciente') {
            $celda->whereExists(function ($s) use ($tabla, $regMedico) {
                $s->select(DB::raw(1))->from('medico_pacientes as mp')
                    ->whereColumn('mp.paciente_id', "{$tabla}.id")
                    ->where('mp.reg_medico', $regMedico);
            });
        } else {
            $celda->where("{$tabla}.reg_medico", $regMedico);
        }

        return $celda->first();
    }

    /**
     * La subconsulta `imagenes_de_la_pc` que dice "ya hay un registro así para el valor actual de la celda", como cierre
     * (`whereExists` de Laravel 8 sólo acepta un `Closure`).
     */
    private function registro(string $regMedico, string $tabla, string $columna, array $estados, ?string $motivo = null): \Closure
    {
        $c = $this->col($tabla, $columna);

        return function ($s) use ($regMedico, $tabla, $columna, $estados, $motivo, $c) {
            $s->select(DB::raw(1))->from('imagenes_de_la_pc as r')
                ->where('r.reg_medico', $regMedico)
                ->where('r.tabla', $tabla)
                ->where('r.columna', $columna)
                ->whereColumn('r.fila_id', $tabla . '.id')
                ->whereIn('r.estado', $estados)
                ->where(function ($w) use ($c) {
                    $w->whereRaw("r.ruta_pc = TRIM({$c})")->orWhereRaw("r.ruta = TRIM({$c})");
                });

            if ($motivo !== null) {
                $s->where('r.motivo', $motivo);
            }
        };
    }

    /** @return array{0:int,1:int} */
    private function leerCursor(?string $cursor): array
    {
        if ($cursor !== null && preg_match('/^(\d+):(\d+)$/', $cursor, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }

        return [0, 0];
    }

    /** El `tabla.columna` ya validado contra la lista blanca, entrecomillado. */
    private function col(string $tabla, string $columna): string
    {
        $this->asegurar($tabla, $columna);

        return DB::getQueryGrammar()->wrap($tabla . '.' . $columna);
    }

    private function asegurar(string $tabla, ?string $columna = null): void
    {
        $ok = $columna === null ? isset($this->catalogo->tablas()[$tabla]) : $this->catalogo->permite($tabla, $columna);

        if (! $ok) {
            throw new \InvalidArgumentException("La tabla/columna {$tabla}.{$columna} no está en la lista de imágenes de la PC.");
        }
    }
}
