<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\Vistas\OrdenDeCatalogoVista;
use App\Clinica\Consulta\Vistas\OrdenVista;
use PHPUnit\Framework\TestCase;

/**
 * El ViewModel de **Laboratorio y Estudios pintados como Motivos de consulta** (O-29) se prueba sin base de datos ni HTTP: lo que la
 * pantalla decide —qué fila del catálogo ya está en la orden y cuál ofrece «Pasar», qué hace el buscador cuando no encuentra nada y
 * qué queda para el botón— está acá.
 *
 * La sección completa (las rutas, las Actions y las escrituras) está en `LaboratorioEstudiosDeLaConsultaWebTest`.
 */
class OrdenDeCatalogoVistaTest extends TestCase
{
    /** @param array<int,array{codigo:string, nombre:string, tipo:?string}> $catalogo */
    private function vista(array $items = [], array $catalogo = [], string $buscar = '', string $tipo = '', bool $hayCatalogo = true): OrdenDeCatalogoVista
    {
        $orden = OrdenVista::laboratorio(
            $items,
            null,
            $items !== [],
            $hayCatalogo,
            [['codigo' => '001', 'nombre' => 'LABORATORIO', 'cantidad' => 3]],
            [
                'guardar' => '/c/42/laboratorio',
                'eliminar' => '/c/42/laboratorio/eliminar',
                'imprimir' => '/c/42/documentos/imprimir?documento=laboratorio',
                'catalogo' => '/c/42/laboratorio/catalogo',
                'rutina' => '/c/42/laboratorio/rutinas/__TIPO__',
                'administrar' => '/clinica/catalogos/examenes',
            ],
        );

        return new OrdenDeCatalogoVista(
            $orden,
            $catalogo,
            $buscar,
            $tipo,
            ['001' => 'LABORATORIO'],
            [['codigo' => '001', 'nombre' => 'LABORATORIO', 'cantidad' => 3]],
            [
                'agregar' => '/c/42/laboratorio/examen',
                'quitar' => '/c/42/laboratorio/examen/quitar',
                'resumen' => '/c/42/laboratorio',
                'rutina' => '/c/42/laboratorio/rutinas/__TIPO__',
                'seccion' => '/c/42?seccion=solicitudes&solicitud=laboratorio',
                'administrar' => '/clinica/catalogos/examenes',
            ],
            fn (string $codigo) => '/c/42/laboratorio/examen/quitar',
            $hayCatalogo,
        );
    }

    private function examen(string $codigo, string $nombre, string $tipo = 'LABORATORIO'): array
    {
        return ['codigo' => $codigo, 'nombre' => $nombre, 'tipo' => $tipo];
    }

    public function test_la_fila_del_catalogo_dice_si_ya_esta_y_solo_ofrece_pasar_si_no_esta(): void
    {
        $vm = $this->vista(
            [['codigo' => '001', 'nombre' => 'GLICEMIA', 'tipo' => 'LABORATORIO', 'aviso' => null]],
            [$this->examen('001', 'GLICEMIA'), $this->examen('003', 'UREA')],
        );

        $filas = $vm->filasDelCatalogo();

        $this->assertTrue($filas[0]['yaEsta'], 'la que ya está en la orden no se vuelve a ofrecer');
        $this->assertFalse($filas[1]['yaEsta']);
        $this->assertSame('001', $filas[0]['codigo']);
        $this->assertSame('/c/42/laboratorio/examen', $filas[1]['urlAgregar']);
        $this->assertSame('/c/42/laboratorio/examen/quitar', $filas[1]['urlQuitar'], 'la baja es la URL de esa fila');
    }

    public function test_lo_que_se_guarda_con_el_boton_es_el_resumen_y_el_boton_de_la_rutina_lleva_su_tipo(): void
    {
        $vm = $this->vista([], [$this->examen('001', 'GLICEMIA')]);

        $this->assertSame('/c/42/laboratorio', $vm->urlResumen());
        $this->assertSame('Guardar el resumen clínico', $vm->textoDeGuardar());
        $this->assertSame('/c/42/laboratorio/rutinas/001', $vm->urlDeLaRutina('001'));
        $this->assertSame('Pasar', $vm->verbo());
        $this->assertStringContainsString('al instante', $vm->ayudaDelCatalogo());
        $this->assertStringContainsString('resumen clínico, con su botón', $vm->ayudaDelCatalogo());
    }

    public function test_sin_resultados_la_ayuda_distingue_el_catalogo_vacio_de_una_busqueda_sin_coincidencias(): void
    {
        $vacia = $this->vista([], [], '', '', false);
        $this->assertFalse($vacia->hayCatalogo());
        $this->assertFalse($vacia->hayResultados());
        $this->assertStringContainsString('Tu catálogo no tiene', $vacia->ayudaDelCatalogo());

        $sinCoincidencias = $this->vista([], [], 'zzz', '001');
        $this->assertTrue($sinCoincidencias->hayCatalogo());
        $this->assertFalse($sinCoincidencias->hayResultados());
        $this->assertStringContainsString('Ningún resultado con «zzz»', $sinCoincidencias->ayudaDelCatalogo());
    }

    public function test_el_filtro_va_a_la_seccion_sin_su_buscador_y_los_tipos_son_los_de_la_lista(): void
    {
        $vm = $this->vista([], [$this->examen('001', 'GLICEMIA')], 'gli', '001');

        $this->assertSame('/c/42', $vm->urlDelFiltro());
        $this->assertSame('/c/42?seccion=solicitudes&solicitud=laboratorio', $vm->urlSeccion());
        $this->assertSame('gli', $vm->buscar());
        $this->assertSame('001', $vm->tipoElegido());
        $this->assertSame(['001' => 'LABORATORIO'], $vm->tipos());
        $this->assertTrue($vm->ofreceRutinas());
    }
}
