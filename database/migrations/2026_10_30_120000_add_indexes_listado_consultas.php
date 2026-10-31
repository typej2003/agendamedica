<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **Los índices del listado de consultas por rango** (2026-10-30).
 *
 * La pantalla `/clinica/listados?listado=consultas-por-rango` tardaba medio minuto. No era la página
 * (25 filas): era el `count(*)` de la paginación, que el motor resolvía recorriendo `medico_pacientes`
 * y haciendo un **full scan de `consultas` por cada fila del pivote**. Medido en MariaDB 10.6 con
 * 3.000 pacientes del médico y 60.000 consultas: 4,2 s el conteo y 85 ms la página. El costo crece
 * como pacientes × consultas, así que con el volumen de QA llegaba a los treinta segundos.
 *
 * `medico_pacientes` no tenía índice por `(reg_medico, numhistoria)` —el `UNIQUE` es por
 * `(reg_medico, paciente_id)` y el otro índice por `(medico_id, …)`— y `consultas` no tenía nada por
 * `(reg_medico, fecha)`. Con los dos primeros índices de acá el plan pasa a recorrer `consultas` una
 * vez y probar el pivote por clave: **4,2 s → 0,048 s** el conteo y **4,3 s → 0,09 s** la pantalla.
 *
 * Los otros son para los filtros y las listas por lote, que tampoco tenían índice por la clave de la
 * consulta: `diagnostico_paciente`, `recipes` y `antece_paciente`. `motivo_consulta_paciente` ya lo
 * tenía desde `2026_10_03_210000_add_indexes_motivo_consulta_paciente`, así que no se repite.
 *
 * El del `vademecum` es el único de los catálogos: el filtro de medicamento resuelve el nombre con un
 * `JOIN` por `(reg_medico, codemedicina)` y sin él costaba 0,64 s (con él, 0,13 s). A los catálogos
 * chicos —`motivos_consulta`, `diagnosticos`, `antecedentes`— **no** se les agrega: medido, el motor
 * los resuelve mejor con su join buffer que probando un índice fila por fila (0,03 → 0,06 s), así que
 * indexarlos sería pagar escrituras para empeorar la lectura.
 *
 * Es **aditiva** y con guardas por tabla e índice (misma técnica que
 * `2026_10_20_100000_vinculo_de_pacientes_por_reg_medico`): se puede volver a correr sobre una base a
 * medio migrar y también corre en SQLite, que es la base de los tests. `down()` sólo quita lo que esta
 * migración creó; las tablas del legado no se tocan en ninguna otra forma.
 *
 * ⚠️ El índice del pivote mide ~2.040 bytes (`reg_medico` y `numhistoria` son `varchar(255)` en
 * utf8mb4). Entra en InnoDB con formato de fila `DYNAMIC` (MySQL 8 / MariaDB 10.6, el que usa el
 * proyecto); en un servidor viejo con filas `COMPACT` habría que acortarlo con prefijos.
 */
return new class extends Migration
{
    /**
     * Tabla → lista de `[nombre del índice, columnas]`.
     *
     * @var array<string, array<int, array{0:string, 1:array<int,string>}>>
     */
    private const INDICES = [
        // El que cambia el plan: permite probar el vínculo del paciente por (consultorio, historia).
        'medico_pacientes' => [
            ['medico_pacientes_reg_medico_historia_idx', ['reg_medico', 'numhistoria', 'paciente_id']],
        ],
        // El rango de fechas y el orden de la página; además queda cubriendo el conteo.
        'consultas' => [
            ['consultas_reg_medico_fecha_idx', ['reg_medico', 'fecha', 'numhistoria', 'nroconsulta']],
        ],
        // Los filtros de contenido y las listas que se leen por lote para la página.
        'diagnostico_paciente' => [
            ['diagnostico_paciente_consulta_idx', ['reg_medico', 'nrohistoria', 'nroconsulta']],
        ],
        'recipes' => [
            ['recipes_consulta_idx', ['reg_medico', 'nrohistoria', 'nroconsulta']],
        ],
        'antece_paciente' => [
            ['antece_paciente_historia_idx', ['reg_medico', 'numhistoria']],
        ],
        // El catálogo del vademécum, que es el único que se indexa: el filtro de medicamento lo une
        // por (reg_medico, codemedicina) y sin índice costaba 0,64 s contra 0,13 s con él.
        'vademecum' => [
            ['vademecum_reg_medico_codigo_idx', ['reg_medico', 'codemedicina']],
        ],
    ];

    public function up(): void
    {
        $this->crearLosIndices();
    }

    public function down(): void
    {
        foreach (self::INDICES as $tabla => $indices) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            foreach ($indices as [$nombre]) {
                if (! $this->existeIndice($tabla, $nombre)) {
                    continue;
                }

                Schema::table($tabla, function (Blueprint $table) use ($nombre) {
                    $table->dropIndex($nombre);
                });
            }
        }
    }

    private function crearLosIndices(): void
    {
        foreach (self::INDICES as $tabla => $indices) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            foreach ($indices as [$nombre, $columnas]) {
                if ($this->existeIndice($tabla, $nombre)) {
                    continue;
                }

                Schema::table($tabla, function (Blueprint $table) use ($columnas, $nombre) {
                    $table->index($columnas, $nombre);
                });
            }
        }
    }

    /**
     * ¿Existe ese índice en esa tabla? `Schema` no lo pregunta sin doctrine/dbal, así que se consulta el
     * catálogo de cada motor: `information_schema.statistics` en MySQL/MariaDB y `sqlite_master` en SQLite.
     */
    private function existeIndice(string $tabla, string $indice): bool
    {
        $consulta = DB::getDriverName() === 'sqlite'
            ? 'SELECT COUNT(*) AS n FROM sqlite_master WHERE type = \'index\' AND tbl_name = ? AND name = ?'
            : 'SELECT COUNT(*) AS n FROM information_schema.statistics
               WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?';

        $fila = DB::selectOne($consulta, [$tabla, $indice]);

        return $fila !== null && (int) $fila->n > 0;
    }
};
