<?php

namespace Tests\Feature;

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
        $this->plantilla($medico, 'W5301', 'IM', 'RARA');
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
}
