<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versión mínima del récipe médico (Fase 1: solo lectura + imprimir desde el
 * app, ver ROADMAP.md). Deja fuera a propósito lo que sí tiene el dominio
 * completo (Docs/Wiki/05-recipes-y-solicitudes.md): catálogo Vademécum,
 * récipes "por tratamiento", récipe de pareja, código QR — se agrega cuando
 * haga falta, no antes.
 */
class CreateRecipesTable extends Migration
{
    public function up()
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->string('reg_medico', 20)->nullable();
            $table->integer('numhistoria')->nullable();
            $table->integer('nroconsulta')->nullable();
            $table->date('fecha')->nullable();
            $table->string('codemedicina', 8)->nullable();
            $table->string('descripcion', 200)->nullable();
            $table->text('indicaciones')->nullable();
            $table->integer('cantidad')->nullable();
            $table->integer('medico')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('recipes');
    }
}
