<?php

use Database\Seeders\ParentescoSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **El catálogo de parentescos, sembrado por la migración** (O-61, 2026-11-05).
 *
 * El catálogo `parentesco` es de AppDDR, global y sin `reg_medico` (lo crea
 * `2026_10_10_190000_add_columnas_representante_y_catalogo_parentesco`), y lo siembra
 * `Database\Seeders\ParentescoSeeder`. Pero **el despliegue no corre seeders**: corre `migrate --force` y
 * `especialidades:sembrar` (MedicAPI/AGENTS.md), así que en producción la tabla quedó **vacía** y el
 * `<select>` de «Parentesco» del diálogo del representante mostraba una sola opción, «— Sin dato —». Los
 * tests y el entorno local no lo veían porque `DatabaseSeeder` sí lo llama.
 *
 * La lista **no se copia acá**: sale de `ParentescoSeeder::PARENTESCOS`, que es la única que hay, así que
 * agregar un parentesco en ese seeder es agregarlo en los dos caminos (el seeder y esta migración) y no
 * puede quedar una lista desactualizada respecto de la otra.
 *
 * **`insertOrIgnore` y no `updateOrCreate`**, a propósito: lo único que tiene que garantizar esta migración
 * es que las siete filas **existan**. Si un consultorio corrigió a mano un nombre (`«Mamá»` en vez de
 * `«Madre»`) o el orden de la lista, eso se respeta; el seeder, que sí corre en desarrollo y en los tests,
 * es el que deja el catálogo al día. La unicidad la da el índice `parentesco_codigo_unico` sobre `codigo`,
 * que es el que creó la tabla: por eso correrla dos veces no duplica ni cambia nada.
 *
 * La guarda es por tabla y no por columna: en una instalación donde la migración del catálogo todavía no
 * corrió, esta no tiene nada que sembrar y no puede hacer fallar el despliegue.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('parentesco')) {
            return;
        }

        $ahora = now();
        $filas = [];

        foreach (ParentescoSeeder::PARENTESCOS as $codigo => [$nombre, $orden]) {
            $filas[] = [
                'codigo'     => $codigo,
                'nombre'     => $nombre,
                'orden'      => $orden,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }

        DB::table('parentesco')->insertOrIgnore($filas);
    }

    /**
     * No se deshace: borrar estas siete filas dejaría sin nombre a los representantes que ya las usan, y no
     * hay forma de distinguir las que sembró esta migración de las que alguien agregó o corrigió a mano en
     * el consultorio. La tabla se va entera con el `down` de la migración que la creó (2026_10_10_190000),
     * que es el que corre `migrate:fresh`.
     */
    public function down(): void
    {
        // Sin vuelta atrás: ver el comentario de arriba.
    }
};
