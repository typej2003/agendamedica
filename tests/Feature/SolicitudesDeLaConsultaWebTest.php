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
 * **Las solicitudes de la consulta** (WEB-5.3a, ítem 11 del panel): constancia, reposo, referencia e informe médico —crear,
 * editar y borrar—. La impresión está en `ImpresionDeSolicitudesWebTest`.
 *
 * Lo que fijan estos casos:
 *
 *  - **El panel** pasa a 10 de 13 y la sección tiene sus cuatro pestañas (y no las que todavía no existen).
 *  - **Constancia**: una por consulta; los diagnósticos de la consulta se traen y se pueden editar; **no se crea una fila vacía**.
 *  - **Reposo**: uno por consulta; el de un solo día sin justificación se rechaza; el cálculo de término y reintegro; la plantilla
 *    del escritorio se corrige en el lugar.
 *  - **Referencia**: el médico sale del catálogo `doctores` del médico; el motivo es de cada referencia; alta rápida del colega.
 *  - **Informe**: libre, con plantilla del médico y copiando uno anterior del mismo paciente.
 *  - **Siempre**: rastro `source = web` en `sync_changes`, last-write-wins, 404 de otro médico y 403 de la secretaría.
 */
class SolicitudesDeLaConsultaWebTest extends TestCase
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

    private function seccion(Consulta $consulta, string $pestana = 'constancia'): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => 'solicitudes', 'solicitud' => $pestana]);
    }

    private function trazas(string $tabla, string $operacion): int
    {
        return SyncChange::where('table_name', $tabla)->where('operation', $operacion)->where('source', 'web')->count();
    }

    private function diagnostico(Medico $medico, string $codigo, string $descripcion, int $historia = 1001, int $consulta = 1): void
    {
        DB::table('diagnosticos')->insert(['reg_medico' => $medico->reg_medico, 'codediagnostico' => $codigo, 'descripcion' => $descripcion, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('diagnostico_paciente')->insert([
            'reg_medico' => $medico->reg_medico, 'nrohistoria' => $historia, 'nroconsulta' => $consulta, 'codediagnostico' => $codigo,
            'detalle_diagnostco' => $descripcion, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function doctor(Medico $medico, int $cedula, string $apellidos, string $nombres, string $especialidad = '002', array $extra = []): void
    {
        DB::table('especial')->updateOrInsert(
            ['reg_medico' => $medico->reg_medico, 'codeespecial' => '002'],
            ['especialidad' => 'CARDIOLOGIA', 'created_at' => now(), 'updated_at' => now()],
        );
        DB::table('doctores')->insert(array_merge([
            'reg_medico' => $medico->reg_medico, 'cedula' => $cedula, 'apellidos' => $apellidos, 'nombres' => $nombres,
            'codeespecial' => $especialidad, 'clinica' => 'CLINICA SAN JOSE', 'direccion' => 'Av. 20 con calle 5', 'telefono' => '0251-5551111',
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    // ===================================================================================== La sección

    public function test_el_panel_queda_en_10_de_13_y_la_seccion_tiene_sus_ocho_pestanas(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $respuesta = $this->como($user, $medico, $gineco)->get(route('clinica.consulta', $consulta->id));
        $respuesta->assertOk()->assertSee('Pre-factura', false);
        $respuesta->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => 'solicitudes']), false);

        $seccion = $this->como($user, $medico, $gineco)->get($this->seccion($consulta));
        $seccion->assertOk();
        // Laboratorio y estudios llegaron con WEB-5.4 (hospitalización y dieta, con WEB-5.3b).
        foreach (['Laboratorio', 'Estudios', 'Constancia', 'Reposo', 'Referencia', 'Hospitalización', 'Informe médico', 'Dieta / Recomendaciones'] as $pestana) {
            $seccion->assertSee($pestana);
        }
        // Cada pestaña es un enlace de la misma sección.
        foreach (['laboratorio', 'estudios'] as $clave) {
            $seccion->assertSee(e($this->seccion($consulta, $clave)), false);
        }
    }

    public function test_una_pestana_que_no_existe_abre_la_constancia(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $this->como($user, $medico, $gineco)->get(route('clinica.consulta', [$consulta->id, 'seccion' => 'solicitudes', 'solicitud' => 'dietas']))
            ->assertOk()
            ->assertSee('Constancia de asistencia');
    }

    // ===================================================================================== Constancia

    public function test_la_constancia_trae_el_texto_base_y_los_diagnosticos_de_la_consulta(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->diagnostico($medico, '0001', 'HIPERTENSION ARTERIAL');
        $this->diagnostico($medico, '0002', 'DIABETES TIPO 2');

        $this->como($user, $medico, $gineco)->get($this->seccion($consulta))
            ->assertOk()
            ->assertSee('Por medio de la presente se hace constar que la (el) paciente Ana Alvarez, asistió a CONSULTA MÉDICA el día 07/10/2026.')
            ->assertSee('HIPERTENSION ARTERIAL. DIABETES TIPO 2.')
            ->assertSee('Solo el texto base');
    }

    public function test_guardar_la_observacion_crea_la_fila_con_su_rastro_y_los_diagnosticos_iguales_a_los_de_la_consulta_no_se_copian(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->diagnostico($medico, '0001', 'HIPERTENSION ARTERIAL');

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.constancia.guardar', $consulta->id), [
            'diagnosticos' => 'HIPERTENSION ARTERIAL.',
            'observacion' => 'Acudió a control.',
        ])->assertRedirect($this->seccion($consulta));

        $fila = DB::table('constancia_obs')->where('reg_medico', $medico->reg_medico)->where('numhistoria', 1001)->where('numconsulta', 1)->first();
        $this->assertNotNull($fila);
        $this->assertSame('Acudió a control.', $fila->observacion01);
        $this->assertNull($fila->observacion, 'los diagnósticos iguales a los de la consulta no se guardan: la constancia sigue a la consulta');
        $this->assertSame(1, $this->trazas('constancia_obs', 'created'));

        $this->como($user, $medico, $gineco)->get($this->seccion($consulta))
            ->assertSee('Con observación o diagnósticos propios')
            ->assertSee('Acudió a control.');
    }

    public function test_sin_nada_propio_no_se_crea_una_fila_vacia(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->diagnostico($medico, '0001', 'HIPERTENSION ARTERIAL');

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.constancia.guardar', $consulta->id), [
            'diagnosticos' => 'HIPERTENSION ARTERIAL.',
            'observacion' => '   ',
        ])->assertRedirect($this->seccion($consulta))->assertSessionHas('estado');

        $this->assertSame(0, DB::table('constancia_obs')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(0, $this->trazas('constancia_obs', 'created'));
    }

    public function test_editar_los_diagnosticos_los_guarda_aparte_y_la_constancia_deja_de_seguir_a_la_consulta(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->diagnostico($medico, '0001', 'HIPERTENSION ARTERIAL');

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.constancia.guardar', $consulta->id), [
            'diagnosticos' => 'HIPERTENSION ARTERIAL CONTROLADA.',
            'observacion' => '',
        ])->assertRedirect();

        $this->assertSame('HIPERTENSION ARTERIAL CONTROLADA.', DB::table('constancia_obs')->where('reg_medico', $medico->reg_medico)->value('observacion'));

        // Un diagnóstico nuevo en la consulta ya no entra solo en la constancia editada.
        $this->diagnostico($medico, '0002', 'DIABETES TIPO 2');
        $pantalla = $this->como($user, $medico, $gineco)->get($this->seccion($consulta));
        $pantalla->assertSee('HIPERTENSION ARTERIAL CONTROLADA.')
            ->assertSee('Editaste los diagnósticos')
            ->assertSee('Usar los diagnósticos de la consulta');
        $this->assertStringNotContainsString('DIABETES TIPO 2. </textarea', $pantalla->getContent());
    }

    public function test_la_fila_vacia_del_escritorio_se_edita_en_el_lugar_con_rastro_por_columna(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $id = DB::table('constancia_obs')->insertGetId([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'numconsulta' => 1, 'observacion' => null, 'titulo' => null,
            'observacion01' => null, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.constancia.guardar', $consulta->id), ['observacion' => 'Nota nueva'])->assertRedirect();

        $this->assertSame(1, DB::table('constancia_obs')->where('reg_medico', $medico->reg_medico)->count(), 'no se duplica la fila del escritorio');
        $this->assertSame('Nota nueva', DB::table('constancia_obs')->find($id)->observacion01);
        $this->assertNotSame('2026-01-01 00:00:00', DB::table('constancia_obs')->find($id)->updated_at, 'cambia updated_at: el móvil baja por delta');
        $this->assertTrue(SyncChange::where('table_name', 'constancia_obs')->where('record_id', $id)->where('operation', 'updated')
            ->where('column_name', 'observacion01')->where('source', 'web')->exists());
        $this->assertFalse(SyncChange::where('table_name', 'constancia_obs')->where('record_id', $id)->where('column_name', 'observacion')->exists(), 'sólo lo que cambió');
    }

    public function test_quitar_lo_propio_deja_la_fila_como_la_deja_el_escritorio(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $id = DB::table('constancia_obs')->insertGetId([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'numconsulta' => 1, 'observacion' => 'EDITADO', 'observacion01' => 'OBS',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->como($user, $medico, $gineco)->delete(route('clinica.consulta.constancia.eliminar', $consulta->id))->assertRedirect();

        $fila = DB::table('constancia_obs')->find($id);
        $this->assertNotNull($fila);
        $this->assertNull($fila->observacion);
        $this->assertNull($fila->observacion01);
    }

    public function test_una_edicion_mas_nueva_del_sync_no_se_pisa(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $id = DB::table('constancia_obs')->insertGetId([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'numconsulta' => 1, 'observacion01' => 'DEL MOVIL',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        SyncChange::create([
            'reg_medico' => $medico->reg_medico, 'table_name' => 'constancia_obs', 'record_id' => $id, 'operation' => 'updated',
            'column_name' => 'observacion01', 'value' => 'DEL MOVIL', 'occurred_at' => now()->addHour(), 'source' => 'mobile',
        ]);

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.constancia.guardar', $consulta->id), ['observacion' => 'DE LA WEB'])
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame('DEL MOVIL', DB::table('constancia_obs')->find($id)->observacion01);
    }

    // ======================================================================================== Reposo

    public function test_guardar_un_reposo_lo_crea_y_la_pantalla_calcula_el_ultimo_dia_y_el_reintegro(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.reposo.guardar', $consulta->id), [
            'dias' => 8, 'desde' => '2026-10-10', 'observacion' => 'Reposo por infección.',
        ])->assertRedirect($this->seccion($consulta, 'reposo'));

        $fila = DB::table('reposo_paciente')->where('reg_medico', $medico->reg_medico)->where('nrohistoria', 1001)->where('nroconsulta', 1)->first();
        $this->assertNotNull($fila);
        $this->assertSame('M', $fila->codereposo);
        $this->assertSame(8, (int) $fila->numdias);
        $this->assertSame('2026-10-10', substr($fila->fdesde, 0, 10));
        $this->assertSame(1, $this->trazas('reposo_paciente', 'created'));

        // Inicio + 8 − 1 = 17/10 (el día de inicio cuenta) y reintegro el 18/10.
        $this->como($user, $medico, $gineco)->get($this->seccion($consulta, 'reposo'))
            ->assertOk()
            ->assertSee('17/10/2026')
            ->assertSee('18/10/2026')
            ->assertSee('Con reposo');
    }

    public function test_un_reposo_de_un_solo_dia_sin_justificacion_se_rechaza_y_con_justificacion_se_guarda(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $url = route('clinica.consulta.reposo.guardar', $consulta->id);

        $this->como($user, $medico, $gineco)->post($url, ['dias' => 1, 'desde' => '2026-10-07', 'observacion' => ''])
            ->assertSessionHasErrors('observacion');
        $this->assertSame(0, DB::table('reposo_paciente')->where('reg_medico', $medico->reg_medico)->count());

        $this->como($user, $medico, $gineco)->post($url, ['dias' => 1, 'desde' => '2026-10-07', 'observacion' => 'Reposo relativo de 24 horas.'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('reposo_paciente')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_la_plantilla_de_un_dia_del_escritorio_no_es_un_reposo_y_se_corrige_en_el_lugar(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $id = DB::table('reposo_paciente')->insertGetId([
            'reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'codereposo' => 'M', 'fdesde' => '2026-10-07',
            'numdias' => 1, 'obser_reposo' => null, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);

        $this->como($user, $medico, $gineco)->get($this->seccion($consulta, 'reposo'))
            ->assertOk()->assertSee('Sin reposo')->assertDontSee('Eliminar el reposo');

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.reposo.guardar', $consulta->id), [
            'dias' => 3, 'desde' => '2026-10-08', 'observacion' => '',
        ])->assertRedirect();

        $this->assertSame(1, DB::table('reposo_paciente')->where('reg_medico', $medico->reg_medico)->count(), 'se corrige la fila del escritorio, no se agrega otra');
        $fila = DB::table('reposo_paciente')->find($id);
        $this->assertSame(3, (int) $fila->numdias);
        $this->assertSame('M', $fila->codereposo);
        $this->assertSame(0, $this->trazas('reposo_paciente', 'created'), 'no es una fila nueva');
        foreach (['numdias', 'fdesde'] as $columna) {
            $this->assertTrue(SyncChange::where('table_name', 'reposo_paciente')->where('record_id', $id)->where('column_name', $columna)->where('source', 'web')->exists(), $columna);
        }
        $this->assertFalse(SyncChange::where('table_name', 'reposo_paciente')->where('record_id', $id)->where('column_name', 'codereposo')->exists());
    }

    public function test_un_reposo_puede_empezar_en_el_futuro(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.reposo.guardar', $consulta->id), [
            'dias' => 5, 'desde' => '2026-12-30', 'observacion' => '',
        ])->assertSessionHasNoErrors();

        // 30/12 + 5 − 1 = 03/01 y reintegro el 04/01: la cuenta cruza de año y la fecha futura no se descarta.
        $this->como($user, $medico, $gineco)->get($this->seccion($consulta, 'reposo'))
            ->assertSee('value="2026-12-30"', false)
            ->assertSee('03/01/2027')
            ->assertSee('04/01/2027');
    }

    public function test_el_reposo_valida_los_dias_y_la_fecha_en_espanol(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $url = route('clinica.consulta.reposo.guardar', $consulta->id);

        foreach ([
            [['dias' => 0, 'desde' => '2026-10-07'], 'dias', 'al menos 1 día'],
            [['dias' => 400, 'desde' => '2026-10-07'], 'dias', 'no puede pasar de 365'],
            [['dias' => 'x', 'desde' => '2026-10-07'], 'dias', 'número entero'],
            [['desde' => '2026-10-07'], 'dias', 'Indica cuántos días'],
            [['dias' => 2, 'desde' => 'ayer'], 'desde', 'no es válida'],
            [['dias' => 2], 'desde', 'Indica desde qué día'],
        ] as [$datos, $campo, $texto]) {
            $r = $this->como($user, $medico, $gineco)->from($this->seccion($consulta, 'reposo'))->post($url, $datos);
            $r->assertSessionHasErrors($campo);
            $this->assertStringContainsString($texto, $r->getSession()->get('errors')->first($campo));
        }
        $this->assertSame(0, DB::table('reposo_paciente')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_eliminar_el_reposo_borra_la_fila_y_deja_su_baja_para_el_movil(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $id = DB::table('reposo_paciente')->insertGetId([
            'reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'codereposo' => 'M', 'fdesde' => '2026-10-07',
            'numdias' => 4, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->como($user, $medico, $gineco)->delete(route('clinica.consulta.reposo.eliminar', $consulta->id))->assertRedirect($this->seccion($consulta, 'reposo'));

        $this->assertNull(DB::table('reposo_paciente')->find($id));
        $this->assertTrue(SyncChange::where('table_name', 'reposo_paciente')->where('record_id', $id)->where('operation', 'deleted')->where('source', 'web')->exists());
    }

    // ===================================================================================== Referencias

    public function test_el_buscador_ofrece_los_medicos_del_catalogo_sin_distinguir_tildes_y_sin_los_de_otro_medico(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->doctor($medico, 7, 'JAQUEZ', 'MARÍA');
        $this->doctor($medico, 8, 'ROJAS', 'PEDRO');
        [, $otro] = $this->medicoConAcceso();
        $this->doctor($otro, 9, 'JAQUEZ', 'OTRA');

        $json = $this->como($user, $medico, $gineco)->getJson(route('clinica.consulta.referencias.buscar', $consulta->id) . '?q=maria')->assertOk()->json();

        $this->assertSame('maria', $json['buscar']);
        $this->assertCount(1, $json['medicos']);
        $this->assertSame(7, $json['medicos'][0]['cedula']);
        $this->assertSame('MARÍA JAQUEZ', $json['medicos'][0]['nombre']);
        $this->assertSame('CARDIOLOGIA', $json['medicos'][0]['especialidad']);
        $this->assertFalse($json['medicos'][0]['yaEsta']);

        $todos = $this->como($user, $medico, $gineco)->getJson(route('clinica.consulta.referencias.buscar', $consulta->id) . '?q=cardio')->json('medicos');
        $this->assertSame([7, 8], array_column($todos, 'cedula'), 'se busca también por especialidad; el catálogo de otro médico no aparece');
    }

    public function test_referir_crea_la_fila_sin_motivo_no_se_duplica_y_exige_que_el_medico_este_en_el_catalogo(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->doctor($medico, 7, 'PEREZ', 'LUIS');
        [, $otro] = $this->medicoConAcceso();
        $this->doctor($otro, 9, 'AJENO', 'MEDICO');
        $url = route('clinica.consulta.referencias.agregar', $consulta->id);

        $this->como($user, $medico, $gineco)->post($url, ['cedula' => 7])->assertRedirect();
        $this->como($user, $medico, $gineco)->post($url, ['cedula' => 7])->assertRedirect();

        $filas = DB::table('referencia')->where('reg_medico', $medico->reg_medico)->where('nrohistoria', 1001)->where('nroconsulta', 1)->get();
        $this->assertCount(1, $filas);
        $this->assertNull($filas[0]->referencia);
        $this->assertSame(1, $this->trazas('referencia', 'created'));

        // Un médico que no es del catálogo propio no se refiere.
        $this->como($user, $medico, $gineco)->post($url, ['cedula' => 9])->assertSessionHas('error');
        $this->assertSame(1, DB::table('referencia')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_el_motivo_es_de_cada_referencia_y_la_pantalla_muestra_los_datos_del_medico(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->doctor($medico, 7, 'PEREZ', 'LUIS');
        $this->doctor($medico, 8, 'ROJAS', 'PEDRO', '002', ['clinica' => 'CLINICA EL PRADO']);

        foreach ([7, 8] as $cedula) {
            $this->como($user, $medico, $gineco)->post(route('clinica.consulta.referencias.agregar', $consulta->id), ['cedula' => $cedula]);
        }
        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.referencias.guardar', [$consulta->id, 7]), ['motivo' => 'Valoración cardiológica.'])->assertRedirect();
        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.referencias.guardar', [$consulta->id, 8]), ['motivo' => 'Control de la tensión.'])->assertRedirect();

        $motivos = DB::table('referencia')->where('reg_medico', $medico->reg_medico)->pluck('referencia', 'ceduladoctor')->all();
        $this->assertSame('Valoración cardiológica.', $motivos[7], 'cada referencia guarda su motivo (el escritorio pisaba el de todas)');
        $this->assertSame('Control de la tensión.', $motivos[8]);

        $this->como($user, $medico, $gineco)->get($this->seccion($consulta, 'referencias'))
            ->assertOk()
            ->assertSee('LUIS PEREZ')
            ->assertSee('PEDRO ROJAS')
            ->assertSee('CLINICA EL PRADO')
            ->assertSee('Valoración cardiológica.')
            ->assertSee('2 referencias');
    }

    public function test_quitar_una_referencia_borra_solo_esa_y_no_toca_el_catalogo(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->doctor($medico, 7, 'PEREZ', 'LUIS');
        $this->doctor($medico, 8, 'ROJAS', 'PEDRO');
        foreach ([7, 8] as $cedula) {
            $this->como($user, $medico, $gineco)->post(route('clinica.consulta.referencias.agregar', $consulta->id), ['cedula' => $cedula]);
        }
        $quitada = DB::table('referencia')->where('reg_medico', $medico->reg_medico)->where('ceduladoctor', 7)->value('id');

        $this->como($user, $medico, $gineco)->delete(route('clinica.consulta.referencias.eliminar', [$consulta->id, 7]))->assertRedirect();

        $this->assertSame([8], DB::table('referencia')->where('reg_medico', $medico->reg_medico)->pluck('ceduladoctor')->map(fn ($c) => (int) $c)->all());
        $this->assertTrue(SyncChange::where('table_name', 'referencia')->where('record_id', $quitada)->where('operation', 'deleted')->where('source', 'web')->exists());
        $this->assertSame(2, DB::table('doctores')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_el_alta_rapida_crea_el_medico_con_el_siguiente_codigo_lo_refiere_y_no_duplica(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->doctor($medico, 12, 'PEREZ', 'LUIS');
        $url = route('clinica.consulta.referencias.medico-nuevo', $consulta->id);

        $this->como($user, $medico, $gineco)->post($url, [
            'apellidos' => 'diaz', 'nombres' => 'carla', 'codeespecial' => '002', 'clinica' => 'Centro Clínico Norte', 'telefono' => '0414-1112233',
        ])->assertRedirect();

        $nuevo = DB::table('doctores')->where('reg_medico', $medico->reg_medico)->where('apellidos', 'DIAZ')->first();
        $this->assertNotNull($nuevo);
        $this->assertSame(13, (int) $nuevo->cedula, 'el correlativo del escritorio: el mayor + 1');
        $this->assertSame('CARLA', $nuevo->nombres);
        $this->assertSame('002', $nuevo->codeespecial);
        $this->assertSame(13, (int) DB::table('referencia')->where('reg_medico', $medico->reg_medico)->value('ceduladoctor'));

        // Repetirlo no crea otro médico: refiere al mismo.
        $this->como($user, $medico, $gineco)->post($url, ['apellidos' => 'Díaz', 'nombres' => 'Carla', 'codeespecial' => '002']);
        $this->assertSame(1, DB::table('doctores')->where('reg_medico', $medico->reg_medico)->where('apellidos', 'DIAZ')->count());
        $this->assertSame(1, DB::table('referencia')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_el_alta_rapida_valida_el_nombre_y_la_especialidad_y_reabre_el_dialogo(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->doctor($medico, 12, 'PEREZ', 'LUIS');
        $url = route('clinica.consulta.referencias.medico-nuevo', $consulta->id);

        $this->como($user, $medico, $gineco)->post($url, ['codeespecial' => '002'])
            ->assertSessionHasErrors('nombres')->assertSessionHas('crear_medico');
        $this->como($user, $medico, $gineco)->post($url, ['apellidos' => 'DIAZ', 'codeespecial' => '999'])
            ->assertSessionHasErrors('nombres');
        $this->como($user, $medico, $gineco)->post($url, ['apellidos' => 'DIAZ'])
            ->assertSessionHasErrors('codeespecial');

        $this->assertSame(1, DB::table('doctores')->where('reg_medico', $medico->reg_medico)->count());
    }

    // ========================================================================================= Informe

    public function test_el_informe_libre_se_guarda_con_su_para_su_fecha_y_su_rastro(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.informe.guardar', $consulta->id), [
            'para' => 'A QUIEN CORRESPONDA', 'descripcion' => "Paciente en control.\nSin hallazgos.", 'fecha' => '2026-10-07',
        ])->assertRedirect($this->seccion($consulta, 'informe'));

        $fila = DB::table('informe')->where('reg_medico', $medico->reg_medico)->where('nrohistoria', 1001)->where('nroconsulta', 1)->first();
        $this->assertNotNull($fila);
        $this->assertSame('A QUIEN CORRESPONDA', $fila->para);
        $this->assertSame("Paciente en control.\nSin hallazgos.", $fila->descripcion);
        $this->assertSame('2026-10-07', substr($fila->fe_cha, 0, 10));
        $this->assertSame(1, $this->trazas('informe', 'created'));
    }

    public function test_el_informe_sin_texto_o_con_el_punto_de_la_plantilla_se_rechaza(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $url = route('clinica.consulta.informe.guardar', $consulta->id);

        $this->como($user, $medico, $gineco)->post($url, ['descripcion' => ''])->assertSessionHasErrors('descripcion');
        $this->como($user, $medico, $gineco)->post($url, ['descripcion' => ' . '])->assertSessionHasErrors('descripcion');
        $this->como($user, $medico, $gineco)->post($url, ['descripcion' => 'x', 'para' => str_repeat('a', 51)])->assertSessionHasErrors('para');
        $this->assertSame(0, DB::table('informe')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_la_plantilla_del_escritorio_se_corrige_en_el_lugar_y_eliminar_borra_la_fila(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $id = DB::table('informe')->insertGetId([
            'reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'para' => 'INFORME MÉDICO', 'descripcion' => '.',
            'fe_cha' => '2026-10-07', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);

        $this->como($user, $medico, $gineco)->get($this->seccion($consulta, 'informe'))->assertSee('Sin informe');

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.informe.guardar', $consulta->id), ['para' => 'INFORME MÉDICO', 'descripcion' => 'Texto real', 'fecha' => '2026-10-07']);
        $this->assertSame(1, DB::table('informe')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame('Texto real', DB::table('informe')->find($id)->descripcion);
        $this->assertSame(0, $this->trazas('informe', 'created'));

        $this->como($user, $medico, $gineco)->delete(route('clinica.consulta.informe.eliminar', $consulta->id))->assertRedirect();
        $this->assertNull(DB::table('informe')->find($id));
        $this->assertTrue(SyncChange::where('table_name', 'informe')->where('record_id', $id)->where('operation', 'deleted')->exists());
    }

    public function test_la_pantalla_ofrece_las_plantillas_del_medico_y_los_informes_anteriores_del_mismo_paciente(): void
    {
        [$user, $medico, $gineco, $consulta, $pacienteId] = $this->escenario();
        DB::table('formato_print')->insert([
            ['reg_medico' => $medico->reg_medico, 'codigo' => 'IM01', 'titulo' => 'INFORME DE CONTROL', 'descripcion' => 'Paciente en control prenatal.', 'tipo' => 'IM', 'created_at' => now(), 'updated_at' => now()],
            ['reg_medico' => $medico->reg_medico, 'codigo' => 'EV01', 'titulo' => 'ES DE EVOLUCION', 'descripcion' => 'No debe salir.', 'tipo' => 'EV', 'created_at' => now(), 'updated_at' => now()],
            ['reg_medico' => $medico->reg_medico, 'codigo' => 'IM02', 'titulo' => 'VACIA', 'descripcion' => 'none', 'tipo' => 'IM', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Otra consulta del mismo paciente con un informe, una con la plantilla vacía y un informe de OTRO paciente.
        Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'nroconsulta' => 2, 'fecha' => '2026-09-01']);
        Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'nroconsulta' => 3, 'fecha' => '2026-09-15']);
        DB::table('informe')->insert([
            ['reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 2, 'para' => 'EL COLEGIO', 'descripcion' => 'Informe anterior del paciente.', 'fe_cha' => '2026-09-01', 'created_at' => now(), 'updated_at' => now()],
            ['reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 3, 'para' => 'X', 'descripcion' => '.', 'fe_cha' => '2026-09-15', 'created_at' => now(), 'updated_at' => now()],
            ['reg_medico' => $medico->reg_medico, 'nrohistoria' => 2002, 'nroconsulta' => 1, 'para' => 'X', 'descripcion' => 'Informe de otro paciente.', 'fe_cha' => '2026-09-01', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $pantalla = $this->como($user, $medico, $gineco)->get($this->seccion($consulta, 'informe'));

        $pantalla->assertOk()
            ->assertSee('INFORME DE CONTROL')
            ->assertSee('data-plantilla-texto="Paciente en control prenatal."', false)
            ->assertSee('Informe anterior del paciente.')
            ->assertSee('data-para="EL COLEGIO"', false)
            ->assertDontSee('ES DE EVOLUCION')
            ->assertDontSee('VACIA')
            ->assertDontSee('Informe de otro paciente.');
        // Una sola entrada de informe anterior: la plantilla `.` de la consulta 3 no cuenta.
        $this->assertSame(1, substr_count($pantalla->getContent(), 'data-copiar-para="#informe-para"'));
    }

    // ================================================================================== Los límites

    public function test_la_secretaria_recibe_403_en_la_seccion_y_en_cada_accion(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $secretaria = $this->secretariaConAcceso($medico);
        $this->doctor($medico, 7, 'PEREZ', 'LUIS');
        $c = fn () => $this->como($secretaria, $medico, $gineco);

        $c()->get($this->seccion($consulta))->assertForbidden();
        $c()->post(route('clinica.consulta.constancia.guardar', $consulta->id), ['observacion' => 'x'])->assertForbidden();
        $c()->delete(route('clinica.consulta.constancia.eliminar', $consulta->id))->assertForbidden();
        $c()->post(route('clinica.consulta.reposo.guardar', $consulta->id), ['dias' => 3, 'desde' => '2026-10-07'])->assertForbidden();
        $c()->delete(route('clinica.consulta.reposo.eliminar', $consulta->id))->assertForbidden();
        $c()->getJson(route('clinica.consulta.referencias.buscar', $consulta->id))->assertForbidden();
        $c()->post(route('clinica.consulta.referencias.agregar', $consulta->id), ['cedula' => 7])->assertForbidden();
        $c()->post(route('clinica.consulta.referencias.guardar', [$consulta->id, 7]), ['motivo' => 'x'])->assertForbidden();
        $c()->delete(route('clinica.consulta.referencias.eliminar', [$consulta->id, 7]))->assertForbidden();
        $c()->post(route('clinica.consulta.referencias.medico-nuevo', $consulta->id), ['apellidos' => 'X', 'codeespecial' => '002'])->assertForbidden();
        $c()->post(route('clinica.consulta.informe.guardar', $consulta->id), ['descripcion' => 'x'])->assertForbidden();
        $c()->delete(route('clinica.consulta.informe.eliminar', $consulta->id))->assertForbidden();
        $c()->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'constancia']))->assertForbidden();

        foreach (['constancia_obs', 'reposo_paciente', 'referencia', 'informe'] as $tabla) {
            $this->assertSame(0, DB::table($tabla)->where('reg_medico', $medico->reg_medico)->count(), $tabla);
        }
    }

    public function test_una_consulta_de_otro_medico_da_404_y_el_invitado_va_al_login(): void
    {
        [, $medico, , $consulta] = $this->escenario();
        [$otroUser, $otroMedico, $otraGineco] = $this->medicoConAcceso();
        $c = fn () => $this->como($otroUser, $otroMedico, $otraGineco);

        $c()->get($this->seccion($consulta))->assertNotFound();
        $c()->post(route('clinica.consulta.constancia.guardar', $consulta->id), ['observacion' => 'x'])->assertNotFound();
        $c()->post(route('clinica.consulta.reposo.guardar', $consulta->id), ['dias' => 3, 'desde' => '2026-10-07'])->assertNotFound();
        $c()->post(route('clinica.consulta.referencias.agregar', $consulta->id), ['cedula' => 7])->assertNotFound();
        $c()->post(route('clinica.consulta.informe.guardar', $consulta->id), ['descripcion' => 'x'])->assertNotFound();
        $c()->delete(route('clinica.consulta.reposo.eliminar', $consulta->id))->assertNotFound();
        $c()->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'constancia']))->assertNotFound();

        $this->assertSame(0, DB::table('constancia_obs')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_el_invitado_va_al_login(): void
    {
        [, , , $consulta] = $this->escenario();

        $this->get($this->seccion($consulta))->assertRedirect(route('login'));
        $this->post(route('clinica.consulta.reposo.guardar', $consulta->id), [])->assertRedirect(route('login'));
    }

    // ===================================================================== La historia del paciente

    public function test_la_historia_lista_los_documentos_con_imprimir_solo_para_el_medico(): void
    {
        [$user, $medico, $gineco, $consulta, $pacienteId] = $this->escenario();
        $secretaria = $this->secretariaConAcceso($medico);
        DB::table('constancia_obs')->insert(['reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'numconsulta' => 1, 'observacion01' => 'Constancia propia', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('reposo_paciente')->insert(['reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'codereposo' => 'M', 'fdesde' => '2026-10-07', 'numdias' => 4, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('informe')->insert(['reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'descripcion' => 'Informe real', 'fe_cha' => '2026-10-07', 'created_at' => now(), 'updated_at' => now()]);

        $url = route('clinica.pacientes.historia', $pacienteId);
        $medicoVe = $this->como($user, $medico, $gineco)->get($url);
        $medicoVe->assertOk()->assertSee('Constancia propia')->assertSee('Informe real');
        foreach (['constancia', 'reposo', 'informe'] as $clave) {
            $medicoVe->assertSee('href="' . route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => $clave]) . '"', false);
        }

        $secretariaVe = $this->como($secretaria, $medico, $gineco)->get($url);
        $secretariaVe->assertOk()->assertSee('Constancia propia');
        $secretariaVe->assertDontSee('documentos/imprimir', false);
    }
}
