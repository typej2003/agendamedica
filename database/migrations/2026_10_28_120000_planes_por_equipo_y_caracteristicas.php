<?php

use App\Support\CaracteristicasPlan;
use App\Support\RestriccionesPlan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Planes con límites de equipo y características (2026-10-28).
 *
 *  - `planes.caracteristicas`: capacidades y forma de cobro (impresión, precio por asiento, secretarías
 *    incluidas, mínimo de asientos). Va separado de `restricciones` (límites) para que cada cosa tenga
 *    un solo lugar; las dos se copian a `reg_medico_servicio` al contratar.
 *  - Siembra los cuatro planes de ejemplo de Alexander. El dominio **no** mira sus códigos: son filas
 *    editables desde el panel, como cualquier otro plan.
 *  - La prueba gratuita pasa a 1 médico y 1 secretaría. No se tocan las filas ya contratadas: cada
 *    `reg_medico_servicio` conserva las restricciones que copió.
 *
 * Migración aditiva con guardas: no edita la migración original de planes.
 */
return new class extends Migration
{
    private const PLANES = [
        [
            'codigo' => 'equipo-1', 'nombre' => 'Consultorio · 1 médico', 'descripcion' => '1 médico y 1 secretaría. Incluye impresión de reportes y PDF.',
            'precio_usd' => 20, 'orden' => 2,
            'restricciones' => ['max_medicos' => 1, 'max_secretarias' => 1],
            'caracteristicas' => ['impresion_reportes' => true],
        ],
        [
            'codigo' => 'equipo-2', 'nombre' => 'Consultorio · 2 médicos', 'descripcion' => '2 médicos y 1 secretaría. Incluye impresión de reportes y PDF.',
            'precio_usd' => 35, 'orden' => 3,
            'restricciones' => ['max_medicos' => 2, 'max_secretarias' => 1],
            'caracteristicas' => ['impresion_reportes' => true],
        ],
        [
            'codigo' => 'equipo-3', 'nombre' => 'Consultorio · 3 médicos', 'descripcion' => '3 médicos y 1 secretaría. Incluye impresión de reportes y PDF.',
            'precio_usd' => 45, 'orden' => 4,
            'restricciones' => ['max_medicos' => 3, 'max_secretarias' => 1],
            'caracteristicas' => ['impresion_reportes' => true],
        ],
        [
            'codigo' => 'equipo-por-asiento', 'nombre' => 'Consultorio · equipo',
            'descripcion' => 'Desde 4 asientos: USD 12 por médico o secretaría; la primera secretaría va incluida.',
            'precio_usd' => 0, 'orden' => 5,
            'restricciones' => ['max_medicos' => null, 'max_secretarias' => null],
            'caracteristicas' => [
                'impresion_reportes' => true,
                'precio_asiento_usd' => 12,
                'secretarias_incluidas' => 1,
                'minimo_asientos' => 4,
            ],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('planes', 'caracteristicas')) {
            Schema::table('planes', function (Blueprint $table) {
                $table->json('caracteristicas')->nullable();
            });
        }

        if (! Schema::hasColumn('reg_medico_servicio', 'caracteristicas')) {
            Schema::table('reg_medico_servicio', function (Blueprint $table) {
                $table->json('caracteristicas')->nullable();
            });
        }

        $ahora = now();

        foreach (self::PLANES as $plan) {
            if (DB::table('planes')->where('codigo', $plan['codigo'])->exists()) {
                continue;
            }

            DB::table('planes')->insert([
                'codigo' => $plan['codigo'],
                'nombre' => $plan['nombre'],
                'descripcion' => $plan['descripcion'],
                'frecuencia' => 'mensual',
                'precio_usd' => $plan['precio_usd'],
                'precio_tachado_usd' => null,
                'restricciones' => json_encode(RestriccionesPlan::desde($plan['restricciones'])->toArray()),
                'caracteristicas' => json_encode(CaracteristicasPlan::desde($plan['caracteristicas'])->toArray()),
                'es_default' => false,
                'visible' => true,
                'activo' => true,
                'orden' => $plan['orden'],
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }

        $this->ajustarLaPrueba();
    }

    public function down(): void
    {
        $codigos = array_column(self::PLANES, 'codigo');
        $ids = DB::table('planes')->whereIn('codigo', $codigos)->pluck('id', 'codigo');

        foreach ($ids as $codigo => $id) {
            $tieneContratos = Schema::hasTable('reg_medico_servicio')
                && DB::table('reg_medico_servicio')->where('plan_id', $id)->exists();

            if ($tieneContratos) {
                DB::table('planes')->where('id', $id)->update(['activo' => false, 'visible' => false]);
            } else {
                DB::table('planes')->where('id', $id)->delete();
            }
        }

        if (Schema::hasColumn('reg_medico_servicio', 'caracteristicas')) {
            Schema::table('reg_medico_servicio', function (Blueprint $table) {
                $table->dropColumn('caracteristicas');
            });
        }

        if (Schema::hasColumn('planes', 'caracteristicas')) {
            Schema::table('planes', function (Blueprint $table) {
                $table->dropColumn('caracteristicas');
            });
        }
    }

    /** La prueba gratuita incluye 1 médico y 1 secretaría, sin perder claves que ya estuvieran guardadas. */
    private function ajustarLaPrueba(): void
    {
        $prueba = DB::table('planes')->where('codigo', 'prueba')->first();
        if ($prueba === null) {
            return;
        }

        $restricciones = RestriccionesPlan::desde($prueba->restricciones)->con([
            'max_medicos' => 1,
            'max_secretarias' => 1,
        ]);

        DB::table('planes')->where('id', $prueba->id)->update([
            'restricciones' => json_encode($restricciones->toArray()),
            'updated_at' => now(),
        ]);
    }
};
