<?php

namespace Tests\Feature;

use App\Actions\Configuracion\GuardarFormatoDelRecipe;
use App\Actions\Configuracion\GuardarMembreteDelMedico;
use App\Models\Evolucion;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\RecipeFormato;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **Formato de impresión del médico en la web** (WEB-8.2): membrete, logo, estilo de cada bloque, firma y sello, la vista previa
 * y «Restablecer valores», en `/clinica/formato`.
 *
 * Lo que estos casos fijan:
 *
 *  - **Quién entra**: el médico del contexto; la secretaría recibe 403 y no ve la entrada del menú; el invitado va al login.
 *  - **Qué se escribe y dónde**: los textos en `evolucion`, el estilo y las imágenes en `recipe_formatos`, siempre bajo el
 *    **registro principal** del médico, y **sólo lo que cambia**; el rastro por columna en `sync_changes` (`source = web`) con
 *    el last-write-wins del sync.
 *  - **Los archivos**: logo, firma y sello van al disco público con las rutas del API, se valida el tipo real y el tamaño, el
 *    archivo anterior se borra si nadie más lo usa y no quedan huérfanos si la fila falla.
 *  - **El API del móvil no cambia** (su JSON y sus reglas) y **lo que se guarda en la web llega al teléfono** por el sync.
 *  - **La hoja** que imprime WEB-5.2 refleja lo guardado, y la vista previa es esa misma hoja con datos de ejemplo.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class FormatoDeImpresionWebTest extends TestCase
{
    use DatabaseTransactions;
    use PreparaRecipesParaImprimir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fijarHoy();
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Evolucion::flushEventListeners();
        RecipeFormato::flushEventListeners();
        $this->soltarHoy();

        parent::tearDown();
    }

    // --------------------------------------------------------------------------------------- Ayudas

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoSinFormato(): array
    {
        return $this->medicoConAcceso();
    }

    private function como(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->conContexto($user, $medico, $specialty)->actingAs($user);
    }

    /**
     * Pasa el reloj de la prueba. El last-write-wins del sync deja pasar una edición sólo si es **más nueva** que el último
     * rastro de esa columna, y con el reloj congelado dos guardados seguidos tendrían la misma hora.
     */
    private function avanzar(int $segundos = 60): void
    {
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::now()->addSeconds($segundos));
    }

    private function png(string $nombre = 'imagen.png', int $ancho = 80, int $alto = 40): UploadedFile
    {
        return UploadedFile::fake()->image($nombre, $ancho, $alto);
    }

    private function evolucionDe(Medico $medico): ?Evolucion
    {
        return Evolucion::where('reg_medico', $medico->reg_medico)->orderBy('id')->first();
    }

    private function formatoDe(Medico $medico): ?RecipeFormato
    {
        return RecipeFormato::where('reg_medico', $medico->reg_medico)->first();
    }

    /** @return array<string,mixed> el estilo completo de los cuatro bloques de texto, con los cambios pedidos */
    private function estilo(array $cambios = []): array
    {
        $texto = fn (bool $negrita) => [
            'alineacion' => 'centro', 'fuente' => 'arimo', 'negrita' => $negrita ? '1' : '0', 'italica' => '0',
            'subrayado' => '0', 'borde' => '0', 'visible' => '1',
        ];

        return array_replace_recursive([
            'color_linea' => 'negro',
            'elementos' => [
                'medico' => $texto(false), 'especialidad' => $texto(true), 'rif' => $texto(true), 'pie' => $texto(false),
            ],
        ], $cambios);
    }

    private function rastros(Evolucion $evolucion)
    {
        return SyncChange::where('table_name', 'evolucion')->where('record_id', $evolucion->id)->where('source', 'web');
    }

    // -------------------------------------------------------------------------------------- Quién entra

    public function test_el_medico_ve_la_pantalla_con_sus_secciones_y_sin_crear_nada(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();

        $respuesta = $this->como($user, $medico, $gineco)->get(route('clinica.formato'));

        $respuesta->assertOk();
        $respuesta->assertSee('Formato de impresión');
        $respuesta->assertSee('Datos del membrete');
        $respuesta->assertSee('Logo');
        $respuesta->assertSee('Estilo del membrete');
        $respuesta->assertSee('Firma y sello');
        $respuesta->assertSee('Dra. Ana Imprime');
        // La vista previa es un marco a la hoja de prueba, y los valores son los del legado.
        $respuesta->assertSee('formato/vista-previa?incrustada=1', false);
        $respuesta->assertSee('Valores del legado');
        // Mirar no crea nada.
        $this->assertNull($this->evolucionDe($medico));
        $this->assertNull($this->formatoDe($medico));
    }

    public function test_la_secretaria_no_entra_ni_ve_la_entrada_del_menu(): void
    {
        [, $medico, $gineco] = $this->medicoSinFormato();
        $secretaria = $this->secretariaConAcceso($medico);

        $this->como($secretaria, $medico, $gineco)->get(route('clinica.formato'))->assertForbidden();
        $this->como($secretaria, $medico, $gineco)->get(route('clinica.formato.vista-previa'))->assertForbidden();
        $this->como($secretaria, $medico, $gineco)->post(route('clinica.formato.membrete'), ['especialidad' => 'X'])->assertForbidden();
        $this->como($secretaria, $medico, $gineco)->post(route('clinica.formato.restablecer'), ['seccion' => 'logo'])->assertForbidden();
        $this->assertNull($this->evolucionDe($medico));

        // Ni en el menú de otra pantalla (A-12: la secretaría entra a la agenda, no al inicio del médico).
        $this->como($secretaria, $medico, $gineco)->get(route('clinica.agenda'))->assertOk()->assertDontSee('Formato de impresión');
    }

    public function test_el_medico_ve_la_entrada_en_el_grupo_configuracion_del_menu(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();

        $this->como($user, $medico, $gineco)->get(route('clinica.inicio'))
            ->assertOk()
            ->assertSee('Configuración')
            ->assertSee(route('clinica.formato'), false);
    }

    public function test_el_invitado_va_al_login(): void
    {
        $this->get(route('clinica.formato'))->assertRedirect('/login');
        $this->post(route('clinica.formato.membrete'), [])->assertRedirect('/login');
    }

    // ---------------------------------------------------------------------------------- Datos del membrete

    public function test_guarda_los_datos_del_membrete_en_evolucion_con_su_rastro(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();

        $this->como($user, $medico, $gineco)->post(route('clinica.formato.membrete'), [
            'especialidad' => 'Ginecología y Obstetricia',
            'rif' => 'J-12345678-9',
            'linea_1' => 'Av. Principal, Torre Salud',
            'linea_2' => '0251-5551234',
            'linea_3' => 'consultorio@example.com',
        ])->assertRedirect(route('clinica.formato') . '#formato-membrete')->assertSessionHas('estado');

        $evolucion = $this->evolucionDe($medico);
        $this->assertNotNull($evolucion, 'Se crea la fila de evolución del médico.');
        $this->assertSame($medico->reg_medico, $evolucion->reg_medico);
        $this->assertSame('Ginecología y Obstetricia', $evolucion->especialidad);
        $this->assertSame('J-12345678-9', $evolucion->rif);
        $this->assertSame('Av. Principal, Torre Salud', $evolucion->linea_1);
        $this->assertSame('0251-5551234', $evolucion->linea_2);
        $this->assertSame('consultorio@example.com', $evolucion->linea_3);
        // Lo que la web no edita no se toca; `clave` es el relleno NOT NULL del esquema legado.
        $this->assertNull($evolucion->ciudad);
        $this->assertNull($evolucion->lineag_1);
        $this->assertSame(0, (int) $evolucion->clave);

        // El rastro: un `created` de la fila y un `updated` por columna escrita, todos `source = web`.
        $rastros = $this->rastros($evolucion)->get();
        $this->assertSame(1, $rastros->where('operation', 'created')->count());
        $this->assertEqualsCanonicalizing(
            ['especialidad', 'rif', 'linea_1', 'linea_2', 'linea_3'],
            $rastros->where('operation', 'updated')->pluck('column_name')->all(),
        );
        $this->assertSame('J-12345678-9', $rastros->firstWhere('column_name', 'rif')->value);
    }

    public function test_guardar_dos_veces_lo_mismo_no_escribe_ni_deja_rastro_nuevo(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();
        $datos = ['especialidad' => 'Pediatría', 'rif' => 'J-1', 'linea_1' => 'Calle 1', 'linea_2' => null, 'linea_3' => null];

        $this->como($user, $medico, $gineco)->post(route('clinica.formato.membrete'), $datos);
        $evolucion = $this->evolucionDe($medico);
        $antes = $this->rastros($evolucion)->count();

        $this->como($user, $medico, $gineco)->post(route('clinica.formato.membrete'), $datos)
            ->assertSessionHas('estado', fn ($mensaje) => str_contains($mensaje, 'No había cambios'));

        $this->assertSame($antes, $this->rastros($evolucion)->count());
    }

    public function test_cambia_solo_las_columnas_distintas_y_vaciar_un_campo_lo_deja_en_null(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();
        $this->membrete($medico, ['ciudad' => 'Barquisimeto', 'lineag_1' => 'Pie del informe']);

        $this->como($user, $medico, $gineco)->post(route('clinica.formato.membrete'), [
            'especialidad' => 'Ginecología y Obstetricia',
            'rif' => 'J-12345678-9',
            'linea_1' => 'Av. Principal, Torre Salud, piso 3',
            'linea_2' => '0251-5551234',
            'linea_3' => '',
        ]);

        $evolucion = $this->evolucionDe($medico);
        $this->assertNull($evolucion->linea_3, 'Vaciar un campo lo deja en null.');
        $this->assertSame('Barquisimeto', $evolucion->ciudad, 'Lo que la web no edita no se pisa.');
        $this->assertSame('Pie del informe', $evolucion->lineag_1);

        // Sólo `linea_3` cambió (las otras cuatro ya valían lo mismo): un solo `updated`.
        $this->assertSame(['linea_3'], $this->rastros($evolucion)->where('operation', 'updated')->pluck('column_name')->all());
    }

    public function test_respeta_el_last_write_wins_y_avisa_cuando_otro_equipo_edito_despues(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();
        $this->membrete($medico, ['especialidad' => 'Del escritorio']);
        $evolucion = $this->evolucionDe($medico);

        SyncChange::create([
            'reg_medico' => $medico->reg_medico, 'table_name' => 'evolucion', 'record_id' => $evolucion->id, 'operation' => 'updated',
            'column_name' => 'especialidad', 'value' => 'Del escritorio', 'occurred_at' => $this->hoy->copy()->addHour(), 'source' => 'escritorio',
        ]);

        $this->como($user, $medico, $gineco)->post(route('clinica.formato.membrete'), [
            'especialidad' => 'De la web', 'rif' => 'J-9', 'linea_1' => 'Calle 1', 'linea_2' => '0251-1', 'linea_3' => 'a@b.com',
        ])->assertSessionHas('error', fn ($mensaje) => str_contains($mensaje, 'otro equipo'));

        $evolucion->refresh();
        $this->assertSame('Del escritorio', $evolucion->especialidad, 'Una edición más nueva no se pisa.');
        $this->assertSame('J-9', $evolucion->rif, 'Las demás columnas sí se escriben.');
    }

    public function test_los_largos_se_validan_con_el_tope_del_esquema_y_en_espanol(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();

        $this->como($user, $medico, $gineco)->from(route('clinica.formato'))->post(route('clinica.formato.membrete'), [
            'especialidad' => str_repeat('x', 201),
            'rif' => str_repeat('x', 41),
            'linea_1' => str_repeat('x', 91),
            'linea_2' => str_repeat('x', 91),
            'linea_3' => str_repeat('x', 91),
        ])->assertRedirect(route('clinica.formato'))
            ->assertSessionHasErrors(['especialidad', 'rif', 'linea_1', 'linea_2', 'linea_3']);

        $this->assertNull($this->evolucionDe($medico));
        $this->assertSame('No puede tener más de 200 caracteres.', session('errors')->first('especialidad'));
    }

    // ------------------------------------------------------------------------------------------------ Logo

    public function test_sube_el_logo_con_las_rutas_del_api_y_guarda_sus_opciones(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();

        $this->como($user, $medico, $gineco)->post(route('clinica.formato.logo'), [
            'logo' => $this->png('logo.png'),
            'elementos' => ['logo' => ['tamano' => 'grande', 'alineacion' => 'derecha', 'borde' => '1', 'visible' => '1']],
        ])->assertRedirect(route('clinica.formato') . '#formato-logo')->assertSessionHas('estado');

        $evolucion = $this->evolucionDe($medico);
        $this->assertStringStartsWith('logos/', $evolucion->logo, 'La misma carpeta que usa el API.');
        Storage::disk('public')->assertExists($evolucion->logo);

        $logo = $this->formatoDe($medico)->elementos['logo'];
        $this->assertSame('grande', $logo['tamano']);
        $this->assertSame('derecha', $logo['alineacion']);
        $this->assertTrue($logo['borde']);
        $this->assertTrue($logo['visible']);
    }

    public function test_reemplazar_el_logo_borra_el_anterior_y_quitarlo_borra_el_actual(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();
        $opciones = ['logo' => ['tamano' => 'mediano', 'alineacion' => 'izquierda', 'borde' => '0', 'visible' => '1']];

        $this->como($user, $medico, $gineco)->post(route('clinica.formato.logo'), ['logo' => $this->png('uno.png'), 'elementos' => $opciones]);
        $primero = $this->evolucionDe($medico)->logo;

        $this->avanzar();
        $this->como($user, $medico, $gineco)->post(route('clinica.formato.logo'), ['logo' => $this->png('dos.png'), 'elementos' => $opciones]);
        $segundo = $this->evolucionDe($medico)->logo;

        $this->assertNotSame($primero, $segundo, 'Cada subida tiene su nombre: es lo que hace que el teléfono vuelva a bajarlo.');
        Storage::disk('public')->assertMissing($primero);
        Storage::disk('public')->assertExists($segundo);

        $this->avanzar();
        $this->como($user, $medico, $gineco)->post(route('clinica.formato.logo'), ['quitar_logo' => '1', 'elementos' => $opciones]);

        $this->assertNull($this->evolucionDe($medico)->logo);
        Storage::disk('public')->assertMissing($segundo);
    }

    public function test_el_archivo_anterior_no_se_borra_si_otro_registro_lo_usa(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();
        $opciones = ['logo' => ['tamano' => 'mediano', 'alineacion' => 'izquierda', 'borde' => '0', 'visible' => '1']];

        $this->como($user, $medico, $gineco)->post(route('clinica.formato.logo'), ['logo' => $this->png(), 'elementos' => $opciones]);
        $compartido = $this->evolucionDe($medico)->logo;

        // Otra fila (de otro médico) apunta al mismo archivo.
        Evolucion::create(['reg_medico' => 'otro-' . uniqid(), 'clave' => 0, 'logo' => $compartido]);

        $this->avanzar();
        $this->como($user, $medico, $gineco)->post(route('clinica.formato.logo'), ['logo' => $this->png('nuevo.png'), 'elementos' => $opciones]);

        $this->assertNotSame($compartido, $this->evolucionDe($medico)->logo, 'El logo sí se reemplazó.');
        Storage::disk('public')->assertExists($compartido);
    }

    public function test_rechaza_lo_que_no_es_una_imagen_aceptada_sin_crear_nada(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();
        $opciones = ['logo' => ['tamano' => 'mediano', 'alineacion' => 'izquierda', 'borde' => '0', 'visible' => '1']];

        $malos = [
            'un SVG' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'un PHP con nombre de imagen' => UploadedFile::fake()->createWithContent('logo.png', '<?php echo "hola";'),
            'un GIF' => UploadedFile::fake()->image('logo.gif'),
            'un PDF' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
            'una imagen de 3 MB' => UploadedFile::fake()->image('grande.png')->size(3000),
        ];

        foreach ($malos as $caso => $archivo) {
            $this->como($user, $medico, $gineco)->from(route('clinica.formato'))
                ->post(route('clinica.formato.logo'), ['logo' => $archivo, 'elementos' => $opciones]);

            $this->assertTrue(session('errors')?->has('logo') ?? false, "Tenía que rechazar {$caso}.");
        }

        $this->assertNull($this->evolucionDe($medico), 'Ningún rechazo crea la fila.');
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertStringContainsString('2 MB', session('errors')->first('logo'));
    }

    public function test_las_opciones_del_logo_invalidas_se_rechazan_en_espanol(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();

        $this->como($user, $medico, $gineco)->from(route('clinica.formato'))->post(route('clinica.formato.logo'), [
            'elementos' => ['logo' => ['tamano' => 'enorme', 'alineacion' => 'arriba', 'borde' => 'quizá', 'visible' => '1']],
        ])->assertSessionHasErrors(['elementos.logo.tamano', 'elementos.logo.alineacion', 'elementos.logo.borde']);

        $this->assertSame('Elige chico, mediano o grande.', session('errors')->first('elementos.logo.tamano'));
        $this->assertNull($this->formatoDe($medico));
    }

    public function test_si_la_fila_no_queda_se_borra_el_logo_recien_subido(): void
    {
        [, $medico] = $this->medicoSinFormato();

        Evolucion::saving(function () {
            throw new RuntimeException('falla simulada');
        });

        try {
            app(GuardarMembreteDelMedico::class)->ejecutar($medico, [], $this->png('huerfano.png'));
            $this->fail('Tenía que propagar el error de la base.');
        } catch (RuntimeException $excepcion) {
            $this->assertSame('falla simulada', $excepcion->getMessage());
        }

        $this->assertSame([], Storage::disk('public')->allFiles(), 'No queda el archivo huérfano.');
        $this->assertNull($this->evolucionDe($medico));
    }

    public function test_si_la_fila_no_queda_se_borran_la_firma_y_el_sello_recien_subidos(): void
    {
        [, $medico] = $this->medicoSinFormato();

        RecipeFormato::saving(function () {
            throw new RuntimeException('falla simulada');
        });

        try {
            app(GuardarFormatoDelRecipe::class)->ejecutar($medico, [], $this->png('f.png'), $this->png('s.png'));
            $this->fail('Tenía que propagar el error de la base.');
        } catch (RuntimeException) {
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertNull($this->formatoDe($medico));
    }

    // ----------------------------------------------------------------------------------------------- Estilo

    public function test_guarda_el_estilo_de_cada_bloque_y_el_color_de_la_linea(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();

        $this->como($user, $medico, $gineco)->post(route('clinica.formato.estilo'), $this->estilo([
            'color_linea' => 'rojo',
            'elementos' => [
                'medico' => ['alineacion' => 'derecha', 'fuente' => 'tinos', 'negrita' => '1', 'italica' => '1', 'subrayado' => '1', 'borde' => '1', 'visible' => '1'],
                'rif' => ['visible' => '0'],
                'pie' => ['alineacion' => 'izquierda', 'fuente' => 'cousine'],
            ],
        ]))->assertRedirect(route('clinica.formato') . '#formato-estilo')->assertSessionHas('estado');

        $formato = $this->formatoDe($medico);
        $this->assertSame('rojo', $formato->color_linea);
        $this->assertSame([
            'alineacion' => 'derecha', 'fuente' => 'tinos', 'negrita' => true, 'italica' => true, 'subrayado' => true, 'borde' => true, 'visible' => true,
        ], $formato->elementos['medico']);
        $this->assertFalse($formato->elementos['rif']['visible'], 'Una casilla desmarcada llega como falso.');
        $this->assertSame('cousine', $formato->elementos['pie']['fuente']);
        $this->assertArrayNotHasKey('logo', $formato->elementos, 'El estilo no toca el logo.');
    }

    public function test_el_estilo_conserva_lo_que_otra_seccion_o_el_movil_guardo(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();
        $this->membrete($medico, [], ['elementos' => json_encode(['logo' => ['tamano' => 'grande'], 'rif' => ['italica' => true]]), 'sello_posicion' => 'derecha']);

        $this->como($user, $medico, $gineco)->post(route('clinica.formato.estilo'), $this->estilo(['color_linea' => 'azul']));

        $formato = $this->formatoDe($medico);
        $this->assertSame('grande', $formato->elementos['logo']['tamano']);
        $this->assertSame('derecha', $formato->sello_posicion);
        $this->assertFalse($formato->elementos['rif']['italica'], 'El formulario manda el estado completo del bloque que muestra.');
    }

    public function test_rechaza_valores_fuera_de_dominio_en_espanol_sin_guardar(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();

        $this->como($user, $medico, $gineco)->from(route('clinica.formato'))->post(route('clinica.formato.estilo'), $this->estilo([
            'color_linea' => 'turquesa',
            'elementos' => ['medico' => ['fuente' => 'papyrus', 'alineacion' => 'justificada', 'negrita' => 'tal vez']],
        ]))->assertSessionHasErrors(['color_linea', 'elementos.medico.fuente', 'elementos.medico.alineacion', 'elementos.medico.negrita']);

        $this->assertNull($this->formatoDe($medico));
        foreach (['color_linea', 'elementos.medico.fuente', 'elementos.medico.alineacion', 'elementos.medico.negrita'] as $campo) {
            $this->assertEspanol(session('errors')->first($campo));
        }
    }

    public function test_un_elemento_inventado_no_entra_al_json(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();

        $this->como($user, $medico, $gineco)->post(route('clinica.formato.estilo'), $this->estilo([
            'elementos' => ['inventado' => ['fuente' => 'arimo'], 'medico' => ['sombra' => '1']],
        ]));

        $elementos = $this->formatoDe($medico)->elementos;
        $this->assertArrayNotHasKey('inventado', $elementos);
        $this->assertArrayNotHasKey('sombra', $elementos['medico']);
    }

    // ----------------------------------------------------------------------------------------- Firma y sello

    public function test_sube_la_firma_y_el_sello_con_las_rutas_del_api(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();

        $this->como($user, $medico, $gineco)->post(route('clinica.formato.firma'), [
            'firma' => $this->png('firma.png'), 'sello' => $this->png('sello.png'), 'sello_posicion' => 'izquierda',
        ])->assertRedirect(route('clinica.formato') . '#formato-firma')->assertSessionHas('estado');

        $formato = $this->formatoDe($medico);
        $this->assertStringStartsWith('recipe/firmas/', $formato->firma);
        $this->assertStringStartsWith('recipe/sellos/', $formato->sello);
        Storage::disk('public')->assertExists([$formato->firma, $formato->sello]);
        $this->assertSame('izquierda', $formato->sello_posicion);
    }

    public function test_reemplazar_o_quitar_la_firma_y_el_sello_borra_el_archivo_anterior(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();

        $this->como($user, $medico, $gineco)->post(route('clinica.formato.firma'), ['firma' => $this->png(), 'sello' => $this->png(), 'sello_posicion' => 'centro']);
        $uno = $this->formatoDe($medico);
        [$firma1, $sello1] = [$uno->firma, $uno->sello];

        $this->como($user, $medico, $gineco)->post(route('clinica.formato.firma'), ['firma' => $this->png('otra.png'), 'sello_posicion' => 'centro']);
        $dos = $this->formatoDe($medico);

        $this->assertNotSame($firma1, $dos->firma);
        Storage::disk('public')->assertMissing($firma1);
        $this->assertSame($sello1, $dos->sello, 'El sello no se tocó.');
        Storage::disk('public')->assertExists([$dos->firma, $sello1]);

        $this->como($user, $medico, $gineco)->post(route('clinica.formato.firma'), ['firma_eliminar' => '1', 'sello_eliminar' => '1', 'sello_posicion' => 'centro']);
        $tres = $this->formatoDe($medico);

        $this->assertNull($tres->firma);
        $this->assertNull($tres->sello);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_la_firma_rechaza_lo_que_no_es_imagen_y_la_posicion_invalida(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();

        $this->como($user, $medico, $gineco)->from(route('clinica.formato'))->post(route('clinica.formato.firma'), [
            'firma' => UploadedFile::fake()->createWithContent('firma.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
            'sello' => UploadedFile::fake()->create('sello.pdf', 10, 'application/pdf'),
            'sello_posicion' => 'arriba',
        ])->assertSessionHasErrors(['firma', 'sello', 'sello_posicion']);

        $this->assertNull($this->formatoDe($medico));
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame('Elige izquierda, centro o derecha.', session('errors')->first('sello_posicion'));
    }

    public function test_updated_at_solo_se_mueve_con_un_cambio_real_porque_es_la_marca_del_telefono(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();

        $this->como($user, $medico, $gineco)->post(route('clinica.formato.firma'), ['firma' => $this->png(), 'sello_posicion' => 'derecha']);
        $creada = $this->formatoDe($medico);

        // Un guardado sin cambios no mueve la marca.
        \Illuminate\Support\Carbon::setTestNow($this->hoy->copy()->addDay());
        $this->como($user, $medico, $gineco)->post(route('clinica.formato.firma'), ['sello_posicion' => 'derecha']);
        $this->assertSame($creada->updated_at->toIso8601String(), $this->formatoDe($medico)->updated_at->toIso8601String());

        // Una firma nueva sí: es lo que le dice al teléfono que vuelva a bajarla.
        $this->como($user, $medico, $gineco)->post(route('clinica.formato.firma'), ['firma' => $this->png('nueva.png'), 'sello_posicion' => 'derecha']);
        $despues = $this->formatoDe($medico);
        $this->assertSame($this->hoy->copy()->addDay()->toIso8601String(), $despues->updated_at->toIso8601String());
        $this->assertNotSame($creada->firma, $despues->firma, 'La URL cambia: es lo que compara el teléfono.');
    }

    // ----------------------------------------------------------------------------------- Restablecer valores

    public function test_restablecer_vuelve_cada_seccion_a_los_valores_del_legado_sin_tocar_las_imagenes(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();
        $web = $this->como($user, $medico, $gineco);

        $web->post(route('clinica.formato.estilo'), $this->estilo(['color_linea' => 'verde', 'elementos' => ['medico' => ['alineacion' => 'derecha', 'fuente' => 'tinos']]]));
        $web->post(route('clinica.formato.firma'), ['firma' => $this->png(), 'sello' => $this->png(), 'sello_posicion' => 'derecha']);
        $web->post(route('clinica.formato.logo'), ['logo' => $this->png(), 'elementos' => ['logo' => ['tamano' => 'grande', 'alineacion' => 'centro', 'borde' => '1', 'visible' => '0']]]);
        $antes = $this->formatoDe($medico);

        $web->post(route('clinica.formato.restablecer'), ['seccion' => 'textos'])->assertSessionHas('estado');
        $web->post(route('clinica.formato.restablecer'), ['seccion' => 'logo'])->assertSessionHas('estado');
        $web->post(route('clinica.formato.restablecer'), ['seccion' => 'sello'])->assertSessionHas('estado');

        $formato = $this->formatoDe($medico);
        $this->assertSame(RecipeFormato::elementosPorDefecto(), $formato->elementosResueltos());
        $this->assertNull($formato->color_linea);
        $this->assertNull($formato->sello_posicion);
        // Las imágenes no se tocan.
        $this->assertSame($antes->firma, $formato->firma);
        $this->assertSame($antes->sello, $formato->sello);
        $this->assertNotNull($this->evolucionDe($medico)->logo);
        Storage::disk('public')->assertExists([$formato->firma, $formato->sello, $this->evolucionDe($medico)->logo]);
    }

    public function test_restablecer_sin_fila_o_con_los_valores_del_legado_no_crea_ni_mueve_nada(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();

        $this->como($user, $medico, $gineco)->post(route('clinica.formato.restablecer'), ['seccion' => 'textos'])
            ->assertSessionHas('estado', fn ($mensaje) => str_contains($mensaje, 'No había nada que restablecer'));
        $this->assertNull($this->formatoDe($medico), 'No se crea una fila para restablecer.');

        $this->membrete($medico, [], ['elementos' => null]);
        $marca = $this->formatoDe($medico)->updated_at->toIso8601String();
        \Illuminate\Support\Carbon::setTestNow($this->hoy->copy()->addDay());

        $this->como($user, $medico, $gineco)->post(route('clinica.formato.restablecer'), ['seccion' => 'textos']);
        $this->assertSame($marca, $this->formatoDe($medico)->updated_at->toIso8601String());
    }

    public function test_restablecer_una_seccion_inexistente_se_rechaza(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();

        $this->como($user, $medico, $gineco)->from(route('clinica.formato'))->post(route('clinica.formato.restablecer'), ['seccion' => 'todo'])
            ->assertSessionHasErrors('seccion');
    }

    // ----------------------------------------------------------------------------------------- Multi-registro

    public function test_el_formato_se_guarda_bajo_el_registro_principal_aunque_el_consultorio_sea_de_otro_registro(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();

        // El principal es el primer registro que se le asignó (`medico_registros`); el consultorio que se elige es el de
        // `medicos.reg_medico`. Acá son distintos: el formato no se guarda bajo el del consultorio.
        $segundo = $medico->reg_medico;
        $principal = 'principal-reg-' . uniqid();
        MedicoRegistro::where('medico_id', $medico->id)->delete();
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $principal]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $segundo]);
        $this->assertSame($principal, $medico->fresh()->regMedicoPrincipal());

        $web = $this->withSession(['clinica.contexto' => ['reg_medico' => $segundo, 'specialty_id' => $gineco->id, 'office_id' => null]])->actingAs($user);

        $web->get(route('clinica.formato'))
            ->assertOk()
            ->assertSee('se guarda bajo su registro principal (' . $principal . ')', false);

        $web->post(route('clinica.formato.membrete'), ['especialidad' => 'Ginecología', 'rif' => null, 'linea_1' => null, 'linea_2' => null, 'linea_3' => null]);
        $web->post(route('clinica.formato.estilo'), $this->estilo(['color_linea' => 'azul']));

        $this->assertSame('Ginecología', Evolucion::where('reg_medico', $principal)->value('especialidad'));
        $this->assertSame('azul', RecipeFormato::where('reg_medico', $principal)->value('color_linea'));
        $this->assertNull(Evolucion::where('reg_medico', $segundo)->first());
        $this->assertNull(RecipeFormato::where('reg_medico', $segundo)->first());
    }

    // ------------------------------------------------------------------------------ La hoja que se imprime

    public function test_la_hoja_del_recipe_refleja_lo_guardado_en_la_web(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->recipe($medico, 1001, 1, 1, [['0001', 2, 'Una cápsula cada 6 horas.']], '2026-10-05');
        $web = $this->como($user, $medico, $gineco);

        $web->post(route('clinica.formato.membrete'), [
            'especialidad' => 'Medicina Interna', 'rif' => 'J-55555555-5', 'linea_1' => 'Calle del Pie 123', 'linea_2' => null, 'linea_3' => null,
        ]);
        $web->post(route('clinica.formato.logo'), ['logo' => $this->png('logo.png'), 'elementos' => ['logo' => ['tamano' => 'grande', 'alineacion' => 'derecha', 'borde' => '0', 'visible' => '1']]]);
        $web->post(route('clinica.formato.estilo'), $this->estilo(['color_linea' => 'rojo', 'elementos' => ['especialidad' => ['alineacion' => 'derecha', 'fuente' => 'tinos', 'italica' => '1']]]));
        $web->post(route('clinica.formato.firma'), ['firma' => $this->png('f.png'), 'sello' => $this->png('s.png'), 'sello_posicion' => 'derecha']);

        $evolucion = $this->evolucionDe($medico);
        $formato = $this->formatoDe($medico);

        $html = $web->get($this->urlRecipe($consulta))->assertOk()->getContent();

        $this->assertStringContainsString('Medicina Interna', $html);
        $this->assertStringContainsString('J-55555555-5', $html);
        $this->assertStringContainsString('Calle del Pie 123', $html);
        $this->assertStringContainsString('logo-grande', $html);
        $this->assertStringContainsString('logo-derecha', $html);
        $this->assertStringContainsString('linea-rojo', $html);
        $this->assertMatchesRegularExpression('/membrete-especialidad[^"]*alin-derecha[^"]*fuente-tinos[^"]*italica/', $html);
        $this->assertStringContainsString('/storage/' . $evolucion->logo, $html);
        $this->assertStringContainsString('/storage/' . $formato->firma, $html);
        $this->assertStringContainsString('sello-derecha', $html);
        $this->assertStringContainsString('/storage/' . $formato->sello, $html);
    }

    public function test_la_vista_previa_es_la_hoja_con_datos_de_ejemplo_y_el_formato_guardado(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();
        $web = $this->como($user, $medico, $gineco);
        $web->post(route('clinica.formato.membrete'), ['especialidad' => 'Endocrinología', 'rif' => 'J-7', 'linea_1' => 'Pie de ejemplo', 'linea_2' => null, 'linea_3' => null]);
        $web->post(route('clinica.formato.estilo'), $this->estilo(['color_linea' => 'fucsia', 'elementos' => ['medico' => ['alineacion' => 'izquierda', 'fuente' => 'cousine']]]));

        $filas = DB::table('recipes')->count() + DB::table('cola')->count() + DB::table('pacientes')->count();

        $completa = $web->get(route('clinica.formato.vista-previa'))->assertOk()->getContent();
        $this->assertStringContainsString('Paciente De Prueba', $completa);
        $this->assertStringContainsString('FOLIFER', $completa);
        $this->assertStringContainsString('Próxima cita:', $completa);
        $this->assertStringContainsString('Dra. Ana Imprime', $completa);
        $this->assertStringContainsString('Endocrinología', $completa);
        $this->assertStringContainsString('Pie de ejemplo', $completa);
        $this->assertStringContainsString('linea-fucsia', $completa);
        $this->assertMatchesRegularExpression('/membrete-medico[^"]*alin-izquierda[^"]*fuente-cousine/', $completa);
        $this->assertStringContainsString('barra-impresion', $completa, 'La página completa lleva su barra con «Imprimir».');
        $this->assertStringContainsString('data-imprimir', $completa);

        $incrustada = $web->get(route('clinica.formato.vista-previa', ['incrustada' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('Paciente De Prueba', $incrustada);
        $this->assertStringNotContainsString('class="barra-impresion"', $incrustada, 'Dentro del marco no hay barra.');

        $this->assertSame($filas, DB::table('recipes')->count() + DB::table('cola')->count() + DB::table('pacientes')->count(), 'La hoja de prueba no escribe nada.');
    }

    public function test_la_vista_previa_respeta_las_opciones_de_impresion_guardadas(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();
        $this->membrete($medico, [], ['modo_impresion' => RecipeFormato::MODO_SOLO_INDICACIONES, 'nombre_medicamento' => RecipeFormato::NOMBRE_GENERICO]);

        $html = $this->como($user, $medico, $gineco)->get(route('clinica.formato.vista-previa', ['incrustada' => 1]))->assertOk()->getContent();

        $this->assertStringContainsString('ÁCIDO FÓLICO', $html, 'Con el nombre genérico.');
        $this->assertStringNotContainsString('recipe-mitad-rp', $html, 'Sólo la mitad de las indicaciones.');
    }

    // -------------------------------------------------------------------------- El API del móvil, sin cambios

    public function test_el_json_del_recurso_del_formato_no_cambia_por_guardar_desde_la_web(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();
        $claves = ['color_linea', 'elementos', 'firma_url', 'sello_posicion', 'sello_url', 'updated_at'];

        // Antes: lo que devuelve el API sin nada guardado.
        $antes = (new \App\Http\Resources\RecipeFormatoResource(new RecipeFormato()))->toArray(request());
        $this->assertEqualsCanonicalizing($claves, array_keys($antes));

        $this->como($user, $medico, $gineco)->post(route('clinica.formato.estilo'), $this->estilo(['color_linea' => 'gris']));
        $this->como($user, $medico, $gineco)->post(route('clinica.formato.firma'), ['firma' => $this->png(), 'sello_posicion' => 'izquierda']);

        // Después: las mismas claves, ahora con lo que guardó la web.
        $despues = (new \App\Http\Resources\RecipeFormatoResource($this->formatoDe($medico)))->toArray(request());
        $this->assertEqualsCanonicalizing($claves, array_keys($despues));
        $this->assertSame('gris', $despues['color_linea']);
        $this->assertSame('izquierda', $despues['sello_posicion']);
        $this->assertNotNull($despues['firma_url']);
        $this->assertEqualsCanonicalizing(array_keys(RecipeFormato::elementosPorDefecto()), array_keys($despues['elementos']));
        foreach (array_keys(RecipeFormato::elementosPorDefecto()) as $elemento) {
            $this->assertEqualsCanonicalizing(array_keys($antes['elementos'][$elemento]), array_keys($despues['elementos'][$elemento]), "Las opciones de «{$elemento}» son las mismas.");
        }

        // Y la configuración del médico, igual: las mismas claves que siempre.
        $this->como($user, $medico, $gineco)->post(route('clinica.formato.membrete'), ['especialidad' => 'X', 'rif' => null, 'linea_1' => null, 'linea_2' => null, 'linea_3' => null]);
        $configuracion = (new \App\Http\Resources\ConfiguracionMedicoResource($this->evolucionDe($medico)))->toArray(request());
        $this->assertEqualsCanonicalizing(
            ['pais', 'prefijos', 'correo', 'telefono', 'especialidad', 'reg_medico', 'ciudad', 'rif', 'logo_url', 'pie_recipe', 'pie_informe', 'plantilla_cita', 'plantilla_cumple', 'estados_cita'],
            array_keys($configuracion),
        );
    }

    public function test_el_api_del_movil_sigue_funcionando_sobre_lo_que_guardo_la_web(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();
        $this->como($user, $medico, $gineco)->post(route('clinica.formato.estilo'), $this->estilo(['elementos' => ['medico' => ['fuente' => 'tinos']]]));
        $this->como($user, $medico, $gineco)->post(route('clinica.formato.membrete'), ['especialidad' => 'De la web', 'rif' => 'J-1', 'linea_1' => null, 'linea_2' => null, 'linea_3' => null]);

        Sanctum::actingAs($user, ['*'], 'api');

        // El móvil edita sólo el RIF y un elemento: lo de la web que no toca se conserva.
        $this->postJson('/api/app/configuracion', ['rif' => 'J-2'])->assertOk()->assertJsonPath('data.especialidad', 'De la web')->assertJsonPath('data.rif', 'J-2');
        $this->postJson('/api/app/configuracion/formato-recipe', ['elementos' => ['rif' => ['negrita' => false]]])
            ->assertOk()
            ->assertJsonPath('data.elementos.medico.fuente', 'tinos')
            ->assertJsonPath('data.elementos.rif.negrita', false);
    }

    public function test_lo_guardado_en_la_web_llega_al_telefono_en_la_siguiente_sincronizacion(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();
        $web = $this->como($user, $medico, $gineco);
        $web->post(route('clinica.formato.membrete'), ['especialidad' => 'Cardiología', 'rif' => 'J-3', 'linea_1' => 'Dirección', 'linea_2' => '0251-1', 'linea_3' => 'c@d.com']);
        $web->post(route('clinica.formato.logo'), ['logo' => $this->png(), 'elementos' => ['logo' => ['tamano' => 'chico', 'alineacion' => 'centro', 'borde' => '0', 'visible' => '1']]]);
        $web->post(route('clinica.formato.estilo'), $this->estilo(['color_linea' => 'verde', 'elementos' => ['pie' => ['alineacion' => 'derecha']]]));
        $web->post(route('clinica.formato.firma'), ['firma' => $this->png(), 'sello' => $this->png(), 'sello_posicion' => 'derecha']);

        $evolucion = $this->evolucionDe($medico);
        $formato = $this->formatoDe($medico);

        Sanctum::actingAs($user, ['*'], 'api');
        $sync = $this->postJson('/api/app/sync-app-data', [])->assertOk();

        $sync->assertJsonPath('configuracion.especialidad', 'Cardiología');
        $sync->assertJsonPath('configuracion.rif', 'J-3');
        $sync->assertJsonPath('configuracion.pie_recipe.direccion', 'Dirección');
        $sync->assertJsonPath('configuracion.pie_recipe.telefono', '0251-1');
        $sync->assertJsonPath('configuracion.pie_recipe.correo', 'c@d.com');
        $this->assertStringEndsWith($evolucion->logo, $sync->json('configuracion.logo_url'));
        $sync->assertJsonPath('formato_recipe.elementos.logo.tamano', 'chico');
        $sync->assertJsonPath('formato_recipe.elementos.logo.alineacion', 'centro');
        $sync->assertJsonPath('formato_recipe.elementos.pie.alineacion', 'derecha');
        $sync->assertJsonPath('formato_recipe.color_linea', 'verde');
        $sync->assertJsonPath('formato_recipe.sello_posicion', 'derecha');
        $this->assertStringEndsWith($formato->firma, $sync->json('formato_recipe.firma_url'));
        $this->assertStringEndsWith($formato->sello, $sync->json('formato_recipe.sello_url'));
        $this->assertNotNull($sync->json('formato_recipe.updated_at'));
    }

    // ----------------------------------------------------------------------------------------- Español

    public function test_todos_los_errores_de_validacion_de_los_cuatro_formularios_estan_en_espanol(): void
    {
        [$user, $medico, $gineco] = $this->medicoSinFormato();
        $web = $this->como($user, $medico, $gineco)->from(route('clinica.formato'));
        $malo = UploadedFile::fake()->create('x.pdf', 10, 'application/pdf');

        $casos = [
            [route('clinica.formato.membrete'), ['especialidad' => ['a'], 'rif' => str_repeat('x', 50), 'linea_1' => str_repeat('x', 100)]],
            [route('clinica.formato.logo'), ['logo' => $malo, 'quitar_logo' => 'x', 'elementos' => ['logo' => ['tamano' => 'x', 'alineacion' => 'x', 'borde' => 'x', 'visible' => 'x']]]],
            [route('clinica.formato.estilo'), ['elementos' => ['rif' => ['fuente' => 'x', 'alineacion' => 'x', 'negrita' => 'x', 'italica' => 'x', 'subrayado' => 'x', 'borde' => 'x', 'visible' => 'x']]]],
            [route('clinica.formato.firma'), ['firma' => $malo, 'sello' => $malo, 'firma_eliminar' => 'x', 'sello_eliminar' => 'x', 'sello_posicion' => 'x']],
            [route('clinica.formato.restablecer'), ['seccion' => 'x']],
        ];

        foreach ($casos as [$url, $datos]) {
            $web->post($url, $datos);
            $errores = session('errors');
            $this->assertNotNull($errores, "Sin errores en {$url}.");
            $this->assertNotEmpty($errores->all());
            foreach ($errores->all() as $mensaje) {
                $this->assertEspanol($mensaje);
            }
        }
    }

    private function assertEspanol(string $mensaje): void
    {
        $this->assertDoesNotMatchRegularExpression('/\b(the|must|field|selected|is invalid|may not|greater|file)\b/i', $mensaje, "Aviso en inglés: {$mensaje}");
        $this->assertNotSame('', $mensaje);
    }
}
