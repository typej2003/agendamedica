<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\EcoObstetrico;
use App\Clinica\Consulta\EstudioEcografico;
use App\Clinica\Consulta\Vistas\EcoObstetricoVista;
use Carbon\Carbon;
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
    protected function setUp(): void
    {
        parent::setUp();
        // "Hoy" fijo: la EG y la FPP salen de la FUR y de la fecha del día.
        Carbon::setTestNow(Carbon::parse('2026-10-06 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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

    // ------------------------------------------------------------------------- La EG y la FPP en vivo

    /**
     * Los dos obstétricos muestran la **EG** y la **FPP** de su FUR, como los `compute` del DataWindow
     * (`d_eco_obstetrico_nueva.srd:123-124`, `d_eco_obstetrico_trimestral_nueva.srd:181-183`): no son
     * columnas ni se guardan, y la pantalla las rehace al teclear la FUR (`consulta.js`).
     */
    public function test_la_eg_y_la_fpp_del_eco_obstetrico_salen_de_su_fur(): void
    {
        $vista = new EcoObstetricoVista(new EstudioEcografico(['fur' => '2026-02-10']));

        // 10/02/2026 → 06/10/2026: 238 días exactos, 34 semanas y 0 días; FPP, FUR + 280 días.
        $this->assertSame('34 semanas y 0 días', $vista->edadGestacional());
        $this->assertSame('17/11/2026', $vista->fpp());
        // El día de la cuenta es el del consultorio, el mismo que usa el servidor para la hoja.
        $this->assertSame('2026-10-06', $vista->hoyIso());

        // Sin FUR no se inventa ninguna de las dos.
        $sinFur = new EcoObstetricoVista(new EstudioEcografico([]));
        $this->assertNull($sinFur->edadGestacional());
        $this->assertNull($sinFur->fpp());
    }
}
