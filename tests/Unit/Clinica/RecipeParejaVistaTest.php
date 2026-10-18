<?php

namespace Tests\Unit\Clinica;

use App\Actions\Consulta\ItemsDeRecipe;
use App\Clinica\Consulta\Vistas\PestanasDeRecipes;
use App\Clinica\Consulta\Vistas\SeccionDeRecipeDeLaParejaVista;
use App\Clinica\Consulta\Vistas\SeccionDeRecipesVista;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

/**
 * El ViewModel del **récipe de la pareja** (WEB-5.1b) se prueba sin base de datos ni HTTP: el récipe ya leído
 * → textos, estados, filas del editor y las dos pestañas (Docs/Web/guia-arquitectura-web.md).
 *
 * Lo que se fija acá es lo que la pantalla decide: el estado «sin récipe» y el estado con medicamentos, que el
 * editor siempre existe (la pareja es una por consulta y se corrige en el lugar), los datos de la pareja de la
 * base o los del `old()` de un POST que falló, qué pestaña está activa en cada vista y que las dos vistas
 * comparten las herramientas y los topes.
 */
class RecipeParejaVistaTest extends TestCase
{
    private const URLS = [
        'guardar' => '/clinica/consulta/42/recipe-pareja',
        'eliminar' => '/clinica/consulta/42/recipe-pareja',
        'medicamentos' => '/clinica/consulta/42/recipes/medicamentos',
        'tratamientos' => '/clinica/consulta/42/recipes/tratamientos',
        'crearMedicamento' => '/clinica/consulta/42/recipes/medicamentos',
    ];

    private function pestanas(): PestanasDeRecipes
    {
        return new PestanasDeRecipes('/clinica/consulta/42?seccion=recipes', '/clinica/consulta/42?seccion=recipes&recipe=pareja');
    }

    /** @param array<int,array<string,mixed>> $items */
    private function pareja(array $items, string $cedula = '12345678', string $nombre = 'Mariana Pérez'): object
    {
        return (object) [
            'cedula' => $cedula,
            'nombre' => $nombre,
            'items' => new Collection(array_map(fn (array $item) => (object) array_merge([
                'id' => 1,
                'codigo' => '0001',
                'nombre' => 'KEFORAL/Cap. 500 mg',
                'generico' => 'CEFALEXINA',
                'cantidad' => 2,
                'indicaciones' => 'Una cápsula cada 6 horas',
                'enVademecum' => true,
            ], $item), $items)),
        ];
    }

    private function vista(?object $pareja = null, array $old = []): SeccionDeRecipeDeLaParejaVista
    {
        return new SeccionDeRecipeDeLaParejaVista($pareja, self::URLS, $this->pestanas(), $old);
    }

    public function test_es_la_vista_de_la_pareja_y_la_del_paciente_no(): void
    {
        $this->assertTrue($this->vista()->esDeLaPareja());

        $delPaciente = new SeccionDeRecipesVista(
            new Collection(), null, '2026-10-07',
            ['lista' => '/l', 'nuevo' => '/n', 'guardarNuevo' => '/g', 'medicamentos' => '/m', 'tratamientos' => '/t', 'crearMedicamento' => '/c'],
            fn (int $n) => "/e/$n", fn (int $n) => "/g/$n", fn (int $n) => "/x/$n",
        );
        $this->assertFalse($delPaciente->esDeLaPareja());
        $this->assertSame([], $delPaciente->pestanas(), 'sin pestañas configuradas no las ofrece');
    }

    public function test_sin_recipe_el_editor_existe_vacio_y_no_se_puede_eliminar(): void
    {
        $vm = $this->vista();

        $this->assertFalse($vm->existe());
        $this->assertSame('Sin récipe de la pareja', $vm->estado()['texto']);
        $this->assertSame('neutro', $vm->estado()['tono']);

        $editor = $vm->editor();
        $this->assertSame('', $editor['cedula']);
        $this->assertSame('', $editor['nombre']);
        $this->assertSame([], $editor['filas']);
        $this->assertSame('/clinica/consulta/42/recipe-pareja', $editor['urlGuardar']);
        $this->assertSame('Sin medicamentos', $vm->estadoDelEditor()['texto']);
        $this->assertSame(0, $vm->siguienteClave());
    }

    public function test_con_recipe_arma_la_pareja_las_filas_el_estado_y_el_aviso_de_eliminar(): void
    {
        $vm = $this->vista($this->pareja([
            ['id' => 1, 'codigo' => '0001'],
            ['id' => 2, 'codigo' => '0002', 'nombre' => 'DIFLUCAN G/Cap. 150 mg', 'generico' => null, 'cantidad' => null, 'indicaciones' => null],
        ]));

        $this->assertTrue($vm->existe());
        $this->assertSame('2 medicamentos', $vm->estado()['texto']);
        $this->assertSame('marca', $vm->estado()['tono']);

        $editor = $vm->editor();
        $this->assertSame('12345678', $editor['cedula']);
        $this->assertSame('Mariana Pérez', $editor['nombre']);
        $this->assertCount(2, $editor['filas']);
        $this->assertSame(['clave' => 0, 'codigo' => '0001', 'nombre' => 'KEFORAL/Cap. 500 mg', 'generico' => 'CEFALEXINA', 'cantidad' => '2', 'indicaciones' => 'Una cápsula cada 6 horas'], $editor['filas'][0]);
        $this->assertSame('', $editor['filas'][1]['cantidad']);
        $this->assertSame('', $editor['filas'][1]['indicaciones']);
        $this->assertSame(2, $vm->siguienteClave());

        $this->assertStringContainsString('2 medicamentos', $vm->avisoDeEliminar());
        $this->assertStringContainsString('no se puede deshacer', $vm->avisoDeEliminar());
        $this->assertSame('/clinica/consulta/42/recipe-pareja', $vm->urlEliminar());
    }

