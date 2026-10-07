<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `sync_credentials.expires_at` deja de auto-actualizarse: era un `TIMESTAMP` y vencía las credenciales solo.
 *
 * El 2026-10-07, en producción, las API keys del escritorio aparecían **Vencidas el mismo día en que se
 * emitían**. La emisión está bien (`SyncCredencialService::emitir()` graba `now()->addYears($anios)`, 1 año
 * por defecto): el valor se perdía en la base. `SHOW CREATE TABLE sync_credentials` en producción mostró:
 *
 *     `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 *
 * La migración original (`2026_09_27_000001_create_sync_credentials_table`) declaraba
 * `$table->timestamp('expires_at')`, que compila a `expires_at timestamp NOT NULL`. Y si esa columna es la
 * **primera columna TIMESTAMP** de la tabla, sin `NULL` ni `DEFAULT` explícitos, y el servidor tiene
 * `explicit_defaults_for_timestamp` deshabilitado (el default en MariaDB y MySQL ≤ 8.0), el motor le agrega
 * por su cuenta `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` (ver MySQL 8.0, "Automatic
 * Initialization and Updating for TIMESTAMP and DATETIME": *"the first TIMESTAMP column has both DEFAULT
 * CURRENT_TIMESTAMP and ON UPDATE CURRENT_TIMESTAMP if neither is specified explicitly"*).
 *
 * Con `ON UPDATE`, **cualquier UPDATE de la fila que no mencione la columna la pisa con "ahora"**. Y el sync
 * la omite todo el tiempo: `SyncAuthService::validarToken()` guarda `last_used_at` en cada petición
 * autenticada (`forceFill(['last_used_at' => now()])->save()`). Resultado: la credencial nacía con vence a
 * un año y quedaba vencida en su **primer uso**; de ahí en adelante todo sync respondía 401. Como el panel
 * muestra "Vence" y "Último uso" solo con fecha (`d/m/Y`), se veía como si la API key durara 24 horas:
 * Vence = el día del último sync, no el año que debería.
 *
 * `DATETIME` no tiene inicialización ni auto-update automáticos (hay que declararlos), así que el problema
 * no puede repetirse. Es además el tipo correcto para un vencimiento absoluto: no tiene el techo de 2038 del
 * `TIMESTAMP` ni la conversión por zona horaria de la sesión.
 *
 * Se detecta sola, igual que `pacientes_numhistoria_admite_null`: si la columna ya es `datetime` no hace
 * nada (una base nueva, con la migración original ya corregida a `->dateTime()`, la salta), y en SQLite (la
 * base local y la de tests) no aplica. Sin doctrine/dbal no hay `->change()`, así que va con SQL directo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasTable('sync_credentials') || ! Schema::hasColumn('sync_credentials', 'expires_at')) {
            return;
        }

        $columna = DB::selectOne(
            'SELECT data_type AS tipo FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            ['sync_credentials', 'expires_at']
        );

        // Ya es `datetime`: no hay nada que sacarle.
        if (! $columna || strtolower((string) $columna->tipo) === 'datetime') {
            return;
        }

        // `MODIFY` reemplaza la definición completa de la columna, así que se lleva puestas las dos
        // propiedades automáticas (`DEFAULT current_timestamp()` y `ON UPDATE current_timestamp()`),
        // que es justo lo que hay que sacar. No toca el índice `sync_credentials_expires_at_index`.
        DB::statement('ALTER TABLE sync_credentials MODIFY expires_at DATETIME NOT NULL');
    }

    public function down(): void
    {
        // No se revierte: volver a `TIMESTAMP NOT NULL` en la primera columna TIMESTAMP de la tabla
        // reintroduciría el `ON UPDATE` que este arreglo saca (y con él, el vencimiento en cada sync).
    }
};
