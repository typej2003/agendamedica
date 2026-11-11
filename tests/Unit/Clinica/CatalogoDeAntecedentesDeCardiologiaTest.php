<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\TiposDeAntecedente;
use PHPUnit\Framework\TestCase;

/**
 * **El catálogo inicial de antecedentes de cardiología** (N2, 2026-11-10).
 *
 * Decisión del usuario: los «antecedentes fijos» del escritorio (`antecente_fijo`) **no son una tabla ni una pantalla
 * propia** —son los antecedentes del paciente, y cada especialidad tiene su catálogo—, así que entran como filas del
 * catálogo inicial de cardiología en la categoría `01` (PERSONAL). Este test cuida el archivo que las trae: que sea
 * legible, que respete las reglas del catálogo (nombre en mayúsculas, ≤ 40 y un tipo de los siete del legado) y que
 * estén los conceptos del escritorio.
 *
 * Sin base de datos: lee el mismo archivo que siembra `SembrarCatalogosIniciales`.
 */
class CatalogoDeAntecedentesDeCardiologiaTest extends TestCase
{
    /** @return array<int,array{descripcion:string, codtipo:string}> */
    private function filas(): array
    {
        $ruta = dirname(__DIR__, 3) . '/database/catalogos-iniciales/cardiologia/antecedentes.json';
        $this->assertFileExists($ruta);

        $filas = json_decode((string) file_get_contents($ruta), true);
        $this->assertIsArray($filas, 'El archivo de antecedentes de cardiología tiene que ser un JSON de filas.');

        return $filas;
    }

    public function test_cada_fila_respeta_las_reglas_del_catalogo(): void
    {
        $filas = $this->filas();

        $this->assertNotEmpty($filas);

        foreach ($filas as $fila) {
            $this->assertArrayHasKey('descripcion', $fila);
            $this->assertArrayHasKey('codtipo', $fila);

            $nombre = trim($fila['descripcion']);

            $this->assertSame(mb_strtoupper($nombre), $nombre, 'El nombre va en mayúsculas, como el catálogo.');
            $this->assertNotSame('', $nombre);
            $this->assertLessThanOrEqual(40, mb_strlen($nombre), 'El antecedente no puede pasar de 40 caracteres.');

            $this->assertContains($fila['codtipo'], array_keys(TiposDeAntecedente::GINECOLOGIA), 'El tipo tiene que ser uno de los siete del legado.');
        }
    }

    public function test_lleva_los_antecedentes_fijos_del_escritorio_en_la_categoria_personal(): void
    {
        $porNombre = [];
        foreach ($this->filas() as $fila) {
            $porNombre[trim($fila['descripcion'])] = $fila['codtipo'];
        }

        // Los quince booleanos y los siete textos de `antecente_fijo` (esquema ASA de cardiología), que el escritorio
        // carga en la evaluación pre-operatoria.
        $fijos = [
            'DIABETES', 'TVP / TEP', 'CARDIOPATIA', 'TABAQUISMO', 'NEOPLASIA', 'REPOSO PROLONGADO',
            'VARICES / TVS', 'TRAUMATISMO', 'HIPERTENSION ARTERIAL', 'CIRUGIA DE BYPASS', 'ENDARTERECTOMIA',
            'ACV / AMAUROSIS', 'ACTP (ANGIOPLASTIA)', 'AMPUTACION DE MIEMBROS INFERIORES', 'AMAUROSIS',
            'BRONCOPULMONARES', 'CARDIOVASCULARES', 'HEPATICOS', 'RENALES', 'ALCOHOLISMO',
            'ALERGIAS MEDICAMENTOSAS', 'OTROS',
        ];

        foreach ($fijos as $nombre) {
            $this->assertArrayHasKey($nombre, $porNombre, 'Falta el antecedente fijo «' . $nombre . '».');
            $this->assertSame('01', $porNombre[$nombre], 'Los antecedentes fijos son personales (tipo 01).');
        }

        $this->assertCount(count($fijos), $porNombre, 'No hay filas de más: el archivo es exactamente el de los fijos.');
    }
}