    public function test_un_post_que_fallo_vuelve_con_la_pareja_y_las_filas_escritas_sin_leer_la_base(): void
    {
        $vm = $this->vista($this->pareja([['codigo' => '0001']], '111', 'DE LA BASE'), [
            'editor' => '1',
            'cedula' => '',
            'nombre' => 'Escrito a mano',
            'items' => [
                ['codemedicina' => '0003', 'nombre' => 'ÁCIDO FÓLICO/Tabletas 5 mg', 'cantidad' => '2', 'indicaciones' => 'Una al día'],
                ['codemedicina' => '', 'nombre' => 'sin código'],
            ],
        ]);

        $editor = $vm->editor();
        $this->assertSame('', $editor['cedula']);
        $this->assertSame('Escrito a mano', $editor['nombre']);
        $this->assertCount(1, $editor['filas'], 'la fila sin código se descarta');
        $this->assertSame('0003', $editor['filas'][0]['codigo']);
        $this->assertSame('ÁCIDO FÓLICO/Tabletas 5 mg', $editor['filas'][0]['nombre']);
        $this->assertSame('2', $editor['filas'][0]['cantidad']);
    }

    public function test_las_pestanas_marcan_la_activa_en_cada_vista(): void
    {
        $enLaPareja = $this->vista()->pestanas();
        $this->assertSame(['Récipes del paciente', 'Récipe de la pareja'], array_column($enLaPareja, 'texto'));
        $this->assertSame([false, true], array_column($enLaPareja, 'activa'));
        $this->assertSame('/clinica/consulta/42?seccion=recipes&recipe=pareja', $enLaPareja[1]['url']);

        $enElPaciente = $this->pestanas()->opciones(PestanasDeRecipes::PACIENTE);
        $this->assertSame([true, false], array_column($enElPaciente, 'activa'));
    }

    public function test_las_pestanas_de_la_seccion_se_ofrecen_en_la_lista_y_en_el_editor(): void
    {
        $urls = ['lista' => '/l', 'nuevo' => '/n', 'guardarNuevo' => '/g', 'medicamentos' => '/m', 'tratamientos' => '/t', 'crearMedicamento' => '/c'];
        $crear = fn (?string $editando) => new SeccionDeRecipesVista(
            new Collection(), $editando, '2026-10-07', $urls,
            fn (int $n) => "/e/$n", fn (int $n) => "/g/$n", fn (int $n) => "/x/$n", [], $this->pestanas(),
        );

        // O-28: el editor es la puerta de entrada, así que el camino al récipe de la pareja tiene que estar
        // también ahí (si no, desde el editor no se llegaría nunca a la segunda pestaña).
        $this->assertCount(2, $crear(null)->pestanas());
        $this->assertCount(2, $crear('nuevo')->pestanas());
        $this->assertCount(2, $crear('2')->pestanas());
    }

    public function test_las_dos_vistas_comparten_los_topes_y_las_herramientas(): void
    {
        $vm = $this->vista();

        $this->assertSame(SeccionDeRecipesVista::CANTIDAD_MAXIMA, $vm->cantidadMaxima());
        $this->assertSame(SeccionDeRecipesVista::LARGO_INDICACIONES, $vm->largoIndicaciones());
        $this->assertSame('/clinica/consulta/42/recipes/medicamentos', $vm->urlMedicamentos());
        $this->assertSame('/clinica/consulta/42/recipes/tratamientos', $vm->urlTratamientos());
        $this->assertSame('/clinica/consulta/42/recipes/medicamentos', $vm->urlCrearMedicamento());
        $this->assertSame(10, $vm->largoCedula());
        $this->assertSame(200, $vm->largoNombreDeLaPareja());
        $this->assertNotSame('', $vm->ayudaDelBuscador());
        $this->assertNotSame('', $vm->avisoDeConexion());
    }

    public function test_los_items_se_normalizan_una_sola_vez_y_sin_repetir_medicamento(): void
    {
        $items = ItemsDeRecipe::normalizar([
            ['codemedicina' => ' 0001 ', 'cantidad' => '2', 'indicaciones' => '  Tomar  ', 'nombre' => 'KEFORAL'],
            ['codemedicina' => '0001', 'cantidad' => 9],
            ['codemedicina' => '', 'cantidad' => 1],
            ['codemedicina' => '0002', 'cantidad' => 'x', 'indicaciones' => '  '],
        ]);

        $this->assertCount(2, $items);
        $this->assertSame('0001', $items[0]['codemedicina']);
        $this->assertSame(2, $items[0]['cantidad']);
        $this->assertSame('Tomar', $items[0]['indicaciones']);
        $this->assertNull($items[1]['cantidad']);
        $this->assertNull($items[1]['indicaciones']);
        $this->assertSame('0002', $items[1]['nombre'], 'sin nombre se cuenta por el código');
    }
}
