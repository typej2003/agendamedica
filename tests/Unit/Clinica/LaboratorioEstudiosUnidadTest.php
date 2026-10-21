<?php

namespace Tests\Unit\Clinica;

use App\Actions\Consulta\ResultadoDeOrden;
use App\Clinica\Consulta\QueEsUnDocumento;
use App\Clinica\Consulta\Vistas\OrdenVista;
use App\Clinica\Impresion\DistribuidorDeDocumento;
use App\Clinica\Impresion\DocumentoImpreso;
use App\Clinica\Impresion\Membrete;
use App\Clinica\Impresion\Vistas\HojasDeDocumentoVista;
use App\Models\RecipeFormato;
use PHPUnit\Framework\TestCase;

/**
 * **Laboratorio y estudios** (WEB-5.4) sin base de datos ni navegador: qué es una orden, cómo se reparte una lista de exámenes en mitades
 * de hoja (se corta entre exámenes, nunca por palabra) y qué dicen las pestañas.
 *
 * El catálogo, las Actions y las pantallas están en `LaboratorioEstudiosDeLaConsultaWebTest`; que el cuerpo real del navegador no se
 * desborde lo comprueba el verificador de Chrome (`.agent-tools/verificar-laboratorio-estudios-web.js`).
 */
class LaboratorioEstudiosUnidadTest extends TestCase
{
    private function membrete(): Membrete
    {
        return new Membrete(
            'Dra. Ana Pérez', 'Ginecología y Obstetricia', 'J-12345678-9', null, ['Av. Principal, Torre Salud', '0251-5551234', 'doctora@example.com'],
            RecipeFormato::elementosPorDefecto(), 'negro', null, null, 'centro',
        );
    }

    /** @param array<int,array<string,mixed>> $parrafos */
    private function documento(array $parrafos): DocumentoImpreso
    {
        return new DocumentoImpreso(
            DocumentoImpreso::LABORATORIO, 'laboratorio', 'SOLICITUD DE EXÁMENES DE LABORATORIO', 'Orden de laboratorio', '2026-10-07',
            ['nombre' => 'Ana Alvarez', 'cedula' => 'V-14555666', 'edad' => 36, 'direccion' => null], [], $parrafos,
        );
    }

    /** @return array<int,string> nombres de exámenes de varias palabras: «EXAMEN 001 COMPLETO DE PRUEBA»… */
    private function examenes(int $cuantos, string $prefijo = 'EXAMEN'): array
    {
        return array_map(fn (int $n) => $prefijo . ' ' . str_pad((string) $n, 3, '0', STR_PAD_LEFT) . ' COMPLETO DE PRUEBA', range(1, $cuantos));
    }

    // ===================================================================== Qué es una orden

    public function test_una_orden_de_laboratorio_es_un_documento_con_un_examen_o_con_un_resumen_con_texto(): void
    {
        $this->assertFalse(QueEsUnDocumento::laboratorio(0, null), 'la fila de resumen que el escritorio crea vacía');
        $this->assertFalse(QueEsUnDocumento::laboratorio(0, ''));
        $this->assertFalse(QueEsUnDocumento::laboratorio(0, "  \n "));
        $this->assertFalse(QueEsUnDocumento::laboratorio(0, 'none'), 'el literal del DataWindow no es texto');
        $this->assertFalse(QueEsUnDocumento::laboratorio(0, 'NONE'));
        $this->assertTrue(QueEsUnDocumento::laboratorio(1, null));
        $this->assertTrue(QueEsUnDocumento::laboratorio(14, ''));
        $this->assertTrue(QueEsUnDocumento::laboratorio(0, 'HIV, VDRL'));
    }

    public function test_una_solicitud_de_estudios_es_un_documento_con_la_misma_vara(): void
    {
        $this->assertFalse(QueEsUnDocumento::estudios(0, null));
        $this->assertFalse(QueEsUnDocumento::estudios(0, '   '));
        $this->assertFalse(QueEsUnDocumento::estudios(0, 'none'));
        $this->assertTrue(QueEsUnDocumento::estudios(1, null));
        $this->assertTrue(QueEsUnDocumento::estudios(0, 'Mastalgia'));
    }

    // ============================================================ Una lista se corta entre elementos

    public function test_un_parrafo_de_lista_lleva_los_elementos_unidos_y_con_los_espacios_colapsados(): void
    {
        $parrafo = DocumentoImpreso::parrafoDeLista('LABORATORIO:', ['GLICEMIA', '  HEMATOLOGÍA   COMPLETA ', "UREA\n"]);

        $this->assertSame('GLICEMIA · HEMATOLOGÍA COMPLETA · UREA', $parrafo['texto']);
        $this->assertSame(['GLICEMIA', 'HEMATOLOGÍA COMPLETA', 'UREA'], $parrafo['items']);
        $this->assertSame('LABORATORIO:', $parrafo['etiqueta']);
        $this->assertTrue($parrafo['aparte']);
    }

