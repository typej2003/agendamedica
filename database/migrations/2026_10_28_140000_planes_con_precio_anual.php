<?php

use App\Support\CaracteristicasPlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un plan, dos frecuencias (2026-10-28).
 *
 *  - `planes.precio_anual_usd`: el precio del mismo plan pagado por año (12 meses de servicio). Null = el
 *    plan es sólo mensual. `precio_usd` sigue siendo el mensual.
 *  - `pagos.meses`: los meses que compró ese pago de plan (1 mensual, 12 anual). Es lo que la aprobación
 *    usa para crear el período correcto; antes se deducía del plan, que ahora puede ofrecer las dos.
 *  - Los planes de ejemplo con precio fijo (20/35/45) quedan con el anual a 10 meses: 200/350/450. El plan
 *    por asiento calcula el anual con `meses_cobrados_anual` (10) porque el monto depende del equipo.
 *
 * No toca los planes viejos ni sus contratos: los "Premium mensual/anual" que ya existen se dejan como
 * están. El administrador puede cargarle el anual al mensual y desactivar el anual cuando quiera.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('planes', 'precio_anual_usd')) {
            Schema::table('planes', function (Blueprint $table) {
                $table->decimal('precio_anual_usd', 8, 2)->nullable();
            });
        }

        if (! Schema::hasColumn('pagos', 'meses')) {
            Schema::table('pagos', function (Blueprint $table) {
                $table->unsignedTinyInteger('meses')->nullable();
            });
        }

        $this->ponerPreciosAnualesDeEjemplo();
    }

    public function down(): void
    {
        if (Schema::hasColumn('pagos', 'meses')) {
            Schema::table('pagos', function (Blueprint $table) {
                $table->dropColumn('meses');
            });
        }

        if (Schema::hasColumn('planes', 'precio_anual_usd')) {
            Schema::table('planes', function (Blueprint $table) {
                $table->dropColumn('precio_anual_usd');
            });
        }
    }

    private function ponerPreciosAnualesDeEjemplo(): void
    {
        // Los fijos: 10 meses (2 de regalo).
        foreach (['equipo-1' => 200, 'equipo-2' => 350, 'equipo-3' => 450] as $codigo => $anual) {
            DB::table('planes')->where('codigo', $codigo)->update([
                'precio_anual_usd' => $anual,
                'updated_at' => now(),
            ]);
        }

        // El de equipo: el anual se calcula con el equipo actual, pero deja explícito que se cobran 10 meses.
        $asiento = DB::table('planes')->where('codigo', 'equipo-por-asiento')->first();
        if ($asiento !== null) {
            $caracteristicas = CaracteristicasPlan::desde($asiento->caracteristicas)->con([
                'meses_cobrados_anual' => 10,
            ]);

            DB::table('planes')->where('id', $asiento->id)->update([
                'precio_anual_usd' => null,
                'caracteristicas' => json_encode($caracteristicas->toArray()),
                'updated_at' => now(),
            ]);
        }
    }
};
