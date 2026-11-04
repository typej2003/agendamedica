<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Las verificaciones de una ficha médica, una fila por tipo** (2026-11-03): `carnet`, `correo` y
 * `telefono`. Pedido de Alexander: el carnet deja de ser un impedimento para usar la app, pero su estado
 * —y el del correo y el del teléfono— tiene que quedar guardado en el perfil para poder verificarlo
 * después.
 *
 * Tabla **nueva de AppDDR**, no del legado: no viaja por el sync del escritorio ni del app. El estado de
 * `carnet` es el único que puede cortar el acceso cuando el administrador enciende la exigencia
 * (`configuracion_verificacion_medicos.exigir_carnet`); los otros dos se informan.
 *
 * Sin clave foránea, como el resto de las tablas nuevas del proyecto: la ficha se borra por su cuenta y
 * esta tabla no tiene que bloquearla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verificaciones_medico', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('medico_id');
            // carnet | correo | telefono
            $table->string('tipo', 20);
            // sin_verificar | pendiente | verificado | rechazado
            $table->string('estado', 20)->default('sin_verificar');
            $table->timestamp('verificado_el')->nullable();
            $table->unsignedBigInteger('verificado_por')->nullable();
            $table->string('nota', 500)->nullable();
            $table->timestamps();

            // Una fila por tipo y médico: `marcar()` es un upsert por esta clave.
            $table->unique(['medico_id', 'tipo']);
            // La bandeja y el detalle del médico preguntan por tipo y estado.
            $table->index(['tipo', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verificaciones_medico');
    }
};
