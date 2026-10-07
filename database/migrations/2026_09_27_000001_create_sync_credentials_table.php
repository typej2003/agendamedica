<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credenciales de sincronización por equipo, para el puente PowerBuilder 10 → Node → API.
 *
 * Por qué una tabla propia y no `personal_access_tokens` de Sanctum (verificado 2026-09-27):
 *  - `config/sanctum.php` NO existe en este proyecto → la expiración del paquete queda en `null`.
 *  - Los endpoints de sync (`/api/sync/upload-batch` y los tres `*SyncController`) validan el header
 *    a mano y NO pasan por el guard de Sanctum, así que la expiración nunca se evalúa ahí.
 *  - La migración de Sanctum que hay instalada no tiene columna `expires_at`.
 * Conclusión: un token de Sanctum en estas rutas NO vence nunca. Acá el vencimiento y la revocación
 * son explícitos y se verifican en el controlador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_credentials', function (Blueprint $table) {
            $table->id();

            // A qué médico pertenece el equipo. La credencial solo puede subir lotes de este médico.
            $table->string('reg_medico', 20)->index();

            // Etiqueta legible del equipo (ej. 'CONSULTORIO-1'). Es lo que se muestra al revocar.
            $table->string('machine_label', 100);

            // Hostname reportado por el puente. Sirve como bitácora siempre; se exige coincidencia
            // solo si `bound_to_machine` es true.
            $table->string('machine_host', 100)->nullable();
            $table->boolean('bound_to_machine')->default(false);

            // SHA-256 hex del token. El token en claro NUNCA se guarda en la base.
            $table->string('token_hash', 64)->unique();

            // Vencimiento absoluto, verificado en el servidor. Decisión del usuario: 1 año.
            //
            // `dateTime()` y NO `timestamp()`: esta es la primera columna TIMESTAMP de la tabla y, en
            // MySQL/MariaDB con `explicit_defaults_for_timestamp` deshabilitado, `timestamp NOT NULL`
            // recibe solo `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` — el vencimiento se
            // pisaba en cada UPDATE (el sync guarda `last_used_at`) y las credenciales vencían al primer
            // uso. Ver la migración `2026_10_07_120000_corrige_expires_at_de_sync_credentials`.
            $table->dateTime('expires_at')->index();

            // Revocación inmediata (equipo robado, técnico que se va, reinstalación).
            $table->timestamp('revoked_at')->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_credentials');
    }
};
