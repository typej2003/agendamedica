<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `pacientes.user_id`: la **cuenta de paciente** enlazada a su ficha (`User::paciente()`, el registro con
 * código, «Mis citas» y el bloqueo de cuentas).
 *
 * La columna entró al esquema base (`2026_08_25_055351_create_medical_tables_schema`) **editando** esa
 * migración el 2026-08-29, cuando ya se había corrido en las bases migradas (QA y producción): las
 * instalaciones creadas desde cero después de esa fecha la tienen y las migradas antes no. Eso dejó dos
 * síntomas: la cuenta del paciente no puede guardar el vínculo (`Unknown column 'pacientes.user_id'`) y el
 * relleno de `users.telefono_validado` no puede leer las fichas.
 *
 * Nullable y aditiva, como el resto de las columnas de AppDDR: no renombra ni reestructura nada del legado.
 */
return new class extends Migration
{
    public function up()
    {
        if (! Schema::hasTable('pacientes') || Schema::hasColumn('pacientes', 'user_id')) {
            return;
        }

        Schema::table('pacientes', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('id');
        });
    }

    /**
     * No se quita al volver atrás: la columna forma parte del esquema base desde el 2026-08-29 y esta
     * migración sólo repara las bases que se migraron antes. Volver a correrla es inocuo (`hasColumn`).
     */
    public function down()
    {
    }
};
