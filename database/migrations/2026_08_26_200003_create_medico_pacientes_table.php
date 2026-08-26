<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMedicoPacientesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('medico_pacientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->constrained('medicos')->onDelete('cascade');
            
            // numhistoria como clave foránea hacia la tabla pacientes
            $table->string('paciente_id');
            $table->foreign('paciente_id')->references('numhistoria')->on('pacientes')->onDelete('cascade');

            $table->timestamps();

            // Evitar registros duplicados de la misma combinación médico-paciente
            $table->unique(['medico_id', 'paciente_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('medico_pacientes');
    }
}