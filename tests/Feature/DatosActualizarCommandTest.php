<?php

namespace Tests\Feature;

use App\Models\ConfiguracionApi;
use App\Support\Datos\VersionesDeDatos;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * `datos:actualizar`: los pasos de datos que se corren una sola vez, versionados como las migraciones.
 *
 * Se bindea un registro falso (`VersionesDeDatosDePrueba`) con comandos inocuos: `inspire` para el caso
 * feliz y `no:existe` para el fallo. Lo que fija:
 *  - corre los comandos pendientes en orden y deja la versión en la última;
 *  - una segunda corrida no hace nada;
 *  - `--simular` no ejecuta ni escribe;
 *  - un comando que falla corta ahí, no avanza la versión y la siguiente versión no corre;
 *  - al reintentar, la versión fallida se vuelve a correr;
 *  - si falta la fila de la versión, se empieza en 0 y se crea.
 */
class DatosActualizarCommandTest extends TestCase
{
    use DatabaseTransactions;

    private VersionesDeDatosDePrueba $versiones;

    protected function setUp(): void
    {
        parent::setUp();

        $this->versiones = new VersionesDeDatosDePrueba();
        $this->app->instance(VersionesDeDatos::class, $this->versiones);
    }

    private function version(): int
    {
        return ConfiguracionApi::entero('version_datos', 0);
    }

    public function test_corre_los_comandos_pendientes_y_deja_la_version(): void
    {
        $this->versiones->versiones = [
            1 => ['inspire'],
            // El mismo comando en otra versión: se corre una sola vez, en la primera.
            2 => ['inspire'],
        ];

        $this->artisan('datos:actualizar')
            ->expectsOutputToContain('Versión de datos: 0 → 2 (1 comando).')
            ->assertSuccessful();

        $this->assertSame(2, $this->version());
    }

    public function test_la_segunda_corrida_no_hace_nada(): void
    {
        $this->versiones->versiones = [1 => ['inspire']];

        $this->artisan('datos:actualizar')->assertSuccessful();
        $this->assertSame(1, $this->version());

        $this->artisan('datos:actualizar')
            ->expectsOutput('Versión de datos: 1. No hay nada que actualizar.')
            ->assertSuccessful();

        $this->assertSame(1, $this->version());
    }

    public function test_simular_muestra_lo_que_correria_sin_ejecutar_ni_escribir(): void
    {
        $this->versiones->versiones = [
            1 => ['inspire'],
            // Si se ejecutara, fallaría: la simulación tiene que terminar bien igual.
            2 => ['no:existe'],
        ];

        $this->artisan('datos:actualizar', ['--simular' => true])
            ->expectsOutputToContain('inspire')
            ->expectsOutputToContain('no:existe')
            ->expectsOutputToContain('Versión de datos: 0 → 2 (2 comandos).')
            ->expectsOutputToContain('--simular: no se ejecutó nada ni se escribió la versión.')
            ->assertSuccessful();

        $this->assertSame(0, $this->version());
        $this->assertDatabaseHas('configuracion_api', ['clave' => 'version_datos', 'valor' => '0']);
    }

    public function test_si_falla_la_primera_version_no_corre_la_siguiente(): void
    {
        $this->versiones->versiones = [
            1 => ['no:existe'],
            2 => ['no:existe-tampoco'],
        ];

        $this->artisan('datos:actualizar')
            ->expectsOutputToContain('Falló «no:existe»')
            ->doesntExpectOutputToContain('no:existe-tampoco')
            ->assertFailed();

        $this->assertSame(0, $this->version());
    }

    public function test_un_comando_que_no_existe_corta_y_no_avanza_la_version(): void
    {
        $this->versiones->versiones = [
            1 => ['inspire'],
            2 => ['no:existe'],
            3 => ['inspire'],
        ];

        $this->artisan('datos:actualizar')
            ->expectsOutputToContain('Falló «no:existe»')
            ->expectsOutputToContain('Versión de datos: 1. Se cortó en la versión 2')
            ->assertFailed();

        // La 1 terminó entera y quedó; la 2 no avanzó y la 3 no corrió.
        $this->assertSame(1, $this->version());
    }

    public function test_al_reintentar_corre_la_version_fallida(): void
    {
        $this->versiones->versiones = [
            1 => ['no:existe'],
            2 => ['inspire'],
        ];

        $this->artisan('datos:actualizar')->assertFailed();
        $this->assertSame(0, $this->version());

        // Se corrigió lo que fallaba: la corrida siguiente reintenta desde la versión 1.
        $this->versiones->versiones = [
            1 => ['inspire'],
            2 => ['inspire'],
        ];

        $this->artisan('datos:actualizar')
            ->expectsOutputToContain('Versión de datos: 0 → 2 (1 comando).')
            ->assertSuccessful();

        $this->assertSame(2, $this->version());
    }

    public function test_funciona_si_falta_la_fila_de_la_version(): void
    {
        ConfiguracionApi::query()->where('clave', 'version_datos')->delete();

        $this->versiones->versiones = [1 => ['inspire']];

        $this->artisan('datos:actualizar')
            ->expectsOutputToContain('Versión de datos: 0 → 1 (1 comando).')
            ->assertSuccessful();

        $this->assertSame(1, $this->version());
    }
}

/** El registro falso: los tests le cambian las versiones entre corridas. */
class VersionesDeDatosDePrueba extends VersionesDeDatos
{
    public array $versiones = [];

    public function todas(): array
    {
        return $this->versiones;
    }
}
