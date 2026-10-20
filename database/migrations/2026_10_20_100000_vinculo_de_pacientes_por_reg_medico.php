<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **El vínculo paciente ↔ consultorio pasa a ser del tenant** (Fase 1 del cambio de `reg_medico`).
 *
 * Hasta acá `medico_pacientes` tenía **una fila por médico**: `UNIQUE (medico_id, paciente_id)`, y el
 * `reg_medico` era una columna más que el sync del escritorio llenaba con el registro de la carga.
 * Eso obligaba a **copiar** los pacientes cada vez que a un médico se le daba acceso a otro registro
 * (`CuentaService::asignarRegistro`) y dejaba al paciente con un número de historia por cada doctor.
 *
 * Desde esta fase el vínculo es **uno por paciente y consultorio**: `UNIQUE (reg_medico, paciente_id)`.
 * `medico_id` sigue existiendo y sigue siendo NOT NULL porque es **quien lo creó** —el doctor, o el
 * centro cuando `medicos.es_centro = 1`—, y `numhistoria` queda como **copia** para el app viejo (el
 * escritorio lee el número del pivote). El dueño del número es `historias`, que pasa a ser **la
 * historia del centro/tenant** (`reg_medico` + `numhistoria` + `paciente_id`).
 *
 * Lo que hace, en este orden (el orden importa y está explicado en cada paso):
 *
 *  1. **Columnas nuevas** de las fases siguientes: `pacientes.reg_medico` (el tenant del primer
 *     vínculo del paciente), `cola.medico_id` (el doctor asignado a la cita; nullable porque una cita
 *     puede no tenerlo), `medicos.es_centro` (el médico que en realidad es un centro) y
 *     `medicos.formato_reportes` (`centro`/`medico`).
 *  2. **Rellenar `medico_pacientes.reg_medico` sólo donde está vacío**, desde `medicos.reg_medico` por
 *     `medico_id`. Las filas que ya lo tienen —las que escribe la web, con el del consultorio— **no se
 *     pisan**: ese valor es el correcto y su `medico_id` puede ser el de otro consultorio.
 *  3. **Deduplicar por `(reg_medico, paciente_id)`** antes de crear la clave única. Se queda la fila del
 *     **centro** (`medicos.es_centro = 1`) o, si no hay, la de **id más bajo**; y el `numhistoria` que
 *     gana es el de `historias` del `(reg_medico, paciente_id)` si existe, porque es el que la ficha, la
 *     agenda y la consulta ya muestran. Las demás filas se borran.
 *  4. **Los índices**: se agrega `(medico_id, paciente_id)` como índice común **antes** de soltar la
 *     clave única vieja (MySQL no deja quitar el índice que sostiene la clave foránea de `medico_id`),
 *     se suelta `UNIQUE (medico_id, paciente_id)` y se crea `UNIQUE (reg_medico, paciente_id)`. El
 *     índice común queda para el app viejo, que sigue leyendo por doctor.
 *  5. **`pacientes.reg_medico`** = el tenant del vínculo **más viejo** del paciente (el de id más bajo);
 *     si no tiene vínculo, el de su primera `historias`; si tampoco, `null`.
 *
 * Corre en **MySQL/MariaDB y en SQLite** (la suite): no usa `->change()` (no hay doctrine/dbal) ni
 * `UPDATE ... JOIN`, que SQLite no tiene. Lo que difiere por motor —el nombre del índice y el
 * `information_schema`— está aislado en `existeIndice()`.
 *
 * No se deshace: volver atrás dejaría otra vez dos filas del pivote para el mismo paciente y consultorio
 * (o perdería las deduplicadas), y las columnas nuevas ya tienen datos.
 */
