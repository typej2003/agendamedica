<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMedicoPacientesTable extends Migration
{
    public function up()
    {
        Schema::create('medico_pacientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->constrained('medicos')->onDelete('cascade');
            $table->foreignId('paciente_id')->constrained('pacientes')->onDelete('cascade');
            $table->string('numhistoria')->nullable(); // El número de historia para este médico específico
            $table->string('reg_medico')->nullable(); // El registro del médico para este paciente específico   
            $table->timestamps();

            $table->unique(['medico_id', 'paciente_id']);
            $table->index(['medico_id', 'numhistoria']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('medico_pacientes');
    }
}