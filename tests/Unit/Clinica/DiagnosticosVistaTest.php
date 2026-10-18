<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\Vistas\SeccionDeDiagnosticosVista;
use App\Models\Diagnostico;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * El ViewModel de la **sección de diagnósticos de una consulta** (WEB-4.5, ítem 8 del panel) se prueba sin
 * base de datos ni HTTP: diagnósticos de la consulta + catálogo → textos y banderas
 * (Docs/Web/guia-arquitectura-web.md).
 *
 * Lo que se fija acá es lo que la pantalla decide: el estado vacío y el estado con diagnósticos, la fila
 * con su detalle editable y el aviso del código que ya no está en el catálogo, qué fila del buscador se
 * ofrece para pasar y cuál no (la que ya está en la consulta), las URLs de las escrituras inmediatas (O-27)
 * y el `old()` de un POST que falló la validación.
 */
class DiagnosticosVistaTest extends TestCase
{
    /** @param array<int,array{0:string,1:string}> $diagnosticos código y descripción */
    private function catalogo(array $diagnosticos): Collection
    {
        return collect($diagnosticos)->map(fn (array $diagnostico) => new Diagnostico([
            'reg_medico' => 'gineco-00001',
            'codediagnostico' => $diagnostico[0],
            'descripcion' => $diagnostico[1],
        ]));
    }

    /** @param array<int,array{id:int, codigo:string, nombre:string, detalle:?string, enCatalogo:bool}> $filas */
    private function deLaConsulta(array $filas): Collection
    {
        return collect($filas)->map(fn (array $fila) => (object) $fila);
    }

    private function vista(array $extra = []): SeccionDeDiagnosticosVista
    {
        return new SeccionDeDiagnosticosVista(
            $extra['diagnosticos'] ?? $this->deLaConsulta([]),
            $extra['catalogo'] ?? $this->catalogo([['0518', 'LIE BAJO GRADO'], ['0474', 'AMENAZA DE ABORTO']]),
            $extra['buscar'] ?? '',
            $extra['urlGuardar'] ?? '/clinica/consulta/42/diagnosticos',
            $extra['urlBase'] ?? '/clinica/consulta/42',
            $extra['urlCrear'] ?? '/clinica/consulta/42/diagnosticos/crear',
            $extra['old'] ?? [],
            null,
            $extra['urlAgregar'] ?? '/clinica/consulta/42/diagnosticos/agregar',
            $extra['urlQuitar'] ?? '/clinica/consulta/42/diagnosticos/quitar',
        );
    }

    public function test_una_consulta_sin_diagnosticos_lo_dice_y_no_lista_nada(): void
    {
        $vm = $this->vista();

        $this->assertTrue($vm->estaVacio());
        $this->assertSame(0, $vm->total());
        $this->assertSame([], $vm->filas());
        $this->assertSame('Sin diagnóstico', $vm->estado()['texto']);
        $this->assertSame('neutro', $vm->estado()['tono']);
    }

    public function test_los_diagnosticos_de_la_consulta_llevan_su_codigo_su_nombre_y_el_aviso_del_huerfano(): void
    {
        $vm = $this->vista(['diagnosticos' => $this->deLaConsulta([
            ['id' => 7, 'codigo' => '0518', 'nombre' => 'LIE BAJO GRADO', 'detalle' => 'LIE BAJO GRADO', 'enCatalogo' => true],
            ['id' => 9, 'codigo' => '8888', 'nombre' => 'DIAGNOSTICO VIEJO', 'detalle' => 'DIAGNOSTICO VIEJO', 'enCatalogo' => false],
        ])]);

        $this->assertFalse($vm->estaVacio());
        $this->assertSame('2 diagnósticos', $vm->estado()['texto']);
        $this->assertSame('1 diagnóstico', $this->vista(['diagnosticos' => $this->deLaConsulta([
            ['id' => 7, 'codigo' => '0518', 'nombre' => 'LIE BAJO GRADO', 'detalle' => null, 'enCatalogo' => true],
        ])])->estado()['texto']);

        $filas = $vm->filas();
        $this->assertCount(2, $filas);

        $this->assertSame(7, $filas[0]['id']);
        $this->assertSame('0518', $filas[0]['codigo']);
        $this->assertSame('LIE BAJO GRADO', $filas[0]['nombre']);
        $this->assertSame('LIE BAJO GRADO', $filas[0]['detalle']);
        $this->assertNull($filas[0]['avisoCatalogo']);

        // Un código que ya no está en el catálogo se muestra igual (el legado no borra diagnósticos: los
        // datos son datos), pero avisando que no se puede volver a elegir. El detalle de la fila es lo que
        // queda como nombre cuando el catálogo no lo resuelve.
        $this->assertSame('DIAGNOSTICO VIEJO', $filas[1]['nombre']);
        $this->assertSame('ya no está en tu catálogo', $filas[1]['avisoCatalogo']);
    }

