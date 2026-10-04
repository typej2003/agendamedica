<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `recipes` es una tabla del esquema LEGADO (Docs/Wiki/07-modelo-de-datos.md) — ya existe de verdad
 * en el backend desplegado, con datos reales, aunque este repo nunca la había modelado. Por eso el
 * `Schema::hasTable` guard: en local (SQLite de desarrollo) la crea; contra el desplegado, la
 * encuentra ya creada y no hace nada. Iguala las columnas reales del legado — en particular
 * `nrohistoria` (no `numhistoria`, que es la convención que sí se usó para las tablas nuevas de este
 * repo como `historias`/`cola`). No renombrar: es el nombre real de la columna, ver AGENTS.md.
 *
 * Versión mínima del récipe médico (Fase 1: solo lectura + imprimir desde el app, ver ROADMAP.md).
 * Deja fuera a propósito lo que sí tiene el dominio completo
 * (Docs/Wiki/05-recipes-y-solicitudes.md): catálogo Vademécum, récipes "por tratamiento", récipe de
 * pareja, código QR — se agrega cuando haga falta, no antes.
 */
class CreateRecipesTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('recipes')) {
            return;
        }

        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->string('reg_medico', 20)->nullable();
            $table->integer('nrohistoria');
            $table->integer('nroconsulta');
            $table->string('codemedicina', 8);
            $table->text('indicaciones')->nullable();
            $table->integer('cantidad')->nullable();
            $table->integer('orden')->nullable();
            $table->string('descripcion', 200)->nullable();
            $table->date('fecha')->nullable();
            $table->integer('recipe')->nullable();
            $table->string('comple', 1)->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        // No se borra: no hay forma de saber acá si esta migración la creó (local) o ya
        // existía con datos reales (desplegado) — más vale no arriesgarse a tirar datos reales.
    }
}
