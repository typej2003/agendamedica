<?php

namespace Tests\Feature;

use App\Models\ConfiguracionApi;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * `configuracion_api`: la tabla de **configuración interna del servidor** en clave/valor, donde vive la
 * versión de datos (`version_datos`).
 *
 * Lo que fija: la migración deja la versión en 0; `valor()` devuelve el defecto cuando la clave falta;
 * `entero()` castea y usa el defecto con un valor vacío; y `fijar()` crea o actualiza **una sola fila** por
 * clave, moviendo `updated_at`.
 */
class ConfiguracionApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_la_migracion_deja_la_version_de_datos_en_cero(): void
    {
        $this->assertDatabaseHas('configuracion_api', ['clave' => 'version_datos', 'valor' => '0']);
        $this->assertSame(0, ConfiguracionApi::entero('version_datos'));
    }

    public function test_valor_devuelve_el_defecto_cuando_falta_la_clave(): void
    {
        $this->assertNull(ConfiguracionApi::valor('no_existe'));
        $this->assertSame('por-defecto', ConfiguracionApi::valor('no_existe', 'por-defecto'));
    }

    public function test_entero_castea_y_usa_el_defecto(): void
    {
        $this->assertSame(3, ConfiguracionApi::entero('no_existe', 3));

        ConfiguracionApi::fijar('prueba_entero', '7');
        $this->assertSame(7, ConfiguracionApi::entero('prueba_entero', 3));

        ConfiguracionApi::fijar('prueba_vacia', '');
        $this->assertSame(3, ConfiguracionApi::entero('prueba_vacia', 3));
    }

    public function test_fijar_crea_y_actualiza_la_fila_sin_duplicarla(): void
    {
        $this->assertDatabaseMissing('configuracion_api', ['clave' => 'prueba']);

        ConfiguracionApi::fijar('prueba', 'uno');

        $this->assertSame('uno', ConfiguracionApi::valor('prueba'));
        $this->assertSame(1, ConfiguracionApi::query()->where('clave', 'prueba')->count());

        $antes = ConfiguracionApi::query()->where('clave', 'prueba')->firstOrFail();

        $this->travel(5)->minutes();
        ConfiguracionApi::fijar('prueba', 'dos');

        $despues = ConfiguracionApi::query()->where('clave', 'prueba')->firstOrFail();

        $this->assertSame('dos', ConfiguracionApi::valor('prueba'));
        $this->assertSame(1, ConfiguracionApi::query()->where('clave', 'prueba')->count());
        $this->assertTrue($despues->updated_at->greaterThan($antes->updated_at));

        $this->travelBack();
    }
}
