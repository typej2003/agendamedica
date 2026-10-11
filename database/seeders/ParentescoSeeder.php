<?php

namespace Database\Seeders;

use App\Models\Parentesco;
use Illuminate\Database\Seeder;

/**
 * **El catálogo de parentescos** del representante del paciente (WEB-3.4). Ver la migración
 * `2026_10_10_190000_add_columnas_representante_y_catalogo_parentesco` y
 * `App\Clinica\Pacientes\CatalogoDeParentescos`.
 *
 * **De dónde salen los códigos: de ninguna parte.** `representante.codeparentesco` es `char(1)` y en las
 * fuentes del legado —`Docs/MICONSULTAGI-legado/fuentes`, `fuentes-pediatria`, los siete
 * `esquemas-asa/*.sql` y `archivos sql/export_parte_14.sql`— la columna **sólo existe declarada**: no hay
 * ningún DataWindow que la liste, ninguna ventana que la escriba y ningún catálogo con el que cruzarla.
 * O sea que **los códigos que pueda haber usado el legado son desconocidos** y estos son una **decisión
 * de producto de AppDDR**, elegidos con la inicial del parentesco en español para que se puedan leer.
 *
 * La consecuencia está asumida y escrita en la wiki (`07-modelo-de-datos.md`): lo que ya esté guardado en
 * `codeparentesco` en una instalación del escritorio **no se interpreta** con este catálogo; la ficha
 * muestra el código tal cual (igual que hace con un `codesegemp` que no está en `seg_emp`), porque
 * inventarle un nombre sería afirmar una equivalencia que no está verificada.
 *
 * Es **catálogo, no datos de prueba**: corre también en producción (como `SpecialtySeeder`) y es
 * **idempotente** —los seeders se corren en cada arranque de la suite de tests, así que un `insert` a
 * secas duplicaría las filas—: `updateOrCreate` por `codigo` deja el catálogo al día si le cambian el
 * nombre o el orden.
 */
class ParentescoSeeder extends Seeder
{
    /**
     * Código (`char(1)`) => [nombre visible, orden].
     *
     * El código es la inicial castellana del parentesco y **no** pretende coincidir con ningún código del
     * legado; `O` de "Otro" y `L` de "Representante legal" son los dos que cubren lo que no es un
     * familiar directo. El orden es el de la lista del `<select>`: primero lo más frecuente en pediatría.
     */
    public const PARENTESCOS = [
        'M' => ['Madre', 10],
        'P' => ['Padre', 20],
        'L' => ['Representante legal', 30],
        'A' => ['Abuelo/a', 40],
        'T' => ['Tío/a', 50],
        'H' => ['Hermano/a', 60],
        'O' => ['Otro', 70],
    ];

    public function run(): void
    {
        foreach (self::PARENTESCOS as $codigo => [$nombre, $orden]) {
            Parentesco::updateOrCreate(['codigo' => $codigo], ['nombre' => $nombre, 'orden' => $orden]);
        }
    }
}
