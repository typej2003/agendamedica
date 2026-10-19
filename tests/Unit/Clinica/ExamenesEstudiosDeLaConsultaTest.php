<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\TextosDelExamen;
use App\Clinica\Consulta\Vistas\PestanasDeExamenesEstudios;
use App\Clinica\Consulta\Vistas\PestanasDelExamen;
use PHPUnit\Framework\TestCase;

/**
 * **El mapa de *Exámenes/Estudios*** (ítem 6 de la consulta pediátrica, 2026-10-19): las tres solapas que el
 * escritorio guarda en `consultas` (`tab_3` de `tabpage_signos`, `w_consulta_principal.srw:1650-1670` y la
 * escritura en `:2852-2855`).
 *
 * Lo que se fija acá, sin base de datos:
 *
 *  - **Cada solapa y su columna**: *Exámenes* → `nariz`, *Imagenología* → `laringe`, *Estudios exploratorios* →
 *    `oido`, con sus tipos de plantilla `EX`, `IG` y `EE`.
 *  - **No son solapas del examen**: son un ítem propio del panel, así que `claves()` no las da (como el
 *    tratamiento) y `PestanasDelExamen` no las ofrece en la evaluación física. Es justo lo que Alexander vio
 *    distinto.
 *  - **La colisión de columnas es la del legado**: `oido` es *Estudios exploratorios* en pediatría y *Resultado de
 *    exámenes* en ginecología; `laringe` es *Imagenología* y también el plan de tratamiento. Cada especialidad
 *    ofrece las suyas y no se cruzan.
 */
class ExamenesEstudiosDeLaConsultaTest extends TestCase
{
    public function test_las_tres_solapas_van_en_el_orden_del_escritorio(): void
    {
        $this->assertSame(
            ['examenes', 'imagenologia', 'exploratorios'],
            PestanasDeExamenesEstudios::claves(),
        );

        $this->assertSame(
            ['Exámenes', 'Imagenología', 'Estudios exploratorios'],
            array_map(fn (string $clave) => TextosDelExamen::titulo($clave), PestanasDeExamenesEstudios::claves()),
        );
    }

    public function test_cada_solapa_escribe_en_su_columna_con_su_tipo_de_plantilla(): void
    {
        $this->assertSame(
            ['nariz', 'laringe', 'oido'],
            array_map(fn (string $clave) => TextosDelExamen::columna($clave), PestanasDeExamenesEstudios::claves()),
        );

        $this->assertSame(
            ['EX', 'IG', 'EE'],
            array_map(fn (string $clave) => TextosDelExamen::tipoDePlantillas($clave), PestanasDeExamenesEstudios::claves()),
        );

        // Ninguna tiene hoja propia ni copia en otra tabla: son texto de la consulta.
        foreach (PestanasDeExamenesEstudios::claves() as $clave) {
            $this->assertNull(TextosDelExamen::documento($clave));
            $this->assertNull(TextosDelExamen::columnaEspejo($clave));
        }
    }

    /** Son un **ítem propio** del panel: no aparecen como solapas de la evaluación física (ítem 4). */
    public function test_no_son_solapas_del_examen(): void
    {
        foreach (PestanasDeExamenesEstudios::claves() as $clave) {
            $this->assertNotContains($clave, TextosDelExamen::claves(), $clave . ' no debe ser solapa del examen.');
            $this->assertFalse(PestanasDelExamen::esDeTexto($clave));
            $this->assertFalse(PestanasDelExamen::existe($clave));
        }

        // Pero la ruta de guardado sí las acepta (la misma de las solapas de texto y el tratamiento).
        foreach (PestanasDeExamenesEstudios::claves() as $clave) {
            $this->assertContains($clave, TextosDelExamen::claves(true));
        }
    }

    /** La colisión es la del legado: la misma columna, otro significado en cada especialidad. */
    public function test_las_columnas_son_las_que_el_escritorio_comparte_con_ginecologia(): void
    {
        $this->assertSame('oido', TextosDelExamen::columna(TextosDelExamen::RESULTADOS));
        $this->assertSame('oido', TextosDelExamen::columna(TextosDelExamen::EXPLORATORIOS));

        $this->assertSame('laringe', TextosDelExamen::columna(TextosDelExamen::TRATAMIENTO));
        $this->assertSame('laringe', TextosDelExamen::columna(TextosDelExamen::IMAGENOLOGIA));
    }

    public function test_una_solapa_desconocida_abre_la_primera(): void
    {
        $this->assertSame(TextosDelExamen::EXAMENES, PestanasDeExamenesEstudios::normalizar(null));
        $this->assertSame(TextosDelExamen::EXAMENES, PestanasDeExamenesEstudios::normalizar(' '));
        $this->assertSame(TextosDelExamen::EXAMENES, PestanasDeExamenesEstudios::normalizar('colposcopia'));
        $this->assertSame(TextosDelExamen::EXPLORATORIOS, PestanasDeExamenesEstudios::normalizar('exploratorios'));
    }

    /** El botón dice el artículo de cada solapa (`los exámenes`, `la imagenología`, `los estudios`). */
    public function test_el_rotulo_de_guardar_cambia_con_la_solapa(): void
    {
        $this->assertSame('Guardar los exámenes', TextosDelExamen::rotuloGuardar(TextosDelExamen::EXAMENES));
        $this->assertSame('Guardar la imagenología', TextosDelExamen::rotuloGuardar(TextosDelExamen::IMAGENOLOGIA));
        $this->assertSame('Guardar los estudios', TextosDelExamen::rotuloGuardar(TextosDelExamen::EXPLORATORIOS));

        // Los de siempre no cambiaron.
        $this->assertSame('Guardar la colposcopía', TextosDelExamen::rotuloGuardar(TextosDelExamen::COLPOSCOPIA));
        $this->assertSame('Guardar el resultado', TextosDelExamen::rotuloGuardar(TextosDelExamen::RESULTADOS));
        $this->assertSame('Guardar el tratamiento', TextosDelExamen::rotuloGuardar(TextosDelExamen::TRATAMIENTO));
    }
}
