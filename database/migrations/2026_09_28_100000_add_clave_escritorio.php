<?php

use App\Sync\Escritorio\TablasLegado;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `clave_escritorio`: la clave de la fila EN EL ESCRITORIO PowerBuilder, para historias, consultas
 * y citas (bridge/DISENO-FASE-2.md § 4).
 *
 * Hace falta porque esos números no siempre coinciden: el app y el escritorio pueden crear la misma
 * historia o consulta sin conexión (el API le da otro número a una de las dos), y el app puede mover
 * una cita, lo que en el escritorio cambia su clave (`fecha`, `hora_ini`). El resto de las tablas del
 * legado cuelga de esos números y no necesita la columna.
 *
 * Columnas nuevas y nullable (regla del proyecto para tablas del legado). Únicas por médico.
 *
 * Para los médicos que YA completaron la carga inicial, la clave se llena con los mismos números
 * (en la carga coinciden). Los que la hagan después la reciben de la propia carga.
 */
return new class extends Migration
{
    private const TABLAS = ['historias', 'consultas', 'cola'];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (! Schema::hasColumn($tabla, 'clave_escritorio')) {
                Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                    $table->string('clave_escritorio', 60)->nullable();
                    $table->unique(['reg_medico', 'clave_escritorio'], "{$tabla}_reg_medico_clave_escritorio_unique");
                });
            }
        }

        $completas = Schema::hasTable('sync_cargas')
            ? DB::table('sync_cargas')->where('estado', 'completa')->pluck('reg_medico')
            : collect();

        foreach ($completas as $regMedico) {
            DB::table('historias')->where('reg_medico', $regMedico)->whereNull('clave_escritorio')
                ->orderBy('id')->chunkById(1000, function ($filas) {
                    foreach ($filas as $f) {
                        DB::table('historias')->where('id', $f->id)
                            ->update(['clave_escritorio' => TablasLegado::claveHistoria($f->numhistoria)]);
                    }
                });

            DB::table('consultas')->where('reg_medico', $regMedico)->whereNull('clave_escritorio')
                ->orderBy('id')->chunkById(1000, function ($filas) {
                    foreach ($filas as $f) {
                        DB::table('consultas')->where('id', $f->id)
                            ->update(['clave_escritorio' => TablasLegado::claveConsulta($f->numhistoria, $f->nroconsulta)]);
                    }
                });

            DB::table('cola')->where('reg_medico', $regMedico)->whereNull('clave_escritorio')
                ->orderBy('id')->chunkById(1000, function ($filas) {
                    foreach ($filas as $f) {
                        DB::table('cola')->where('id', $f->id)
                            ->update(['clave_escritorio' => TablasLegado::claveCola($f->fecha, $f->hora_ini)]);
                    }
                });
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (Schema::hasColumn($tabla, 'clave_escritorio')) {
                Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                    $table->dropUnique("{$tabla}_reg_medico_clave_escritorio_unique");
                    $table->dropColumn('clave_escritorio');
                });
            }
        }
    }
};
