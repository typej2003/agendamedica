<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las columnas numéricas del esquema de **pediatría** pasan de `double(8,2)` a `double` a secas.
 *
 * ⚠️ **Trampa**: `$table->float('x')` en Laravel **no** es un `FLOAT` de MySQL, es un `double(8, 2)`
 * (`Blueprint::float($column, $total = 8, $places = 2)` y `MySqlGrammar::typeFloat` → `typeDouble`), o sea
 * un número de **6 dígitos enteros y 2 decimales**: máximo ±999.999,99. La migración de pediatría salió
 * con `float()` para las 179 columnas que el legado declara `float` —y el `float` de SQL Anywhere es de
 * **doble precisión**, no de 4 bytes—, así que cualquier valor fuera de ese rango hacía fallar el lote
 * entero. Pasó de verdad (2026-10-13, con una base real): la carga inicial se cortó en
 * `examenes_paciente_consulta`, fila 22.360, con
 * `SQLSTATE[22003]: ... Out of range value for column 'sodio' at row 65`, y ahí quedó trabada.
 *
 * Las migraciones que las crean se corrigieron a `double` (una instalación nueva nace bien); esta existe
 * para las bases que ya las tienen creadas. Se agrupa un `ALTER TABLE` por tabla —cada uno reconstruye la
 * tabla una sola vez— y sólo toca los que existen.
 *
 * El `down()` no vuelve a `double(8,2)`: truncaría a dos decimales los valores que ya entraron y haría
 * fallar la migración con los que no caben.
 */
return new class extends Migration
{
    /** tabla => columnas que quedaron como `double(8,2)` y tienen que ser `double` a secas. */
    private const COLUMNAS = [
        'consultas' => ['espirometria_pef', 'espirometria_fev1'],
        'dietas' => ['calorias'],
        'espiro' => ['pef', 'pve'],
        'examenes_paciente_consulta' => ['peso', 'talla', 'urea', 'cr', 'sodio', 'potasio', 'cloro', 'calcio', 'fosforo', 'magnesio', 'paratohormona', 'ferritina', 'trasferrina', 'proteinuria', 'depuracion_creatinina', 'ac_urico_u', 'amonio', 'hierro_serico', 'folato', 'creatinina_u', 'sodio_u', 'potasio_u', 'cloro_u', 'fosforo_u', 'magnesio_u', 'citrato_u', 'oxelato_u', 'albumina_u', 'calcio_u', 'g_v_ph', 'g_v_pco2', 'g_v_hco3', 'g_v_eb', 'ac_urico_serico', 'hb', 'hto', 'hb_glicosilada', 'triglicerido', 'colesterol', 'creatinina_s', 'v_urina', 'creatinina_u_24', 'sodio_u_24', 'fosforo_u_24', 'cloro_u_24', 'calcio_u_24', 'aci_urico_u_24', 'potasio_u_24', 'oxato_u_24', 'citrato_u_24', 'ac_urico'],
        'grafi_ca' => ['valor'],
        'grafi_cl' => ['valor'],
        'grafi_cr' => ['valor'],
        'grafi_de' => ['valor'],
        'grafi_fe' => ['valor'],
        'grafi_fo' => ['valor'],
        'grafi_ma' => ['valor'],
        'grafi_pa' => ['valor'],
        'grafi_po' => ['valor'],
        'grafi_pr' => ['valor'],
        'grafi_so' => ['valor'],
        'grafi_tr' => ['valor'],
        'grafi_urea' => ['valor'],
        'grafica_brazo' => ['edad', 'brazo'],
        'grafica_circunferencia_c' => ['edad', 'circunferencia'],
        'grafica_imc' => ['edad', 'imc'],
        'grafica_peso' => ['edad', 'peso'],
        'grafica_peso_talla' => ['talla', 'peso'],
        'grafica_presio_d' => ['edad', 'presion_d'],
        'grafica_presio_s' => ['edad', 'presion_s'],
        'grafica_talla' => ['edad', 'talla'],
        'percentil_nina' => ['peso_b', 'peso_m', 'peso_a', 'talla_b', 'talla_m', 'talla_a', 'cefalica_b', 'cefalica_m', 'cefalica_a', 'brazo_b', 'brazo_m', 'brazo_a', 'anos', 'imc_b', 'imc_m', 'imc_a'],
        'percentil_nino' => ['peso_b', 'peso_m', 'peso_a', 'talla_b', 'talla_m', 'talla_a', 'cefalica_b', 'cefalica_m', 'cefalica_a', 'brazo_b', 'brazo_m', 'brazo_a', 'anos', 'imc_b', 'imc_m', 'imc_a'],
        'percentil_pa_diast_m' => ['tres', 'diez', 'veinte_5', 'cincuenta', 'setenta_5', 'noventa', 'noventa_7', 'cefalica_m', 'cefalica_a', 'anos'],
        'percentil_pa_sisto_m' => ['tres', 'diez', 'veinte_5', 'cincuenta', 'setenta_5', 'noventa', 'noventa_7', 'cefalica_m', 'cefalica_a', 'anos'],
        'percentil_pulso_a_m' => ['tres', 'diez', 'veinte_5', 'cincuenta', 'setenta_5', 'noventa', 'noventa_7', 'anos'],
        'percentiles_paciente' => ['peso', 'talla', 'c_cefalica', 'c_brazo', 'pulso', 'p_a_sistolica', 'p_a_diastolica', 'imc'],
        'ultra_renal' => ['rd_longitud', 'rd_transverso', 'rd_anteroporteior', 'rd_c_medidas', 'rd_ha_loe_medida', 'rd_ha_litiasis_numero', 'rd_ha_litiasis_medida', 'rd_ha_litiasis_medida_o', 'ri_longitud', 'ri_transverso', 'ri_anteroporteior', 'ri_c_medidas', 'ri_ha_loe_medida', 'ri_ha_litiasis_numero', 'ri_ha_litiasis_medida', 'ri_ha_litiasis_medida_o', 'rd_ha_loe_medida_o', 'ri_ha_loe_medida_o', 'frecuencia'],
        'vacuna_paciente' => ['edad_aplicada'],
        'vacunas' => ['preciov'],
        'vacunas_aplicadas' => ['preciovu', 'preciovt'],
        'vacunas_compra' => ['preciot'],
        'vacunas_compradas' => ['precioc'],
        'vacunas_dosis' => ['meses'],
    ];

    public function up(): void
    {
        // El problema y la corrección son de MySQL/MariaDB. En SQLite (tests) el tipo es `float` y el
        // rango no se aplica, así que no hay nada que cambiar.
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        foreach (self::COLUMNAS as $tabla => $columnas) {
            if (! Schema::hasTable($tabla)) {
                continue;
            }

            $modificaciones = [];
            foreach ($columnas as $columna) {
                if (Schema::hasColumn($tabla, $columna)) {
                    $modificaciones[] = "MODIFY `{$columna}` DOUBLE NULL";
                }
            }
            if ($modificaciones === []) {
                continue;
            }

            DB::statement('ALTER TABLE `' . $tabla . '` ' . implode(', ', $modificaciones));
        }
    }

    public function down(): void
    {
        // No se vuelve a `double(8,2)`: truncaría a dos decimales lo que ya entró.
    }
};