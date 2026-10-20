<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `users.ultima_actividad_web_at`: la última vez que la cuenta usó la web (panel o `/clinica`). La escribe
 * en segundo plano `App\Listeners\GuardarUltimaActividadWeb` y la lee la sección "API Keys". Nullable: las
 * cuentas que existían no tienen el dato hasta su próxima visita ("Sin registro", no "Nunca").
 */
return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('ultima_actividad_web_at')->nullable()->after('blocked_reason');
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ultima_actividad_web_at');
        });
    }
};
