<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\Vistas\SeccionDeMotivosVista;
use App\Models\MotivoConsulta;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * El ViewModel de la **sección de motivos de una consulta** (WEB-4.2) se prueba sin base de datos ni HTTP:
 * catálogo + motivos de la consulta → textos y banderas (Docs/Web/guia-arquitectura-web.md).
 *
 * Lo que se fija acá es lo que la pantalla decide: qué fila se ofrece para agregar y cuál no (la que ya
 * está en la consulta), el estado vacío, que el "Quitar" apunte a la fila correcta y que el aviso de
 * conexión del alta exista —es la única parte de la pantalla que no funciona sin red—.
 */
class SeccionDeMotivosVistaTest extends TestCase
{
    /** @param array<int,array{0:string,1:string}> $motivos código y descripción */
    private function catalogo(array $motivos): Collection
    {
        return collect($motivos)->map(fn (array $motivo) => new MotivoConsulta([
            'reg_medico' => 'gineco-00001',
            'codemotivo' => $motivo[0],
            'descripcion' => $motivo[1],
        ]));
    }

    /** @param array<int,array{id:int, codigo:string, descripcion:string, enCatalogo:bool}> $motivos */
    private function cargados(array $motivos): Collection
    {
        return collect($motivos)->map(fn (array $motivo) => (object) $motivo);
    }

    private function vista(array $extra = []): SeccionDeMotivosVista
    {
        return new SeccionDeMotivosVista(
            $extra['catalogo'] ?? $this->catalogo([['0001', 'DOLOR PELVICO'], ['0002', 'CONTROL']]),
            $extra['cargados'] ?? $this->cargados([]),
            $extra['buscar'] ?? '',
            $extra['urlBuscar'] ?? '/clinica/consulta/42/motivos/buscar',
            $extra['urlAgregar'] ?? '/clinica/consulta/42/motivos',
            // Como en el controlador: la URL de quitar se arma **por fila** (la ruta lleva dos parámetros).
            $extra['urlQuitar'] ?? fn (int $fila) => '/clinica/consulta/42/motivos/' . $fila,
            $extra['urlCrear'] ?? '/clinica/consulta/42/motivos/crear',
            $extra['crearAbierto'] ?? false,
        );
    }

    public function test_una_consulta_sin_motivos_lo_dice_y_no_lista_nada(): void
    {
        $vm = $this->vista();

        $this->assertTrue($vm->estaVacio());
        $this->assertSame(0, $vm->total());
        $this->assertSame('Todavía sin motivos', $vm->resumen());
        $this->assertSame([], $vm->cargados());
    }

    public function test_los_motivos_cargados_llevan_su_quitar_y_el_aviso_del_codigo_huerfano(): void
    {
        $vm = $this->vista(['cargados' => $this->cargados([
            ['id' => 7, 'codigo' => '0001', 'descripcion' => 'DOLOR PELVICO', 'enCatalogo' => true],
            ['id' => 9, 'codigo' => '8888', 'descripcion' => 'MOTIVO VIEJO', 'enCatalogo' => false],
        ])]);

        $this->assertFalse($vm->estaVacio());
        $this->assertSame('2 motivos', $vm->resumen());

        $filas = $vm->cargados();
        $this->assertCount(2, $filas);

        $this->assertSame('/clinica/consulta/42/motivos/7', $filas[0]['urlQuitar']);
        $this->assertNull($filas[0]['avisoCatalogo']);

        // Un código que ya no está en el catálogo se muestra igual, pero avisando que no se puede volver
        // a elegir (el legado no borra motivos: los datos son datos).
        $this->assertSame('MOTIVO VIEJO', $filas[1]['descripcion']);
        $this->assertSame('Ya no está en tu catálogo', $filas[1]['avisoCatalogo']);
    }

    public function test_el_resumen_distingue_uno_de_varios(): void
    {
        $vm = $this->vista(['cargados' => $this->cargados([
            ['id' => 7, 'codigo' => '0001', 'descripcion' => 'DOLOR PELVICO', 'enCatalogo' => true],
        ])]);

        $this->assertSame('1 motivo', $vm->resumen());
    }

    public function test_el_resultado_de_un_motivo_que_ya_esta_no_ofrece_agregarlo(): void
    {
        $vm = $this->vista([
            'cargados' => $this->cargados([
                ['id' => 7, 'codigo' => '0001', 'descripcion' => 'DOLOR PELVICO', 'enCatalogo' => true],
            ]),
        ]);

        $resultados = $vm->resultados();
        $this->assertCount(2, $resultados);

        $this->assertSame('0001', $resultados[0]['codigo']);
        $this->assertTrue($resultados[0]['yaEsta'], 'el que ya está en la consulta no se ofrece para agregar');
        $this->assertFalse($resultados[1]['yaEsta']);
    }

    public function test_la_ayuda_distingue_catalogo_vacio_busqueda_sin_resultado_y_consulta_vacia(): void
    {
        // Consulta vacía con catálogo: dice por dónde empezar.
        $this->assertSame(
            'Busca en tu catálogo y agrega los motivos de esta consulta.',
            $this->vista()->ayuda(),
        );

        // Con motivos cargados: aclara que los de la consulta van marcados.
        $this->assertStringContainsString('marcados', $this->vista(['cargados' => $this->cargados([
            ['id' => 7, 'codigo' => '0001', 'descripcion' => 'DOLOR PELVICO', 'enCatalogo' => true],
        ])])->ayuda());

        // Catálogo vacío: ofrece crear el primero (es lo que el buscador va a ofrecer).
        $this->assertStringContainsString(
            'créalo',
            $this->vista(['catalogo' => $this->catalogo([])])->ayuda(),
        );

        // Búsqueda sin resultados: lo dice con el texto buscado.
        $this->assertStringContainsString(
            'MAMOGRAFIA',
            $this->vista(['catalogo' => $this->catalogo([]), 'buscar' => 'MAMOGRAFIA'])->ayuda(),
        );
    }

    public function test_el_alta_del_motivo_nuevo_dice_que_necesita_conexion_y_su_largo_maximo(): void
    {
        $vm = $this->vista();

        $this->assertStringContainsString('conexión', $vm->avisoDeConexion());
        $this->assertSame(40, $vm->largoMaximo());
        $this->assertFalse($vm->crearAbierto());

        $this->assertTrue($this->vista(['crearAbierto' => true])->crearAbierto());
    }

    public function test_las_urls_las_resuelve_el_controlador_y_el_buscador_puede_no_tenerla(): void
    {
        $vm = $this->vista();

        $this->assertSame('/clinica/consulta/42/motivos', $vm->urlAgregar());
        $this->assertSame('/clinica/consulta/42/motivos/crear', $vm->urlCrear());
        $this->assertSame('/clinica/consulta/42/motivos/buscar', $vm->urlBuscar());

        $sinBuscador = new SeccionDeMotivosVista($this->catalogo([]), $this->cargados([]));
        $this->assertNull($sinBuscador->urlBuscar());
        $this->assertSame('', $sinBuscador->urlAgregar());
    }
}