return new class extends Migration
{
    /** Cuántos pacientes se procesan por vuelta al rellenar `pacientes.reg_medico`. */
    private const LOTE = 500;

    private const VINCULO = 'medico_pacientes';

    public function up(): void
    {
        $this->agregarColumnas();

        if (! Schema::hasTable(self::VINCULO)) {
            return;
        }

        $this->completarRegistroDelVinculo();
        $this->deduplicarElVinculo();
        $this->cambiarLosIndices();
        $this->completarRegistroDelPaciente();
    }

    public function down(): void
    {
        // No se vuelve atrás: ver el comentario de la clase.
    }

    /* ------------------------------------------------------------------ */
    /* 1. Columnas nuevas                                                  */
    /* ------------------------------------------------------------------ */

    private function agregarColumnas(): void
    {
        // El tenant del primer vínculo del paciente. La escribe `CrearPaciente` **sólo al crear** la
        // ficha (enlazar por cédula no la toca): el primero gana.
        if (Schema::hasTable('pacientes') && ! Schema::hasColumn('pacientes', 'reg_medico')) {
            Schema::table('pacientes', function (Blueprint $table) {
                $table->string('reg_medico')->nullable();
            });
        }

        // El `medicos.id` del doctor asignado a la cita (el de `medicos`, **no** la `clave` de
        // `evolucion` que vive en `cola.medico`). Nullable: la cita sin doctor queda con `null`. La
        // lógica de la cita llega en una fase posterior; acá sólo está la columna.
        if (Schema::hasTable('cola') && ! Schema::hasColumn('cola', 'medico_id')) {
            Schema::table('cola', function (Blueprint $table) {
                $table->unsignedBigInteger('medico_id')->nullable();
            });
        }

        // El médico que en realidad es un **centro**: sus filas del pivote son del centro y no de un
        // doctor, y por eso ganan en la deduplicación (`es_centro`).
        if (Schema::hasTable('medicos') && ! Schema::hasColumn('medicos', 'es_centro')) {
            Schema::table('medicos', function (Blueprint $table) {
                $table->boolean('es_centro')->default(false);
            });
        }

        // El formato de los reportes del médico: `centro` o `medico`. Nullable: sin elegir, el que
        // corresponda por defecto en la fase que lo use.
        if (Schema::hasTable('medicos') && ! Schema::hasColumn('medicos', 'formato_reportes')) {
            Schema::table('medicos', function (Blueprint $table) {
                $table->string('formato_reportes')->nullable();
            });
        }
    }

    /* ------------------------------------------------------------------ */
    /* 2. El reg_medico que falta en el vínculo                            */
    /* ------------------------------------------------------------------ */

    /**
     * Sólo las filas **vacías** (`null` o `''`) toman el `reg_medico` de su médico. Las que ya lo tienen
     * —las de la web, que guardan el del **consultorio**— quedan intactas: pisarlas con el de
     * `medicos.reg_medico` cambiaría el tenant de esos pacientes.
     *
     * Una subconsulta correlacionada sirve para los dos motores (`UPDATE ... JOIN` no existe en SQLite).
     */
    private function completarRegistroDelVinculo(): void
    {
        if (! Schema::hasTable('medicos') || ! Schema::hasColumn('medicos', 'reg_medico')) {
            return;
        }

        DB::statement(
            'UPDATE ' . self::VINCULO . ' SET reg_medico = (
                SELECT m.reg_medico FROM medicos m WHERE m.id = ' . self::VINCULO . '.medico_id
             )
             WHERE (reg_medico IS NULL OR TRIM(reg_medico) = \'\')
               AND EXISTS (
                SELECT 1 FROM medicos m
                WHERE m.id = ' . self::VINCULO . '.medico_id
                  AND m.reg_medico IS NOT NULL AND TRIM(m.reg_medico) <> \'\'
             )'
        );
    }

    /* ------------------------------------------------------------------ */
    /* 3. Una sola fila por (reg_medico, paciente_id)                      */
    /* ------------------------------------------------------------------ */

    /**
     * Deja **una** fila por paciente y consultorio, con la regla decidida:
     *
     *  - gana la fila del **centro** (`medicos.es_centro = 1`); si ninguna es de un centro, la de **id
     *    más bajo** (la más vieja, que es el vínculo original del paciente);
     *  - el `numhistoria` que queda es el de **`historias`** del `(reg_medico, paciente_id)` si existe:
     *    es el número que la ficha, la agenda y las citas ya muestran, y el que se puede atender.
     *
     * Las filas con `reg_medico` nulo no se tocan: la clave única no las alcanza (varios `NULL` conviven
     * en MySQL y en SQLite) y no son de ningún consultorio. Las de `reg_medico = ''` **sí** se agrupan:
     * para el índice son un valor como cualquier otro.
     */
    private function deduplicarElVinculo(): void
    {
        $grupos = DB::table(self::VINCULO)
            ->select('reg_medico', 'paciente_id')
            ->whereNotNull('reg_medico')
            ->groupBy('reg_medico', 'paciente_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $hayCentros = Schema::hasTable('medicos') && Schema::hasColumn('medicos', 'es_centro');

        foreach ($grupos as $grupo) {
            $filas = DB::table(self::VINCULO)
                ->where('reg_medico', $grupo->reg_medico)
                ->where('paciente_id', $grupo->paciente_id)
                ->orderBy('id')
                ->get(['id', 'medico_id', 'numhistoria']);

            $centros = $hayCentros
                ? DB::table('medicos')
                    ->whereIn('id', $filas->pluck('medico_id')->filter()->unique()->all())
                    ->where('es_centro', true)
                    ->pluck('id')
                    ->flip()
                : collect();

            $ganadora = $filas->first(fn ($fila) => $centros->has($fila->medico_id)) ?? $filas->first();

            $deLaHistoria = DB::table('historias')
                ->where('reg_medico', $grupo->reg_medico)
                ->where('paciente_id', $grupo->paciente_id)
                ->orderBy('id')
                ->value('numhistoria');

            if ($deLaHistoria !== null && $deLaHistoria !== '') {
                DB::table(self::VINCULO)->where('id', $ganadora->id)->update(['numhistoria' => $deLaHistoria]);
            }

            $sobran = $filas->pluck('id')
                ->reject(fn ($id) => (int) $id === (int) $ganadora->id)
                ->values()
                ->all();

            if ($sobran !== []) {
                DB::table(self::VINCULO)->whereIn('id', $sobran)->delete();
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* 4. Los índices                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * `UNIQUE (reg_medico, paciente_id)` es la regla nueva; `(medico_id, paciente_id)` queda como
     * **índice común** para el app viejo, que sigue preguntando por doctor.
     *
     * El índice común se crea **antes** de soltar la clave única: en MySQL la clave foránea de
     * `medico_id` se sostiene con ese índice y sin otro que la cubra el `DROP INDEX` falla.
     */
    private function cambiarLosIndices(): void
    {
        if (! $this->existeIndice(self::VINCULO, 'medico_pacientes_medico_id_paciente_id_index')) {
            Schema::table(self::VINCULO, function (Blueprint $table) {
                $table->index(['medico_id', 'paciente_id'], 'medico_pacientes_medico_id_paciente_id_index');
            });
        }

        if ($this->existeIndice(self::VINCULO, 'medico_pacientes_medico_id_paciente_id_unique')) {
            Schema::table(self::VINCULO, function (Blueprint $table) {
                $table->dropUnique('medico_pacientes_medico_id_paciente_id_unique');
            });
        }

        if (! $this->existeIndice(self::VINCULO, 'medico_pacientes_reg_medico_paciente_unique')) {
            Schema::table(self::VINCULO, function (Blueprint $table) {
                $table->unique(['reg_medico', 'paciente_id'], 'medico_pacientes_reg_medico_paciente_unique');
            });
        }
    }

    /**
     * ¿Existe ese índice en esa tabla? `Schema` no lo pregunta sin doctrine/dbal, así que se consulta el
     * catálogo de cada motor: `information_schema.statistics` en MySQL/MariaDB y `sqlite_master` en
     * SQLite. Sirve para que la migración se pueda volver a correr sobre una base a medio migrar.
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

    /* ------------------------------------------------------------------ */
    /* 5. El tenant del paciente                                           */
    /* ------------------------------------------------------------------ */

    /**
     * `pacientes.reg_medico` = el consultorio del **primer vínculo** del paciente (el de id más bajo, que
     * después de deduplicar es el único de ese tenant); si no tiene ningún vínculo, el de su **primera
     * `historias`**; si tampoco, `null`.
     *
     * Se hace en lotes de pacientes y en PHP (no con una subconsulta por fila) porque `historias` no tiene
     * índice por `paciente_id` y en una base real la subconsulta recorrería la tabla entera una vez por
     * paciente.
     */
    private function completarRegistroDelPaciente(): void
    {
        if (! Schema::hasTable('pacientes') || ! Schema::hasColumn('pacientes', 'reg_medico')) {
            return;
        }

        DB::table('pacientes')->select('id')->orderBy('id')->chunkById(self::LOTE, function ($filas) {
            $ids = $filas->pluck('id')->map(fn ($id) => (int) $id)->all();

            $porPaciente = [];
            if (Schema::hasColumn(self::VINCULO, 'reg_medico')) {
                $vinculos = DB::table(self::VINCULO)
                    ->whereIn('paciente_id', $ids)
                    ->whereNotNull('reg_medico')
                    ->where('reg_medico', '<>', '')
                    ->orderBy('id')
                    ->get(['paciente_id', 'reg_medico']);

                foreach ($vinculos as $vinculo) {
                    $porPaciente[(int) $vinculo->paciente_id] ??= $vinculo->reg_medico;
                }
            }

            $sinVinculo = array_values(array_diff($ids, array_map('intval', array_keys($porPaciente))));

            if ($sinVinculo !== []) {
                $historias = DB::table('historias')
                    ->whereIn('paciente_id', $sinVinculo)
                    ->whereNotNull('reg_medico')
                    ->where('reg_medico', '<>', '')
                    ->orderBy('id')
                    ->get(['paciente_id', 'reg_medico']);

                foreach ($historias as $historia) {
                    $porPaciente[(int) $historia->paciente_id] ??= $historia->reg_medico;
                }
            }

            // Una sola escritura por consultorio en el lote (y no una por paciente).
            $porRegistro = [];
            foreach ($ids as $id) {
                $porRegistro[$porPaciente[$id] ?? ''][] = $id;
            }

            foreach ($porRegistro as $registro => $losIds) {
                DB::table('pacientes')->whereIn('id', $losIds)->update([
                    'reg_medico' => (string) $registro === '' ? null : (string) $registro,
                ]);
            }
        });
    }
};
