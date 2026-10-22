<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **El candado suave de la consulta** («abierta en otro dispositivo»): quién la tiene abierta ahora y cuándo
 * tocó la pantalla por última vez.
 *
 * Tabla **nueva de AppDDR**, no del legado: no entra al sync del escritorio ni del app, así que se diseña libre y
 * **no toca `consultas`**. Una fila vigente por consulta (`consulta_id` único): abrir la consulta la crea o la
 * renueva, tomar el control la sobrescribe y el candado vence solo (`ultima_actividad_en` vieja). Es un aviso, no
 * un bloqueo: ver `App\Actions\Consulta\RegistrarSesionDeConsulta`.
 *
 * Sin clave foránea a `consultas`: el legado borra y recrea filas por su cuenta y un candado huérfano no hace daño.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consulta_sesiones', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('consulta_id')->unique();
            // El tenant (`reg_medico`) de la consulta: con él se comprueba que quien registra es del mismo consultorio.
            $table->string('reg_medico', 20)->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable();
            // El UUID que cada navegador se inventa y guarda (localStorage): identifica el dispositivo, no a la persona.
            $table->string('dispositivo_id', 64);

            $table->timestamp('abierta_en')->nullable();
            $table->timestamp('ultima_actividad_en')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consulta_sesiones');
    }
};
