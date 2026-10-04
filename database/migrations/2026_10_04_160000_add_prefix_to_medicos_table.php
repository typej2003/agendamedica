<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `medicos.prefix`: tratamiento que se antepone al nombre ("Dr.", "Dra.", "Ing.", "Lic."…). Campo libre y
 * opcional (máximo 20 caracteres): sin él, ni la pantalla de inicio ni los reportes de GinecoReport llevan
 * prefijo. Lo devuelve `POST sync/cambios/estado` (`medico_prefix`); el escritorio arma con él su `Doct`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('medicos', 'prefix')) {
            return;
        }

        Schema::table('medicos', function (Blueprint $table) {
            $table->string('prefix', 20)->nullable()->after('lastname');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('medicos', 'prefix')) {
            return;
        }

        Schema::table('medicos', function (Blueprint $table) {
            $table->dropColumn('prefix');
        });
    }
};
