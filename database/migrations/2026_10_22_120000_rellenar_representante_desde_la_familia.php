<?php

use App\Clinica\Pacientes\RepresentanteDesdeLaFamilia;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Los niños de pediatría guardan al representante en `familia_bebe` y la web lo lee de `representante`: acá se completa esta última con lo
 * que ya dice la familia. Sólo crea filas que faltan o llena columnas vacías; nunca pisa lo cargado en la ficha. No se deshace: no hay forma
 * de saber qué filas eran de la web y cuáles salieron de acá.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('paciente_chamo') && Schema::hasTable('familia_bebe') && Schema::hasTable('representante')) {
            (new RepresentanteDesdeLaFamilia())->completarTodo();
        }
    }

    public function down(): void
    {
    }
};
