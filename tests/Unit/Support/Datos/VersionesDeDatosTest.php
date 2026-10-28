<?php

namespace Tests\Unit\Support\Datos;

use App\Support\Datos\VersionesDeDatos;
use PHPUnit\Framework\TestCase;

/**
 * **El registro de los comandos de datos** (`App\Support\Datos\VersionesDeDatos`), sin base de datos.
 *
 * Lo que fija: que las versiones salgan en orden y sólo las mayores que la actual; que un comando repetido
 * en dos versiones se corra una sola vez, en la primera; que una versión que sólo traía repetidos cuente
 * igual como completada (queda en `[]`); y cómo se separa una entrada en comando y parámetros de Artisan.
 */
class VersionesDeDatosTest extends TestCase
{
    private function registro(array $versiones): VersionesDeDatos
    {
        return new class($versiones) extends VersionesDeDatos {
            public function __construct(private array $versiones)
            {
            }

            public function todas(): array
            {
                return $this->versiones;
            }
        };
    }

    public function test_la_version_1_es_el_catalogo_inicial_de_ginecologia(): void
    {
        $registro = new VersionesDeDatos();

        $this->assertSame([
            1 => [
                'catalogos:sembrar-iniciales',
                'catalogos:cargar-a-medicos',
            ],
        ], $registro->todas());

        $this->assertSame([
            1 => [
                'catalogos:sembrar-iniciales',
                'catalogos:cargar-a-medicos',
            ],
        ], $registro->pendientes(0));
    }

    public function test_solo_devuelve_las_versiones_mayores_que_la_actual_y_en_orden(): void
    {
        $registro = $this->registro([
            3 => ['tercero'],
            1 => ['primero'],
            2 => ['segundo'],
        ]);

        $this->assertSame([
            1 => ['primero'],
            2 => ['segundo'],
            3 => ['tercero'],
        ], $registro->pendientes(0));

        $this->assertSame([
            2 => ['segundo'],
            3 => ['tercero'],
        ], $registro->pendientes(1));

        $this->assertSame([3 => ['tercero']], $registro->pendientes(2));
        $this->assertSame([], $registro->pendientes(3));
        $this->assertSame([], $registro->pendientes(10));
    }

    public function test_deduplica_entre_versiones_y_deja_el_comando_en_la_primera(): void
    {
        $registro = $this->registro([
            1 => ['a', 'b'],
            2 => ['b', 'c'],
            3 => ['a', 'c'],
        ]);

        $this->assertSame([
            1 => ['a', 'b'],
            2 => ['c'],
            // Sólo traía comandos ya corridos: cuenta como completada.
            3 => [],
        ], $registro->pendientes(0));
    }

    public function test_una_version_sin_comandos_propios_cuenta_como_completada(): void
    {
        $registro = $this->registro([
            1 => ['a'],
            2 => ['a'],
        ]);

        $this->assertSame([
            1 => ['a'],
            2 => [],
        ], $registro->pendientes(0));

        $this->assertSame([2 => []], $registro->pendientes(1));
    }

    public function test_desglosa_una_bandera_sin_valor(): void
    {
        $this->assertSame([
            'comando' => 'pediatria:sembrar-referencia',
            'parametros' => ['--reemplazar-vacunas' => true],
        ], (new VersionesDeDatos())->desglosar('pediatria:sembrar-referencia --reemplazar-vacunas'));
    }

    public function test_desglosa_una_opcion_con_valor_y_un_argumento_posicional(): void
    {
        $this->assertSame([
            'comando' => 'algo:comando',
            'parametros' => [
                '--clave' => 'valor',
                0 => 'posicional',
            ],
        ], (new VersionesDeDatos())->desglosar('algo:comando --clave=valor posicional'));
    }

    public function test_desglosa_varios_posicionales_y_mezcla_opciones(): void
    {
        $this->assertSame([
            'comando' => 'algo:comando',
            'parametros' => [
                0 => 'uno',
                1 => 'dos',
                '--bandera' => true,
                '--clave' => 'tres',
            ],
        ], (new VersionesDeDatos())->desglosar('algo:comando uno dos --bandera --clave=tres'));
    }

    public function test_desglosa_un_argumento_entre_comillas(): void
    {
        $this->assertSame([
            'comando' => 'algo:comando',
            'parametros' => [0 => 'dos palabras'],
        ], (new VersionesDeDatos())->desglosar('algo:comando "dos palabras"'));
    }
}
