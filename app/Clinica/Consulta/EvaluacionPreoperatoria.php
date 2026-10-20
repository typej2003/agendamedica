<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use App\Models\PreAnestesico;

/**
 * **Evaluación pre-operatoria** (cirugía pediátrica, 2026-10-19): la solapa de «I.Q.» del árbol de PediaReport
 * (`tabpage_32`, con su `dw_73` = DataWindow `d_pre_aneste`) y **una fila por consulta** en `pre_aneste`.
 *
 * Columnas de la tabla del legado (todas `char` cortas, así que los topes del formulario son los suyos):
 *
 * | Columna | Qué es |
 * |---|---|
 * | `tipo` | el tipo de evaluación: un carácter |
 * | `resultado` | el resultado, `char(200)` |
 * | `emergencia` | si es urgente: `S` / `N` (el escritorio compara contra `'S'`, `w_consulta_principal.srw:14344`) |
 *
 * La clave es `historia` + `consulta`, como el resto de las tablas de la consulta.
 */
final class EvaluacionPreoperatoria
{
    public const TABLA = 'pre_aneste';

    /** El valor de `emergencia` que el escritorio trata como urgencia. */
    public const EMERGENCIA_SI = 'S';
    public const EMERGENCIA_NO = 'N';

    /**
     * Los campos, en el orden del escritorio: `clave => [etiqueta, columna, clase, ayuda?]`.
     *
     * @var array<string,array{etiqueta:string, columna:string, clase:string, ayuda?:string}>
     */
    private const CAMPOS = [
        'tipo'       => ['etiqueta' => 'Tipo de evaluación', 'columna' => 'tipo', 'clase' => 'opcion',
            'ayuda' => ''],
        'resultado'  => ['etiqueta' => 'Resultado', 'columna' => 'resultado', 'clase' => 'parrafo'],
        'emergencia' => ['etiqueta' => '¿Es una emergencia?', 'columna' => 'emergencia', 'clase' => 'si_no'],
    ];

    /** @return array<string,array{etiqueta:string, columna:string, clase:string, ayuda?:string}> */
    public static function campos(): array
    {
        return self::CAMPOS;
    }

    /** @return array<int,string> */
    public static function claves(): array
    {
        return array_keys(self::CAMPOS);
    }

    /** @return array<int,string> */
    public static function columnas(): array
    {
        return array_values(array_unique(array_column(self::CAMPOS, 'columna')));
    }

    /**
     * Los valores guardados, por clave.
     *
     * @return array<string,?string>
     */
    public function de(Consulta $consulta): array
    {
        $fila = $this->fila($consulta);
        $valores = [];

        foreach (self::CAMPOS as $clave => $campo) {
            $valor = $fila?->{$campo['columna']};
            $valores[$clave] = $valor === null ? null : (string) $valor;
        }

        return $valores;
    }

    public function fila(Consulta $consulta): ?PreAnestesico
    {
        return PreAnestesico::where('historia', (int) $consulta->numhistoria)
            ->where('consulta', (int) $consulta->nroconsulta)
            ->first();
    }

    public function filaParaEscribir(Consulta $consulta): PreAnestesico
    {
        return $this->fila($consulta) ?? PreAnestesico::create([
            'reg_medico' => $consulta->reg_medico,
            'historia'   => (int) $consulta->numhistoria,
            'consulta'   => (int) $consulta->nroconsulta,
        ]);
    }

    /** ¿La consulta ya tiene la evaluación cargada? */
    public function tieneDatos(Consulta $consulta): bool
    {
        return array_filter($this->de($consulta), fn ($valor) => $valor !== null && $valor !== '') !== [];
    }

    /** El texto legible de `emergencia` para la pantalla. */
    public static function emergenciaLegible(?string $valor): ?string
    {
        return match ($valor) {
            self::EMERGENCIA_SI => 'Sí',
            self::EMERGENCIA_NO => 'No',
            default => null,
        };
    }
}
