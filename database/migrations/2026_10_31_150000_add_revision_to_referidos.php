<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Marca de revisión de un referido** (fase 4, 2026-10-31).
 *
 * El plan no bloquea automáticamente al referente que supera el umbral mensual: el premio se acredita y la
 * fila queda marcada para que el administrador la revise. La columna es nullable/default false para no
 * tocar las filas que ya existen en QA.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('referidos') || Schema::hasColumn('referidos', 'revisar')) {
            return;
        }

        Schema::table('referidos', function (Blueprint $table) {
            $table->boolean('revisar')->default(false)->after('estado');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('referidos') && Schema::hasColumn('referidos', 'revisar')) {
            Schema::table('referidos', function (Blueprint $table) {
                $table->dropColumn('revisar');
            });
        }
    }
};
