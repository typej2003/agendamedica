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
