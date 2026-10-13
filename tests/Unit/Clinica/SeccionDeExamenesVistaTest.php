<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\Vistas\SeccionDeExamenesVista;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * El ViewModel de la **sección de exámenes de una consulta** (WEB-4.7, ítem 7 del panel) se prueba sin base
 * de datos ni HTTP: los exámenes ya leídos y la observación del conjunto → textos y estados
 * (Docs/Web/guia-arquitectura-web.md).
 *
 * Lo que se fija acá:
 *
 *  - El **nombre resuelto por catálogo** y, cuando el `codeexamen` no está en el catálogo del médico, el
 *    **código a la vista** en vez de esconder la fila (el caso existe de verdad en el dataset).
 *  - El **resultado ausente** como `—`: la columna `resultado` de `examen_paciente` está vacía en los
 *    318.469 registros de la base real, así que el estado normal es "no hay resultado que mostrar".
 *  - Los **dos estados vacíos distintos**: el catálogo del médico está vacío (el escritorio todavía no lo
 *    subió) o esta consulta no pidió exámenes. Son cosas distintas y el texto lo dice.
 *  - La **observación del conjunto** (`examen_obs.observacion`), que no es el resultado de una fila.
 */
class SeccionDeExamenesVistaTest extends TestCase
{
    /**
     * @param  array<int,array{0:string,1:?string,2:?string,3:?string}>  $filas  código, nombre, tipo, resultado
     */
    private function examenes(array $filas): Collection
    {
        return collect($filas)->map(fn (array $fila) => (object) [
            'id' => 1,
            'codigo' => $fila[0],
            'nombre' => $fila[1] ?? $fila[0],
            'tipo' => $fila[2] ?? null,
            'resultado' => $fila[3] ?? null,
            'vinculante' => null,
        ]);
    }

    private function vista(array $extra = []): SeccionDeExamenesVista
    {
        return new SeccionDeExamenesVista(
            $extra['examenes'] ?? $this->examenes([]),
            $extra['observacion'] ?? null,
            $extra['hayCatalogo'] ?? true,
        );
    }

    public function test_una_consulta_sin_examenes_lo_dice(): void
    {
        $vm = $this->vista();

        $this->assertFalse($vm->hay());
        $this->assertSame(0, $vm->total());
        $this->assertSame('Sin exámenes', $vm->estado()['texto']);
        $this->assertSame('neutro', $vm->estado()['tono']);
        $this->assertSame([], $vm->examenes());
    }

    public function test_los_examenes_llegan_con_su_codigo_su_nombre_su_tipo_y_su_resultado(): void
    {
        $vm = $this->vista(['examenes' => $this->examenes([
            ['017', 'HEMOGRAMA COMPLETO', 'LABORATORIO', 'Normal'],
            ['022', 'GLICEMIA', 'LABORATORIO', null],
        ])]);

        $this->assertTrue($vm->hay());
        $this->assertSame(2, $vm->total());
        $this->assertSame('2 exámenes', $vm->estado()['texto']);
        $this->assertSame('info', $vm->estado()['tono']);

        $filas = $vm->examenes();
        $this->assertSame('017', $filas[0]['codigo']);
        $this->assertSame('HEMOGRAMA COMPLETO', $filas[0]['nombre']);
        $this->assertSame('LABORATORIO', $filas[0]['tipo']);
        $this->assertSame('Normal', $filas[0]['resultado']);
        $this->assertNull($filas[0]['sinNombre']);

        // El resultado vacío de la fuente (0 de 318.469 filas lo tienen) llega como `null` y la vista
        // pinta `—`: no se inventa un "pendiente".
        $this->assertNull($filas[1]['resultado']);
    }

    public function test_un_examen_que_no_esta_en_el_catalogo_se_muestra_con_su_codigo(): void
    {
        // La lectura (`ExamenesDeLaConsulta`) cae al código cuando el `LEFT JOIN` no encuentra el nombre.
        // El ViewModel lo marca para que la pantalla lo diga, en vez de mostrar un código como si fuera un
        // nombre.
        $vm = $this->vista(['examenes' => $this->examenes([['999', null, null, null]])]);

        $fila = $vm->examenes()[0];
        $this->assertSame('999', $fila['nombre']);
        $this->assertNotNull($fila['sinNombre']);
        $this->assertStringContainsString('catálogo', $fila['sinNombre']);
    }

    public function test_el_estado_vacio_distingue_el_catalogo_vacio_de_la_consulta_sin_examenes(): void
    {
        $conCatalogo = $this->vista(['hayCatalogo' => true])->sinExamenes();
        $this->assertSame('Esta consulta no tiene exámenes pedidos', $conCatalogo['titulo']);
        $this->assertStringContainsString('escritorio', $conCatalogo['texto']);

        $sinCatalogo = $this->vista(['hayCatalogo' => false])->sinExamenes();
        $this->assertSame('Tu catálogo de exámenes está vacío', $sinCatalogo['titulo']);
        $this->assertStringContainsString('examenes', $sinCatalogo['texto']);
        $this->assertStringContainsString('WEB-8.1', $sinCatalogo['texto']);
    }

    public function test_la_observacion_del_conjunto_es_de_la_consulta_y_no_de_una_fila(): void
    {
        // `examen_obs.observacion`: el texto que el escritorio deja para el grupo de exámenes
        // («HIV, VDRL»). Va arriba de la tabla, con su propio rótulo.
        $vm = $this->vista(['observacion' => 'HIV, VDRL']);
        $this->assertTrue($vm->hayObservacion());
        $this->assertSame('HIV, VDRL', $vm->observacion());

        $vacia = $this->vista(['observacion' => null]);
        $this->assertFalse($vacia->hayObservacion());
        $this->assertNull($vacia->observacion());

        // Una observación en blanco es el mismo estado que no tenerla.
        $this->assertFalse($this->vista(['observacion' => '   '])->hayObservacion());
    }

    public function test_un_solo_examen_se_dice_en_singular(): void
    {
        $vm = $this->vista(['examenes' => $this->examenes([['017', 'HEMOGRAMA', null, null]])]);

        $this->assertSame(1, $vm->total());
        $this->assertSame('1 examen', $vm->estado()['texto']);
    }
}
