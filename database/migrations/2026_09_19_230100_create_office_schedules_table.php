<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los bloques de trabajo de un consultorio: una fila por día y bloque.
 *
 * **Por qué filas y no un JSON en `offices`** (se evaluaron las dos): el merge de conflictos del
 * sync es **por columna**. Un JSON es una sola columna, así que si la secretaria cambia el horario
 * del lunes en el teléfono y el médico el del miércoles en el escritorio, last-write-wins borra
 * uno de los dos cambios entero. Con una fila por bloque cada cambio viaja por su lado y la
 * maquinaria que ya existe funciona sin tocarla. De paso, el cupo cuelga del bloque y la
 * validación de solapes es un `WHERE` en vez de recorrer JSON.
 *
 * El día de la semana se guarda como entero con la convención de `DateTime` (lunes = 1), que es
 * la del cliente Flutter; el legado los guarda como columnas sueltas mal escritas
 * (`vienes_i`, `domigo_f`) y no vale la pena heredar eso.
 */
class CreateOfficeSchedulesTable extends Migration
{
    public function up()
    {
        Schema::create('office_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained('offices')->onDelete('cascade');
            // Mismo motivo que en `offices`: es lo que filtra el delta del sync.
            $table->string('reg_medico', 20)->nullable();
            $table->unsignedTinyInteger('dia_semana'); // 1 = lunes … 7 = domingo
            $table->time('hora_inicio');
            $table->time('hora_fin');
            // Máximo de pacientes de **esa jornada**, no del día: el mismo consultorio puede
            // atender 20 en la mañana y 12 en la tarde. Nulo = sin tope configurado, que es el
            // estado de todos los datos reales — sin esto el app avisaría "el día está lleno"
            // con un número que nadie definió.
            $table->unsignedSmallInteger('cupo')->nullable();
            $table->timestamps();

            $table->index(['office_id', 'dia_semana']);
            $table->index(['reg_medico', 'updated_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('office_schedules');
    }
}
