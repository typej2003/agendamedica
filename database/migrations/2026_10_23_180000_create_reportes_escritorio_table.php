<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Reportes de fallo del instalador del escritorio.**
 *
 * Los manda el launcher (`instalar.exe`), el instalador (`actualizar-consultorio`) o el propio puente
 * (`sync.exe --reporte`) cuando algo falla, para no depender de que el médico copie un mensaje por
 * WhatsApp. Tabla **sólo del servidor**: no viaja por el sync ni la baja el móvil.
 */
class CreateReportesEscritorioTable extends Migration
{
    public function up()
    {
        Schema::create('reportes_escritorio', function (Blueprint $table) {
            $table->id();

            // El `reg_medico` puede no conocerse (falla antes del paso 3b).
            $table->string('reg_medico', 40)->nullable()->index();

            // instalador | launcher | sincronizacion | imagenes
            $table->string('tipo', 30)->index();

            // Dónde falló: "descarga", "4. Base de datos", "apikey"…
            $table->string('etapa', 60)->nullable();

            $table->string('aplicacion', 60)->nullable();
            $table->string('equipo', 100)->nullable();
            $table->string('version_canal', 30)->nullable();
            $table->string('windows', 100)->nullable();

            // La línea que resume el problema (lo primero que se mira en la bandeja).
            $table->string('mensaje', 500);

            // El detalle: el final del log, ya recortado por quien lo manda y por el servidor.
            $table->text('detalle')->nullable();

            $table->string('ip', 45)->nullable();

            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('reportes_escritorio');
    }
}
