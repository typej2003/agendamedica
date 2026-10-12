<?php

namespace Tests\Unit\Clinica;

use App\Actions\Catalogos\CamposDelMedicamento;
use App\Clinica\Catalogos\CatalogosDisponibles;
use App\Clinica\Catalogos\Vistas\EditorDeTratamientoVista;
use App\Clinica\Catalogos\Vistas\MedicamentosVista;
use App\Clinica\Catalogos\Vistas\TratamientosVista;
use App\Models\RecipeGrupo;
use App\Models\Vademecum;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Los ViewModels y la regla de campos de los **catálogos del médico** (WEB-8.1a), sin base de datos: lo ya leído →
 * textos, estados, filas y URLs (Docs/Web/guia-arquitectura-web.md).
 *
 * Extiende `Tests\TestCase` (y no el de PHPUnit a secas) porque los ViewModels de formulario usan `old()` y
 * `route()`; **no** usa `RefreshDatabase` ni toca la base.
 */
class CatalogosVistaTest extends TestCase
{
    private const BASE = 'http://localhost/clinica/catalogos/medicamentos';

    private function medicamento(array $campos = []): Vademecum
    {
        $fila = new Vademecum();
        $fila->forceFill(array_merge([
            'id' => 5,
            'codemedicina' => '0005',
            'nombrecomercial' => 'KEFORAL',
            'nombregenerico' => 'CEFALEXINA',
            'presentacion' => 'Cap. 500 mg',
            'uso' => "Tomar una cápsula\ncada 6 horas.",
            'sico' => 'N',
        ], $campos));
        $fila->exists = true;

        return $fila;
    }

    /** @param array<int,Vademecum> $filas */
    private function vista(array $filas, string $buscar = '', int $total = 40, ?Vademecum $editando = null, int $pagina = 1, int $deTodos = 40, bool $abrirNuevo = false): MedicamentosVista
    {
        return new MedicamentosVista(
            new LengthAwarePaginator($filas, $deTodos, 25, $pagina, ['path' => self::BASE]),
            $buscar,
            $total,
            $editando,
            self::BASE,
            ['guardar' => self::BASE, 'quitar' => fn (int $id) => self::BASE . '/' . $id],
            $abrirNuevo,
        );
    }

    // ------------------------------------------------------------------------------------ Medicamentos

    public function test_una_fila_de_medicamento_trae_su_nombre_presentacion_codigo_y_enlaces(): void
    {
        $vista = $this->vista([$this->medicamento(['nombrecomercial1' => 'CEFA', 'nombrecomercial2' => '-', 'sico' => 'S'])]);

        $fila = $vista->filas()[0];

        $this->assertSame('KEFORAL', $fila['nombre']);
        $this->assertSame('Cap. 500 mg', $fila['presentacion']);
        $this->assertSame('0005', $fila['codigo']);
        $this->assertSame('CEFALEXINA', $fila['generico']);
        $this->assertSame(['CEFA'], $fila['marcas'], 'las marcas vacías o con el guion del legado no se muestran');
        $this->assertTrue($fila['psicotropico']);
        $this->assertSame('Tomar una cápsula cada 6 horas.', $fila['uso'], 'las indicaciones van en una línea en la lista');
        $this->assertSame(self::BASE . '?editar=5', $fila['urlEditar']);
        $this->assertSame(self::BASE . '/5', $fila['urlQuitar']);
        $this->assertStringContainsString('«KEFORAL/Cap. 500 mg»', $fila['avisoQuitar']);
    }

    public function test_un_medicamento_sin_comercial_se_muestra_por_su_generico_y_sin_nada_se_dice(): void
    {
        $vista = $this->vista([
            $this->medicamento(['nombrecomercial' => null, 'nombregenerico' => 'CEFALEXINA', 'presentacion' => '-']),
            $this->medicamento(['id' => 6, 'codemedicina' => '0006', 'nombrecomercial' => null, 'nombregenerico' => null, 'uso' => null]),
        ]);

        [$conGenerico, $sinNada] = $vista->filas();

        $this->assertSame('CEFALEXINA', $conGenerico['nombre']);
        $this->assertNull($conGenerico['generico'], 'el genérico no se repite si ya es el nombre');
        $this->assertSame('Sin nombre', $sinNada['nombre']);
        $this->assertNull($sinNada['uso']);
    }

