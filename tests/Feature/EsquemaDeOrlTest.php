<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * **Las tablas propias de Otorrinolaringología** (ORL, 2026-11-10): las once de su esquema (`orl.sql`) ya están en el
 * API con sus claves del legado, el manifiesto las declara y el sync las sube.
 *
 * Lo que fijan estos casos:
 *
 *  - Cada tabla existe y tiene su clave (la de `historia` + `consulta`, y la de la audiometría, que lleva la
 *    frecuencia).
 *  - El manifiesto de ORL las declara como propias y las suma al sync.
 *  - Se puede guardar y releer una fila (la forma en que el sync las va a escribir).
 */
class EsquemaDeOrlTest extends TestCase
{
    use DatabaseTransactions;

    /** Las once tablas, con sus columnas de clave además de `historia`/`consulta`. */
    private const TABLAS = [
        'fisico_orl' => [],
        'oido_derecho' => ['frecuencia_od', 'db_aereo_od', 'db_osea_od'],
        'oido_izquierdo' => ['frecuencia_oi', 'db_aereo_oi', 'db_osea_oi'],
        'oidos_graficas' => ['frecuecia', 'desibeles', 'oido'],
        'oidos_graficas_iz' => ['frecuecia', 'desibeles', 'oido'],
        'audio_detalle' => ['audiometro', 'porcetaje_perdida_od', 'porcetaje_perdida_oi', 'diagnostico'],
        'NASO' => ['fn_cornetes_i_d', 'rinofaringe_adenoides_h', 'ima_nasa'],
        'naso_new' => ['hipofaringe', 'laringe_r_glotica_c_vocal', 'ima_rinof'],
        'naso_new_video' => ['hipofaringe', 'laringe_r_suglotica', 'ima_farin'],
        'imagen_nasolarigo' => ['nrohistoria', 'nroconsulta', 'imagen', 'orden', 'tipo'],
        'imagen_nasovideo' => ['nrohistoria', 'nroconsulta', 'imagen', 'orden', 'tipo'],
    ];

    public function test_las_once_tablas_existen_con_sus_columnas(): void
    {
        foreach (self::TABLAS as $tabla => $columnas) {
            $this->assertTrue(Schema::hasTable($tabla), 'Falta la tabla ' . $tabla . '.');

            foreach (array_merge(['reg_medico', 'created_at', 'updated_at'], $columnas) as $columna) {
                $this->assertTrue(Schema::hasColumn($tabla, $columna), 'Falta ' . $tabla . '.' . $columna . '.');
            }
        }
    }

    public function test_el_manifiesto_de_orl_las_declara_y_las_sube(): void
    {
        $manifiesto = require config_path('especialidades/orl.php');

        $this->assertSame('ORL', $manifiesto['codigo']);
        $this->assertSame('otorrinolaringologia', $manifiesto['specialty_slug']);

        foreach (array_keys(self::TABLAS) as $tabla) {
            $this->assertContains($tabla, $manifiesto['propias'], 'El manifiesto no declara «' . $tabla . '» como propia.');
            $this->assertContains($tabla, $manifiesto['sync']['tablas'], 'El manifiesto no sube «' . $tabla . '».');
        }
    }

    public function test_una_fila_de_la_evaluacion_fisica_y_de_la_audiometria_se_guarda_y_se_relee(): void
    {
        DB::table('fisico_orl')->insert([
            'reg_medico' => 'med_00001',
            'historia' => 1001,
            'consulta' => 1,
            'nar_septum' => 'Desviado a la derecha.',
            'oid_membrana' => 'Íntegra bilateral.',
        ]);

        DB::table('oido_derecho')->insert([
            'reg_medico' => 'med_00001',
            'historia' => 1001,
            'consulta' => 1,
            'frecuencia_od' => 1000,
            'db_aereo_od' => 20.5,
            'db_osea_od' => 15,
        ]);

        $this->assertSame('Desviado a la derecha.', DB::table('fisico_orl')->where('historia', 1001)->value('nar_septum'));
        $this->assertSame(20.5, (float) DB::table('oido_derecho')->where('frecuencia_od', 1000)->value('db_aereo_od'));
    }
}
