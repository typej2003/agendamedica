<?php

namespace Tests\Feature;

use Database\Seeders\ParentescoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * **La migración que siembra el catálogo de parentescos** (O-61, 2026-11-05).
 *
 * El despliegue no corre seeders —corre `migrate --force`—, así que en producción la tabla `parentesco`
 * quedó vacía y el `<select>` de «Parentesco» del diálogo del representante mostraba sólo «— Sin dato —».
 * Lo que se prueba acá es el criterio de esa migración de datos:
 *
 *  - deja **las siete** filas de `ParentescoSeeder::PARENTESCOS` en un catálogo vacío (código, nombre y
 *    orden, leídos de la constante: acá no hay una segunda lista que se pueda desactualizar);
 *  - correrla **dos veces** no duplica ni cambia nada, y **no pisa** un nombre u orden corregido a mano;
 *  - sin la tabla, **no hace nada** (una instalación que todavía no corrió la migración del catálogo no se
 *    cae por esto).
 *
 * Se corre la migración **a mano** (`require` + `up()`), sin el registro en la tabla `migrations`, porque en
 * la base de tests ya corrió con `migrate:fresh` y su fila está puesta. `DatabaseTransactions`, como el
 * resto de los tests de la web clínica: lo que se borra o se siembra acá se revierte al terminar.
 */
class SembrarCatalogoDeParentescosTest extends TestCase
{
    use DatabaseTransactions;

    private const MIGRACION = '2026_11_05_140000_sembrar_el_catalogo_de_parentescos.php';

    /** La migración tal como la corre `artisan migrate`: se carga el archivo y se le pide `up()`. */
    private function migrar(): void
    {
        /** @var \Illuminate\Database\Migrations\Migration $migracion */
        $migracion = require database_path('migrations/' . self::MIGRACION);
        $migracion->up();
    }

    /** El catálogo vacío, como quedó el de producción después de `migrate --force` sin seeders. */
    private function vaciar(): void
    {
        DB::table('parentesco')->delete();

        $this->assertSame(0, DB::table('parentesco')->count(), 'El catálogo no quedó vacío antes de probar.');
    }

    /**
     * Las filas del catálogo en el orden en que se muestran, como arreglos, para poder compararlas enteras
     * contra la constante del seeder.
     *
     * @return array<int,array{codigo:string, nombre:string, orden:int}>
     */
    private function filas(): array
    {
        return DB::table('parentesco')
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get(['codigo', 'nombre', 'orden'])
            ->map(fn ($fila) => [
                'codigo' => (string) $fila->codigo,
                'nombre' => (string) $fila->nombre,
                'orden'  => (int) $fila->orden,
            ])
            ->all();
    }

    /** Lo que tiene que quedar: la constante del seeder, en el orden del `<select>`. */
    private function esperadas(): array
    {
        $esperadas = [];

        foreach (ParentescoSeeder::PARENTESCOS as $codigo => [$nombre, $orden]) {
            $esperadas[] = ['codigo' => (string) $codigo, 'nombre' => $nombre, 'orden' => (int) $orden];
        }

        return $esperadas;
    }

    public function test_la_migracion_deja_los_siete_parentescos_en_un_catalogo_vacio(): void
    {
        $this->vaciar();

        $this->migrar();

        $this->assertSame(7, DB::table('parentesco')->count(), 'El catálogo tiene que quedar con las siete filas.');
        // Se compara la tabla entera contra la constante del seeder: los códigos, los nombres y el orden.
        $this->assertSame($this->esperadas(), $this->filas());
        // Y el orden del `<select>`: madre y padre primero, después el resto.
        $this->assertSame(['M', 'P', 'L', 'A', 'T', 'H', 'O'], array_column($this->filas(), 'codigo'));
    }

    public function test_correrla_dos_veces_no_duplica_ni_pisa_lo_corregido_a_mano(): void
    {
        $this->vaciar();

        $this->migrar();

        // Un consultorio corrigió el nombre y el orden de un parentesco: eso manda sobre la constante.
        DB::table('parentesco')->where('codigo', 'H')->update(['nombre' => 'Hermana', 'orden' => 5]);

        $this->migrar();

        $this->assertSame(7, DB::table('parentesco')->count(), 'Correrla dos veces no puede duplicar ninguna fila.');
        $this->assertSame('Hermana', DB::table('parentesco')->where('codigo', 'H')->value('nombre'));
        $this->assertSame(5, (int) DB::table('parentesco')->where('codigo', 'H')->value('orden'));

        // Y no se tocó nada más: las otras seis siguen como las dejó la constante.
        $this->assertSame(
            array_values(array_filter($this->esperadas(), fn ($fila) => $fila['codigo'] !== 'H')),
            array_values(array_filter($this->filas(), fn ($fila) => $fila['codigo'] !== 'H')),
        );
    }

    public function test_sin_la_tabla_del_catalogo_no_hace_nada(): void
    {
        // Se tira la tabla dentro de la transacción del test: el rollback del teardown la deja como estaba.
        Schema::drop('parentesco');
        $this->assertFalse(Schema::hasTable('parentesco'));

        $this->migrar();

        $this->assertFalse(Schema::hasTable('parentesco'), 'La migración no puede crear ni tocar nada sin la tabla.');
    }
}