    public function test_el_rango_el_subtitulo_y_el_vacio_distinguen_los_casos(): void
    {
        $this->assertSame('Mostrando 26–40 de 40', $this->vista(array_fill(0, 15, $this->medicamento()), '', 40, null, 2)->rango());
        $this->assertSame('', $this->vista([], '', 0, null, 1, 0)->rango());

        $this->assertStringContainsString('40 medicamentos en tu vademécum', $this->vista([$this->medicamento()])->subtitulo());
        $this->assertSame('3 medicamentos para «kef».', $this->vista([$this->medicamento()], 'kef', 40, null, 1, 3)->subtitulo());
        $this->assertSame('Sin resultados para «zzz».', $this->vista([], 'zzz', 40, null, 1, 0)->subtitulo());

        $busqueda = $this->vista([], 'zzz', 40, null, 1, 0)->vacio();
        $this->assertSame('bi-search', $busqueda['icono']);
        $this->assertStringContainsString('«zzz»', $busqueda['titulo']);

        $nada = $this->vista([], '', 0, null, 1, 0)->vacio();
        $this->assertSame('bi-capsule', $nada['icono']);
        $this->assertStringContainsString('todavía no tiene', $nada['titulo']);
    }

    public function test_las_urls_conservan_la_busqueda_y_la_pagina(): void
    {
        $vista = $this->vista([$this->medicamento()], 'kef', 80, null, 2, 80);

        $this->assertSame(self::BASE . '?buscar=kef&page=2&nuevo=1', $vista->urlNuevo());
        $this->assertSame(self::BASE . '?buscar=kef&page=2', $vista->urlCancelar());
        $this->assertSame(self::BASE . '?buscar=kef&page=2&editar=5', $vista->filas()[0]['urlEditar']);
        $this->assertSame(self::BASE, $vista->urlLimpiar());
    }

    public function test_el_dialogo_se_abre_al_editar_o_al_venir_de_nuevo_y_el_formulario_toma_sus_valores(): void
    {
        $this->assertFalse($this->vista([$this->medicamento()])->abrirAlCargar());
        $this->assertTrue($this->vista([$this->medicamento()], '', 40, null, 1, 40, true)->abrirAlCargar());

        $editando = $this->vista([$this->medicamento()], '', 40, $this->medicamento(['sico' => 'S', 'nombrecomercial1' => 'CEFA']));
        $this->assertTrue($editando->abrirAlCargar());
        $this->assertSame('Editar el medicamento', $editando->tituloDelFormulario());
        $this->assertSame('Guardar los cambios', $editando->botonGuardar());
        $this->assertSame('0005', $editando->codigoEditando());
        $this->assertSame('KEFORAL', $editando->texto('nombrecomercial'));
        $this->assertSame('', $editando->texto('dosificacion'));
        $this->assertTrue($editando->esPsicotropico());
        $this->assertTrue($editando->hayMarcas());

        $nuevo = $this->vista([$this->medicamento()]);
        $this->assertSame('Nuevo medicamento', $nuevo->tituloDelFormulario());
        $this->assertSame('Crear el medicamento', $nuevo->botonGuardar());
        $this->assertNull($nuevo->codigoEditando());
        $this->assertFalse($nuevo->esPsicotropico());
        $this->assertFalse($nuevo->hayMarcas());
        $this->assertSame(35, $nuevo->largoNombre());
        $this->assertSame(40, $nuevo->largoMarca());
        $this->assertSame(50, $nuevo->largoPresentacion());
    }

    // ------------------------------------------------------------------------------------ Tratamientos

