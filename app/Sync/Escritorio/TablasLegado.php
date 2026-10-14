<?php

namespace App\Sync\Escritorio;

use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Utilidades compartidas por el sync del escritorio PowerBuilder (carga inicial y cambios):
 * columnas de las tablas del legado en el API, normalización de nombres y el formato de la
 * "clave del escritorio" (`clave_escritorio`) de historias, consultas y citas.
 */
class TablasLegado
{
    /** Columnas que guardan un número de historia, según la tabla del legado. */
    public const COLUMNAS_HISTORIA = ['numhistoria', 'nrohistoria', 'historia', 'histroria'];

    /**
     * **Qué columna lleva el número de historia en cada tabla del legado.** Es la fuente única de esa
     * diferencia de nombres, y sale de los dumps del legado (`Docs/MICONSULTAGI-legado/esquemas-asa/*.sql`),
     * no de lo que parece.
     *
     * Existe por una razón concreta: la tabla `cola` del API tiene **además** una columna `nrohistoria`,
     * vieja de la primera migración, que **no** lleva el número. Un filtro que la usó dejó pasar 49.955
     * récipes de pacientes que no se atendían (trampa 122 de `TRAMPAS.md`). Cualquier código nuevo que
     * necesite el número de historia de una tabla clínica debe preguntar acá.
     */
    private const COLUMNA_HISTORIA = [
        'cola' => 'numhistoria',
        'consultas' => 'numhistoria',
        'constancia_obs' => 'numhistoria',
        'recipes' => 'nrohistoria',
        'informe' => 'nrohistoria',
        'referencia' => 'nrohistoria',
        'reposo_paciente' => 'nrohistoria',
        'diagnostico_paciente' => 'nrohistoria',
        'motivo_consulta_paciente' => 'nrohistoria',
        // Ojo con esta: el legado la llama `nrohistoria` y el API la tiene como `historia`, porque el
        // manifiesto de la especialidad traduce el nombre al subir (`sync.columnas_equivalentes`). El mapa
        // habla de las columnas **del API**.
        'examen_fisico_nuevo' => 'historia',
    ];

    /**
     * La columna del número de historia en esa tabla del legado. Sin dato, `numhistoria` (el nombre más
     * común y el que usa la tabla de citas).
     */
    public static function columnaHistoria(string $tabla): string
    {
        return self::COLUMNA_HISTORIA[$tabla] ?? 'numhistoria';
    }

    /** El mapa completo (tabla => columna), para los tests que comprueban que siga siendo cierto. */
    public static function columnasDeHistoria(): array
    {
        return self::COLUMNA_HISTORIA;
    }

    /** Columnas que guardan un número de consulta (siempre junto a una de historia). */
    public const COLUMNAS_CONSULTA = ['nroconsulta', 'numconsulta', 'consulta', 'num_consulta'];

    /** @var array<string, string[]> */
    private array $columnas = [];

    /**
     * Nombre de tabla o columna del legado como lo migró el esquema del API: minúsculas, sin
     * acentos ni símbolos (`por_retención_seg` -> `por_retencin_seg`, `PARA` -> `para`).
     */
    public function normalizar(string $nombre): string
    {
        return preg_replace('/[^a-z0-9_]/', '', strtolower(trim($nombre)));
    }

    /** @return string[] */
    public function columnasDe(string $tabla): array
    {
        return $this->columnas[$tabla] ??= array_map('strtolower', Schema::getColumnListing($tabla));
    }

    public function tieneColumna(string $tabla, string $columna): bool
    {
        return in_array($columna, $this->columnasDe($tabla), true);
    }

    /**
     * Traduce los nombres de columna que el escritorio llama distinto que el API (config
     * `sync_legado.columnas_equivalentes`, que sale del manifiesto de la especialidad).
     *
     * Es lo que evita que el mismo dato quede en dos columnas: el escritorio de pediatría llavea
     * `examen_fisico_nuevo` por `nrohistoria` + `nroconsulta` y el API la guarda por `historia` +
     * `consulta` (como la escriben la web y ginecología). Si vinieran las dos formas, gana el nombre
     * del API.
     */
    public function traducirColumnas(string $tabla, array $fila): array
    {
        $equivalencias = config("sync_legado.columnas_equivalentes.{$tabla}", []);
        if (! is_array($equivalencias) || $equivalencias === []) {
            return $fila;
        }

        // Se recorre en el orden que vino (la fila no se reordena) y, si aparecen las dos formas del
        // mismo dato, gana la del API.
        $salida = [];
        $deAlias = [];
        foreach ($fila as $columna => $valor) {
            $destino = $equivalencias[$columna] ?? $columna;
            $esAlias = $destino !== $columna;

            if (array_key_exists($destino, $salida) && ! ($deAlias[$destino] && ! $esAlias)) {
                continue;
            }

            $salida[$destino] = $valor;
            $deAlias[$destino] = $esAlias;
        }

        return $salida;
    }

