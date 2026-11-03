<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `solicitudes_medico.aprobada_automaticamente` (2026-11-03): distingue la solicitud que aprobó el
 * administrador de la que se aprobó sola al confirmar el correo, porque la política no exigía carnet. La
 * bandeja lo muestra y es la traza de por qué la ficha nació con el carnet sin verificar.
 *
 * Columna nueva y nullable-free (default `false`): las solicitudes viejas quedan como manuales.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('solicitudes_medico', 'aprobada_automaticamente')) {
            return;
        }

        Schema::table('solicitudes_medico', function (Blueprint $table) {
            $table->boolean('aprobada_automaticamente')->default(false)->after('revisada_el');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('solicitudes_medico', 'aprobada_automaticamente')) {
            return;
        }

        Schema::table('solicitudes_medico', function (Blueprint $table) {
            $table->dropColumn('aprobada_automaticamente');
        });
    }
};
