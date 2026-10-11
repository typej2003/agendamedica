<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Consulta\RecipesDeLaConsulta;
use App\Clinica\Consulta\Vistas\SeccionDeRecipesVista;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * El ViewModel de la **sección de récipes de una consulta** (WEB-5.1, ítem 10 del panel) se prueba sin base
 * de datos ni HTTP: récipes ya leídos → textos, banderas y filas del editor (Docs/Web/guia-arquitectura-web.md).
 *
 * Lo que se fija acá es lo que la pantalla decide: el estado vacío y el estado con récipes, qué se arma para
 * cada uno (título, emisión, vigencia, resumen, medicamentos y las URLs de sus acciones), los dos modos de la
 * sección —lista y editor— con la fecha por defecto del récipe nuevo, las filas del editor (de la base o las
 * del `old()` de un POST que falló, con sus nombres) y la agrupación del legado (`NULL` = récipe 1).
 */
class RecipesVistaTest extends TestCase
{
    /** @param array<int,array<string,mixed>> $items */
    private function recipe(int $numero, ?string $fecha, array $items, int $vigencia = 30): object
    {
        return (object) [
            'numero' => $numero,
            'fecha' => $fecha,
            'vigencia' => $vigencia,
            'cabecera' => $fecha !== null,
            'items' => collect($items)->map(fn (array $item) => (object) array_merge([
                'id' => 1,
                'codigo' => '0001',
                'nombre' => 'KEFORAL/Cap. 500 mg',
                'generico' => 'CEFALEXINA',
                'cantidad' => 2,
                'indicaciones' => 'Una cápsula cada 6 horas',
                'enVademecum' => true,
            ], $item)),
        ];
    }

    /** @param array<int,object> $recipes */
    private function vista(array $recipes = [], ?string $editando = null, array $old = []): SeccionDeRecipesVista
    {
        return new SeccionDeRecipesVista(
            new Collection($recipes),
            $editando,
            '2026-10-07',
            [
                'lista' => '/clinica/consulta/42?seccion=recipes',
                'nuevo' => '/clinica/consulta/42?seccion=recipes&recipe=nuevo',
                'guardarNuevo' => '/clinica/consulta/42/recipes',
                'medicamentos' => '/clinica/consulta/42/recipes/medicamentos',
                'tratamientos' => '/clinica/consulta/42/recipes/tratamientos',
                'crearMedicamento' => '/clinica/consulta/42/recipes/medicamentos',
            ],
            fn (int $numero) => "/clinica/consulta/42?seccion=recipes&recipe={$numero}",
            fn (int $numero) => "/clinica/consulta/42/recipes/{$numero}",
            fn (int $numero) => "/clinica/consulta/42/recipes/{$numero}/eliminar",
            $old,
        );
    }

    public function test_el_nulo_del_escritorio_y_el_uno_son_el_mismo_recipe(): void
    {
        $this->assertSame(1, RecipesDeLaConsulta::numeroDeGrupo(null));
        $this->assertSame(1, RecipesDeLaConsulta::numeroDeGrupo(''));
        $this->assertSame(1, RecipesDeLaConsulta::numeroDeGrupo(1));
        $this->assertSame(1, RecipesDeLaConsulta::numeroDeGrupo('1'));
        $this->assertSame(3, RecipesDeLaConsulta::numeroDeGrupo('3'));
    }

    public function test_una_consulta_sin_recipes_lo_dice_y_no_es_editor(): void
    {
        $vm = $this->vista();

        $this->assertTrue($vm->estaVacia());
        $this->assertFalse($vm->esEditor());
        $this->assertNull($vm->editor());
        $this->assertSame([], $vm->recetas());
        $this->assertSame('Sin récipes', $vm->estado()['texto']);
        $this->assertSame('neutro', $vm->estado()['tono']);
        $this->assertSame('/clinica/consulta/42?seccion=recipes&recipe=nuevo', $vm->urlNuevo());
    }

