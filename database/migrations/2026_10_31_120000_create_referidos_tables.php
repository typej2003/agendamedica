<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **El programa de referidos** (fase 4, 2026-10-31).
 *
 * Tres piezas nuevas de AppDDR (sólo del servidor, no viajan por el sync):
 *  - `codigos_de_referido`: un código vigente por consultorio. “Regenerar” cambia el código.
 *  - `referidos`: el vínculo entre el que refiere y el referido, con los Latidos congelados y el estado
 *    (`acreditado`, `rechazado`, `anulado`). La fila nace al aprobar la solicitud; antes de eso el código
 *    vive en `solicitudes_medico`.
 *  - Las dos columnas del código en `solicitudes_medico`, para atribuir el registro.
 *
 * La FK de `movimientos_de_latidos.referido_id` se agrega acá (la columna se creó en la migración de
 * Latidos) para no depender del orden entre las dos migraciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('referidos')) {
            return;
        }

        Schema::create('codigos_de_referido', function (Blueprint $table) {
            $table->id();
            $table->string('reg_medico', 50)->unique();
            $table->string('codigo', 12)->unique();
            $table->boolean('activo')->default(true);
            // Sólo el contador: no se guarda IP ni datos personales del que abre el link.
            $table->unsignedInteger('clics')->default(0);
            $table->timestamp('ultimo_clic_el')->nullable();
            $table->timestamps();
        });

        Schema::create('referidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('codigo_id')->nullable()->constrained('codigos_de_referido')->nullOnDelete();
            $table->string('codigo', 12);
            $table->string('referente_reg_medico', 50);
            // Un médico sólo puede ser referido una vez.
            $table->string('referido_reg_medico', 50)->unique();
            $table->foreignId('solicitud_id')->nullable()->unique()->constrained('solicitudes_medico')->nullOnDelete();
            $table->foreignId('medico_id')->nullable()->constrained('medicos')->nullOnDelete();
            // acreditado | rechazado | anulado
            $table->string('estado', 12)->default('acreditado');
            $table->unsignedBigInteger('latidos_referente')->default(0);
            $table->unsignedBigInteger('latidos_referido')->default(0);
            $table->timestamp('acreditado_el')->nullable();
            $table->timestamp('anulado_el')->nullable();
            $table->unsignedBigInteger('anulado_por')->nullable();
            // Motivo del rechazo o de la anulación. No se borra: deja la traza.
            $table->string('motivo', 255)->nullable();
            $table->timestamps();

            $table->index(['referente_reg_medico', 'estado'], 'referidos_referente_estado_idx');
            $table->index('created_at', 'referidos_fecha_idx');
        });

        Schema::table('solicitudes_medico', function (Blueprint $table) {
            if (! Schema::hasColumn('solicitudes_medico', 'codigo_referido')) {
                $table->string('codigo_referido', 12)->nullable();
            }
            if (! Schema::hasColumn('solicitudes_medico', 'referente_reg_medico')) {
                $table->string('referente_reg_medico', 50)->nullable();
            }
        });

        if (Schema::hasTable('movimientos_de_latidos')
            && Schema::hasColumn('movimientos_de_latidos', 'referido_id')) {
            Schema::table('movimientos_de_latidos', function (Blueprint $table) {
                $table->foreign('referido_id')->references('id')->on('referidos')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('movimientos_de_latidos') && Schema::hasColumn('movimientos_de_latidos', 'referido_id')) {
            Schema::table('movimientos_de_latidos', function (Blueprint $table) {
                $table->dropForeign(['referido_id']);
            });
        }

        if (Schema::hasTable('solicitudes_medico')) {
            Schema::table('solicitudes_medico', function (Blueprint $table) {
                if (Schema::hasColumn('solicitudes_medico', 'codigo_referido')) {
                    $table->dropColumn('codigo_referido');
                }
                if (Schema::hasColumn('solicitudes_medico', 'referente_reg_medico')) {
                    $table->dropColumn('referente_reg_medico');
                }
            });
        }

        Schema::dropIfExists('referidos');
        Schema::dropIfExists('codigos_de_referido');
    }
};
