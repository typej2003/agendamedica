<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\Vistas\OpcionesDelDesplegable;
use App\Models\PrenaExamenB;
use PHPUnit\Framework\TestCase;

/**
 * **Los cuatro reactivos del laboratorio del embarazo** (`prena_exames_b`, WEB-6.6): el código de una letra
 * del escritorio y su rótulo.
 *
 * Es la regla de la trampa 140: las cuatro columnas son `VARCHAR(1)` en MySQL (`hiv`, `vdrl`, `toxo_igm` y
 * `toxo_igg` en `Docs/MICONSULTAGI-legado/archivos sql/export_parte_11.sql`) y la web guardaba «Negativo»,
 * que en producción —MySQL en modo estricto— moría con `1406 Data too long for column 'hiv'`. En SQLite el
 * `INSERT` pasaba igual, así que el bug no se veía en los tests; este fija el **contrato** de los valores.
 *
 * Sin base de datos ni HTTP: es el mapa del dato.
 */
class ReactivosDelLaboratorioTest extends TestCase
{
    /**
     * Lo que se guarda en una columna de un carácter tiene que medir **un carácter**: es la aserción que
     * habría atrapado el bug sin MySQL.
     */
    public function test_cada_opcion_de_un_reactivo_guarda_un_solo_caracter(): void
    {
        $reactivos = 0;

        foreach (PrenaExamenB::campos() as $campo) {
            if (! PrenaExamenB::esReactivo($campo['columna'])) {
                continue;
            }

            $reactivos++;

            foreach ($campo['opciones'] as $opcion) {
                $this->assertSame(
                    1,
                    mb_strlen($opcion['valor']),
                    'La opción «' . $opcion['texto'] . '» de ' . $campo['columna'] . ' guarda más de un carácter.',
                );
            }
        }

        $this->assertSame(4, $reactivos, 'Los cuatro reactivos del laboratorio');
    }

    /**
     * Los **números** del laboratorio declaran su ancho: las columnas son `char(5)` y la validación de la
     * pestaña usa ese `max` (si no, un valor largo desborda la columna igual que el rótulo del reactivo).
     */
    public function test_los_numeros_del_laboratorio_declaran_el_ancho_de_su_columna(): void
    {
        $numeros = 0;

        foreach (PrenaExamenB::campos() as $campo) {
            if (($campo['tipo'] ?? 'texto') !== 'numero') {
                continue;
            }

            $numeros++;
            $this->assertSame(5, $campo['max'], 'El tope de ' . $campo['columna']);
        }

        $this->assertSame(12, $numeros, 'Los doce números de la pestaña');
    }

    /**
     * **El formulario** resuelve una fila vieja —la que la web guardó con el rótulo— a su código, así el
     * `<select>` la muestra elegida en vez de agregar un «Negativo (del escritorio)».
     */
    public function test_el_formulario_resuelve_un_rotulo_viejo_a_su_codigo(): void
    {
        $campos = [];
        foreach (PrenaExamenB::campos() as $campo) {
            $campos[$campo['columna']] = $campo;
        }

        $this->assertSame('N', OpcionesDelDesplegable::valorConocido($campos['hiv'], 'Negativo'));
        $this->assertSame('R', OpcionesDelDesplegable::valorConocido($campos['vdrl'], 'Reactivo'));
        $this->assertSame('S', OpcionesDelDesplegable::valorConocido($campos['toxo_igg'], 'Positivo'));

        // Lo que ya es una clave (o un código desconocido del escritorio) queda igual.
        $this->assertSame('N', OpcionesDelDesplegable::valorConocido($campos['hiv'], 'N'));
        $this->assertSame('X', OpcionesDelDesplegable::valorConocido($campos['hiv'], 'X'));
        $this->assertSame('', OpcionesDelDesplegable::valorConocido($campos['hiv'], ''));
    }

    /** El código que manda el escritorio y el rótulo que mostraba la web terminan en el mismo valor. */
    public function test_el_rotulo_se_traduce_al_codigo_del_escritorio(): void
    {
        $this->assertSame('N', PrenaExamenB::codigoDe('hiv', 'Negativo'));
        $this->assertSame('N', PrenaExamenB::codigoDe('vdrl', 'No reactivo'));
        $this->assertSame('P', PrenaExamenB::codigoDe('hiv', 'Positivo'));
        $this->assertSame('R', PrenaExamenB::codigoDe('vdrl', 'Reactivo'));
        $this->assertSame('S', PrenaExamenB::codigoDe('toxo_igm', 'Positivo'));

        // El código pasa tal cual (lo manda el `<select>` y es lo que tiene una fila del escritorio).
        $this->assertSame('N', PrenaExamenB::codigoDe('hiv', 'N'));
        $this->assertSame('S', PrenaExamenB::codigoDe('toxo_igg', 'S'));

        // Vacío es vacío, y un código de una letra que no está en la lista se conserva (viene del escritorio).
        $this->assertNull(PrenaExamenB::codigoDe('hiv', ''));
        $this->assertNull(PrenaExamenB::codigoDe('hiv', null));
        $this->assertSame('X', PrenaExamenB::codigoDe('hiv', 'X'));

        // Cualquier otra cosa no entra en la columna: se guarda en blanco en vez de reventar el `INSERT`.
        $this->assertNull(PrenaExamenB::codigoDe('hiv', 'no concluyente'));
    }

    /** La pantalla y la hoja imprimen el rótulo, no la letra; lo que ya era texto (o un código raro) sigue igual. */
    public function test_el_texto_que_se_muestra_es_el_rotulo(): void
    {
        $this->assertSame('Negativo', PrenaExamenB::textoDe('hiv', 'N'));
        $this->assertSame('Reactivo', PrenaExamenB::textoDe('vdrl', 'R'));
        $this->assertSame('No reactivo', PrenaExamenB::textoDe('vdrl', 'No reactivo'));
        $this->assertSame('Negativo', PrenaExamenB::textoDe('toxo_igg', 'N'));

        // Un código desconocido del escritorio no se traduce ni se pierde.
        $this->assertSame('X', PrenaExamenB::textoDe('hiv', 'X'));

        // Y en las columnas que no son reactivos no cambia nada.
        $this->assertSame('11.5', PrenaExamenB::textoDe('hemoglobina', '11.5'));
        $this->assertNull(PrenaExamenB::textoDe('hemoglobina', null));
    }
}