    public function test_la_lista_arma_cada_recipe_con_su_emision_su_resumen_y_sus_acciones(): void
    {
        $vm = $this->vista([
            $this->recipe(1, '2026-10-05', [['id' => 1], ['id' => 2, 'codigo' => '0002', 'nombre' => 'DIFLUCAN G/Cap. 150 mg', 'indicaciones' => null]]),
            $this->recipe(2, null, [['id' => 3, 'enVademecum' => false, 'cantidad' => null]]),
            $this->recipe(3, '2026-10-06', []),
        ]);

        $this->assertFalse($vm->estaVacia());
        $this->assertSame('3 récipes', $vm->estado()['texto']);
        $this->assertSame('marca', $vm->estado()['tono']);

        [$uno, $dos, $tres] = $vm->recetas();

        $this->assertSame('Récipe 1', $uno['titulo']);
        $this->assertSame('05/10/2026', $uno['emision']);
        $this->assertSame('30 días', $uno['vigencia']);
        $this->assertSame('2 medicamentos', $uno['resumen']);
        $this->assertSame('/clinica/consulta/42?seccion=recipes&recipe=1', $uno['urlEditar']);
        $this->assertSame('/clinica/consulta/42/recipes/1/eliminar', $uno['urlEliminar']);
        $this->assertStringContainsString('récipe 1', $uno['avisoEliminar']);
        $this->assertStringContainsString('2 medicamentos', $uno['avisoEliminar']);
        $this->assertStringContainsString('vademécum no se toca', $uno['avisoEliminar']);
        $this->assertSame('KEFORAL/Cap. 500 mg', $uno['items'][0]['nombre']);
        $this->assertSame('2', $uno['items'][0]['cantidad']);
        $this->assertNull($uno['items'][1]['indicaciones']);

        // Sin fecha: la raya, no un vacío. Cantidad ausente: la raya. Un código que el vademécum ya no tiene
        // lo avisa.
        $this->assertSame('—', $dos['emision']);
        $this->assertSame('1 medicamento', $dos['resumen']);
        $this->assertSame('—', $dos['items'][0]['cantidad']);
        $this->assertSame('ya no está en tu vademécum', $dos['items'][0]['avisoVademecum']);
        $this->assertNull($uno['items'][0]['avisoVademecum']);

        // Un récipe con cabecera y sin medicamentos se muestra y se puede completar o eliminar.
        $this->assertTrue($tres['vacio']);
        $this->assertSame('Sin medicamentos', $tres['resumen']);
    }

    public function test_el_editor_nuevo_usa_la_fecha_de_la_consulta_y_no_tiene_filas(): void
    {
        $vm = $this->vista([$this->recipe(1, '2026-10-05', [[]])], 'nuevo');

        $editor = $vm->editor();

        $this->assertTrue($vm->esEditor());
        $this->assertTrue($vm->esNuevo());
        $this->assertNull($vm->numero());
        $this->assertSame('Récipe nuevo', $editor['titulo']);
        $this->assertSame('2026-10-07', $editor['fecha']);
        $this->assertSame('/clinica/consulta/42/recipes', $editor['urlGuardar']);
        $this->assertSame([], $editor['filas']);
        $this->assertSame('Sin medicamentos', $vm->estadoDelEditor()['texto']);
        $this->assertSame(0, $vm->siguienteClave());
    }

