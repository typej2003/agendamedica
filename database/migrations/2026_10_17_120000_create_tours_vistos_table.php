<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Los tours guiados ya vistos** (A-15): qué pantalla de la web clínica ya recorrió cada usuario.
 *
 * Tabla **nueva de AppDDR**, no del legado: no entra al sync del escritorio ni al del móvil, así que se
 * diseña libre. Vive en el servidor —y no en el navegador— a propósito: una médica que entra desde su
 * computadora y después desde el celular no vuelve a ver el mismo tour.
 *
 * Una fila por usuario y pantalla: alcanza para saber si hay que mostrarlo, y el `updateOrCreate` del
 * endpoint mueve `visto_el` sin duplicar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tours_vistos', function (Blueprint $table) {
            $table->id();

            // Sin clave foránea a propósito (como `comentarios`): si la cuenta se va, la fila no rompe nada.
            $table->unsignedBigInteger('user_id');

            // La clave de la pantalla del tour (`inicio`, `agenda`, `consulta`, `catalogos`, `consultorios`):
            // ver `App\Clinica\Tours\ToursDisponibles`.
            $table->string('pantalla', 40);

            $table->timestamp('visto_el');

            // La consulta que se hace en cada pantalla: ¿este usuario ya vio este tour?
            $table->unique(['user_id', 'pantalla']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tours_vistos');
    }
};
