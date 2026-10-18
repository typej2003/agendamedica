<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\EcoObstetrico;
use PHPUnit\Framework\TestCase;

/**
 * **El formato de los campos del eco obstétrico del primer trimestre**, copiado de las `editmask` del
 * DataWindow vivo (`ventana_consulta/d_eco_obstetrico_nueva.srd`): las medidas del útero y los ovarios son
 * decimales (`###.00`) y LCC, DBP, TN y el saco vitelino son enteros (`###`/`##`, con su spin).
 *
 * El mapa es lo que pinta el control (`_estudio-campo`) y lo que arma las reglas del servidor
 * (`ConsultaEstudioController::reglasDe()`), así que un campo que vuelva a `texto` deja entrar «25 mm» a una
 * columna `INT` y se rechaza acá, sin esperar al MySQL de producción.
 *
 * Sin base de datos: es el mapa del dato.
 */
class EcoObstetricoMapaTest extends TestCase
{
    /** @return array<string,array<string,mixed>> */
    private function campos(): array
    {
        $campos = [];

        foreach (EcoObstetrico::grupos() as $grupo) {
            foreach ($grupo['campos'] as $campo) {
                $campos[$campo['columna']] = $campo;
            }
        }

        return $campos;
    }

    public function test_las_medidas_son_decimales_y_los_enteros_no_admiten_decimales(): void
    {
        $campos = $this->campos();

        // Las que el escritorio pinta con máscara `###.00`: la columna es `decimal(5,2)`.
        $decimales = [
            'cuerpo', 'transverso', 'antero',
            'ovarioderlong', 'ovarioderap', 'ovariodertras',
            'ovarioizqlong', 'ovarioizqap', 'ovarioizqtras',
            'der', 'izqu',
        ];

        foreach ($decimales as $columna) {
            $this->assertSame('numero', $campos[$columna]['tipo'] ?? null, $columna . ' tiene que ser numérico.');
            $this->assertSame('0.01', $campos[$columna]['paso'] ?? null, $columna . ' tiene que admitir centésimas.');
            $this->assertNotTrue($campos[$columna]['entero'] ?? false, $columna . ' es decimal, no entero.');
        }

        // Las que el escritorio pinta con máscara `###` o `##` y un spin. `sa_vit` entra acá porque su
        // máscara es `##` aunque la columna sea `decimal(5,2)`: manda la máscara del DataWindow.
        foreach (['lcc', 'dbp', 'tn', 'sa_vit'] as $columna) {
            $this->assertSame('numero', $campos[$columna]['tipo'] ?? null, $columna . ' tiene que ser numérico.');
            $this->assertTrue($campos[$columna]['entero'] ?? false, $columna . ' tiene que ser entero.');
        }
    }

    /** El escritorio no tenía estos campos como texto libre, y la web tampoco puede tenerlos. */
    public function test_ningun_campo_numerico_quedo_como_texto(): void
    {
        $campos = $this->campos();

        foreach ([
            'cuerpo', 'transverso', 'antero', 'ovarioderlong', 'ovarioderap', 'ovariodertras',
            'ovarioizqlong', 'ovarioizqap', 'ovarioizqtras', 'der', 'izqu', 'lcc', 'dbp', 'tn', 'sa_vit',
        ] as $columna) {
            $this->assertArrayHasKey($columna, $campos, 'Falta el campo ' . $columna . ' en el mapa.');
            $this->assertNotContains(
                $campos[$columna]['tipo'] ?? null,
                ['texto', 'area'],
                'El campo ' . $columna . ' volvió a texto: en el escritorio era numérico.',
            );
        }
    }
}
