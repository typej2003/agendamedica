<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Catalogos\PlantillasDeLaEspecialidad;
use App\Clinica\Catalogos\TiposDePlantillaDeTexto;
use PHPUnit\Framework\TestCase;

/**
 * **Las plantillas de texto que ve cada especialidad** en el ABM (2026-10-18): el catálogo y el diálogo ofrecen los tipos de la
 * especialidad del contexto, y en pediatría no aparecen los ginecológicos.
 *
 * Lo que se fija acá: ginecología no cambia, pediatría conserva lo suyo (con el resumen físico y el eco abdominal) y un tipo del
 * legado que no está en el catálogo **nunca** se oculta —esconderlo borraría de la pantalla datos del médico que la web no sabe
 * leer—.
 */
class PlantillasDeLaEspecialidadTest extends TestCase
{
    private const GINECO = 'ginecologia-y-obstetricia';

    public function test_ginecologia_ofrece_todos_los_tipos_conocidos(): void
    {
        $this->assertSame(TiposDePlantillaDeTexto::nombres(), PlantillasDeLaEspecialidad::nombres(self::GINECO));
        $this->assertSame([], PlantillasDeLaEspecialidad::ocultos(self::GINECO));
    }

    public function test_pediatria_no_ofrece_los_tipos_ginecologicos(): void
    {
        $codigos = PlantillasDeLaEspecialidad::codigos('pediatria');

        foreach (['CO', 'CI', 'HI', 'RE', 'EG', 'UG', 'O1', 'O2', 'O3', 'ED', 'UM', 'EM', 'ER'] as $tipo) {
            $this->assertNotContains($tipo, $codigos, 'Pediatría no ofrece ' . $tipo . '.');
        }

        // Lo suyo sí: las secciones del núcleo, el resumen físico y el único estudio que tiene (el eco abdominal).
        foreach (['EV', 'IM', 'DI', 'EA', 'TR', 'RF', 'UA'] as $tipo) {
            $this->assertContains($tipo, $codigos, 'Pediatría conserva ' . $tipo . '.');
            $this->assertFalse(PlantillasDeLaEspecialidad::estaOculto($tipo, 'pediatria'));
        }
    }

    /** El nombre de cada tipo sigue saliendo del catálogo: la pantalla no tiene una segunda lista de rótulos. */
    public function test_los_nombres_salen_del_catalogo(): void
    {
        $nombres = PlantillasDeLaEspecialidad::nombres('pediatria');

        $this->assertSame(TiposDePlantillaDeTexto::nombre('RF'), $nombres['RF']);
        $this->assertSame('Resumen físico', $nombres['RF']);
    }

    /** Un código que el catálogo no conoce (basura del escritorio) no se oculta nunca: se muestra y se puede editar. */
    public function test_un_tipo_del_legado_no_se_oculta(): void
    {
        $this->assertFalse(PlantillasDeLaEspecialidad::estaOculto('ZZ', 'pediatria'));
        $this->assertFalse(PlantillasDeLaEspecialidad::estaOculto('', 'pediatria'));
        $this->assertFalse(PlantillasDeLaEspecialidad::estaOculto(null, 'pediatria'));

        // Y el vacío tampoco aparece entre los que se ofrecen (no es un tipo).
        $this->assertNotContains('', PlantillasDeLaEspecialidad::codigos('pediatria'));
    }
}
