<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('citas', function (Blueprint $table) {
            $table->date('fecha');
            $table->unsignedBigInteger('numhistoria');
            $table->integer('numorden')->nullable();
            $table->decimal('atendido', 1, 0)->default(0);
            $table->decimal('estado', 1, 0)->default(0);
            $table->char('turno', 1)->nullable();
            $table->string('motivo', 100)->nullable();
            $table->time('hora_ini')->nullable();
            $table->time('hora_fin')->nullable();
            $table->integer('tiempo')->nullable();
            $table->string('tipo', 10)->nullable();
            $table->integer('conse')->nullable();
            $table->char('sms', 1)->nullable();
            $table->string('sms_text', 160)->nullable();
            $table->integer('medico')->nullable();
            $table->timestamps();

            $table->primary(['fecha', 'numhistoria']);

            $table->foreign('numhistoria')
                  ->references('numhistoria')
                  ->on('pacientes')
                  ->onDelete('cascade');

            $table->foreign('tipo')
                  ->references('codigo')
                  ->on('motivo_citas')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('citas');
    }
};