<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pacientes', function (Blueprint $table) {
            $table->unsignedBigInteger('numhistoria')->primary();
            $table->char('nac', 1)->nullable();
            $table->string('cedula', 10);
            $table->string('apellidos', 25);
            $table->string('nombres', 25);
            $table->char('sexo', 1)->nullable();
            $table->date('fnacimiento')->nullable();
            $table->string('lnacimiento', 100)->nullable();
            $table->string('codeestado', 3)->nullable();
            $table->string('direccion', 200)->nullable();
            $table->string('telefono', 20)->nullable();
            $table->date('fingreso')->nullable();
            $table->string('escolaridad', 100)->nullable();
            $table->string('ocupacion', 100)->nullable();
            $table->string('codesegemp', 3)->nullable();
            $table->string('foto_pac', 300)->nullable();
            $table->string('profesion', 50)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('dependencia', 30)->nullable();
            $table->integer('medico')->nullable();
            $table->char('sms', 1)->nullable();
            $table->timestamps();

            $table->foreign('codesegemp')
                  ->references('codesegemp')
                  ->on('seg_emps')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pacientes');
    }
};