    public function test_una_lista_corta_va_en_una_sola_mitad_con_la_firma(): void
    {
        $reparto = (new DistribuidorDeDocumento())->distribuir($this->membrete(), $this->documento([
            DocumentoImpreso::parrafoDeLista('LABORATORIO:', ['GLICEMIA', 'UREA', 'CREATININA']),
        ]));

        $this->assertSame(10, $reparto['puntos']);
        $this->assertCount(1, $reparto['mitades']);
        $this->assertTrue($reparto['mitades'][0]['firma']);
        $this->assertFalse($reparto['mitades'][0]['sigue']);
    }

    public function test_una_lista_larga_sigue_en_la_otra_mitad_y_nunca_corta_un_examen_por_la_mitad(): void
    {
        $nombres = $this->examenes(160);
        $reparto = (new DistribuidorDeDocumento())->distribuir($this->membrete(), $this->documento([
            DocumentoImpreso::parrafoDeLista('LABORATORIO:', $nombres),
        ]));

        $this->assertGreaterThan(1, count($reparto['mitades']));
        $this->assertTrue(end($reparto['mitades'])['firma'], 'la firma va en la última');
        $this->assertSame(1, count(array_filter($reparto['mitades'], fn (array $m) => $m['firma'])), 'y sólo en la última');

        // Todos los exámenes, una vez, completos y en orden: lo que cada mitad imprime es su `texto`, hecho de elementos enteros.
        $impresos = [];
        foreach ($reparto['mitades'] as $mitad) {
            foreach ($mitad['parrafos'] as $parrafo) {
                $this->assertArrayHasKey('items', $parrafo);
                $this->assertSame(implode(DocumentoImpreso::SEPARADOR_DE_ITEMS, $parrafo['items']), $parrafo['texto']);
                $impresos = array_merge($impresos, $parrafo['items']);
            }
        }
        $this->assertSame($nombres, $impresos);

        // La parte que sigue repite el rótulo del grupo con «(cont.)»; la primera conserva el suyo.
        $this->assertSame('LABORATORIO:', $reparto['mitades'][0]['parrafos'][0]['etiqueta']);
        $this->assertSame('LABORATORIO (cont.):', $reparto['mitades'][1]['parrafos'][0]['etiqueta']);
        $this->assertTrue($reparto['mitades'][1]['parrafos'][0]['aparte']);
    }

    public function test_una_lista_que_se_parte_dos_veces_no_acumula_cont(): void
    {
        $reparto = (new DistribuidorDeDocumento())->distribuir($this->membrete(), $this->documento([
            DocumentoImpreso::parrafoDeLista('LABORATORIO:', $this->examenes(700)),
        ]));

        $this->assertGreaterThan(2, count($reparto['mitades']));
        foreach (array_slice($reparto['mitades'], 1) as $mitad) {
            $this->assertSame('LABORATORIO (cont.):', $mitad['parrafos'][0]['etiqueta']);
        }
    }

    public function test_varios_grupos_se_reparten_sin_perder_ni_reordenar_ningun_examen(): void
    {
        $a = $this->examenes(70, 'ALFA');
        $b = $this->examenes(70, 'BETA');
        $resumen = DocumentoImpreso::parrafosDe("Primera linea del resumen.\nSegunda linea del resumen.", 'RESUMEN CLÍNICO:', true);
        $reparto = (new DistribuidorDeDocumento())->distribuir($this->membrete(), $this->documento(array_merge([
            DocumentoImpreso::parrafoDeLista('PRIMER TIPO:', $a),
            DocumentoImpreso::parrafoDeLista('SEGUNDO TIPO:', $b),
        ], $resumen)));

        $impresos = [];
        $textos = [];
        foreach ($reparto['mitades'] as $mitad) {
            foreach ($mitad['parrafos'] as $parrafo) {
                if (isset($parrafo['items'])) {
                    $impresos = array_merge($impresos, $parrafo['items']);
                } else {
                    $textos[] = $parrafo['texto'];
                }
            }
        }

        $this->assertSame(array_merge($a, $b), $impresos);
        $this->assertSame(['Primera linea del resumen.', 'Segunda linea del resumen.'], $textos, 'el resumen sigue a los exámenes, completo');
    }

