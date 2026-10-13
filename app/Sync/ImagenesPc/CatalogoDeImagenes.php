<?php

namespace App\Sync\ImagenesPc;

use Illuminate\Support\Facades\Schema;

/**
 * **Qué tablas y columnas guardan la ruta de una imagen de la PC** (WEB-T.1): la lista blanca de
 * `config/imagenes_pc.php`, ya filtrada por lo que existe en esta base.
 *
 * Es la única puerta de los endpoints `sync/imagenes/*`: una (tabla, columna) que no esté acá no se pide, no se sube y
 * no se omite. Los nombres de tabla y de columna que llegan a SQL salen **siempre** de esta lista (nunca del cliente).
 */
final class CatalogoDeImagenes
{
    /** @var array<string,array{columnas:array<int,string>,dueno:string,reescribe:bool}>|null */
    private ?array $cache = null;

    /**
     * Las tablas que existen y tienen sus columnas, en el orden de la configuración.
     *
     * @return array<string,array{columnas:array<int,string>,dueno:string,reescribe:bool}>
     */
    public function tablas(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $tablas = [];
        foreach ((array) config('imagenes_pc.columnas', []) as $tabla => $def) {
            if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, 'id')) {
                continue;
            }

            $dueno = (string) ($def['dueno'] ?? 'reg_medico');
            if ($dueno === 'reg_medico' && ! Schema::hasColumn($tabla, 'reg_medico')) {
                continue;
            }

            $columnas = array_values(array_filter(
                (array) ($def['columnas'] ?? []),
                fn (string $columna) => Schema::hasColumn($tabla, $columna)
            ));
            if ($columnas === []) {
                continue;
            }

            $tablas[$tabla] = [
                'columnas' => $columnas,
                'dueno' => $dueno,
                'reescribe' => (bool) ($def['reescribe'] ?? false),
            ];
        }

        return $this->cache = $tablas;
    }

    /**
     * Todos los pares (tabla, columna), en un orden estable: es el orden de la paginación de `pendientes`.
     *
     * @return array<int,array{tabla:string,columna:string}>
     */
    public function pares(?string $soloTabla = null): array
    {
        $pares = [];
        foreach ($this->tablas() as $tabla => $def) {
            if ($soloTabla !== null && $soloTabla !== $tabla) {
                continue;
            }
            foreach ($def['columnas'] as $columna) {
                $pares[] = ['tabla' => $tabla, 'columna' => $columna];
            }
        }

        return $pares;
    }

    public function permite(string $tabla, string $columna): bool
    {
        return isset($this->tablas()[$tabla]) && in_array($columna, $this->tablas()[$tabla]['columnas'], true);
    }

    public function dueno(string $tabla): string
    {
        return $this->tablas()[$tabla]['dueno'] ?? 'reg_medico';
    }

    /** ¿Al subir se reescribe la columna legada? Sólo `imagen_consulta` (la web ya la sirve). */
    public function reescribe(string $tabla): bool
    {
        return (bool) ($this->tablas()[$tabla]['reescribe'] ?? false);
    }
}
