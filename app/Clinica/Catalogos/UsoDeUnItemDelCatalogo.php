<?php

namespace App\Clinica\Catalogos;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **Dónde se usa un ítem de un catálogo clínico** (WEB-8.1b): la regla que decide si se puede quitar. La misma
 * idea que `UsoDeUnMedicamento` (WEB-8.1a) para los otros cinco catálogos: **en uso = alguna fila de lo clínico lo
 * nombra por su código**, dentro del mismo `reg_medico`, y entonces no se borra y el aviso dice **dónde**.
 *
 *  - **Diagnóstico** (`diagnosticos.codediagnostico`): los diagnósticos de las consultas (`diagnostico_paciente`).
 *  - **Motivo de consulta** (`motivos_consulta.codemotivo`): los motivos de las consultas (`motivo_consulta_paciente`).
 *  - **Examen** (`examenes.codeexamen`): los exámenes de las consultas (`examen_paciente`) y los de la pareja
 *    (`examen_pareja`; vacía en los datos, pero es la otra tabla que lo nombra).
 *  - **Tipo de examen** (`tipos_examenes.codetipo`): los exámenes del catálogo que cuelgan de él (`examenes.codetipo`).
 *  - **Médico referido** (`doctores.cedula`): las referencias de las consultas (`referencia.ceduladoctor`), buscadas en
 *    **todos los registros del médico** porque el catálogo de colegas se resuelve por registros (`DoctoresDelMedico`).
 *
 * Se cuentan **consultas** (`nrohistoria` + `nroconsulta`), no filas. El escritorio no comprobaba nada de esto: su
 * `DELETE` directo dejaba filas huérfanas (la consulta seguía nombrando un código que ya no existía). La web no lo
 * permite (decisión del usuario, 2026-10-11: igual que los medicamentos de 8.1a).
 *
 * Sólo lectura: quitar es una Action de `App\Actions\Catalogos\`, que vuelve a preguntar dentro de su transacción.
 */
final class UsoDeUnItemDelCatalogo
{
    /** Cuántos exámenes se nombran cuando es un tipo el que está en uso. */
    private const MAX_NOMBRES = 5;

    /** @param  array<int,string>  $partes  cada frase de "dónde" (vacío = sin uso) */
    private function __construct(private array $partes)
    {
    }

    public static function diagnostico(string $regMedico, string $codigo): self
    {
        $consultas = self::consultas('diagnostico_paciente', $regMedico, 'codediagnostico', $codigo);

        return new self(self::cuando($consultas, 'consulta', 'consultas'));
    }

    public static function motivo(string $regMedico, string $codigo): self
    {
        $consultas = self::consultas('motivo_consulta_paciente', $regMedico, 'codemotivo', $codigo);

        return new self(self::cuando($consultas, 'consulta', 'consultas'));
    }

    public static function examen(string $regMedico, string $codigo): self
    {
        $partes = self::cuando(self::consultas('examen_paciente', $regMedico, 'codeexamen', $codigo), 'consulta', 'consultas');
        $pareja = self::cuando(self::consultas('examen_pareja', $regMedico, 'codeexamen', $codigo), 'consulta de la pareja', 'consultas de la pareja');

        return new self(array_merge($partes, $pareja));
    }

    public static function tipoDeExamen(string $regMedico, string $codigo): self
    {
        $nombres = DB::table('examenes')
            ->where('reg_medico', $regMedico)
            ->where('codetipo', trim($codigo))
            ->orderBy('examen')
            ->pluck('examen')
            ->map(fn ($nombre) => trim((string) $nombre))
            ->all();

        if ($nombres === []) {
            return new self([]);
        }

        $total = count($nombres);
        $lista = array_map(fn ($nombre) => '«' . ($nombre === '' ? 'sin nombre' : $nombre) . '»', array_slice($nombres, 0, self::MAX_NOMBRES));
        $resto = $total - count($lista);
        $texto = ($total === 1 ? '1 examen' : $total . ' exámenes') . ' (' . implode(', ', $lista) . ($resto > 0 ? ' y ' . $resto . ' más' : '') . ')';

        return new self([$texto]);
    }

    /** @param  array<int,string>  $registros  los registros del médico */
    public static function doctor(array $registros, int $cedula): self
    {
        $filas = DB::table('referencia')
            ->whereIn('reg_medico', $registros)
            ->where('ceduladoctor', $cedula)
            ->get(['nrohistoria', 'nroconsulta']);

        $consultas = $filas
            ->map(fn ($fila) => (int) $fila->nrohistoria . '|' . (int) $fila->nroconsulta)
            ->unique()
            ->count();

        return new self(self::cuando($consultas, 'referencia', 'referencias'));
    }

    public function enUso(): bool
    {
        return $this->partes !== [];
    }

    /** «3 consultas», «2 consultas y 1 consulta de la pareja»: lo que el médico lee para decidir qué hacer. */
    public function descripcion(): string
    {
        if (count($this->partes) <= 1) {
            return $this->partes[0] ?? '';
        }

        $ultima = array_pop($this->partes);

        return implode(', ', $this->partes) . ' y ' . $ultima;
    }

    // ------------------------------------------------------------------------------------------ Adentro

    /** Cuántas consultas distintas nombran el código en esa tabla de lo clínico. */
    private static function consultas(string $tabla, string $regMedico, string $columna, string $codigo): int
    {
        /** @var Collection $filas */
        $filas = DB::table($tabla)
            ->where('reg_medico', $regMedico)
            ->where($columna, trim($codigo))
            ->get(['nrohistoria', 'nroconsulta']);

        return $filas
            ->map(fn ($fila) => (int) $fila->nrohistoria . '|' . (int) $fila->nroconsulta)
            ->unique()
            ->count();
    }

    /** @return array<int,string> cero o una frase: «N consultas» */
    private static function cuando(int $cuantas, string $singular, string $plural): array
    {
        if ($cuantas === 0) {
            return [];
        }

        return [$cuantas === 1 ? '1 ' . $singular : $cuantas . ' ' . $plural];
    }
}
