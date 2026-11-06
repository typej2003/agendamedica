<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `medicos.medico_padre_id`: **el consultorio del que cuelga esta ficha** (2026-11-06).
 *
 * Un mismo doctor atiende en varios sitios y cada uno sincroniza con su propio `reg_medico`. Hasta acá, el
 * segundo consultorio era un tenant independiente: pagaba su plan y el doctor ocupaba un asiento en los dos.
 * Con esta columna, el consultorio nuevo **cuelga del principal del doctor** y usa **su** plan
 * (`ServicioService::actual()` resuelve al padre), sin cuenta de acceso y sin contar dos veces al mismo médico
 * (`CupoDelPlan`).
 *
 * Nula por defecto: todo lo que existe hoy sigue siendo independiente. Sólo la llena el alta del consultorio
 * del médico y se puede quitar para volver a separarlo (ahí el panel le asigna un plan propio).
 *
 * Sin clave foránea, como `medical_centers.medico_id`: el esquema legado no las usa para las columnas nuevas
 * del panel. Un solo nivel: el padre de un consultorio no puede ser, a su vez, un consultorio.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('medicos', 'medico_padre_id')) {
            return;
        }

        Schema::table('medicos', function (Blueprint $table) {
            $table->unsignedBigInteger('medico_padre_id')->nullable()->after('formato_reportes');
            $table->index('medico_padre_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('medicos', 'medico_padre_id')) {
            return;
        }

        Schema::table('medicos', function (Blueprint $table) {
            $table->dropIndex(['medico_padre_id']);
            $table->dropColumn('medico_padre_id');
        });
    }
};
