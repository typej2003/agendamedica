<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `personal_access_tokens` de Sanctum.
 *
 * Sanctum 3 creaba esta tabla desde las migraciones del paquete; Sanctum 4 ya no, así
 * que se publicó acá al pasar a Laravel 11. En las bases desplegadas la tabla **ya
 * existe** y esta migración todavía no figura en `migrations`: por eso `up()` no la
 * vuelve a crear y sólo agrega la columna nueva (`expires_at`, que la migración vieja
 * de Sanctum no tenía). Sin estas guardas, el primer `migrate --force` fallaría con
 * «table already exists» (trampa 167).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->morphs('tokenable');
                $table->text('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });

            return;
        }

        if (! Schema::hasColumn('personal_access_tokens', 'expires_at')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->timestamp('expires_at')->nullable()->index();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
