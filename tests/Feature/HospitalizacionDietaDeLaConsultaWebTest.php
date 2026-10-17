<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Medico;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **Hospitalización y dieta / recomendaciones de la consulta** (WEB-5.3b, las otras dos pestañas del ítem 11 «Solicitudes»): crear,
 * editar y borrar. La impresión está en `ImpresionDeHospitalizacionDietaWebTest`.
 *
 * Lo que fijan estos casos:
 *
 *  - **El panel** sigue en 10 de 13 y la sección tiene sus seis pestañas, en el orden del escritorio.
 *  - **Hospitalización**: una por consulta con tres campos (lugar, diagnóstico provisional, recomendaciones); **no se crea una fila
 *    vacía**; la fila vacía que deja el escritorio se corrige en el lugar y `indiprev`/`habitacion` no se tocan.
 *  - **Dieta**: una por consulta, libre o con las plantillas del médico (`formato_print`, tipo `DI`); `cod_dieta` y `calorias` no se tocan.
 *  - **Siempre**: rastro `source = web` por columna en `sync_changes`, last-write-wins, 404 de otro médico, 403 de la secretaría y la
 *    historia del paciente con los dos tipos nuevos.
 */
class HospitalizacionDietaDeLaConsultaWebTest extends TestCase
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

    /** @return array{0:User, 1:Medico, 2:Specialty, 3:Consulta, 4:int} */
    private function escenario(): array
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->membrete($medico);
        [$pacienteId, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);

        return [$user, $medico, $gineco, $consulta, $pacienteId];
    }

    private function como(User $user, Medico $medico, Specialty $gineco): self
    {
        return $this->conContexto($user, $medico, $gineco)->actingAs($user);
    }

    private function seccion(Consulta $consulta, string $pestana): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => 'solicitudes', 'solicitud' => $pestana]);
    }

    private function trazas(string $tabla, string $operacion, ?string $columna = null): int
    {
        return SyncChange::where('table_name', $tabla)->where('operation', $operacion)->where('source', 'web')
            ->when($columna !== null, fn ($q) => $q->where('column_name', $columna))->count();
    }

    private function orden(Medico $medico, array $extra = [], int $historia = 1001, int $consulta = 1): int
    {
        return DB::table('hospitalizacion')->insertGetId(array_merge([
            'reg_medico' => $medico->reg_medico, 'nrohistoria' => $historia, 'nroconsulta' => $consulta,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ], $extra));
    }

    private function dieta(Medico $medico, array $extra = [], int $historia = 1001, int $consulta = 1): int
    {
        return DB::table('dieta_paciente')->insertGetId(array_merge([
            'reg_medico' => $medico->reg_medico, 'histroria' => $historia, 'consulta' => $consulta,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ], $extra));
    }

    // ===================================================================================== La sección

    public function test_el_panel_sigue_en_10_de_13_y_la_seccion_ofrece_hospitalizacion_e_informe_y_dieta_en_el_orden_del_escritorio(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $this->como($user, $medico, $gineco)->get(route('clinica.consulta', $consulta->id))
            ->assertOk()
            ->assertSee('13 de 14 disponibles', false)
            ->assertSee('hospitalización, informe médico y dieta / recomendaciones');

        $html = $this->como($user, $medico, $gineco)->get($this->seccion($consulta, 'constancia'))->assertOk()->getContent();
        $this->assertLessThan(strpos($html, 'Informe médico'), strpos($html, 'Hospitalización'));
        $this->assertLessThan(strpos($html, 'Dieta / Recomendaciones'), strpos($html, 'Informe médico'));
        foreach (['hospitalizacion', 'dieta'] as $clave) {
            $this->assertStringContainsString(e($this->seccion($consulta, $clave)), $html);
        }
    }

    // ================================================================================ Hospitalización

    public function test_la_hospitalizacion_vacia_se_ve_sin_orden_y_sin_imprimir(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $this->como($user, $medico, $gineco)->get($this->seccion($consulta, 'hospitalizacion'))
            ->assertOk()
            ->assertSee('Orden de hospitalización')
            ->assertSee('Sin orden')
            ->assertDontSee('Eliminar la orden')
            ->assertDontSee(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'hospitalizacion']), false);
    }

    public function test_guardar_la_orden_crea_la_fila_con_sus_tres_campos_y_su_rastro(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.hospitalizacion.guardar', $consulta->id), [
            'hospital' => 'CLINICA SANTA MONICA',
            'diagnostico' => "EMBARAZO DE 39 SEMANAS\nCON TRABAJO DE PARTO",
            'recomendaciones' => 'Ayuno y traer exámenes.',
        ])->assertRedirect($this->seccion($consulta, 'hospitalizacion'))->assertSessionHas('estado', 'Orden de hospitalización guardada.');

        $fila = DB::table('hospitalizacion')->where('reg_medico', $medico->reg_medico)->where('nrohistoria', 1001)->where('nroconsulta', 1)->first();
        $this->assertNotNull($fila);
        $this->assertSame('CLINICA SANTA MONICA', $fila->hospital);
        $this->assertSame("EMBARAZO DE 39 SEMANAS\nCON TRABAJO DE PARTO", $fila->diagprev);
        $this->assertSame('Ayuno y traer exámenes.', $fila->dieta);
        $this->assertNull($fila->indiprev);
        $this->assertNull($fila->habitacion);
        $this->assertSame(1, $this->trazas('hospitalizacion', 'created'));

        $this->como($user, $medico, $gineco)->get($this->seccion($consulta, 'hospitalizacion'))
            ->assertSee('Con orden')
            ->assertSee('CLINICA SANTA MONICA')
            ->assertSee('Eliminar la orden')
            ->assertSee(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'hospitalizacion']), false);
    }

    public function test_una_orden_sin_ningun_texto_o_con_un_campo_pasado_de_largo_se_rechaza_y_no_crea_filas_vacias(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $url = route('clinica.consulta.hospitalizacion.guardar', $consulta->id);

        $this->como($user, $medico, $gineco)->post($url, [])->assertSessionHasErrors('hospital');
        $this->como($user, $medico, $gineco)->post($url, ['hospital' => '  ', 'diagnostico' => "\n", 'recomendaciones' => 'none'])->assertSessionHasErrors('hospital');
        $this->como($user, $medico, $gineco)->post($url, ['hospital' => str_repeat('H', 61)])->assertSessionHasErrors('hospital');
        $this->como($user, $medico, $gineco)->post($url, ['diagnostico' => str_repeat('d', 20001)])->assertSessionHasErrors('diagnostico');
        $this->como($user, $medico, $gineco)->post($url, ['hospital' => 'X', 'recomendaciones' => str_repeat('r', 20001)])->assertSessionHasErrors('recomendaciones');

        $this->assertSame(0, DB::table('hospitalizacion')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(0, SyncChange::where('table_name', 'hospitalizacion')->count());
    }

    public function test_los_errores_salen_en_espanol_y_no_borran_lo_escrito(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.hospitalizacion.guardar', $consulta->id), ['hospital' => str_repeat('H', 61), 'diagnostico' => 'Algo escrito'])
            ->assertSessionHasErrors(['hospital' => 'El lugar a hospitalizar admite hasta 60 caracteres.'])
            ->assertSessionHasInput('diagnostico', 'Algo escrito');
    }

    public function test_la_fila_vacia_del_escritorio_se_corrige_en_el_lugar_y_no_se_tocan_indiprev_ni_habitacion(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $id = $this->orden($medico, ['indiprev' => 'PREVIO', 'habitacion' => '2']);

        $this->como($user, $medico, $gineco)->get($this->seccion($consulta, 'hospitalizacion'))->assertSee('Sin orden');

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.hospitalizacion.guardar', $consulta->id), [
            'hospital' => 'HOSPITAL CENTRAL', 'diagnostico' => 'COLELITIASIS', 'recomendaciones' => '',
        ])->assertRedirect();

        $this->assertSame(1, DB::table('hospitalizacion')->where('reg_medico', $medico->reg_medico)->count());
        $fila = DB::table('hospitalizacion')->find($id);
        $this->assertSame('HOSPITAL CENTRAL', $fila->hospital);
        $this->assertSame('COLELITIASIS', $fila->diagprev);
        $this->assertNull($fila->dieta);
        $this->assertSame('PREVIO', $fila->indiprev);
        $this->assertSame('2', $fila->habitacion);
        $this->assertNotSame('2026-01-01 00:00:00', $fila->updated_at, 'el delta del móvil mira updated_at');
        $this->assertSame(0, $this->trazas('hospitalizacion', 'created'));
        $this->assertSame(2, $this->trazas('hospitalizacion', 'updated'), 'una traza por columna que cambió (la vacía no cambió)');
        $this->assertSame(1, $this->trazas('hospitalizacion', 'updated', 'hospital'));
    }

    public function test_guardar_lo_mismo_no_escribe_ni_deja_rastro(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $id = $this->orden($medico, ['hospital' => 'CLINICA A', 'diagprev' => 'DX', 'dieta' => 'REC']);

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.hospitalizacion.guardar', $consulta->id), [
            'hospital' => 'CLINICA A', 'diagnostico' => 'DX', 'recomendaciones' => 'REC',
        ])->assertSessionHas('estado', 'La orden ya estaba guardada así.');

        $this->assertSame('2026-01-01 00:00:00', DB::table('hospitalizacion')->find($id)->updated_at);
        $this->assertSame(0, SyncChange::where('table_name', 'hospitalizacion')->count());
    }

    public function test_una_edicion_mas_nueva_de_otro_equipo_no_se_pisa(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $id = $this->orden($medico, ['hospital' => 'CLINICA A', 'diagprev' => 'DX DEL ESCRITORIO']);
        SyncChange::create([
            'reg_medico' => $medico->reg_medico, 'table_name' => 'hospitalizacion', 'record_id' => $id, 'operation' => 'updated',
            'column_name' => 'diagprev', 'value' => 'DX DEL ESCRITORIO', 'occurred_at' => '2026-10-08 00:00:00', 'source' => 'escritorio',
        ]);

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.hospitalizacion.guardar', $consulta->id), [
            'hospital' => 'CLINICA B', 'diagnostico' => 'DX DE LA WEB',
        ])->assertSessionHas('error');

        $fila = DB::table('hospitalizacion')->find($id);
        $this->assertSame('DX DEL ESCRITORIO', $fila->diagprev, 'la columna con edición más nueva no se pisa');
        $this->assertSame('CLINICA B', $fila->hospital, 'las otras columnas sí se escriben');
    }

    public function test_eliminar_la_orden_borra_la_fila_y_deja_su_baja_para_el_sync(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $id = $this->orden($medico, ['hospital' => 'CLINICA A']);
        $otra = $this->orden($medico, ['hospital' => 'OTRA CONSULTA'], 1001, 2);

        $this->como($user, $medico, $gineco)->delete(route('clinica.consulta.hospitalizacion.eliminar', $consulta->id))
            ->assertRedirect($this->seccion($consulta, 'hospitalizacion'))
            ->assertSessionHas('estado', 'Orden de hospitalización eliminada.');

        $this->assertNull(DB::table('hospitalizacion')->find($id));
        $this->assertNotNull(DB::table('hospitalizacion')->find($otra), 'la de otra consulta del paciente sigue');
        $this->assertTrue(SyncChange::where('table_name', 'hospitalizacion')->where('record_id', $id)->where('operation', 'deleted')->where('source', 'web')->exists());

        $this->como($user, $medico, $gineco)->delete(route('clinica.consulta.hospitalizacion.eliminar', $consulta->id))
            ->assertSessionHas('estado', 'Esta consulta no tenía orden de hospitalización.');
    }

    public function test_el_boton_de_usar_los_diagnosticos_de_la_consulta_solo_aparece_si_hay(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $this->como($user, $medico, $gineco)->get($this->seccion($consulta, 'hospitalizacion'))
            ->assertDontSee('Usar los diagnósticos de la consulta');

        DB::table('diagnosticos')->insert(['reg_medico' => $medico->reg_medico, 'codediagnostico' => '0001', 'descripcion' => 'COLELITIASIS', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('diagnostico_paciente')->insert([
            'reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'codediagnostico' => '0001',
            'detalle_diagnostco' => 'COLELITIASIS', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Copiar es escribir en el campo: el diagnóstico guardado sigue vacío hasta el botón.
        $this->como($user, $medico, $gineco)->get($this->seccion($consulta, 'hospitalizacion'))
            ->assertSee('Usar los diagnósticos de la consulta')
            ->assertSee('data-texto="COLELITIASIS."', false);
        $this->assertSame(0, DB::table('hospitalizacion')->where('reg_medico', $medico->reg_medico)->count());
    }

    // ============================================================================================ Dieta

    public function test_la_dieta_vacia_se_ve_sin_dieta_y_sin_plantillas(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $this->como($user, $medico, $gineco)->get($this->seccion($consulta, 'dieta'))
            ->assertOk()
            ->assertSee('Sin dieta')
            ->assertSee('Todavía no tienes plantillas de dieta')
            ->assertDontSee('Eliminar la dieta');
    }

    public function test_guardar_la_dieta_libre_crea_la_fila_con_ejecutada_s_y_su_rastro(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.dieta.guardar', $consulta->id), [
            'titulo' => 'DIETA HIPOSÓDICA', 'descripcion' => "Evitar sal.\nBeber 2 litros de agua.",
        ])->assertRedirect($this->seccion($consulta, 'dieta'))->assertSessionHas('estado', 'Dieta guardada.');

        $fila = DB::table('dieta_paciente')->where('reg_medico', $medico->reg_medico)->where('histroria', 1001)->where('consulta', 1)->first();
        $this->assertNotNull($fila);
        $this->assertSame('DIETA HIPOSÓDICA', $fila->dieta);
        $this->assertSame("Evitar sal.\nBeber 2 litros de agua.", $fila->descripcion);
        $this->assertSame('S', $fila->ejecutada);
        $this->assertNull($fila->cod_dieta);
        $this->assertNull($fila->calorias);
        $this->assertSame(1, $this->trazas('dieta_paciente', 'created'));

        $this->como($user, $medico, $gineco)->get($this->seccion($consulta, 'dieta'))
            ->assertSee('Con dieta')
            ->assertSee('DIETA HIPOSÓDICA')
            ->assertSee('Evitar sal.')
            ->assertSee(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'dieta']), false);
    }

    public function test_el_titulo_es_opcional_y_el_texto_obligatorio_y_con_topes(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $url = route('clinica.consulta.dieta.guardar', $consulta->id);

        $this->como($user, $medico, $gineco)->post($url, ['titulo' => 'SOLO TITULO'])->assertSessionHasErrors('descripcion');
        $this->como($user, $medico, $gineco)->post($url, ['descripcion' => '   '])->assertSessionHasErrors('descripcion');
        $this->como($user, $medico, $gineco)->post($url, ['descripcion' => 'none'])->assertSessionHasErrors('descripcion');
        $this->como($user, $medico, $gineco)->post($url, ['descripcion' => 'x', 'titulo' => str_repeat('t', 201)])->assertSessionHasErrors('titulo');
        $this->como($user, $medico, $gineco)->post($url, ['descripcion' => str_repeat('x', 20001)])->assertSessionHasErrors('descripcion');
        $this->assertSame(0, DB::table('dieta_paciente')->where('reg_medico', $medico->reg_medico)->count());

        $this->como($user, $medico, $gineco)->post($url, ['descripcion' => 'Sin título.'])->assertSessionHasNoErrors();
        $this->assertNull(DB::table('dieta_paciente')->where('reg_medico', $medico->reg_medico)->value('dieta'));
    }

    public function test_editar_una_dieta_existente_no_toca_cod_dieta_ejecutada_ni_calorias(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $id = $this->dieta($medico, ['cod_dieta' => 'D1', 'descripcion' => 'Texto viejo', 'ejecutada' => 'S', 'calorias' => 1800, 'dieta' => 'DIETA VIEJA']);

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.dieta.guardar', $consulta->id), ['titulo' => 'DIETA NUEVA', 'descripcion' => 'Texto nuevo'])->assertRedirect();

        $this->assertSame(1, DB::table('dieta_paciente')->where('reg_medico', $medico->reg_medico)->count());
        $fila = DB::table('dieta_paciente')->find($id);
        $this->assertSame('Texto nuevo', $fila->descripcion);
        $this->assertSame('DIETA NUEVA', $fila->dieta);
        $this->assertSame('D1', $fila->cod_dieta);
        $this->assertSame('S', $fila->ejecutada);
        $this->assertSame(1800, (int) $fila->calorias);
        $this->assertSame(0, $this->trazas('dieta_paciente', 'created'));
        $this->assertSame(2, $this->trazas('dieta_paciente', 'updated'));
    }

    public function test_eliminar_la_dieta_borra_solo_la_fila_de_la_consulta_y_deja_su_baja(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $id = $this->dieta($medico, ['descripcion' => 'Texto', 'ejecutada' => 'S']);
        $otra = $this->dieta($medico, ['descripcion' => 'Otra consulta'], 1001, 2);

        $this->como($user, $medico, $gineco)->delete(route('clinica.consulta.dieta.eliminar', $consulta->id))
            ->assertRedirect($this->seccion($consulta, 'dieta'))->assertSessionHas('estado', 'Dieta eliminada.');

        $this->assertNull(DB::table('dieta_paciente')->find($id));
        $this->assertNotNull(DB::table('dieta_paciente')->find($otra));
        $this->assertTrue(SyncChange::where('table_name', 'dieta_paciente')->where('record_id', $id)->where('operation', 'deleted')->where('source', 'web')->exists());
    }

    public function test_la_pantalla_ofrece_solo_las_plantillas_de_dieta_del_medico_y_no_las_vacias(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        [, $ajeno] = $this->medicoConAcceso();
        DB::table('formato_print')->insert([
            ['reg_medico' => $medico->reg_medico, 'codigo' => 'DI001', 'titulo' => 'DIETA BLANDA', 'descripcion' => 'Caldos y purés.', 'tipo' => 'DI', 'created_at' => now(), 'updated_at' => now()],
            ['reg_medico' => $medico->reg_medico, 'codigo' => 'IM001', 'titulo' => 'ES DE INFORME', 'descripcion' => 'No debe salir.', 'tipo' => 'IM', 'created_at' => now(), 'updated_at' => now()],
            ['reg_medico' => $medico->reg_medico, 'codigo' => 'DI002', 'titulo' => 'VACIA', 'descripcion' => 'none', 'tipo' => 'DI', 'created_at' => now(), 'updated_at' => now()],
            ['reg_medico' => $ajeno->reg_medico, 'codigo' => 'DI003', 'titulo' => 'DE OTRO MEDICO', 'descripcion' => 'Tampoco.', 'tipo' => 'DI', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $pantalla = $this->como($user, $medico, $gineco)->get($this->seccion($consulta, 'dieta'));

        $pantalla->assertOk()
            ->assertSee('DIETA BLANDA')
            ->assertSee('data-plantilla-texto="Caldos y purés."', false)
            ->assertSee('data-titulo-destino="#dieta-titulo"', false)
            ->assertSee('data-plantilla-titulo="DIETA BLANDA"', false)
            ->assertDontSee('ES DE INFORME')
            ->assertDontSee('VACIA')
            ->assertDontSee('DE OTRO MEDICO');
        // Elegir una plantilla es escribir en el editor: no guarda nada.
        $this->assertSame(0, DB::table('dieta_paciente')->where('reg_medico', $medico->reg_medico)->count());
    }

    // ================================================================================== Los límites

    public function test_la_secretaria_recibe_403_en_las_pestanas_y_en_cada_accion(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $secretaria = $this->secretariaConAcceso($medico);
        $c = fn () => $this->como($secretaria, $medico, $gineco);

        $c()->get($this->seccion($consulta, 'hospitalizacion'))->assertForbidden();
        $c()->get($this->seccion($consulta, 'dieta'))->assertForbidden();
        $c()->post(route('clinica.consulta.hospitalizacion.guardar', $consulta->id), ['hospital' => 'X'])->assertForbidden();
        $c()->delete(route('clinica.consulta.hospitalizacion.eliminar', $consulta->id))->assertForbidden();
        $c()->post(route('clinica.consulta.dieta.guardar', $consulta->id), ['descripcion' => 'x'])->assertForbidden();
        $c()->delete(route('clinica.consulta.dieta.eliminar', $consulta->id))->assertForbidden();
        $c()->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'dieta']))->assertForbidden();

        $this->assertSame(0, DB::table('hospitalizacion')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(0, DB::table('dieta_paciente')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_una_consulta_de_otro_medico_da_404_y_el_invitado_va_al_login(): void
    {
        [, $medico, , $consulta] = $this->escenario();
        [$otroUser, $otroMedico, $otraGineco] = $this->medicoConAcceso();
        $c = fn () => $this->como($otroUser, $otroMedico, $otraGineco);

        $c()->get($this->seccion($consulta, 'hospitalizacion'))->assertNotFound();
        $c()->get($this->seccion($consulta, 'dieta'))->assertNotFound();
        $c()->post(route('clinica.consulta.hospitalizacion.guardar', $consulta->id), ['hospital' => 'X'])->assertNotFound();
        $c()->delete(route('clinica.consulta.hospitalizacion.eliminar', $consulta->id))->assertNotFound();
        $c()->post(route('clinica.consulta.dieta.guardar', $consulta->id), ['descripcion' => 'x'])->assertNotFound();
        $c()->delete(route('clinica.consulta.dieta.eliminar', $consulta->id))->assertNotFound();
        $c()->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'hospitalizacion']))->assertNotFound();

        $this->assertSame(0, DB::table('hospitalizacion')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(0, DB::table('dieta_paciente')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_el_invitado_va_al_login(): void
    {
        // Aparte del 404 de arriba: después de un `actingAs` el siguiente `get` del mismo test sigue autenticado (trampa 47).
        [, , , $consulta] = $this->escenario();

        $this->get($this->seccion($consulta, 'dieta'))->assertRedirect(route('login'));
        $this->get($this->seccion($consulta, 'hospitalizacion'))->assertRedirect(route('login'));
        $this->post(route('clinica.consulta.dieta.guardar', $consulta->id), [])->assertRedirect(route('login'));
        $this->post(route('clinica.consulta.hospitalizacion.guardar', $consulta->id), [])->assertRedirect(route('login'));
    }

    public function test_la_orden_del_mismo_numero_de_historia_de_otro_medico_no_se_ve_ni_se_toca(): void
    {
        // El número de historia es único por médico, no global: la orden de OTRO médico con la misma historia/consulta no es de este.
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        [, $otroMedico] = $this->medicoConAcceso();
        $ajena = $this->orden($otroMedico, ['hospital' => 'HOSPITAL AJENO', 'diagprev' => 'DX AJENO']);
        $dietaAjena = $this->dieta($otroMedico, ['descripcion' => 'DIETA AJENA']);

        $this->como($user, $medico, $gineco)->get($this->seccion($consulta, 'hospitalizacion'))->assertDontSee('HOSPITAL AJENO')->assertSee('Sin orden');
        $this->como($user, $medico, $gineco)->get($this->seccion($consulta, 'dieta'))->assertDontSee('DIETA AJENA')->assertSee('Sin dieta');

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.hospitalizacion.guardar', $consulta->id), ['hospital' => 'MIO']);
        $this->como($user, $medico, $gineco)->delete(route('clinica.consulta.dieta.eliminar', $consulta->id));

        $this->assertSame('HOSPITAL AJENO', DB::table('hospitalizacion')->find($ajena)->hospital);
        $this->assertNotNull(DB::table('dieta_paciente')->find($dietaAjena));
    }

    // ===================================================================== La historia del paciente

    public function test_la_historia_lista_la_orden_y_la_dieta_con_imprimir_solo_para_el_medico_y_no_las_filas_vacias(): void
    {
        [$user, $medico, $gineco, $consulta, $pacienteId] = $this->escenario();
        $secretaria = $this->secretariaConAcceso($medico);
        Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'nroconsulta' => 2, 'fecha' => '2026-09-01']);
        Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'nroconsulta' => 3, 'fecha' => '2026-08-01']);

        $this->orden($medico, ['hospital' => 'CLINICA DEL ESTE', 'diagprev' => 'APENDICITIS AGUDA']);
        $this->orden($medico, [], 1001, 2);                                           // la fila vacía del escritorio
        $this->orden($medico, ['hospital' => '', 'diagprev' => '  ', 'dieta' => null], 1001, 3); // y la de cadenas vacías
        $this->dieta($medico, ['dieta' => 'DIETA BLANDA', 'descripcion' => 'Caldos y purés.'], 1001, 2);
        $this->dieta($medico, ['dieta' => 'SOLO TITULO', 'descripcion' => ''], 1001, 3);

        $url = route('clinica.pacientes.historia', $pacienteId);
        $medicoVe = $this->como($user, $medico, $gineco)->get($url);
        $medicoVe->assertOk()
            ->assertSee('Hospitalización')
            ->assertSee('APENDICITIS AGUDA')
            ->assertSee('Dieta / Recomendaciones')
            ->assertSee('DIETA BLANDA')
            ->assertDontSee('SOLO TITULO');
        $this->assertSame(1, substr_count($medicoVe->getContent(), 'APENDICITIS AGUDA'), 'una sola orden: las vacías no se listan');
        $medicoVe->assertSee('href="' . route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'hospitalizacion']) . '"', false);
        $segunda = Consulta::where('reg_medico', $medico->reg_medico)->where('nroconsulta', 2)->firstOrFail();
        $medicoVe->assertSee('href="' . route('clinica.consulta.documentos.imprimir', [$segunda->id, 'documento' => 'dieta']) . '"', false);

        $secretariaVe = $this->como($secretaria, $medico, $gineco)->get($url);
        $secretariaVe->assertOk()->assertSee('APENDICITIS AGUDA');
        $secretariaVe->assertDontSee('documentos/imprimir', false);
    }

    public function test_la_historia_del_paciente_sin_orden_ni_dieta_no_cambia(): void
    {
        [$user, $medico, $gineco, , $pacienteId] = $this->escenario();
        $this->orden($medico);

        $this->como($user, $medico, $gineco)->get(route('clinica.pacientes.historia', $pacienteId))
            ->assertOk()
            ->assertDontSee('Hospitalización')
            ->assertDontSee('Dieta / Recomendaciones');
    }
}
