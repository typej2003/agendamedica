<?php

namespace App\Actions\Catalogos;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Alta y edición de una fila de la referencia de crecimiento inicial** (WEB-7.4.3e, la `d_modifi_percentiles` del escritorio de pediatría): las tablas
 * `percentil_nino` y `percentil_nina` con `reg_medico` NULL, que son lo que ve todo médico que no cargó la suya (`ReferenciaDeCrecimiento`). **Sólo toca filas sin
 * dueño**: las de un consultorio no se encuentran, y no hay edición por médico (decisión de Alexander, 2026-10-17). Sólo el panel de administración la usa.
 *
 * Una fila es un **rango de edad en días** (`edad_ini_dia`–`edad_fin_dia`) con tres valores por medida —**bajo, medio y alto**— de peso, talla, perímetro
 * cefálico, perímetro del brazo e IMC. Las reglas:
 *  - **Rango**: enteros, el inicio menor que el fin y hasta 36.500 días. **Dos filas no se solapan** (compartir el borde sí: el rango siguiente empieza donde termina
 *    el anterior, como las tablas de la OMS que trae el legado) y entre dos vecinas **no queda un hueco de más de {@see self::HUECO_MAXIMO} días**.
 *  - **Medidas**: cada medida va completa (bajo, medio y alto) o vacía; con valores, **bajo ≤ medio ≤ alto**, de 0 a 999. Tiene que haber al menos peso o talla.
 *  - **Edad en años, en meses y texto**: opcionales; si se dejan vacíos se calculan (años = inicio / 365,25; meses = fin / 30,4375; el texto dice el rango en días).
 *
 * La presión arterial y el pulso no se editan acá: el escritorio no tiene una ventana para ellas.
 */
final class GuardarPercentilInicial
{
    public const TABLAS = ['nino' => 'percentil_nino', 'nina' => 'percentil_nina'];

    public const MEDIDAS = ['peso' => 'Peso', 'talla' => 'Talla', 'cefalica' => 'Perímetro cefálico', 'brazo' => 'Perímetro del brazo', 'imc' => 'IMC'];

    public const DIAS_MAXIMOS = 36500;
    public const HUECO_MAXIMO = 365;
    public const VALOR_MAXIMO = 999;

    /**
     * @param  array<string,mixed>  $datos  `edad_ini_dia`, `edad_fin_dia`, `edad_texto`, `edad_meses`, `anos` y `<medida>_b|m|a`
     * @param  int|null  $id  la fila que se edita (`null` = alta)
     * @return int  el id de la fila
     *
     * @throws InvalidArgumentException con el mensaje que ve el administrador; el campo del que habla se deduce con {@see self::campoDelError()}
     */
    public function ejecutar(string $sexo, array $datos, ?int $id = null): int
    {
        $tabla = self::TABLAS[$sexo] ?? throw new InvalidArgumentException('Elige si la referencia es de niños o de niñas.');

        $ini = $this->entero($datos['edad_ini_dia'] ?? null, 'El inicio del rango');
        $fin = $this->entero($datos['edad_fin_dia'] ?? null, 'El fin del rango');
        if ($fin <= $ini) {
            throw new InvalidArgumentException('El fin del rango tiene que ser mayor que el inicio.');
        }

        $fila = ['edad_ini_dia' => $ini, 'edad_fin_dia' => $fin] + $this->medidas($datos);

        $texto = trim((string) ($datos['edad_texto'] ?? ''));
        if (mb_strlen($texto) > 100) {
            throw new InvalidArgumentException('El texto de la edad no puede pasar de 100 caracteres.');
        }
        $fila['edad_texto'] = $texto !== '' ? $texto : 'Días ' . $ini . ' a ' . $fin;
        $fila['edad_meses'] = $this->opcional($datos['edad_meses'] ?? null, 'Los meses', 1200, true) ?? (int) round($fin / 30.4375);
        $fila['anos'] = $this->opcional($datos['anos'] ?? null, 'Los años', 100, false) ?? round($ini / 365.25, 2);

        return DB::transaction(function () use ($tabla, $fila, $id, $ini, $fin) {
            if ($id !== null && ! DB::table($tabla)->whereNull('reg_medico')->where('id', $id)->exists()) {
                throw new InvalidArgumentException('Esa fila no está en la referencia inicial.');
            }

            $vecinas = DB::table($tabla)->whereNull('reg_medico')->when($id, fn ($q) => $q->where('id', '!=', $id))->lockForUpdate()
                ->get(['id', 'edad_ini_dia', 'edad_fin_dia', 'edad_texto']);

            $this->comprobarElRango($vecinas, $ini, $fin);

            if ($id === null) {
                return (int) DB::table($tabla)->insertGetId($fila + ['reg_medico' => null, 'created_at' => now(), 'updated_at' => now()]);
            }

            DB::table($tabla)->whereNull('reg_medico')->where('id', $id)->update($fila + ['updated_at' => now()]);

            return $id;
        });
    }

    /** Quita una fila **inicial** (sin dueño); una de un consultorio no se encuentra. */
    public function quitar(string $sexo, int $id): void
    {
        $tabla = self::TABLAS[$sexo] ?? throw new InvalidArgumentException('Elige si la referencia es de niños o de niñas.');

        if (DB::table($tabla)->whereNull('reg_medico')->where('id', $id)->delete() === 0) {
            throw new InvalidArgumentException('Esa fila no está en la referencia inicial.');
        }
    }

