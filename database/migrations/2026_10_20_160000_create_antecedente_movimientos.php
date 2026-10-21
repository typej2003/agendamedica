<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **El registro de movimientos de los antecedentes del paciente** (decisión del usuario, 2026-10-20).
 *
 * `antece_paciente` sigue siendo **una tabla por paciente** (legado, `PRIMARY KEY (numhistoria,
 * codeantecedente)` en el escritorio) y no se toca: quitar es un `DELETE` físico y editar pisa el detalle.
 * Con eso el médico no puede demostrar **qué se sabía en cada consulta** (p. ej. un antecedente que el
 * paciente ocultó al principio). Esta tabla guarda cada alta, edición y baja, con la consulta (si se hizo
 * desde una), el origen y el usuario.
 *
 * Es **tabla nueva de AppDDR, sólo del servidor**: no está en la lista blanca del sync del escritorio
 * (`config/sync_legado.php`) ni baja al móvil. Las filas que ya existían antes de esta migración (y las que
 * trae la carga inicial) **no tienen movimientos**: se leen como «sin fecha».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('antecedente_movimientos', function (Blueprint $table) {
            $table->id();
            $table->string('reg_medico', 20);
            $table->integer('numhistoria');
            $table->string('codeantecedente', 4);
            // La copia del nombre y del tipo en el momento del movimiento (el catálogo puede cambiar después).
            $table->string('descripcion', 50)->nullable();
            $table->string('tipo', 2)->nullable();
            // `agregado`, `editado` o `quitado` (`App\Models\AntecedenteMovimiento`).
            $table->string('operacion', 10);
            // El detalle **tras** el movimiento; en `quitado`, el que tenía al quitarse.
            $table->text('detalles')->nullable();
            // `antece_paciente.tipo2 = 'A'` tras el movimiento (en `quitado`, el que tenía).
            $table->boolean('alerta')->default(false);
            // `consultas.id` desde la que se hizo el movimiento; `null` si no vino de una consulta.
            $table->unsignedBigInteger('consulta_id')->nullable();
            // `web`, `movil`, `escritorio` o `carga_inicial`.
            $table->string('origen', 15);
            $table->unsignedBigInteger('user_id')->nullable();
            // Cuándo ocurrió (para el escritorio, la fecha del cambio que mandó); `null` si no se sabe.
            $table->dateTime('ocurrido_en')->nullable();
            $table->timestamps();

            $table->index(['reg_medico', 'numhistoria'], 'antecedente_movimientos_historia_idx');
            $table->index(['reg_medico', 'numhistoria', 'codeantecedente'], 'antecedente_movimientos_codigo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('antecedente_movimientos');
    }
};
