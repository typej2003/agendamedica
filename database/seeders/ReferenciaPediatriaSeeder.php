<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **La referencia de pediatría** (WEB-12.0): las curvas de crecimiento (`percentil_nino`, `percentil_nina`) y el catálogo de vacunas
 * (`vacunas`) que trae el escritorio de pediatría, sin dueño (`reg_medico` nulo) para que los lea cualquier consultorio que todavía no
 * haya subido las suyas (`ReferenciaDeCrecimiento` prefiere las del `reg_medico` del contexto).
 *
 * Los JSON salen de la base real con `bridge/tools/exportar-catalogos-asa8.ps1`, que sólo exporta tablas de catálogo de una lista blanca
 * (nunca pacientes). Es idempotente: una tabla que ya tiene filas sin dueño no se vuelve a cargar.
 *
 * `percentil_pa_*` y `percentil_pulso_a_m` **no se cargan**: en la base real traen una sola fila con columnas que no corresponden a las
 * del esquema (restos de otra tabla) y `vacunas_dosis` y `tipo_vacuna` están vacías.
 *
 *   php artisan db:seed --class=ReferenciaPediatriaSeeder
 */
class ReferenciaPediatriaSeeder extends Seeder
{
    private const TABLAS = ['percentil_nino', 'percentil_nina', 'vacunas'];

    public function run(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (! Schema::hasTable($tabla) || DB::table($tabla)->whereNull('reg_medico')->exists()) {
                continue;
            }

            $archivo = __DIR__ . '/data/pediatria/' . $tabla . '.json';
            $filas = json_decode((string) file_get_contents($archivo), true) ?: [];
            $columnas = array_flip(Schema::getColumnListing($tabla));

            foreach ($filas as $fila) {
                DB::table($tabla)->insert(array_intersect_key($fila, $columnas) + ['reg_medico' => null, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
}
