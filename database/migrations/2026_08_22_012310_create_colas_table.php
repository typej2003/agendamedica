<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('colas', function (Blueprint $table) {
            $table->date('fecha');
            $table->unsignedBigInteger('numhistoria');
            $table->time('hora_ini')->nullable();
            $table->timestamps();

            $table->primary(['fecha', 'numhistoria']);

            $table->foreign('numhistoria')
                  ->references('numhistoria')
                  ->on('pacientes')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colas');
    }
};