    public function test_el_detalle_de_una_fila_sin_dato_llega_vacio_y_se_puede_escribir(): void
    {
        // El `<input>` del detalle lleva el texto **completo** (es el dato que se edita) y una fila sin
        // detalle no muestra "null": llega la cadena vacía.
        $vm = $this->vista(['diagnosticos' => $this->deLaConsulta([
            ['id' => 3, 'codigo' => '0474', 'nombre' => 'AMENAZA DE ABORTO', 'detalle' => null, 'enCatalogo' => true],
        ])]);

        $this->assertSame('', $vm->filas()[0]['detalle']);
    }

    public function test_el_post_que_fallo_la_validacion_no_borra_lo_escrito(): void
    {
        $vm = $this->vista([
            'diagnosticos' => $this->deLaConsulta([
                ['id' => 7, 'codigo' => '0518', 'nombre' => 'LIE BAJO GRADO', 'detalle' => 'lo guardado', 'enCatalogo' => true],
            ]),
            'old' => ['filas' => [7 => ['detalle' => 'lo que el médico escribió']]],
        ]);

        $this->assertSame('lo que el médico escribió', $vm->filas()[0]['detalle']);
    }

    public function test_el_buscador_distingue_lo_que_ya_esta_de_lo_que_se_puede_pasar(): void
    {
        $vm = $this->vista([
            'diagnosticos' => $this->deLaConsulta([
                ['id' => 7, 'codigo' => '0518', 'nombre' => 'LIE BAJO GRADO', 'detalle' => null, 'enCatalogo' => true],
            ]),
            'catalogo' => $this->catalogo([['0518', 'LIE BAJO GRADO'], ['0474', 'AMENAZA DE ABORTO'], ['0305', 'ALERGIA']]),
        ]);

        $resultados = $vm->resultados();

        $this->assertTrue($resultados[0]['yaEsta']);
        $this->assertFalse($resultados[1]['yaEsta']);
        $this->assertFalse($resultados[2]['yaEsta']);
        // Desde O-27 no hay estado intermedio de «pendiente»: «Pasar» escribe.
        $this->assertArrayNotHasKey('pendiente', $resultados[1]);
    }

    public function test_las_urls_de_las_escrituras_inmediatas_son_sus_rutas(): void
    {
        // O-27: «Pasar» y «Quitar» son POST inmediatos; el código y el id viajan en el cuerpo del formulario.
        $vm = $this->vista(['buscar' => 'aborto']);

        $this->assertSame('/clinica/consulta/42/diagnosticos/agregar', $vm->urlAgregar());
        $this->assertSame('/clinica/consulta/42/diagnosticos/quitar', $vm->urlQuitar());

        // El guardado del detalle ya no arrastra pendientes: sólo la sección para volver a ella.
        $this->assertSame('/clinica/consulta/42/diagnosticos', $vm->urlGuardar());

        // El "Cancelar" vuelve a la sección.
        $this->assertSame('/clinica/consulta/42?seccion=diagnostico', $vm->urlVolver());
    }

    public function test_los_topes_son_los_de_las_columnas_del_legado(): void
    {
        $vm = $this->vista();

        // `diagnostico_paciente.detalle_diagnostco` es varchar(100) y `diagnosticos.descripcion` varchar(50).
        $this->assertSame(100, $vm->limiteDetalle());
        $this->assertSame(50, $vm->limiteDescripcion());
    }

    public function test_el_estado_vacio_del_buscador_cambia_si_hay_busqueda(): void
    {
        $this->assertStringContainsString('Tu catálogo está vacío', $this->vista()->sinResultados()['texto']);
        $this->assertStringContainsString('créalo abajo', $this->vista(['buscar' => 'zzz'])->sinResultados()['texto']);
    }

    public function test_el_aviso_de_cambios_sin_guardar_nombra_la_seccion(): void
    {
        $this->assertStringContainsString('diagnósticos', $this->vista()->avisoSinGuardar());
    }
}