    private function tratamientoLeido(int $id, string $nombre, array $nombres): object
    {
        return (object) [
            'id' => $id,
            'codigo' => sprintf('%04d', $id),
            'nombre' => $nombre,
            'items' => new Collection(array_map(fn ($n) => (object) ['nombre' => $n], $nombres)),
        ];
    }

    private function tratamientos(array $lista, string $buscar = '', int $total = 3): TratamientosVista
    {
        return new TratamientosVista(new Collection($lista), $buscar, $total, [
            'base' => '/clinica/catalogos/tratamientos',
            'nuevo' => '/clinica/catalogos/tratamientos/nuevo',
            'editar' => fn (int $id) => "/clinica/catalogos/tratamientos/{$id}/editar",
            'quitar' => fn (int $id) => "/clinica/catalogos/tratamientos/{$id}",
        ]);
    }

    public function test_la_lista_de_tratamientos_resume_los_medicamentos_con_un_y_n_mas(): void
    {
        $vista = $this->tratamientos([
            $this->tratamientoLeido(1, 'CONTROL PRENATAL', ['A', 'B', 'C', 'D', 'E']),
            $this->tratamientoLeido(2, 'UNO SOLO', ['A']),
            $this->tratamientoLeido(3, 'VACÍO', []),
        ]);

        [$cinco, $uno, $vacio] = $vista->filas();

        $this->assertSame('5 medicamentos', $cinco['cantidad']);
        $this->assertSame('A · B · C · y 2 más', $cinco['resumen']);
        $this->assertSame('1 medicamento', $uno['cantidad']);
        $this->assertSame('A', $uno['resumen']);
        $this->assertSame('Sin medicamentos', $vacio['cantidad']);
        $this->assertNull($vacio['resumen']);
        $this->assertSame('/clinica/catalogos/tratamientos/1/editar', $cinco['urlEditar']);
        $this->assertSame('/clinica/catalogos/tratamientos/1', $cinco['urlQuitar']);
        $this->assertStringContainsString('sus 5 medicamentos', $cinco['avisoQuitar']);
        $this->assertStringContainsString('su medicamento', $uno['avisoQuitar']);
        $this->assertStringContainsString('Los récipes que ya hiciste con él no cambian', $cinco['avisoQuitar']);
    }

    public function test_el_subtitulo_y_el_vacio_de_tratamientos_distinguen_buscar_de_no_tener(): void
    {
        $this->assertSame('3 tratamientos. Cada uno carga de una vez sus medicamentos al armar un récipe.', $this->tratamientos([])->subtitulo());
        $this->assertSame('2 tratamientos para «pre».', $this->tratamientos([$this->tratamientoLeido(1, 'A', []), $this->tratamientoLeido(2, 'B', [])], 'pre')->subtitulo());
        $this->assertSame('bi-search', $this->tratamientos([], 'zzz')->vacio()['icono']);
        $this->assertStringContainsString('Todavía no tienes', $this->tratamientos([], '', 0)->vacio()['titulo']);
        $this->assertSame('2 tratamientos', $this->tratamientos([$this->tratamientoLeido(1, 'A', []), $this->tratamientoLeido(2, 'B', [])])->rango());
    }

    // ------------------------------------------------------------------------------ El editor de uno

    private const URLS_EDITOR = [
        'guardar' => '/clinica/catalogos/tratamientos/9',
        'lista' => '/clinica/catalogos/tratamientos',
        'medicamentos' => '/clinica/catalogos/medicamentos/buscar',
        'vademecum' => '/clinica/catalogos/medicamentos',
    ];

    private function grupo(): RecipeGrupo
    {
        $grupo = new RecipeGrupo();
        $grupo->forceFill(['id' => 9, 'reg_medico' => 'r', 'codigo' => '0009', 'tratamiento' => 'CONTROL PRENATAL']);
        $grupo->exists = true;

        return $grupo;
    }

    private function filasDelTratamiento(): Collection
    {
        return new Collection([
            (object) ['codigo' => '0002', 'nombre' => 'DIFLUCAN G/Cap. 150 mg', 'generico' => 'FLUCONAZOL', 'cantidad' => 2, 'indicaciones' => 'Una al día'],
            (object) ['codigo' => '0001', 'nombre' => 'KEFORAL/Cap. 500 mg', 'generico' => null, 'cantidad' => null, 'indicaciones' => null],
        ]);
    }

