<?php

namespace Tests\Feature;

use App\Clinica\Catalogos\TiposDePlantillaDeTexto;
use App\Models\Consulta;
use App\Models\FormatoPrint;
use App\Models\Medico;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **Plantillas de texto del médico en la web** (WEB-8.4): `formato_print` en `/clinica/catalogos/plantillas-texto`, la ventana «Plantillas»
 * (`w_agregar_dietas`) del escritorio.
 *
 * Lo que estos casos fijan:
 *
 *  - **Quién entra**: el médico del contexto; la secretaría recibe 403 y no ve la entrada del menú; el invitado va al login; la
 *    plantilla de otro médico responde 404.
 *  - **El listado**: buscador sin tildes (título, texto, código o tipo), filtro por tipo con su cuenta y los tipos del legado que la
 *    web no conoce, que se muestran tal cual.
 *  - **El alta**: el código lo da el servidor (correlativo de cuatro dígitos por médico, ignorando los códigos no numéricos), el título
 *    va en mayúsculas, no se duplica un título dentro del mismo tipo y deja su `created` en `sync_changes` (`source = web`).
 *  - **La edición**: sólo lo que cambió, con el last-write-wins por columna; el código no se edita.
 *  - **Quitar**: siempre se puede, con su `deleted`; **lo ya escrito en las consultas no cambia** (la plantilla se copia, no se enlaza).
 *  - **La consulta las ofrece al instante** (es la misma tabla) en la evolución, el informe y las dietas.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class PlantillasDeTextoWebTest extends TestCase
{
    use DatabaseTransactions;
    use PreparaRecipesParaImprimir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fijarHoy();
    }

    protected function tearDown(): void
    {
        $this->soltarHoy();

        parent::tearDown();
    }

    // --------------------------------------------------------------------------------------- Ayudas

    private function como(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->conContexto($user, $medico, $specialty)->actingAs($user);
    }

    private function avanzar(int $segundos = 60): void
    {
        Carbon::setTestNow(Carbon::now()->addSeconds($segundos));
    }

    private function plantilla(Medico $medico, string $codigo, string $tipo, string $titulo, ?string $texto = 'TEXTO'): FormatoPrint
    {
        return FormatoPrint::create(['reg_medico' => $medico->reg_medico, 'codigo' => $codigo, 'tipo' => $tipo, 'titulo' => $titulo, 'descripcion' => $texto]);
    }

    private function datos(array $extra = []): array
    {
        return array_merge(['tipo' => 'EV', 'titulo' => 'Control prenatal', 'descripcion' => "Paciente en control.\r\nSin novedades."], $extra);
    }

    /** El atributo con que cada sección de la consulta lleva el texto de la plantilla al botón que lo copia. */
    private function atributoDelTexto(string $tipo, string $texto): string
    {
        return 'data-plantilla-texto="' . $texto . '"';
    }

    private function filas(Medico $medico)
    {
        return FormatoPrint::where('reg_medico', $medico->reg_medico)->orderBy('id')->get();
    }

    private function rastros(FormatoPrint $fila)
    {
        return SyncChange::where('table_name', 'formato_print')->where('record_id', $fila->id)->where('source', 'web');
    }

    // -------------------------------------------------------------------------------------- Quién entra

    public function test_el_medico_ve_la_pantalla_vacia_con_su_menu(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $respuesta = $this->como($user, $medico, $gineco)->get(route('clinica.catalogos.plantillas-texto'));

        $respuesta->assertOk();
        $respuesta->assertSee('Plantillas de texto');
        $respuesta->assertSee('Todavía no tienes plantillas de texto');
        $respuesta->assertSee('Nueva plantilla');
        $respuesta->assertSee('Se ofrecen en la consulta de la web', false);
        $respuesta->assertSee('Otros formatos del escritorio', false);
        // La entrada está en el grupo «Catálogos» del menú lateral.
        $this->como($user, $medico, $gineco)->get(route('clinica.inicio'))->assertSee(route('clinica.catalogos.plantillas-texto'), false);
    }

    public function test_la_secretaria_no_entra_ni_ve_la_entrada_y_el_invitado_va_al_login(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = $this->secretariaConAcceso($medico);
        $propia = $this->plantilla($medico, '0001', 'EV', 'DE PRUEBA');

        $web = $this->como($secretaria, $medico, $gineco);
        $web->get(route('clinica.catalogos.plantillas-texto'))->assertForbidden();
        $web->post(route('clinica.catalogos.plantillas-texto.guardar'), $this->datos())->assertForbidden();
        $web->delete(route('clinica.catalogos.plantillas-texto.quitar', $propia->id))->assertForbidden();
        $this->assertSame(1, $this->filas($medico)->count());

        // A-12: la secretaría entra a la agenda, no al inicio del médico.
        $this->como($secretaria, $medico, $gineco)->get(route('clinica.agenda'))->assertOk()->assertDontSee(route('clinica.catalogos.plantillas-texto'), false);

        auth()->logout();
        $this->flushSession();
        $this->get(route('clinica.catalogos.plantillas-texto'))->assertRedirect('/login');
        $this->post(route('clinica.catalogos.plantillas-texto.guardar'), $this->datos())->assertRedirect('/login');
    }

    public function test_la_plantilla_de_otro_medico_es_404_y_no_aparece(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $ajena = $this->plantilla($otro, '0001', 'EV', 'AJENA');
        $this->plantilla($medico, '0001', 'EV', 'PROPIA');

        $web = $this->como($user, $medico, $gineco);
        $web->get(route('clinica.catalogos.plantillas-texto', ['editar' => $ajena->id]))->assertNotFound();
        $web->post(route('clinica.catalogos.plantillas-texto.guardar'), $this->datos(['id' => $ajena->id]))->assertNotFound();
        $web->delete(route('clinica.catalogos.plantillas-texto.quitar', $ajena->id))->assertNotFound();

        $web->get(route('clinica.catalogos.plantillas-texto'))->assertOk()->assertSee('PROPIA')->assertDontSee('AJENA');
        $this->assertSame('AJENA', $ajena->fresh()->titulo);
    }

    // --------------------------------------------------------------------------------------- El alta

    public function test_el_alta_asigna_el_codigo_correlativo_en_mayusculas_y_deja_su_rastro(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        // Los códigos no numéricos del legado no cuentan; el correlativo sigue al mayor numérico.
        $this->plantilla($medico, 'W530', 'IM', 'RARA');
        $this->plantilla($medico, '0006', 'IM', 'SEIS');
        $web = $this->como($user, $medico, $gineco);

        $web->post(route('clinica.catalogos.plantillas-texto.guardar'), $this->datos())
            ->assertRedirect(route('clinica.catalogos.plantillas-texto', ['filtro' => 'EV']))
            ->assertSessionHas('estado');

        $creada = FormatoPrint::where('reg_medico', $medico->reg_medico)->where('titulo', 'CONTROL PRENATAL')->firstOrFail();
        $this->assertSame('0007', $creada->codigo);
        $this->assertSame('EV', $creada->tipo);
        $this->assertSame("Paciente en control.\r\nSin novedades.", $creada->descripcion, 'El texto se guarda como lo escribe el navegador, igual que el escritorio.');
        $this->assertSame(1, $this->rastros($creada)->where('operation', 'created')->count());

        $web->post(route('clinica.catalogos.plantillas-texto.guardar'), $this->datos(['titulo' => 'Otra', 'tipo' => 'DI']));
        $this->assertSame('0008', FormatoPrint::where('reg_medico', $medico->reg_medico)->where('titulo', 'OTRA')->value('codigo'));
    }

    public function test_el_primer_codigo_de_un_medico_es_0001_y_cada_medico_lleva_su_cuenta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $this->plantilla($otro, '0042', 'EV', 'DEL OTRO');

        $this->como($user, $medico, $gineco)->post(route('clinica.catalogos.plantillas-texto.guardar'), $this->datos());

        $this->assertSame('0001', $this->filas($medico)->first()->codigo);
    }

    public function test_no_se_duplica_un_titulo_dentro_del_mismo_tipo_pero_si_en_otro(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->plantilla($medico, '0001', 'EV', 'DIETA DIABÉTICA');
        $web = $this->como($user, $medico, $gineco)->from(route('clinica.catalogos.plantillas-texto'));

        $web->post(route('clinica.catalogos.plantillas-texto.guardar'), $this->datos(['titulo' => '  dieta   diabetica ']))
            ->assertSessionHasErrors(['titulo']);
        $this->assertStringContainsString('Ya tienes «DIETA DIABÉTICA» en ese tipo (código 0001)', session('errors')->first('titulo'));
        $this->assertSame(1, $this->filas($medico)->count());

        $web->post(route('clinica.catalogos.plantillas-texto.guardar'), $this->datos(['titulo' => 'Dieta diabetica', 'tipo' => 'DI']))->assertSessionHasNoErrors();
        $this->assertSame(2, $this->filas($medico)->count());
    }

    public function test_el_alta_valida_en_espanol_y_no_pierde_lo_escrito(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $web = $this->como($user, $medico, $gineco)->from(route('clinica.catalogos.plantillas-texto'));

        $casos = [
            [['tipo' => ''], 'tipo', 'Elige el tipo de plantilla.'],
            [['tipo' => 'ZZ'], 'tipo', 'Elige el tipo de plantilla.'],
            [['titulo' => ''], 'titulo', 'Escribe el título de la plantilla.'],
            [['titulo' => str_repeat('a', 201)], 'titulo', 'El título no puede pasar de 200 caracteres.'],
            [['descripcion' => ''], 'descripcion', 'Escribe el texto de la plantilla: sin texto no se ofrece en la consulta.'],
            [['descripcion' => str_repeat('a', 20001)], 'descripcion', 'El texto no puede pasar de 20000 caracteres.'],
        ];

        foreach ($casos as [$cambio, $campo, $mensaje]) {
            $web->post(route('clinica.catalogos.plantillas-texto.guardar'), $this->datos($cambio))->assertSessionHasErrors([$campo => $mensaje]);
        }

        $this->assertSame(0, $this->filas($medico)->count());

        // Lo escrito vuelve al diálogo, que se abre solo.
        $web->withSession(['_old_input' => ['_catalogo' => 'plantilla-texto', 'tipo' => 'EV', 'titulo' => 'Mi título', 'descripcion' => 'Mi texto']])
            ->get(route('clinica.catalogos.plantillas-texto'))
            ->assertSee('value="Mi título"', false)
            ->assertSee('Mi texto');
    }

    // ------------------------------------------------------------------------------------- El listado

    public function test_el_buscador_ignora_tildes_y_busca_en_titulo_texto_codigo_y_tipo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->plantilla($medico, '0001', 'EV', 'CONTROL PRENATAL', 'Embarazo sin complicaciones');
        $this->plantilla($medico, '0002', 'IM', 'ALTA MÉDICA', 'Egreso con indicación de reposo');
        $this->plantilla($medico, '0003', 'DI', 'DIETA BLANDA', 'Evitar irritantes');
        $web = $this->como($user, $medico, $gineco);

        $web->get(route('clinica.catalogos.plantillas-texto', ['buscar' => 'medica']))->assertSee('ALTA MÉDICA')->assertDontSee('DIETA BLANDA');
        $web->get(route('clinica.catalogos.plantillas-texto', ['buscar' => 'indicacion reposo']))->assertSee('ALTA MÉDICA')->assertDontSee('CONTROL PRENATAL');
        $web->get(route('clinica.catalogos.plantillas-texto', ['buscar' => '0003']))->assertSee('DIETA BLANDA')->assertDontSee('ALTA MÉDICA');
        $web->get(route('clinica.catalogos.plantillas-texto', ['buscar' => 'informe']))->assertSee('ALTA MÉDICA')->assertDontSee('CONTROL PRENATAL');
        $web->get(route('clinica.catalogos.plantillas-texto', ['buscar' => 'inexistente']))->assertSee('Ninguna plantilla coincide');
    }

    public function test_el_filtro_por_tipo_cuenta_y_muestra_los_tipos_del_legado_tal_cual(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->plantilla($medico, '0001', 'EV', 'UNA EVOLUCION');
        $this->plantilla($medico, '0002', 'EV', 'OTRA EVOLUCION');
        $this->plantilla($medico, '0003', 'IM', 'UN INFORME');
        $this->plantilla($medico, '0004', 'ZZ', 'DEL LEGADO');
        $this->plantilla($medico, '0005', 'CO', 'COLPOSCOPIA TIPO');
        $web = $this->como($user, $medico, $gineco);

        $todas = $web->get(route('clinica.catalogos.plantillas-texto'));
        $todas->assertSee('Evolución (2)')->assertSee('Informe médico (1)')->assertSee('Dietas / Recomendaciones (0)')
            ->assertSee('Tipo ZZ (1)')->assertSee('Colposcopía (1)');

        $web->get(route('clinica.catalogos.plantillas-texto', ['filtro' => 'EV']))
            ->assertSee('UNA EVOLUCION')->assertSee('OTRA EVOLUCION')->assertDontSee('UN INFORME')->assertDontSee('DEL LEGADO');
        $web->get(route('clinica.catalogos.plantillas-texto', ['filtro' => 'ZZ']))->assertSee('DEL LEGADO')->assertDontSee('UNA EVOLUCION');

        // Un filtro de un tipo que el médico no tiene vale como «todos».
        $web->get(route('clinica.catalogos.plantillas-texto', ['filtro' => 'QQ']))->assertSee('UNA EVOLUCION')->assertSee('DEL LEGADO');
    }

    public function test_la_lista_se_pagina_de_a_25_y_avisa_las_que_no_tienen_texto(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        for ($i = 1; $i <= 26; $i++) {
            $this->plantilla($medico, str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'EV', 'PLANTILLA ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), $i === 26 ? null : 'texto');
        }
        $web = $this->como($user, $medico, $gineco);

        $web->get(route('clinica.catalogos.plantillas-texto'))->assertSee('Mostrando 1–25 de 26')->assertDontSee('PLANTILLA 26');
        $web->get(route('clinica.catalogos.plantillas-texto', ['page' => 2]))->assertSee('PLANTILLA 26')->assertSee('Sin texto: la consulta no la ofrece');
    }

    // ------------------------------------------------------------------------------------- La edición

    public function test_la_edicion_escribe_solo_lo_que_cambio_con_su_rastro_y_no_toca_el_codigo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $fila = $this->plantilla($medico, '0009', 'EV', 'CONTROL', "Linea uno\r\nLinea dos");
        $web = $this->como($user, $medico, $gineco);

        // Los mismos renglones con otro salto de línea no son un cambio.
        $web->post(route('clinica.catalogos.plantillas-texto.guardar'), ['id' => $fila->id, 'tipo' => 'EV', 'titulo' => 'Control', 'descripcion' => "Linea uno\nLinea dos"])
            ->assertSessionHas('estado', '«CONTROL» no tenía cambios.');
        $this->assertSame(0, $this->rastros($fila)->count());

        $this->avanzar();
        $web->post(route('clinica.catalogos.plantillas-texto.guardar'), ['id' => $fila->id, 'tipo' => 'IM', 'titulo' => 'Control', 'descripcion' => "Linea uno\r\nLinea dos"])
            ->assertSessionHas('estado');

        $fila->refresh();
        $this->assertSame('IM', $fila->tipo);
        $this->assertSame('CONTROL', $fila->titulo);
        $this->assertSame("Linea uno\r\nLinea dos", $fila->descripcion, 'El texto no cambió: no se reescribe.');
        $this->assertSame('0009', $fila->codigo, 'El código no se edita.');
        $this->assertSame(['tipo'], $this->rastros($fila)->where('operation', 'updated')->pluck('column_name')->all());
    }

    public function test_editar_el_titulo_y_el_texto_deja_un_rastro_por_columna_y_mueve_updated_at(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $fila = $this->plantilla($medico, '0001', 'EV', 'VIEJO', 'texto viejo');
        $antes = $fila->fresh()->updated_at;

        $this->avanzar(3600);
        $this->como($user, $medico, $gineco)->post(route('clinica.catalogos.plantillas-texto.guardar'), ['id' => $fila->id, 'tipo' => 'EV', 'titulo' => 'nuevo', 'descripcion' => 'texto nuevo']);

        $fila->refresh();
        $this->assertSame('NUEVO', $fila->titulo);
        $this->assertSame('texto nuevo', $fila->descripcion);
        $this->assertEqualsCanonicalizing(['titulo', 'descripcion'], $this->rastros($fila)->pluck('column_name')->all());
        $this->assertTrue($fila->updated_at->gt($antes));
    }

    public function test_editar_no_puede_dejar_el_titulo_de_otra_del_mismo_tipo_pero_un_duplicado_historico_se_edita(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $a = $this->plantilla($medico, '0001', 'EV', 'UNA');
        $this->plantilla($medico, '0002', 'EV', 'OTRA');
        $web = $this->como($user, $medico, $gineco)->from(route('clinica.catalogos.plantillas-texto'));

        $web->post(route('clinica.catalogos.plantillas-texto.guardar'), ['id' => $a->id, 'tipo' => 'EV', 'titulo' => 'otra', 'descripcion' => 'x'])
            ->assertSessionHasErrors(['titulo']);
        $this->assertSame('UNA', $a->fresh()->titulo);

        // Dos con el mismo título y tipo (así vienen del legado): se puede seguir editando el texto de una.
        $x = $this->plantilla($medico, '0003', 'DI', 'IGUAL');
        $this->plantilla($medico, '0004', 'DI', 'IGUAL');
        $web->post(route('clinica.catalogos.plantillas-texto.guardar'), ['id' => $x->id, 'tipo' => 'DI', 'titulo' => 'IGUAL', 'descripcion' => 'texto editado'])
            ->assertSessionHasNoErrors();
        $this->assertSame('texto editado', $x->fresh()->descripcion);
    }

    public function test_un_tipo_del_legado_se_conserva_al_editar_pero_no_se_acepta_para_una_nueva(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $fila = $this->plantilla($medico, '0001', 'ZZ', 'DEL LEGADO', 'texto');
        $web = $this->como($user, $medico, $gineco)->from(route('clinica.catalogos.plantillas-texto'));

        $web->get(route('clinica.catalogos.plantillas-texto', ['editar' => $fila->id]))->assertOk()->assertSee('Tipo ZZ (no está en la lista)');
        $web->post(route('clinica.catalogos.plantillas-texto.guardar'), ['id' => $fila->id, 'tipo' => 'ZZ', 'titulo' => 'Del legado', 'descripcion' => 'texto nuevo'])->assertSessionHasNoErrors();
        $this->assertSame('ZZ', $fila->fresh()->tipo);

        $web->post(route('clinica.catalogos.plantillas-texto.guardar'), $this->datos(['tipo' => 'QQ']))->assertSessionHasErrors('tipo');
    }

    public function test_una_edicion_mas_nueva_de_otro_equipo_no_se_pisa(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $fila = $this->plantilla($medico, '0001', 'EV', 'TITULO', 'texto');
        SyncChange::create([
            'reg_medico' => $medico->reg_medico, 'table_name' => 'formato_print', 'record_id' => $fila->id, 'operation' => 'updated',
            'column_name' => 'titulo', 'value' => 'DEL ESCRITORIO', 'occurred_at' => Carbon::now()->addHour(), 'source' => 'escritorio',
        ]);

        $this->como($user, $medico, $gineco)->post(route('clinica.catalogos.plantillas-texto.guardar'), ['id' => $fila->id, 'tipo' => 'EV', 'titulo' => 'De la web', 'descripcion' => 'texto'])
            ->assertSessionHas('error');
        $this->assertSame('TITULO', $fila->fresh()->titulo);
    }

    // ------------------------------------------------------------------------------------------ Quitar

    public function test_quitar_siempre_se_puede_deja_su_rastro_y_no_cambia_lo_ya_escrito(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $consulta->update(['evolucion' => 'Texto de la plantilla ya copiado a la evolución']);
        $fila = $this->plantilla($medico, '0001', 'EV', 'MI PLANTILLA', 'Texto de la plantilla ya copiado a la evolución');

        $this->como($user, $medico, $gineco)->delete(route('clinica.catalogos.plantillas-texto.quitar', $fila->id), ['filtro' => 'EV'])
            ->assertRedirect(route('clinica.catalogos.plantillas-texto', ['filtro' => 'EV']))
            ->assertSessionHas('estado');

        $this->assertNull(FormatoPrint::find($fila->id));
        $this->assertSame(1, $this->rastros($fila)->where('operation', 'deleted')->count());
        $this->assertSame('Texto de la plantilla ya copiado a la evolución', Consulta::find($consulta->id)->evolucion, 'Lo ya escrito no cambia.');
    }

    // --------------------------------------------------------------------- La consulta las ofrece al instante

    public function test_la_consulta_ofrece_la_plantilla_nueva_o_editada_en_evolucion_informe_y_dieta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $web = $this->como($user, $medico, $gineco);

        $secciones = [
            'EV' => route('clinica.consulta', [$consulta->id, 'seccion' => 'evolucion']),
            'IM' => route('clinica.consulta', [$consulta->id, 'seccion' => 'solicitudes', 'solicitud' => 'informe']),
            'DI' => route('clinica.consulta', [$consulta->id, 'seccion' => 'solicitudes', 'solicitud' => 'dieta']),
        ];

        foreach ($secciones as $tipo => $url) {
            $web->get($url)->assertOk()->assertDontSee('WEB84 ' . $tipo);
        }

        foreach ($secciones as $tipo => $url) {
            $web->post(route('clinica.catalogos.plantillas-texto.guardar'), $this->datos(['tipo' => $tipo, 'titulo' => 'web84 ' . $tipo, 'descripcion' => 'Cuerpo ' . $tipo . ' de la plantilla']));
            $web->get($url)->assertOk()->assertSee('WEB84 ' . $tipo)->assertSee($this->atributoDelTexto($tipo, 'Cuerpo ' . $tipo . ' de la plantilla'), false);
        }

        // Editada: la consulta ofrece el texto nuevo.
        $evolucion = FormatoPrint::where('reg_medico', $medico->reg_medico)->where('tipo', 'EV')->firstOrFail();
        $web->post(route('clinica.catalogos.plantillas-texto.guardar'), ['id' => $evolucion->id, 'tipo' => 'EV', 'titulo' => 'web84 EV', 'descripcion' => 'Texto corregido']);
        $web->get($secciones['EV'])->assertSee($this->atributoDelTexto('EV', 'Texto corregido'), false)->assertDontSee('Cuerpo EV de la plantilla');

        // Quitada: ya no se ofrece.
        $web->delete(route('clinica.catalogos.plantillas-texto.quitar', $evolucion->id))->assertRedirect();
        // (El aviso de «Quitaste…» sale en la pantalla siguiente: se mide por el botón de la plantilla, no por el título suelto.)
        $web->get($secciones['EV'])->assertDontSee('data-plantilla-titulo="WEB84 EV"', false);
    }

    // ----------------------------------------------- Los estudios ecográficos (A-14, revisión 2026-10-17)

    /**
     * Las plantillas de las ecografías se podían crear pero **no se ofrecían en ninguna pantalla**: el pedido de
     * una médica que probó la web fue «plantillas listas para ecografías». Acá se fija que los siete tipos del
     * ítem 5 entran en los «usados en la web», que el catálogo dice **en qué pantalla** se ofrece cada uno y que
     * los dos que la web no tiene (el eco de cuello y el renal) siguen sin ofrecerse.
     */
    public function test_los_tipos_de_eco_se_ofrecen_en_la_web_y_el_catalogo_dice_en_que_pantalla(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        // El escritorio tiene nueve códigos de ecografía (`d_a_formatos.srd`); la web ofrece los siete del ítem 5.
        $ofrecidos = [
            'UG' => 'eco ginecológico',
            'O1' => '1.er trimestre',
            'O2' => '2.º trimestre',
            'O3' => '3.er trimestre',
            'ED' => 'Doppler',
            'UA' => 'abdominal',
            'UM' => 'mamario',
        ];

        foreach ($ofrecidos as $tipo => $pantalla) {
            $this->assertTrue(TiposDePlantillaDeTexto::seUsaEnLaWeb($tipo), 'El tipo ' . $tipo . ' se ofrece en la web.');
            $this->assertStringContainsString('estudio ecográfico', (string) TiposDePlantillaDeTexto::dondeSeUsa($tipo));
            $this->assertStringContainsString($pantalla, (string) TiposDePlantillaDeTexto::dondeSeUsa($tipo));
        }

        // El eco de cuello y el renal son del escritorio: la web no tiene su pantalla, así que no se ofrecen.
        foreach (['EM', 'ER'] as $tipo) {
            $this->assertFalse(TiposDePlantillaDeTexto::seUsaEnLaWeb($tipo));
            $this->assertNull(TiposDePlantillaDeTexto::dondeSeUsa($tipo));
        }

        $this->plantilla($medico, '0001', 'UG', 'ECO PELVICO NORMAL', 'Útero en anteversión, ovarios normales.');
        $this->plantilla($medico, '0002', 'EM', 'ECO DE CUELLO', 'Un texto del escritorio.');

        $web = $this->como($user, $medico, $gineco);

        $web->get(route('clinica.catalogos.plantillas-texto'))
            ->assertOk()
            // El tipo ya está en el filtro con su cuenta (los tipos usados en la web se listan aunque tengan 0).
            ->assertSee('Eco ginecológico (1)')
            // Y la lista dice dónde se ofrece; el que la web no tiene lo dice también.
            ->assertSee('Se ofrece en el estudio ecográfico, en el eco ginecológico.')
            ->assertSee('Todavía no se ofrece en la consulta de la web.');

        // El filtro por tipo (el parámetro `filtro` del catálogo) llega desde el cajón del estudio.
        $web->get(route('clinica.catalogos.plantillas-texto', ['filtro' => 'UG']))
            ->assertOk()
            ->assertSee('ECO PELVICO NORMAL')
            ->assertDontSee('ECO DE CUELLO');
    }

    /**
     * **El cajón de cada estudio** ofrece las plantillas de **su** tipo y no las de otro, y las copia al campo
     * libre del informe de ese estudio (el que la pantalla edita).
     */
    public function test_el_cajon_de_cada_estudio_ofrece_las_plantillas_de_su_tipo_y_no_las_de_otro(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        // El tipo de `formato_print` de cada estudio y la columna a la que se copia la plantilla. Salen de la
        // ventana del escritorio de cada uno (`retrieve('<tipo>')` de su `dw_76`): ver `PlantillasDelEstudio`.
        $estudios = [
            'ginecologico' => ['UG', 'observacion'],
            'obstetrico' => ['O1', 'observacion'],
            'segundo' => ['O2', 'observacion'],
            'tercero' => ['O3', 'observacion'],
            'doppler' => ['ED', 'texto'],
            'mamario' => ['UM', 'conclusion'],
            'abdominal' => ['UA', 'conclusiones'],
        ];

        foreach ($estudios as [$tipo, $columna]) {
            $this->plantilla($medico, str_pad((string) (array_search($tipo, array_column($estudios, 0), true) + 1), 4, '0', STR_PAD_LEFT), $tipo, 'PLANTILLA ' . $tipo, 'Texto de ' . $tipo);
        }

        $web = $this->como($user, $medico, $gineco);

        foreach ($estudios as $estudio => [$tipo, $columna]) {
            $respuesta = $web->get(route('clinica.consulta', [$consulta->id, 'seccion' => 'estudio', 'tipo' => $estudio]));
            $respuesta->assertOk();
            $html = $respuesta->getContent();

            $this->assertStringContainsString('data-plantilla-titulo="PLANTILLA ' . $tipo . '"', $html, 'El estudio ' . $estudio . ' ofrece sus plantillas.');
            $this->assertStringContainsString('data-destino="#' . $columna . '"', $html, 'El estudio ' . $estudio . ' copia la plantilla a ' . $columna . '.');
            $this->assertStringContainsString(route('clinica.catalogos.plantillas-texto', ['filtro' => $tipo]), $html, 'El estudio ' . $estudio . ' administra las de su tipo.');

            foreach ($estudios as $otroEstudio => [$otroTipo]) {
                if ($otroTipo === $tipo) {
                    continue;
                }

                $this->assertStringNotContainsString('data-plantilla-titulo="PLANTILLA ' . $otroTipo . '"', $html, 'El estudio ' . $estudio . ' no ofrece las de ' . $otroTipo . '.');
            }
        }
    }

    /**
     * **«Administrar plantillas»** al pie del cajón, en **todas** las secciones que lo usan: abre el catálogo
     * filtrado por el tipo de esa sección (el descubrimiento que a la médica le faltaba).
     */
    public function test_el_cajon_lleva_a_administrar_las_plantillas_filtradas_por_su_tipo_en_todas_las_secciones(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $secciones = [
            'EV' => ['seccion' => 'evolucion'],
            'EA' => ['seccion' => 'enfermedad'],
            'RF' => ['seccion' => 'evaluacion', 'pestana' => 'fisico'],
            'EG' => ['seccion' => 'evaluacion', 'pestana' => 'ginecologico'],
            'CO' => ['seccion' => 'evaluacion', 'pestana' => 'colposcopia'],
            'TR' => ['seccion' => 'tratamiento'],
            'IM' => ['seccion' => 'solicitudes', 'solicitud' => 'informe'],
            'DI' => ['seccion' => 'solicitudes', 'solicitud' => 'dieta'],
            'UG' => ['seccion' => 'estudio', 'tipo' => 'ginecologico'],
        ];

        $web = $this->como($user, $medico, $gineco);

        foreach ($secciones as $tipo => $parametros) {
            $html = $web->get(route('clinica.consulta', array_merge([$consulta->id], $parametros)))
                ->assertOk()
                ->getContent();

            $this->assertStringContainsString('Administrar plantillas', $html, 'La sección ' . $tipo . ' enlaza al catálogo.');
            $this->assertStringContainsString(route('clinica.catalogos.plantillas-texto', ['filtro' => $tipo]), $html, 'La sección ' . $tipo . ' filtra por su tipo.');
        }
    }

    /**
     * El componente acepta **una lista de tipos** (el caso del estudio que en el escritorio comparte plantillas
     * entre dos): el cajón lista las de todos y el desplegable elige con cuál se guarda la nueva. Hoy cada
     * estudio de la web tiene un solo tipo, así que el desplegable no se ve en las pantallas.
     */
    public function test_el_componente_acepta_una_lista_de_tipos_y_elige_con_cual_se_guarda(): void
    {
        $vista = $this->blade(
            '<x-ui.plantillas :plantillas="$lista" destino="#observacion" :tipos="$tipos" nombre="eco obstétrico" />',
            [
                'lista' => [
                    ['titulo' => 'DEL SEGUNDO', 'texto' => 'Texto del segundo.'],
                    ['titulo' => 'DEL TERCERO', 'texto' => 'Texto del tercero.'],
                ],
                'tipos' => ['O2' => 'Obstétrico 2', 'O3' => 'Obstétrico 3'],
            ]
        );

        $vista->assertSee('data-plantilla-titulo="DEL SEGUNDO"', false);
        $vista->assertSee('data-plantilla-titulo="DEL TERCERO"', false);
        // El desplegable para elegir con qué tipo se guarda, con el primero adelante.
        $vista->assertSee('data-plantillas-tipo', false);
        $vista->assertSee('<option value="O2">Obstétrico 2</option>', false);
        $vista->assertSee('<option value="O3">Obstétrico 3</option>', false);
        $vista->assertSee('data-tipo="O2"', false);
        // Y el enlace al catálogo, filtrado por el primero (el JS lo sigue si se cambia el tipo).
        $vista->assertSee(route('clinica.catalogos.plantillas-texto', ['filtro' => 'O2']), false);
    }

    // ------------------------------------------------- Las plantillas de la especialidad (2026-10-18)

    /**
     * **El ABM ofrece los tipos de la especialidad del contexto** (revisión del usuario, 2026-10-18): pediatría no necesita —ni ve—
     * las plantillas de colposcopía ni las de los ecos ginecológicos y obstétricos. Lo que se fija:
     *
     *  - la lista, el filtro por tipo y el `<select>` del diálogo sólo traen los tipos de la especialidad;
     *  - ginecología, con las mismas filas, las sigue viendo todas (no cambia);
     *  - un tipo del legado que el catálogo no conoce no se oculta nunca;
     *  - editar por URL directa una plantilla de otra especialidad **sí** abre su diálogo y conserva su tipo (no se pierde el
     *    dato), igual que con un tipo del legado.
     */
    public function test_en_pediatria_el_catalogo_no_ofrece_las_plantillas_ginecologicas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $pediatria = Specialty::firstOrCreate(['slug' => 'pediatria'], ['name' => 'Pediatría']);
        // El contexto resuelve la especialidad entre las del médico: para mirar la pantalla como pediatra hay que tenerla. El
        // médico queda con las dos, que es justo el caso que importa: un mismo `formato_print` y dos contextos.
        $medico->specialties()->syncWithoutDetaching([$pediatria->id]);

        $evolucion = $this->plantilla($medico, '0001', 'EV', 'DE EVOLUCION');
        $colposcopia = $this->plantilla($medico, '0002', 'CO', 'DE COLPOSCOPIA');
        $this->plantilla($medico, '0003', 'UG', 'DE ECO GINECOLOGICO');
        $resumen = $this->plantilla($medico, '0004', 'RF', 'DE RESUMEN FISICO');
        $this->plantilla($medico, '0005', 'UA', 'DE ECO ABDOMINAL');
        $this->plantilla($medico, '0006', 'ZZ', 'DEL LEGADO');

        // Ginecología no cambia: las ve todas y el filtro las cuenta todas.
        $ginecologo = $this->como($user, $medico, $gineco)->get(route('clinica.catalogos.plantillas-texto'))->assertOk();
        $ginecologo->assertSee('DE COLPOSCOPIA')->assertSee('DE ECO GINECOLOGICO')->assertSee('6 plantillas');
        $ginecologo->assertSee('<option value="CO"', false)->assertSee('<option value="UG"', false);

        // Pediatría: sólo las de sus secciones (y las del legado, que no se esconden). Desde el 2026-10-19 tampoco
        // ofrece la del eco abdominal: la especialidad no tiene el ítem del estudio ecográfico.
        $pediatra = $this->como($user, $medico, $pediatria)->get(route('clinica.catalogos.plantillas-texto'))->assertOk();

        $pediatra->assertSee('DE EVOLUCION')->assertSee('DE RESUMEN FISICO')->assertSee('DEL LEGADO');
        $pediatra->assertDontSee('DE COLPOSCOPIA')->assertDontSee('DE ECO GINECOLOGICO')->assertDontSee('DE ECO ABDOMINAL');
        // Ni el listado ni el filtro ni el diálogo las ofrecen (las dos listas de opciones usan el mismo `<option value…>`).
        $pediatra->assertDontSee('<option value="CO"', false)->assertDontSee('<option value="UG"', false);
        $pediatra->assertSee('<option value="RF"', false)->assertDontSee('<option value="UA"', false);
        // El subtítulo cuenta sólo las que la pantalla muestra: EV, RF y la del legado.
        $pediatra->assertSee('3 plantillas');

        // Editar por URL directa una plantilla de otra especialidad la abre y conserva su tipo, para no perder el dato.
        $edicion = $this->como($user, $medico, $pediatria)
            ->get(route('clinica.catalogos.plantillas-texto', ['editar' => $colposcopia->id]))
            ->assertOk();

        $edicion->assertSee('DE COLPOSCOPIA');
        $edicion->assertSee('No se ofrece en esta especialidad');
        $edicion->assertSee('<option value="CO" selected', false);

        // Y una de las suyas se edita como siempre (el caso de control: la pantalla no escondió lo propio).
        $this->como($user, $medico, $pediatria)
            ->get(route('clinica.catalogos.plantillas-texto', ['editar' => $evolucion->id]))
            ->assertOk()
            ->assertSee('DE EVOLUCION')
            ->assertSee('<option value="EV" selected', false);
    }
}