    public function test_el_editor_de_uno_existente_trae_su_fecha_y_sus_filas_y_postea_a_su_numero(): void
    {
        $vm = $this->vista([
            $this->recipe(1, '2026-10-05', [[]]),
            $this->recipe(2, '2026-10-06', [
                ['id' => 3, 'codigo' => '0002', 'nombre' => 'DIFLUCAN G/Cap. 150 mg', 'cantidad' => 3, 'indicaciones' => 'Los dos'],
                ['id' => 4, 'codigo' => '0003', 'nombre' => 'ÁCIDO FÓLICO/Tabletas 5 mg', 'cantidad' => null, 'indicaciones' => null],
            ]),
        ], '2');

        $editor = $vm->editor();

        $this->assertSame(2, $vm->numero());
        $this->assertFalse($vm->esNuevo());
        $this->assertSame('Récipe 2', $editor['titulo']);
        $this->assertSame('2026-10-06', $editor['fecha']);
        $this->assertSame('/clinica/consulta/42/recipes/2', $editor['urlGuardar']);
        $this->assertCount(2, $editor['filas']);
        $this->assertSame(
            ['clave' => 0, 'codigo' => '0002', 'nombre' => 'DIFLUCAN G/Cap. 150 mg', 'generico' => 'CEFALEXINA', 'cantidad' => '3', 'indicaciones' => 'Los dos'],
            $editor['filas'][0],
        );
        // Cantidad e indicaciones ausentes: vacíos en el control, no `null`.
        $this->assertSame('', $editor['filas'][1]['cantidad']);
        $this->assertSame('', $editor['filas'][1]['indicaciones']);
        $this->assertSame('2 medicamentos', $vm->estadoDelEditor()['texto']);
        // La próxima fila que agregue el script no choca con las que ya hay.
        $this->assertSame(2, $vm->siguienteClave());
    }

    public function test_un_post_que_fallo_vuelve_al_editor_con_lo_escrito_y_con_los_nombres(): void
    {
        $vm = $this->vista([$this->recipe(1, '2026-10-05', [[]])], '1', [
            'editor' => '1',
            'fecha' => '2026-10-02',
            'items' => [
                ['codemedicina' => '0002', 'nombre' => 'DIFLUCAN G/Cap. 150 mg', 'cantidad' => 'x', 'indicaciones' => 'A mano'],
                ['codemedicina' => '', 'nombre' => 'fila vacía', 'cantidad' => '1', 'indicaciones' => ''],
            ],
        ]);

        $editor = $vm->editor();

        $this->assertSame('2026-10-02', $editor['fecha']);
        // Sólo la fila con código; el nombre sale del formulario (es de pantalla) y lo escrito se conserva.
        $this->assertCount(1, $editor['filas']);
        $this->assertSame('0002', $editor['filas'][0]['codigo']);
        $this->assertSame('DIFLUCAN G/Cap. 150 mg', $editor['filas'][0]['nombre']);
        $this->assertSame('x', $editor['filas'][0]['cantidad']);
        $this->assertSame('A mano', $editor['filas'][0]['indicaciones']);
    }

    public function test_un_post_que_fallo_sin_medicamentos_no_vuelve_a_leer_la_base(): void
    {
        // El médico quitó todas las filas y guardó: el editor vuelve vacío, como lo dejó, no con lo de la base.
        $vm = $this->vista([$this->recipe(1, '2026-10-05', [[]])], '1', ['editor' => '1', 'fecha' => '2026-10-05']);

        $this->assertSame([], $vm->editor()['filas']);
    }

    public function test_los_topes_y_las_ayudas_salen_de_las_reglas(): void
    {
        $vm = $this->vista();

        $this->assertSame(999, $vm->cantidadMaxima());
        $this->assertSame(2000, $vm->largoIndicaciones());
        $this->assertSame(35, $vm->largoNombre());
        $this->assertSame(50, $vm->largoPresentacion());
        $this->assertStringContainsString('cambios sin guardar', $vm->avisoSinGuardar());
        $this->assertStringContainsString('conexión', $vm->avisoDeConexion());
        $this->assertStringContainsString('sin importar las tildes', $vm->ayudaDelBuscador());
        $this->assertSame('/clinica/consulta/42/recipes/medicamentos', $vm->urlMedicamentos());
        $this->assertSame('/clinica/consulta/42/recipes/tratamientos', $vm->urlTratamientos());
    }
}