    public function test_el_editor_de_un_tratamiento_es_el_del_recipe_con_el_orden_encendido_y_lo_demas_apagado(): void
    {
        $vista = new EditorDeTratamientoVista($this->grupo(), $this->filasDelTratamiento(), self::URLS_EDITOR);

        $this->assertFalse($vista->ofreceTratamientos(), 'un tratamiento no se arma con otros');
        $this->assertFalse($vista->ofreceCrearMedicamento(), 'el medicamento que falta se crea en su pantalla');
        $this->assertTrue($vista->ofreceOrdenar());
        $this->assertSame('/clinica/catalogos/medicamentos', $vista->enlaceDeMedicamentos());
        $this->assertSame('/clinica/catalogos/medicamentos/buscar', $vista->urlMedicamentos());
        $this->assertStringContainsString('tratamiento', $vista->avisoSinGuardar());

        $editor = $vista->editor();
        $this->assertSame('Editar tratamiento', $editor['titulo']);
        $this->assertSame('/clinica/catalogos/tratamientos/9', $editor['urlGuardar']);
        $this->assertSame('CONTROL PRENATAL', $editor['nombre']);
        $this->assertSame(['0002', '0001'], array_column($editor['filas'], 'codigo'), 'en el orden del tratamiento');
        $this->assertSame('', $editor['filas'][1]['cantidad'], 'un valor que no hay se ve vacío, no se inventa');
        $this->assertSame(2, $vista->siguienteClave());
        $this->assertSame('2 medicamentos', $vista->estadoDelEditor()['texto']);
        $this->assertStringContainsString('Código 0009', $vista->subtitulo());
        $this->assertSame('Guardar el tratamiento', $vista->botonGuardar());
    }

    public function test_el_editor_nuevo_arranca_vacio_y_si_un_post_fallo_vuelve_con_lo_escrito(): void
    {
        $nuevo = new EditorDeTratamientoVista(null, null, self::URLS_EDITOR);

        $this->assertTrue($nuevo->esNuevo());
        $this->assertSame('Nuevo tratamiento', $nuevo->titulo());
        $this->assertSame('Crear el tratamiento', $nuevo->botonGuardar());
        $this->assertSame([], $nuevo->editor()['filas']);
        $this->assertSame('Sin medicamentos', $nuevo->estadoDelEditor()['texto']);

        $conOld = new EditorDeTratamientoVista($this->grupo(), $this->filasDelTratamiento(), self::URLS_EDITOR, [
            'editor' => '1',
            'tratamiento' => 'LO QUE ESCRIBÍ',
            'items' => [['codemedicina' => '0003', 'nombre' => 'ÁCIDO FÓLICO', 'cantidad' => '4', 'indicaciones' => 'Mi texto']],
        ]);

        $editor = $conOld->editor();
        $this->assertSame('LO QUE ESCRIBÍ', $editor['nombre']);
        $this->assertSame(['0003'], array_column($editor['filas'], 'codigo'), 'las filas del old(), no las de la base');
        $this->assertSame('4', $editor['filas'][0]['cantidad']);
    }

    // ------------------------------------------------------------------------ La regla de los campos

