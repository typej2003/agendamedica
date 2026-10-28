<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **La columna `expires_at` de `personal_access_tokens`, también en las bases que ya estaban.**
 *
 * Sanctum 4 (*v4.3.3*, la versión instalada) **siempre** escribe `expires_at` al crear un token: si la
 * tabla no tiene la columna, todo login del API revienta con
 * `SQLSTATE[42S22]: Column not found: 1054 Unknown column 'expires_at'` — y no sólo el de la app móvil,
 * también cualquier herramienta que pida un token. Medido el 2026-10-28 contra QA y producción: las dos
 * bases tienen la tabla sin la columna, así que **el login del API estaba caído**.
 *
 * La migración `2019_12_14_000001_create_personal_access_tokens_table` ya trae la guarda que la agrega,
 * pero en esos servidores figura como corrida desde antes de que existiera esa guarda: `migrate --force`
 * no la vuelve a ejecutar. Por eso la corrección va acá, como migración **nueva** (trampa 167: una
 * migración desplegada no se edita) y con la misma protección para poder correrla dos veces.
 *
 * Es aditiva y nullable: no toca ningún token existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personal_access_tokens') || Schema::hasColumn('personal_access_tokens', 'expires_at')) {
            return;
        }

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('personal_access_tokens') || ! Schema::hasColumn('personal_access_tokens', 'expires_at')) {
            return;
        }

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['expires_at']);
            $table->dropColumn('expires_at');
        });
    }
};