    /**
     * Los huecos entre filas **vecinas** de la referencia (para avisarlos en la lista): `[desde día, hasta día]` de lo que queda sin referencia.
     *
     * @param  Collection<int,object>  $filas
     * @return array<int,array{desde:int, hasta:int}>
     */
    public static function huecos(Collection $filas): array
    {
        $huecos = [];
        $anterior = null;

        foreach ($filas->sortBy('edad_ini_dia') as $fila) {
            if ($anterior !== null && (int) $fila->edad_ini_dia > (int) $anterior->edad_fin_dia) {
                $huecos[] = ['desde' => (int) $anterior->edad_fin_dia, 'hasta' => (int) $fila->edad_ini_dia];
            }
            if ($anterior === null || (int) $fila->edad_fin_dia > (int) $anterior->edad_fin_dia) {
                $anterior = $fila;
            }
        }

        return $huecos;
    }

    /** El campo del formulario del que habla un mensaje (para pintarlo bajo él). */
    public static function campoDelError(string $mensaje): string
    {
        return match (true) {
            str_contains($mensaje, 'inicio') => 'edad_ini_dia',
            str_contains($mensaje, 'rango') || str_contains($mensaje, 'solapa') || str_contains($mensaje, 'hueco') => 'edad_fin_dia',
            str_contains($mensaje, 'texto de la edad') => 'edad_texto',
            str_contains($mensaje, 'meses') => 'edad_meses',
            str_contains($mensaje, 'años') => 'anos',
            default => 'medidas',
        };
    }

    // ------------------------------------------------------------------------------------------ Adentro

    /** @param  Collection<int,object>  $vecinas */
    private function comprobarElRango(Collection $vecinas, int $ini, int $fin): void
    {
        foreach ($vecinas as $v) {
            // Compartir el borde (fin = inicio del que sigue) no es solaparse.
            if ($ini < (int) $v->edad_fin_dia && (int) $v->edad_ini_dia < $fin) {
                throw new InvalidArgumentException('El rango se solapa con «' . trim((string) $v->edad_texto) . '» (días ' . $v->edad_ini_dia . ' a ' . $v->edad_fin_dia . '). Ajusta los días.');
            }
        }

        $anteriorFin = $vecinas->filter(fn ($v) => (int) $v->edad_fin_dia <= $ini)->max('edad_fin_dia');
        if ($anteriorFin !== null && $ini - (int) $anteriorFin > self::HUECO_MAXIMO) {
            throw new InvalidArgumentException('Queda un hueco de ' . ($ini - (int) $anteriorFin) . ' días sin referencia antes de este rango (hasta ' . self::HUECO_MAXIMO . '). Revisa el inicio.');
        }

        $siguienteIni = $vecinas->filter(fn ($v) => (int) $v->edad_ini_dia >= $fin)->min('edad_ini_dia');
        if ($siguienteIni !== null && (int) $siguienteIni - $fin > self::HUECO_MAXIMO) {
            throw new InvalidArgumentException('Queda un hueco de ' . ((int) $siguienteIni - $fin) . ' días sin referencia después de este rango (hasta ' . self::HUECO_MAXIMO . '). Revisa el fin.');
        }
    }

    /** @return array<string,float|null> */
    private function medidas(array $datos): array
    {
        $fila = [];
        $conAlguna = [];

        foreach (self::MEDIDAS as $clave => $nombre) {
            $valores = [];
            foreach (['b' => 'bajo', 'm' => 'medio', 'a' => 'alto'] as $letra => $rotulo) {
                $valores[$letra] = $this->numero($datos[$clave . '_' . $letra] ?? null, $nombre . ' (' . $rotulo . ')');
            }

            $llenos = count(array_filter($valores, fn ($v) => $v !== null));
            if ($llenos > 0 && $llenos < 3) {
                throw new InvalidArgumentException($nombre . ': completa bajo, medio y alto, o deja los tres vacíos.');
            }
            if ($llenos === 3 && ! ($valores['b'] <= $valores['m'] && $valores['m'] <= $valores['a'])) {
                throw new InvalidArgumentException($nombre . ': el valor bajo tiene que ser menor o igual al medio, y el medio al alto.');
            }
            if ($llenos === 3) {
                $conAlguna[] = $clave;
            }

            foreach ($valores as $letra => $valor) {
                $fila[$clave . '_' . $letra] = $valor;
            }
        }

        if (! array_intersect(['peso', 'talla'], $conAlguna)) {
            throw new InvalidArgumentException('Carga al menos el peso o la talla del rango.');
        }

        return $fila;
    }

    private function numero($valor, string $nombre): ?float
    {
        $texto = trim(str_replace(',', '.', (string) $valor));
        if ($texto === '') {
            return null;
        }
        if (! is_numeric($texto) || (float) $texto < 0 || (float) $texto > self::VALOR_MAXIMO) {
            throw new InvalidArgumentException($nombre . ' tiene que ser un número de 0 a ' . self::VALOR_MAXIMO . '.');
        }

        return (float) $texto;
    }

    private function entero($valor, string $nombre): int
    {
        $texto = trim((string) $valor);
        if (! preg_match('/^\d{1,5}$/', $texto) || (int) $texto > self::DIAS_MAXIMOS) {
            throw new InvalidArgumentException($nombre . ' va en días, con un número entero de 0 a ' . self::DIAS_MAXIMOS . '.');
        }

        return (int) $texto;
    }

    /** @return int|float|null */
    private function opcional($valor, string $nombre, int $maximo, bool $entero)
    {
        $texto = trim(str_replace(',', '.', (string) $valor));
        if ($texto === '') {
            return null;
        }
        if (! is_numeric($texto) || (float) $texto < 0 || (float) $texto > $maximo || ($entero && (float) (int) $texto !== (float) $texto)) {
            throw new InvalidArgumentException($nombre . ' tiene que ser un número' . ($entero ? ' entero' : '') . ' de 0 a ' . $maximo . ', o dejarse vacío.');
        }

        return $entero ? (int) $texto : (float) $texto;
    }
}