    public function test_los_campos_del_medicamento_se_normalizan_como_el_abm_del_escritorio(): void
    {
        $campos = CamposDelMedicamento::normalizar([
            'nombrecomercial' => '  adevit   junior ',
            'nombregenerico' => 'multivitamínico',
            'nombrecomercial1' => 'plus',
            'presentacion' => '  Jarabe   120 ml ',
            'uso' => "  Una cucharada\r\nal día. ",
            'sico' => '1',
        ], true);

        $this->assertSame('ADEVIT JUNIOR', $campos['nombrecomercial']);
        $this->assertSame('MULTIVITAMÍNICO', $campos['nombregenerico']);
        $this->assertSame('PLUS', $campos['nombrecomercial1']);
        $this->assertNull($campos['nombrecomercial2']);
        $this->assertSame('Jarabe 120 ml', $campos['presentacion'], 'la presentación va como se escribe, con los espacios de más colapsados');
        $this->assertSame("Una cucharada\r\nal día.", $campos['uso'], 'las indicaciones conservan sus saltos de línea');
        $this->assertNull($campos['dosificacion']);
        $this->assertSame('S', $campos['sico']);
        $this->assertSame(CamposDelMedicamento::COLUMNAS, array_keys(array_merge(array_flip(CamposDelMedicamento::COLUMNAS), $campos)));
    }

    public function test_el_psicotropico_es_s_o_n(): void
    {
        foreach ([true, 1, '1', 'S', 's', 'on'] as $tildado) {
            $this->assertSame('S', CamposDelMedicamento::psicotropico($tildado));
        }
        foreach ([false, 0, '0', null, '', 'N', 'no'] as $destildado) {
            $this->assertSame('N', CamposDelMedicamento::psicotropico($destildado));
        }
    }

    public function test_el_alta_pide_el_comercial_y_la_edicion_pide_alguno_de_los_dos_nombres(): void
    {
        $this->assertSame('KEFORAL', CamposDelMedicamento::normalizar(['nombrecomercial' => 'keforal'], true)['nombrecomercial']);
        $this->assertNull(CamposDelMedicamento::normalizar(['nombregenerico' => 'cefalexina'], false)['nombrecomercial']);

        foreach ([[['nombregenerico' => 'x'], true], [[], false], [['nombrecomercial' => '  '], false]] as [$datos, $exigir]) {
            try {
                CamposDelMedicamento::normalizar($datos, $exigir);
                $this->fail('tenía que rechazar un medicamento sin nombre');
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_los_topes_de_cada_columna_se_rechazan_con_su_nombre(): void
    {
        $casos = [
            ['nombrecomercial', 36, 'nombre comercial'],
            ['nombregenerico', 36, 'principio activo'],
            ['nombrecomercial1', 41, 'segunda marca'],
            ['nombrecomercial2', 41, 'tercera marca'],
            ['nombrecomercial3', 41, 'cuarta marca'],
            ['presentacion', 51, 'presentación'],
            ['uso', 2001, 'indicaciones'],
            ['dosificacion', 2001, 'dosificación'],
        ];

        foreach ($casos as [$columna, $largo, $cual]) {
            try {
                CamposDelMedicamento::normalizar(['nombrecomercial' => 'A', $columna => str_repeat('B', $largo)], true);
                $this->fail("{$columna} de {$largo} caracteres tenía que rechazarse");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsStringIgnoringCase($cual, $e->getMessage(), $columna);
            }
        }

        // En el tope exacto pasa.
        $this->assertSame(35, mb_strlen(CamposDelMedicamento::normalizar(['nombrecomercial' => str_repeat('A', 35)], true)['nombrecomercial']));
        $this->assertSame(40, mb_strlen(CamposDelMedicamento::normalizar(['nombrecomercial' => 'A', 'nombrecomercial1' => str_repeat('A', 40)], true)['nombrecomercial1']));
    }

    // ------------------------------------------------------------------------------ El índice de catálogos

    public function test_el_indice_de_catalogos_marca_el_actual_y_solo_lista_los_que_tienen_pantalla(): void
    {
        $pestanas = CatalogosDisponibles::pestanas('tratamientos');

        $this->assertSame(['Medicamentos', 'Tratamientos'], array_column($pestanas, 'texto'));
        $this->assertSame([false, true], array_column($pestanas, 'activa'));
        $this->assertSame(route('clinica.catalogos.medicamentos'), $pestanas[0]['url']);
        $this->assertSame(route('clinica.catalogos.medicamentos'), CatalogosDisponibles::urlDeEntrada());
        $this->assertSame([false, false], array_column(CatalogosDisponibles::pestanas(null), 'activa'));
    }
}