    public function test_la_hoja_de_una_lista_trae_el_titulo_de_continuacion_y_el_texto_unido(): void
    {
        $hojas = (new HojasDeDocumentoVista($this->membrete(), [$this->documento([
            DocumentoImpreso::parrafoDeLista('LABORATORIO:', $this->examenes(160)),
        ])->conPagina('pequena')]))->hojas();

        $mitades = array_merge(...array_column($hojas, 'mitades'));
        $this->assertSame('SOLICITUD DE EXÁMENES DE LABORATORIO', $mitades[0]['titulo']);
        $this->assertSame('SOLICITUD DE EXÁMENES DE LABORATORIO (continuación)', $mitades[1]['titulo']);
        $this->assertTrue($mitades[0]['sigue']);
        $this->assertStringContainsString(' · EXAMEN 002 COMPLETO DE PRUEBA · ', $mitades[0]['parrafos'][0]['texto']);
    }

    // ============================================================================== El ViewModel

    public function test_la_vista_de_laboratorio_agrupa_ofrece_rutinas_y_dice_su_estado(): void
    {
        $items = [['codigo' => '001', 'nombre' => 'GLICEMIA', 'tipo' => 'LABORATORIO', 'aviso' => null], ['codigo' => '002', 'nombre' => 'UREA', 'tipo' => 'LABORATORIO', 'aviso' => null]];
        $urls = ['guardar' => '/g', 'eliminar' => '/e', 'imprimir' => '/i', 'catalogo' => '/c', 'rutina' => '/r/__TIPO__', 'administrar' => '/a'];

        $vm = OrdenVista::laboratorio($items, 'HIV', true, true, [['codigo' => '001', 'nombre' => 'LABORATORIO', 'cantidad' => 12]], $urls);
        $this->assertSame('examenes', $vm->campo());
        $this->assertTrue($vm->esLaboratorio());
        $this->assertTrue($vm->ofreceRutinas());
        $this->assertFalse($vm->numerada());
        $this->assertSame(2, $vm->total());
        $this->assertSame('2 exámenes en la orden.', $vm->cuenta());
        $this->assertSame(['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => '2 exámenes', 'titulo' => 'Esta consulta tiene una orden de laboratorio'], $vm->estado());
        $this->assertSame('/r/__TIPO__', $vm->urlRutina());
        $this->assertStringContainsString('Eliminar la orden', $vm->textoDeEliminar());
        $this->assertSame('Agregar exámenes', $vm->tituloDelCatalogo(), 'el título de la columna derecha (A-04)');

        $vacia = OrdenVista::laboratorio([], null, false, true, [], $urls);
        $this->assertSame('Sin orden', $vacia->estado()['texto']);
        $this->assertSame('neutro', $vacia->estado()['tono']);
        $this->assertSame('La orden no tiene exámenes.', $vacia->cuenta());
        $this->assertFalse($vacia->puedeEliminar());
        $this->assertFalse($vacia->ofreceRutinas(), 'sin tipos con exámenes no hay rutinas');
        $this->assertStringNotContainsString('de abajo', $vacia->vacio()['texto'], 'el catálogo ya no está debajo de la orden');
    }

    public function test_la_vista_de_estudios_va_numerada_sin_rutinas_y_un_resumen_solo_cuenta_como_con_solicitud(): void
    {
        $urls = ['guardar' => '/g', 'eliminar' => '/e', 'imprimir' => '/i', 'catalogo' => '/c', 'administrar' => '/a'];

        $vm = OrdenVista::estudios([['codigo' => '099', 'nombre' => 'DENSITOMETRIA OSEA', 'tipo' => null, 'aviso' => null]], null, true, true, $urls);
        $this->assertSame('estudios', $vm->campo());
        $this->assertTrue($vm->numerada());
        $this->assertFalse($vm->ofreceRutinas());
        $this->assertSame('1 estudio en la solicitud.', $vm->cuenta());
        $this->assertSame('1 estudio', $vm->estado()['texto']);
        $this->assertSame('Agregar estudios', $vm->tituloDelCatalogo());

        $soloResumen = OrdenVista::estudios([], 'Mastalgia', true, true, $urls);
        $this->assertSame('Con resumen', $soloResumen->estado()['texto']);
        $this->assertSame('Mastalgia', $soloResumen->observacion());
        $this->assertSame('', OrdenVista::estudios([], null, false, true, $urls)->observacion());
    }

    public function test_el_resultado_de_guardar_distingue_sin_cambios_de_conflicto(): void
    {
        $this->assertTrue((new ResultadoDeOrden())->sinCambios());
        $this->assertTrue((new ResultadoDeOrden(agregados: 1))->seEscribio());
        $this->assertTrue((new ResultadoDeOrden(quitados: 2))->seEscribio());
        $this->assertTrue((new ResultadoDeOrden(observacionCreada: true))->seEscribio());
        $this->assertTrue((new ResultadoDeOrden(columnas: ['orden']))->seEscribio());

        $conflicto = new ResultadoDeOrden(bloqueadas: ['observacion']);
        $this->assertTrue($conflicto->huboConflicto());
        $this->assertFalse($conflicto->sinCambios());
        $this->assertFalse($conflicto->seEscribio());
    }
}