    /**
     * Renombra las columnas de una fila de **pacientes** del escritorio a los nombres del API, según el
     * mapeo del manifiesto de la especialidad (`sync.pacientes.columnas`).
     *
     * Hace falta porque la tabla de pacientes no es la misma en todas las especialidades: ginecología los
     * tiene en `pacientes` (los nombres coinciden y el mapeo es vacío) y pediatría en `paciente_chamo`,
     * donde la historia es `historia`, el nombre es `nombre` y el teléfono es `celular`. Después de esto la
     * fila se trata como una de `pacientes` y el resto del camino no cambia.
     *
     * Se aplica **también a la clave** de un cambio: la que manda el escritorio es la primaria de SU tabla
     * (`paciente_chamo` → `{historia}`, `pacientes` → `{numhistoria}`) y el camino de pacientes busca por
     * `numhistoria`.
     */
    public function traducirPaciente(array $fila, array $mapa): array
    {
        if ($mapa === []) {
            return $this->normalizarClaves($fila);
        }

        // Se recorre en el orden que vino y, si aparecen las dos formas del mismo dato, gana la del API.
        $salida = [];
        $deAlias = [];
        foreach ($this->normalizarClaves($fila) as $columna => $valor) {
            $destino = $mapa[$columna] ?? $columna;
            $esAlias = $destino !== $columna;

            if (array_key_exists($destino, $salida) && ! ($deAlias[$destino] && ! $esAlias)) {
                continue;
            }

            $salida[$destino] = $valor;
            $deAlias[$destino] = $esAlias;
        }

        return $salida;
    }

    /**
     * Deja solo las columnas que existen en la tabla del API, sin `id`, `reg_medico` ni las
     * excluidas en `config/sync_legado.php`. Las que el API no tiene se agregan a $ignoradas: se
     * informan, no se pierden en silencio.
     */
    public function filtrar(string $tabla, array $fila, array &$ignoradas): array
    {
        $excluidas = config("sync_legado.columnas_excluidas.{$tabla}", []);
        $fila = $this->traducirColumnas($tabla, $this->normalizarClaves($fila));
        $limpia = [];

        foreach ($fila as $columna => $valor) {
            $columna = $this->normalizar((string) $columna);
            if ($columna === 'id' || $columna === 'reg_medico' || $columna === 'clave_escritorio'
                || in_array($columna, $excluidas, true)) {
                continue;
            }
            if (! $this->tieneColumna($tabla, $columna)) {
                if (! in_array($columna, $ignoradas, true)) {
                    $ignoradas[] = $columna;
                }
                continue;
            }
            $limpia[$columna] = is_array($valor) ? json_encode($valor) : $valor;
        }

        return $limpia;
    }

    /** Claves de un arreglo normalizadas (el escritorio puede mandar mayúsculas o acentos). */
    public function normalizarClaves(array $valores): array
    {
        $salida = [];
        foreach ($valores as $columna => $valor) {
            $salida[$this->normalizar((string) $columna)] = $valor;
        }

        return $salida;
    }

    /* ------------------------------------------------------------------ */
    /* Clave del escritorio (columna `clave_escritorio`)                   */
    /* ------------------------------------------------------------------ */
    // Es la clave primaria de la fila EN EL ESCRITORIO. Puede no coincidir con los números del API
    // cuando el app y el escritorio crearon lo mismo sin conexión (ver bridge/DISENO-FASE-2.md § 4).

    public static function claveHistoria($numHistoria): string
    {
        return (string) (int) $numHistoria;
    }

    public static function claveConsulta($numHistoria, $nroConsulta): string
    {
        return (int) $numHistoria . '|' . (int) $nroConsulta;
    }

    /**
     * `fecha|hora_ini`: la `clave_escritorio` de una cita y **sólo una de las dos claves primarias** que
     * `cola` tiene en el legado. En los ocho esquemas de `Docs/MICONSULTAGI-legado/esquemas-asa/`
     * (pediatría: `pediatria.sql`) la primaria es `fecha` + `numhistoria`, así que dos pacientes a la
     * misma hora comparten esta clave; la cita se identifica por su historia en
     * `CambiosEscritorio::citaDelCambio`. Ver la trampa 109.
     */
    public static function claveCola($fecha, $horaIni): string
    {
        return Carbon::parse((string) $fecha)->format('Y-m-d') . '|' . Carbon::parse((string) $horaIni)->format('H:i:s');
    }
}
