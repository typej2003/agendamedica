<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `eco_obstetrico.dc_venoso` pasa de DECIMAL(5,2) a VARCHAR(20).
 *
 * En el legado esa columna se creó de dos maneras (`numeric(5,2)` en una rutina y `char` en otra,
 * ver `f_agregar_datos.srf`), así que hay consultorios con números y otros con letras (por ejemplo
 * 'N'). Con DECIMAL el API rechazaba el lote entero ("Incorrect decimal value: 'N'") y la carga
 * inicial quedaba cortada. Como texto guarda ambas versiones tal cual; ya se verá cómo se mapea.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('eco_obstetrico') || ! Schema::hasColumn('eco_obstetrico', 'dc_venoso')) {
            return;
        }

        // Sin doctrine/dbal no hay ->change(). SQLite no tipa las columnas: acepta el texto tal cual.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE eco_obstetrico MODIFY dc_venoso VARCHAR(20) NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('eco_obstetrico') || ! Schema::hasColumn('eco_obstetrico', 'dc_venoso')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            // Lo que no sea un número no cabe en DECIMAL: se pierde (queda NULL).
            DB::statement("UPDATE eco_obstetrico SET dc_venoso = NULL WHERE dc_venoso IS NOT NULL AND dc_venoso NOT REGEXP '^-?[0-9]+(\\.[0-9]+)?$'");
            DB::statement('ALTER TABLE eco_obstetrico MODIFY dc_venoso DECIMAL(5,2) NULL');
        }
    }
};
