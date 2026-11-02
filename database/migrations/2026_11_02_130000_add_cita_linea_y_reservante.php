<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **La información de la cita en línea y quién la reservó** (O-52, 2026-11-02).
 *
 * Dos cosas que el portal público necesita y que hoy no tienen dónde vivir:
 *
 *  - **Lo que el médico le dice al paciente antes de venir** (O-52): cuántos minutos antes llegar, cuántos
 *    acompañantes se admiten y una línea libre de indicaciones. Va en `evolucion`, que es la fila por
 *    médico de su configuración (donde ya viven el porcentaje de cita online y la publicación de la
 *    agenda), y las tres son nullable: sin configurar, la ficha pública no muestra nada.
 *  - **Quién hizo la reserva** cuando no es el paciente sino su representante (O-52). Va en la cita
 *    (`cola`) porque es de esa reserva, no del paciente: tres columnas nullable que el escritorio ignora.
 *
 * Toda la migración es **aditiva** y con guardas: sólo agrega columnas nullable, no toca las existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('evolucion')) {
            Schema::table('evolucion', function (Blueprint $table) {
                if (! Schema::hasColumn('evolucion', 'cita_linea_minutos_antes')) {
                    $table->unsignedSmallInteger('cita_linea_minutos_antes')->nullable();
                }

                if (! Schema::hasColumn('evolucion', 'cita_linea_acompanantes')) {
                    $table->unsignedSmallInteger('cita_linea_acompanantes')->nullable();
                }

                if (! Schema::hasColumn('evolucion', 'cita_linea_indicaciones')) {
                    $table->string('cita_linea_indicaciones', 255)->nullable();
                }
            });
        }

        if (Schema::hasTable('cola')) {
            Schema::table('cola', function (Blueprint $table) {
                // `P` = la reservó el paciente, `R` = su representante. NULL en las citas que no vienen del portal.
                if (! Schema::hasColumn('cola', 'reservado_por')) {
                    $table->string('reservado_por', 1)->nullable();
                }

                if (! Schema::hasColumn('cola', 'reservado_por_nombre')) {
                    $table->string('reservado_por_nombre', 120)->nullable();
                }

                if (! Schema::hasColumn('cola', 'reservado_por_documento')) {
                    $table->string('reservado_por_documento', 20)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('evolucion')) {
            Schema::table('evolucion', function (Blueprint $table) {
                foreach (['cita_linea_minutos_antes', 'cita_linea_acompanantes', 'cita_linea_indicaciones'] as $columna) {
                    if (Schema::hasColumn('evolucion', $columna)) {
                        $table->dropColumn($columna);
                    }
                }
            });
        }

        if (Schema::hasTable('cola')) {
            Schema::table('cola', function (Blueprint $table) {
                foreach (['reservado_por', 'reservado_por_nombre', 'reservado_por_documento'] as $columna) {
                    if (Schema::hasColumn('cola', $columna)) {
                        $table->dropColumn($columna);
                    }
                }
            });
        }
    }
};
