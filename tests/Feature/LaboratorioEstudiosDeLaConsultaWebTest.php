<?php

namespace Tests\Feature;

use App\Clinica\Consulta\CatalogoParaPedirExamenes;
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
 * **Laboratorio y estudios de la consulta** (WEB-5.4, las dos pestañas nuevas del ítem 11 «Solicitudes»): armar, editar y borrar la orden
 * de exámenes de laboratorio y la solicitud de estudios. La impresión está en `ImpresionDeLaboratorioEstudiosWebTest`.
 *
 * Lo que fijan estos casos:
 *
 *  - **Laboratorio**: la orden se guarda **entera** (lo marcado es lo que queda): crea los que faltan, borra los que sobran y deja en paz
 *    los que ya estaban (su `resultado`/`vinculante` no se tocan); un examen no se repite; sólo se piden exámenes del catálogo de
 *    laboratorio (sin el tipo `002`); la observación («Resumen clínico») es una fila de `examen_obs`; una **rutina** es un tipo de examen
 *    completo y no repite lo que ya está; la sección *Exámenes* de WEB-4.7 ve lo mismo.
 *  - **Estudios**: lo mismo con `radiologia_paciente` (cuyo `coderadio` es un examen de tipo `002`), `nroopcion = 1` y `orden` numerado.
 *  - **Siempre**: no se crean filas vacías, rastro `source = web` por fila y por columna en `sync_changes`, last-write-wins, 404 de otro
 *    médico, 403 de la secretaría y la historia del paciente con los dos tipos nuevos.
 */
class LaboratorioEstudiosDeLaConsultaWebTest extends TestCase
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
    private function escenario(bool $conCatalogo = true): array
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->membrete($medico);
        [$pacienteId, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);

        if ($conCatalogo) {
            $this->catalogo($medico);
        }

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

    /** El catálogo del médico: tres tipos (el 002 es el de estudios de imágenes) y exámenes de laboratorio y de estudios. */
    private function catalogo(Medico $medico): void
    {
        foreach ([['001', 'LABORATORIO'], ['004', 'PREOPERATORIOS'], ['002', 'ESTUDIO DE IMAGENES']] as [$codigo, $tipo]) {
            DB::table('tipos_examenes')->insert(['reg_medico' => $medico->reg_medico, 'codetipo' => $codigo, 'tipo' => $tipo, 'created_at' => now(), 'updated_at' => now()]);
        }

        foreach ([
            ['001', 'GLICEMIA', '001'], ['002', 'HEMATOLOGÍA COMPLETA', '001'], ['003', 'UREA', '001'], ['005', 'Glicemia', '001'],
            ['010', 'ELECTROCARDIOGRAMA', '004'], ['011', 'TIEMPO DE PROTROMBINA', '004'],
            ['099', 'DENSITOMETRIA OSEA', '002'], ['100', 'MAMOGRAFIA', '002'], ['114', 'ECOGRAFIA MAMARIA', '002'],
        ] as [$codigo, $nombre, $tipo]) {
            DB::table('examenes')->insert(['reg_medico' => $medico->reg_medico, 'codeexamen' => $codigo, 'examen' => $nombre, 'codetipo' => $tipo, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function examen(Medico $medico, string $codigo, array $extra = [], int $historia = 1001, int $consulta = 1): int
    {
        return DB::table('examen_paciente')->insertGetId(array_merge([
            'reg_medico' => $medico->reg_medico, 'nrohistoria' => $historia, 'nroconsulta' => $consulta, 'codeexamen' => $codigo,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ], $extra));
    }

    private function observacionDeExamenes(Medico $medico, ?string $texto, int $historia = 1001, int $consulta = 1): int
    {
        return DB::table('examen_obs')->insertGetId([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => $historia, 'numconsulta' => $consulta, 'observacion' => $texto,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    private function estudio(Medico $medico, string $codigo, array $extra = [], int $historia = 1001, int $consulta = 1): int
    {
        return DB::table('radiologia_paciente')->insertGetId(array_merge([
            'reg_medico' => $medico->reg_medico, 'nrohistoria' => $historia, 'nroconsulta' => $consulta, 'coderadio' => $codigo, 'nroopcion' => 1,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ], $extra));
    }

    private function observacionDeEstudios(Medico $medico, ?string $texto, int $historia = 1001, int $consulta = 1): int
    {
        return DB::table('radiologia_obs')->insertGetId([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => $historia, 'numconsulta' => $consulta, 'observacion' => $texto,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    private function codigosDe(Medico $medico, string $tabla = 'examen_paciente', string $columna = 'codeexamen'): array
    {
        return DB::table($tabla)->where('reg_medico', $medico->reg_medico)->orderBy('id')->pluck($columna)->map(fn ($c) => trim((string) $c))->all();
    }

    // ===================================================================================== La sección

    public function test_el_panel_sigue_en_10_de_13_con_su_tarjeta_actualizada_y_laboratorio_abre_vacio(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $this->como($user, $medico, $gineco)->get(route('clinica.consulta', $consulta->id))
            ->assertOk()
            ->assertSee('11 de 13 disponibles', false)
            ->assertSee('Laboratorio, estudios, constancia, reposo, referencia, hospitalización, informe médico y dieta / recomendaciones.')
            ->assertDontSee('llegan después');

        $this->como($user, $medico, $gineco)->get($this->seccion($consulta, 'laboratorio'))
            ->assertOk()
            ->assertSee('Orden de exámenes de laboratorio')
            ->assertSee('Sin orden')
            ->assertSee('La orden no tiene exámenes.')
            ->assertSee('Todavía no hay exámenes en la orden')
            ->assertDontSee('Eliminar la orden')
            ->assertDontSee(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'laboratorio']), false)
            // Las rutinas: los tipos con exámenes, sin el de estudios de imágenes.
            ->assertSee('data-orden-rutina="001"', false)->assertSee('data-orden-rutina="004"', false)
            ->assertDontSee('data-orden-rutina="002"', false)
            ->assertSee('data-url-catalogo="' . route('clinica.consulta.laboratorio.catalogo', $consulta->id) . '"', false);
    }

    public function test_el_catalogo_vacio_se_dice_y_manda_al_catalogo_de_examenes(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario(false);

        foreach (['laboratorio' => 'Tu catálogo de exámenes está vacío', 'estudios' => 'Tu catálogo no tiene estudios de imágenes'] as $pestana => $titulo) {
            $this->como($user, $medico, $gineco)->get($this->seccion($consulta, $pestana))
                ->assertOk()->assertSee($titulo)->assertSee(route('clinica.catalogos.examenes'), false)
                ->assertDontSee('id="orden-texto"', false);
        }
    }

    // ================================================================================== Laboratorio

    public function test_guardar_la_orden_crea_los_examenes_y_el_resumen_con_su_rastro(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.laboratorio.guardar', $consulta->id), [
            'examenes' => ['001', '010'],
            'observacion' => "Control prenatal.\nDescartar anemia.",
        ])->assertRedirect($this->seccion($consulta, 'laboratorio'))
            ->assertSessionHas('estado', 'Orden de laboratorio guardada (2 exámenes agregados).');

        $filas = DB::table('examen_paciente')->where('reg_medico', $medico->reg_medico)->orderBy('codeexamen')->get();
        $this->assertSame(['001', '010'], $filas->pluck('codeexamen')->map(fn ($c) => trim($c))->all());
        foreach ($filas as $fila) {
            $this->assertSame(1001, (int) $fila->nrohistoria);
            $this->assertSame(1, (int) $fila->nroconsulta);
            $this->assertNull($fila->resultado, 'resultado no lo escribe esta pestaña');
            $this->assertNull($fila->vinculante, 'vinculante no lo escribe esta pestaña');
        }
        $this->assertSame("Control prenatal.\nDescartar anemia.", DB::table('examen_obs')->where('reg_medico', $medico->reg_medico)->where('numhistoria', 1001)->where('numconsulta', 1)->value('observacion'));
        $this->assertSame(2, $this->trazas('examen_paciente', 'created'));
        $this->assertSame(1, $this->trazas('examen_obs', 'created'));

        $this->como($user, $medico, $gineco)->get($this->seccion($consulta, 'laboratorio'))
            ->assertOk()
            ->assertSee('2 exámenes')
            ->assertSee('GLICEMIA')->assertSee('ELECTROCARDIOGRAMA')
            ->assertSee('Control prenatal.')
            ->assertSee('Eliminar la orden')
            ->assertSee(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'laboratorio']), false);
    }

    public function test_la_lista_se_ve_por_tipo_y_por_nombre_y_las_filas_sin_catalogo_se_muestran_con_su_codigo(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        foreach (['010', '003', '002', '777'] as $codigo) {
            $this->examen($medico, $codigo);
        }

        $html = $this->como($user, $medico, $gineco)->get($this->seccion($consulta, 'laboratorio'))->assertOk()->getContent();

        // LABORATORIO antes que PREOPERATORIOS; dentro del tipo, por nombre; el que no está en el catálogo, al final y con su código.
        $this->assertLessThan(strpos($html, 'UREA'), strpos($html, 'HEMATOLOGÍA COMPLETA'));
        $this->assertLessThan(strpos($html, 'ELECTROCARDIOGRAMA'), strpos($html, 'UREA'));
        $this->assertLessThan(strpos($html, '777'), strpos($html, 'ELECTROCARDIOGRAMA'));
        $this->assertStringContainsString('No está en tu catálogo: se muestra el código.', $html);
    }

    public function test_una_orden_vacia_sin_resumen_se_rechaza_y_no_crea_filas(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $url = route('clinica.consulta.laboratorio.guardar', $consulta->id);

        $this->como($user, $medico, $gineco)->post($url, [])->assertSessionHasErrors(['observacion' => 'La orden necesita al menos un examen o un resumen clínico.']);
        $this->como($user, $medico, $gineco)->post($url, ['examenes' => [], 'observacion' => " \n "])->assertSessionHasErrors('observacion');
        $this->como($user, $medico, $gineco)->post($url, ['observacion' => 'none'])->assertSessionHasErrors('observacion');
        $this->como($user, $medico, $gineco)->post($url, ['examenes' => ['001'], 'observacion' => str_repeat('r', 20001)])->assertSessionHasErrors('observacion');
        $this->como($user, $medico, $gineco)->post($url, ['examenes' => ['123456789']])->assertSessionHasErrors('examenes.0');

        $this->assertSame(0, DB::table('examen_paciente')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(0, DB::table('examen_obs')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(0, SyncChange::whereIn('table_name', ['examen_paciente', 'examen_obs'])->count());
    }

    public function test_solo_el_resumen_clinico_alcanza_para_guardar_una_orden(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.laboratorio.guardar', $consulta->id), ['observacion' => 'HIV, VDRL'])
            ->assertSessionHasNoErrors()->assertSessionHas('estado', 'Orden de laboratorio guardada.');

        $this->assertSame(0, DB::table('examen_paciente')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame('HIV, VDRL', DB::table('examen_obs')->where('reg_medico', $medico->reg_medico)->value('observacion'));
    }

    public function test_un_examen_que_no_es_del_catalogo_de_laboratorio_se_rechaza_y_la_lista_vuelve_con_el_formulario(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        [, $otroMedico] = $this->medicoConAcceso();
        $this->catalogo($otroMedico);
        DB::table('examenes')->insert(['reg_medico' => $otroMedico->reg_medico, 'codeexamen' => '555', 'examen' => 'SOLO DEL OTRO MEDICO', 'codetipo' => '001', 'created_at' => now(), 'updated_at' => now()]);

        // Un estudio de imágenes (tipo 002), el examen de otro médico y un código inventado.
        foreach (['099', '555', '404'] as $codigo) {
            $this->como($user, $medico, $gineco)->post(route('clinica.consulta.laboratorio.guardar', $consulta->id), ['examenes' => ['001', $codigo], 'observacion' => 'texto'])
                ->assertSessionHasErrors('observacion');
        }

        $this->assertSame(0, DB::table('examen_paciente')->where('reg_medico', $medico->reg_medico)->count(), 'no se guarda nada a medias');
        $this->assertSame(0, $this->trazas('examen_paciente', 'created'));

        // La lista que había en el formulario vuelve (con el nombre del que sí es del catálogo), no se pierde lo armado.
        $this->como($user, $medico, $gineco)->from($this->seccion($consulta, 'laboratorio'))
            ->post(route('clinica.consulta.laboratorio.guardar', $consulta->id), ['examenes' => ['003', '099'], 'observacion' => 'texto largo escrito']);
        $this->como($user, $medico, $gineco)->withSession(['_old_input' => ['examenes' => ['003'], 'observacion' => 'texto largo escrito']])
            ->get($this->seccion($consulta, 'laboratorio'))
            ->assertSee('UREA')->assertSee('texto largo escrito')->assertSee('1 examen en la orden.');
    }

    public function test_editar_la_orden_quita_agrega_y_deja_en_paz_los_que_ya_estaban(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $glicemia = $this->examen($medico, '001', ['resultado' => '90', 'vinculante' => 'S']);
        $urea = $this->examen($medico, '003');
        $obs = $this->observacionDeExamenes($medico, 'Primera vez');

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.laboratorio.guardar', $consulta->id), [
            'examenes' => ['001', '010'], 'observacion' => 'Primera vez',
        ])->assertSessionHas('estado', 'Orden de laboratorio guardada (1 examen agregado, 1 examen quitado).');

        $this->assertSame(['001', '010'], $this->codigosDe($medico));
        $conservada = DB::table('examen_paciente')->find($glicemia);
        $this->assertSame('90', $conservada->resultado, 'lo que ya estaba no se reescribe: el resultado sigue');
        $this->assertSame('S', $conservada->vinculante);
        $this->assertSame('2026-01-01 00:00:00', $conservada->updated_at);
        $this->assertNull(DB::table('examen_paciente')->find($urea));
        $this->assertSame(1, $this->trazas('examen_paciente', 'created'));
        $this->assertSame(1, $this->trazas('examen_paciente', 'deleted'));
        $this->assertTrue(SyncChange::where('table_name', 'examen_paciente')->where('record_id', $urea)->where('operation', 'deleted')->where('source', 'web')->exists());
        $this->assertSame(0, $this->trazas('examen_obs', 'updated'), 'el resumen no cambió');
        $this->assertSame('2026-01-01 00:00:00', DB::table('examen_obs')->find($obs)->updated_at);
    }

    public function test_guardar_lo_mismo_no_escribe_ni_deja_rastro(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->examen($medico, '001');
        $obs = $this->observacionDeExamenes($medico, 'Igual');

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.laboratorio.guardar', $consulta->id), ['examenes' => ['001'], 'observacion' => 'Igual'])
            ->assertSessionHas('estado', 'La orden ya estaba guardada así.');

        $this->assertSame('2026-01-01 00:00:00', DB::table('examen_obs')->find($obs)->updated_at);
        $this->assertSame(0, SyncChange::whereIn('table_name', ['examen_paciente', 'examen_obs'])->count());
    }

    public function test_un_examen_no_se_repite_en_la_misma_consulta(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->examen($medico, '001');

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.laboratorio.guardar', $consulta->id), ['examenes' => ['001', '001', ' 001 ', '003', '003']])
            ->assertSessionHasNoErrors();

        $this->assertSame(['001', '003'], $this->codigosDe($medico));
    }

    public function test_el_resumen_se_edita_y_se_vacia_con_su_rastro_por_columna(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->examen($medico, '001');
        $obs = $this->observacionDeExamenes($medico, 'Texto viejo');
        $url = route('clinica.consulta.laboratorio.guardar', $consulta->id);

        $this->como($user, $medico, $gineco)->post($url, ['examenes' => ['001'], 'observacion' => 'Texto nuevo'])->assertSessionHas('estado', 'Orden de laboratorio guardada.');
        $this->assertSame('Texto nuevo', DB::table('examen_obs')->find($obs)->observacion);
        $this->assertNotSame('2026-01-01 00:00:00', DB::table('examen_obs')->find($obs)->updated_at);
        $this->assertSame(1, $this->trazas('examen_obs', 'updated', 'observacion'));
        $this->assertSame(1, DB::table('examen_obs')->where('reg_medico', $medico->reg_medico)->count(), 'se corrige la fila, no se crea otra');

        $this->travel(5)->seconds();
        $this->como($user, $medico, $gineco)->post($url, ['examenes' => ['001'], 'observacion' => ''])->assertSessionHasNoErrors();
        $this->assertNull(DB::table('examen_obs')->find($obs)->observacion);
        $this->assertSame(2, $this->trazas('examen_obs', 'updated', 'observacion'));
    }

    public function test_una_edicion_mas_nueva_del_resumen_hecha_en_otro_equipo_no_se_pisa(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $obs = $this->observacionDeExamenes($medico, 'DEL ESCRITORIO');
        SyncChange::create([
            'reg_medico' => $medico->reg_medico, 'table_name' => 'examen_obs', 'record_id' => $obs, 'operation' => 'updated',
            'column_name' => 'observacion', 'value' => 'DEL ESCRITORIO', 'occurred_at' => '2026-10-08 00:00:00', 'source' => 'escritorio',
        ]);

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.laboratorio.guardar', $consulta->id), ['examenes' => ['001'], 'observacion' => 'DE LA WEB'])
            ->assertSessionHas('error');

        $this->assertSame('DEL ESCRITORIO', DB::table('examen_obs')->find($obs)->observacion);
        $this->assertSame(['001'], $this->codigosDe($medico), 'los exámenes sí se escriben');
    }

    public function test_eliminar_la_orden_borra_los_examenes_y_el_resumen_de_esta_consulta_y_deja_sus_bajas(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $a = $this->examen($medico, '001');
        $b = $this->examen($medico, '003');
        $obs = $this->observacionDeExamenes($medico, 'Resumen');
        $otra = $this->examen($medico, '001', [], 1001, 2);

        $this->como($user, $medico, $gineco)->delete(route('clinica.consulta.laboratorio.eliminar', $consulta->id))
            ->assertRedirect($this->seccion($consulta, 'laboratorio'))
            ->assertSessionHas('estado', 'Orden de laboratorio eliminada.');

        foreach ([$a, $b] as $id) {
            $this->assertNull(DB::table('examen_paciente')->find($id));
            $this->assertTrue(SyncChange::where('table_name', 'examen_paciente')->where('record_id', $id)->where('operation', 'deleted')->where('source', 'web')->exists());
        }
        $this->assertNull(DB::table('examen_obs')->find($obs));
        $this->assertSame(1, $this->trazas('examen_obs', 'deleted'));
        $this->assertNotNull(DB::table('examen_paciente')->find($otra), 'la orden de otra consulta del paciente sigue');

        $this->como($user, $medico, $gineco)->delete(route('clinica.consulta.laboratorio.eliminar', $consulta->id))
            ->assertSessionHas('estado', 'Esta consulta no tenía orden de laboratorio.');
    }

    // ========================================================================= Buscador y rutinas

    public function test_el_buscador_no_distingue_tildes_ni_mayusculas_y_filtra_por_tipo(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $url = route('clinica.consulta.laboratorio.catalogo', $consulta->id);

        $r = $this->como($user, $medico, $gineco)->getJson($url . '?q=hematologia')->assertOk();
        $this->assertSame(['HEMATOLOGÍA COMPLETA'], array_column($r->json('examenes'), 'nombre'));
        $this->assertSame('LABORATORIO', $r->json('examenes.0.tipo'));
        $this->assertSame('002', $r->json('examenes.0.codigo'));

        $r = $this->como($user, $medico, $gineco)->getJson($url . '?q=' . urlencode('HEMATOLOGÍA comp'))->assertOk();
        $this->assertSame(1, $r->json('total'));

        // Por tipo, sin texto: sólo los de ese tipo y sin el 002.
        $r = $this->como($user, $medico, $gineco)->getJson($url . '?tipo=004')->assertOk();
        $this->assertSame(['ELECTROCARDIOGRAMA', 'TIEMPO DE PROTROMBINA'], array_column($r->json('examenes'), 'nombre'));

        // Los estudios (tipo 002) no se ofrecen aquí ni con el tipo explícito.
        $this->assertSame([], $this->como($user, $medico, $gineco)->getJson($url . '?q=mamografia')->json('examenes'));
        $this->assertSame([], $this->como($user, $medico, $gineco)->getJson($url . '?tipo=002')->json('examenes'));

        // Un examen por nombre y tipo: «GLICEMIA» y «Glicemia» son el mismo (queda el de código más bajo).
        $r = $this->como($user, $medico, $gineco)->getJson($url . '?q=glicemia')->assertOk();
        $this->assertSame(['001'], array_column($r->json('examenes'), 'codigo'));
    }

    public function test_el_buscador_corta_los_resultados_y_dice_el_total(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        foreach (range(1, 45) as $n) {
            DB::table('examenes')->insert(['reg_medico' => $medico->reg_medico, 'codeexamen' => 'P' . str_pad((string) $n, 3, '0', STR_PAD_LEFT), 'examen' => 'PERFIL ' . str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'codetipo' => '001', 'created_at' => now(), 'updated_at' => now()]);
        }

        $r = $this->como($user, $medico, $gineco)->getJson(route('clinica.consulta.laboratorio.catalogo', $consulta->id) . '?q=perfil')->assertOk();

        $this->assertSame(45, $r->json('total'));
        $this->assertCount(CatalogoParaPedirExamenes::MAX_RESULTADOS, $r->json('examenes'));
        $this->assertSame('PERFIL 01', $r->json('examenes.0.nombre'));
    }

    public function test_una_rutina_trae_los_examenes_de_su_tipo_y_no_existe_la_de_estudios_ni_la_desconocida(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $r = $this->como($user, $medico, $gineco)->getJson(route('clinica.consulta.laboratorio.rutina', [$consulta->id, '001']))->assertOk();
        $this->assertSame('LABORATORIO', $r->json('nombre'));
        $this->assertSame(['GLICEMIA', 'HEMATOLOGÍA COMPLETA', 'UREA'], array_column($r->json('examenes'), 'nombre'), 'por nombre y sin el repetido');

        $this->como($user, $medico, $gineco)->getJson(route('clinica.consulta.laboratorio.rutina', [$consulta->id, '002']))->assertNotFound();
        $this->como($user, $medico, $gineco)->getJson(route('clinica.consulta.laboratorio.rutina', [$consulta->id, '999']))->assertNotFound();
    }

    public function test_guardar_una_rutina_completa_no_repite_los_examenes_que_ya_estaban(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->examen($medico, '010');

        // Lo que hace el editor al pasar la rutina PREOPERATORIOS: la lista queda con los dos del tipo.
        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.laboratorio.guardar', $consulta->id), ['examenes' => ['010', '011']])
            ->assertSessionHas('estado', 'Orden de laboratorio guardada (1 examen agregado).');

        $this->assertSame(['010', '011'], $this->codigosDe($medico));
        $this->assertSame(1, $this->trazas('examen_paciente', 'created'));
    }

    // ============================================================ La sección Exámenes (WEB-4.7)

    public function test_la_seccion_de_examenes_ve_lo_que_se_arma_en_laboratorio(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.laboratorio.guardar', $consulta->id), ['examenes' => ['001', '010'], 'observacion' => 'HIV, VDRL']);

        $this->como($user, $medico, $gineco)->get(route('clinica.consulta', [$consulta->id, 'seccion' => 'examenes']))
            ->assertOk()
            ->assertSee('GLICEMIA')->assertSee('ELECTROCARDIOGRAMA')->assertSee('PREOPERATORIOS')
            ->assertSee('HIV, VDRL')
            ->assertSee('Pedir o corregir')
            ->assertSee(e($this->seccion($consulta, 'laboratorio')), false);
    }

    public function test_la_seccion_de_examenes_vacia_manda_a_laboratorio(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $this->como($user, $medico, $gineco)->get(route('clinica.consulta', [$consulta->id, 'seccion' => 'examenes']))
            ->assertOk()
            ->assertSee('Esta consulta no tiene exámenes pedidos')
            ->assertSee('se piden en Solicitudes → Laboratorio');
    }

    // ================================================================================== Estudios

    public function test_guardar_la_solicitud_crea_los_estudios_con_opcion_1_orden_y_su_rastro(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.estudios.guardar', $consulta->id), [
            'estudios' => ['114', '099'],
            'observacion' => 'Mastalgia',
        ])->assertRedirect($this->seccion($consulta, 'estudios'))
            ->assertSessionHas('estado', 'Solicitud de estudios guardada (2 estudios agregados).');

        $filas = DB::table('radiologia_paciente')->where('reg_medico', $medico->reg_medico)->orderBy('orden')->get();
        $this->assertSame(['114', '099'], $filas->pluck('coderadio')->map(fn ($c) => trim($c))->all(), 'el orden de la lista');
        $this->assertSame([1, 2], $filas->pluck('orden')->map(fn ($o) => (int) $o)->all());
        $this->assertSame([1, 1], $filas->pluck('nroopcion')->map(fn ($o) => (int) $o)->all());
        $this->assertSame('Mastalgia', DB::table('radiologia_obs')->where('reg_medico', $medico->reg_medico)->where('numhistoria', 1001)->where('numconsulta', 1)->value('observacion'));
        $this->assertSame(2, $this->trazas('radiologia_paciente', 'created'));
        $this->assertSame(1, $this->trazas('radiologia_obs', 'created'));

        $html = $this->como($user, $medico, $gineco)->get($this->seccion($consulta, 'estudios'))
            ->assertOk()->assertSee('Solicitud de estudios')->assertSee('2 estudios')->assertSee('Eliminar la solicitud')->assertSee('Mastalgia')->getContent();
        $this->assertLessThan(strpos($html, 'DENSITOMETRIA OSEA'), strpos($html, 'ECOGRAFIA MAMARIA'), 'en el orden de la lista');
        $this->assertSame(0, DB::table('examen_paciente')->where('reg_medico', $medico->reg_medico)->count(), 'no mezcla las dos tablas');
    }

    public function test_los_estudios_solo_se_piden_del_catalogo_de_tipo_002(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        // Un examen de laboratorio y un código inventado.
        foreach (['001', '404'] as $codigo) {
            $this->como($user, $medico, $gineco)->post(route('clinica.consulta.estudios.guardar', $consulta->id), ['estudios' => ['100', $codigo]])
                ->assertSessionHasErrors('observacion');
        }
        $this->assertSame(0, DB::table('radiologia_paciente')->where('reg_medico', $medico->reg_medico)->count());

        $url = route('clinica.consulta.estudios.catalogo', $consulta->id);
        $r = $this->como($user, $medico, $gineco)->getJson($url . '?q=ecografia')->assertOk();
        $this->assertSame(['ECOGRAFIA MAMARIA'], array_column($r->json('examenes'), 'nombre'));
        $this->assertSame([], $this->como($user, $medico, $gineco)->getJson($url . '?q=glicemia')->json('examenes'), 'los de laboratorio no se ofrecen');
        $this->assertSame(3, $this->como($user, $medico, $gineco)->getJson($url)->json('total'));
    }

    public function test_reordenar_los_estudios_corrige_el_orden_y_el_mismo_orden_no_escribe(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $a = $this->estudio($medico, '099', ['orden' => 1]);
        $b = $this->estudio($medico, '100', ['orden' => 2]);
        $url = route('clinica.consulta.estudios.guardar', $consulta->id);

        $this->como($user, $medico, $gineco)->post($url, ['estudios' => ['099', '100']])->assertSessionHas('estado', 'La solicitud ya estaba guardada así.');
        $this->assertSame(0, SyncChange::whereIn('table_name', ['radiologia_paciente', 'radiologia_obs'])->count());

        $this->como($user, $medico, $gineco)->post($url, ['estudios' => ['100', '099']])->assertSessionHas('estado', 'Solicitud de estudios guardada.');
        $this->assertSame(2, (int) DB::table('radiologia_paciente')->find($a)->orden);
        $this->assertSame(1, (int) DB::table('radiologia_paciente')->find($b)->orden);
        $this->assertSame(2, $this->trazas('radiologia_paciente', 'updated', 'orden'));
        $this->assertSame(0, $this->trazas('radiologia_paciente', 'created'));
    }

    public function test_las_filas_del_escritorio_sin_orden_se_leen_en_el_orden_de_creacion_y_al_guardar_reciben_su_numero(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $primero = $this->estudio($medico, '114');          // orden null, como la mayoría del escritorio
        $segundo = $this->estudio($medico, '099', ['orden' => 84]);   // el «número de filas del catálogo» que ponía el escritorio

        $html = $this->como($user, $medico, $gineco)->get($this->seccion($consulta, 'estudios'))->getContent();
        $this->assertLessThan(strpos($html, 'ECOGRAFIA MAMARIA'), strpos($html, 'DENSITOMETRIA OSEA'), 'la fila con orden va antes que la que no lo tiene');

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.estudios.guardar', $consulta->id), ['estudios' => ['114', '099', '100']]);

        $this->assertSame([1, 2, 3], DB::table('radiologia_paciente')->where('reg_medico', $medico->reg_medico)->orderBy('orden')->pluck('orden')->map(fn ($o) => (int) $o)->all());
        $this->assertSame(1, (int) DB::table('radiologia_paciente')->find($primero)->orden);
        $this->assertSame(1, $this->trazas('radiologia_paciente', 'created'), 'solo el estudio nuevo es una fila nueva');
    }

    public function test_quitar_estudios_y_eliminar_la_solicitud_dejan_sus_bajas(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $a = $this->estudio($medico, '099', ['orden' => 1]);
        $b = $this->estudio($medico, '100', ['orden' => 2]);
        $obs = $this->observacionDeEstudios($medico, 'Resumen');
        $otra = $this->estudio($medico, '099', ['orden' => 1], 1001, 2);

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.estudios.guardar', $consulta->id), ['estudios' => ['099'], 'observacion' => 'Resumen'])
            ->assertSessionHas('estado', 'Solicitud de estudios guardada (1 estudio quitado).');
        $this->assertNull(DB::table('radiologia_paciente')->find($b));
        $this->assertSame(1, $this->trazas('radiologia_paciente', 'deleted'));

        $this->como($user, $medico, $gineco)->delete(route('clinica.consulta.estudios.eliminar', $consulta->id))
            ->assertRedirect($this->seccion($consulta, 'estudios'))->assertSessionHas('estado', 'Solicitud de estudios eliminada.');
        $this->assertNull(DB::table('radiologia_paciente')->find($a));
        $this->assertNull(DB::table('radiologia_obs')->find($obs));
        $this->assertNotNull(DB::table('radiologia_paciente')->find($otra));

        $this->como($user, $medico, $gineco)->delete(route('clinica.consulta.estudios.eliminar', $consulta->id))
            ->assertSessionHas('estado', 'Esta consulta no tenía solicitud de estudios.');
    }

    public function test_la_solicitud_vacia_sin_resumen_se_rechaza_y_el_resumen_solo_alcanza(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $url = route('clinica.consulta.estudios.guardar', $consulta->id);

        $this->como($user, $medico, $gineco)->post($url, [])->assertSessionHasErrors(['observacion' => 'La solicitud necesita al menos un estudio o un resumen clínico.']);
        $this->assertSame(0, DB::table('radiologia_paciente')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(0, DB::table('radiologia_obs')->where('reg_medico', $medico->reg_medico)->count());

        $this->como($user, $medico, $gineco)->post($url, ['observacion' => 'Resumen solo'])->assertSessionHasNoErrors();
        $this->assertSame('Resumen solo', DB::table('radiologia_obs')->where('reg_medico', $medico->reg_medico)->value('observacion'));
    }

    // ============================================================= El catálogo de exámenes (WEB-8.1b)

    public function test_un_examen_o_un_estudio_pedido_cuenta_como_en_uso_y_no_se_quita_del_catalogo(): void
    {
        [$user, $medico, $gineco] = $this->escenario();
        $this->examen($medico, '001');
        $this->estudio($medico, '099');
        $id = fn (string $codigo) => DB::table('examenes')->where('reg_medico', $medico->reg_medico)->where('codeexamen', $codigo)->value('id');

        $laboratorio = $this->como($user, $medico, $gineco)->delete(route('clinica.catalogos.examenes.quitar', $id('001')));
        $laboratorio->assertSessionHas('error');
        $this->assertStringContainsString('1 consulta', session('error'));

        // Antes de WEB-5.4 un estudio pedido no contaba (sólo `examen_paciente`): se podía quitar y la solicitud quedaba con un código huérfano.
        $estudio = $this->como($user, $medico, $gineco)->delete(route('clinica.catalogos.examenes.quitar', $id('099')));
        $estudio->assertSessionHas('error');
        $this->assertStringContainsString('solicitud de estudios', session('error'));
        $this->assertNotNull($id('099'));

        // Uno que nadie pidió sí se quita.
        $this->como($user, $medico, $gineco)->delete(route('clinica.catalogos.examenes.quitar', $id('100')))->assertSessionHas('estado');
        $this->assertNull($id('100'));
    }

    // ========================================================================= Quién puede hacerlo

    public function test_la_secretaria_recibe_403_en_las_pestanas_las_acciones_y_los_buscadores(): void
    {
        [, $medico, $gineco, $consulta] = $this->escenario();
        $secretaria = $this->secretariaConAcceso($medico);
        $c = fn () => $this->como($secretaria, $medico, $gineco);

        $c()->get($this->seccion($consulta, 'laboratorio'))->assertForbidden();
        $c()->get($this->seccion($consulta, 'estudios'))->assertForbidden();
        $c()->post(route('clinica.consulta.laboratorio.guardar', $consulta->id), ['examenes' => ['001']])->assertForbidden();
        $c()->delete(route('clinica.consulta.laboratorio.eliminar', $consulta->id))->assertForbidden();
        $c()->getJson(route('clinica.consulta.laboratorio.catalogo', $consulta->id))->assertForbidden();
        $c()->getJson(route('clinica.consulta.laboratorio.rutina', [$consulta->id, '001']))->assertForbidden();
        $c()->post(route('clinica.consulta.estudios.guardar', $consulta->id), ['estudios' => ['099']])->assertForbidden();
        $c()->delete(route('clinica.consulta.estudios.eliminar', $consulta->id))->assertForbidden();
        $c()->getJson(route('clinica.consulta.estudios.catalogo', $consulta->id))->assertForbidden();

        $this->assertSame(0, DB::table('examen_paciente')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(0, DB::table('radiologia_paciente')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_una_consulta_de_otro_medico_da_404_en_todo(): void
    {
        [, $medico, , $consulta] = $this->escenario();
        [$otroUser, $otroMedico, $otraGineco] = $this->medicoConAcceso();
        $c = fn () => $this->como($otroUser, $otroMedico, $otraGineco);

        $c()->get($this->seccion($consulta, 'laboratorio'))->assertNotFound();
        $c()->get($this->seccion($consulta, 'estudios'))->assertNotFound();
        $c()->post(route('clinica.consulta.laboratorio.guardar', $consulta->id), ['examenes' => ['001']])->assertNotFound();
        $c()->delete(route('clinica.consulta.laboratorio.eliminar', $consulta->id))->assertNotFound();
        $c()->getJson(route('clinica.consulta.laboratorio.catalogo', $consulta->id))->assertNotFound();
        $c()->getJson(route('clinica.consulta.laboratorio.rutina', [$consulta->id, '001']))->assertNotFound();
        $c()->post(route('clinica.consulta.estudios.guardar', $consulta->id), ['estudios' => ['099']])->assertNotFound();
        $c()->delete(route('clinica.consulta.estudios.eliminar', $consulta->id))->assertNotFound();
        $c()->getJson(route('clinica.consulta.estudios.catalogo', $consulta->id))->assertNotFound();

        $this->assertSame(0, DB::table('examen_paciente')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_el_invitado_va_al_login(): void
    {
        // Aparte del 404 de arriba: después de un `actingAs` el siguiente `get` del mismo test sigue autenticado (trampa 47).
        [, , , $consulta] = $this->escenario();

        $this->get($this->seccion($consulta, 'laboratorio'))->assertRedirect(route('login'));
        $this->get($this->seccion($consulta, 'estudios'))->assertRedirect(route('login'));
        $this->post(route('clinica.consulta.laboratorio.guardar', $consulta->id), [])->assertRedirect(route('login'));
        $this->post(route('clinica.consulta.estudios.guardar', $consulta->id), [])->assertRedirect(route('login'));
    }

    public function test_la_orden_del_mismo_numero_de_historia_de_otro_medico_no_se_ve_ni_se_toca(): void
    {
        // El número de historia es único por médico, no global: lo de OTRO médico con la misma historia y consulta no es de este.
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        [, $otroMedico] = $this->medicoConAcceso();
        $this->catalogo($otroMedico);
        $ajeno = $this->examen($otroMedico, '001');
        $obsAjena = $this->observacionDeExamenes($otroMedico, 'RESUMEN AJENO');
        $estudioAjeno = $this->estudio($otroMedico, '099');

        $this->como($user, $medico, $gineco)->get($this->seccion($consulta, 'laboratorio'))->assertDontSee('RESUMEN AJENO')->assertSee('Sin orden');
        $this->como($user, $medico, $gineco)->get($this->seccion($consulta, 'estudios'))->assertSee('Sin solicitud');

        $this->como($user, $medico, $gineco)->post(route('clinica.consulta.laboratorio.guardar', $consulta->id), ['examenes' => ['003'], 'observacion' => 'MIO']);
        $this->como($user, $medico, $gineco)->delete(route('clinica.consulta.laboratorio.eliminar', $consulta->id));
        $this->como($user, $medico, $gineco)->delete(route('clinica.consulta.estudios.eliminar', $consulta->id));

        $this->assertNotNull(DB::table('examen_paciente')->find($ajeno));
        $this->assertSame('RESUMEN AJENO', DB::table('examen_obs')->find($obsAjena)->observacion);
        $this->assertNotNull(DB::table('radiologia_paciente')->find($estudioAjeno));
    }

    // ===================================================================== La historia del paciente

    public function test_la_historia_lista_laboratorio_y_estudios_con_imprimir_solo_para_el_medico_y_no_las_filas_vacias(): void
    {
        [$user, $medico, $gineco, $consulta, $pacienteId] = $this->escenario();
        $secretaria = $this->secretariaConAcceso($medico);
        $segunda = Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'nroconsulta' => 2, 'fecha' => '2026-09-01']);
        Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'nroconsulta' => 3, 'fecha' => '2026-08-01']);

        $this->examen($medico, '001');
        $this->examen($medico, '003');
        $this->estudio($medico, '099', ['orden' => 1]);
        $this->observacionDeExamenes($medico, null, 1001, 2);                     // la fila vacía del escritorio: no es una orden
        $this->observacionDeExamenes($medico, 'none', 1001, 3);                   // ni el literal del DataWindow
        $this->observacionDeEstudios($medico, '  ', 1001, 2);
        $this->observacionDeEstudios($medico, 'MAMOGRAFIA DE CONTROL', 1001, 3);  // un resumen con texto sí

        $url = route('clinica.pacientes.historia', $pacienteId);
        $medicoVe = $this->como($user, $medico, $gineco)->get($url);
        $medicoVe->assertOk()
            ->assertSee('Laboratorio')->assertSee('2 exámenes')
            ->assertSee('Estudios')->assertSee('1 estudio')->assertSee('MAMOGRAFIA DE CONTROL')
            ->assertSee('href="' . route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'laboratorio']) . '"', false)
            ->assertSee('href="' . route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'estudios']) . '"', false);
        $this->assertSame(1, substr_count($medicoVe->getContent(), '2 exámenes'), 'una sola orden: las filas vacías no se listan');
        $medicoVe->assertDontSee(route('clinica.consulta.documentos.imprimir', [$segunda->id, 'documento' => 'laboratorio']), false);
        $medicoVe->assertDontSee(route('clinica.consulta.documentos.imprimir', [$segunda->id, 'documento' => 'estudios']), false);

        $this->como($secretaria, $medico, $gineco)->get($url)->assertOk()->assertSee('2 exámenes')->assertDontSee('documentos/imprimir', false);
    }

    public function test_la_historia_sin_laboratorio_ni_estudios_no_cambia(): void
    {
        [$user, $medico, $gineco, , $pacienteId] = $this->escenario();
        $this->observacionDeExamenes($medico, null);
        $this->observacionDeEstudios($medico, '');

        $this->como($user, $medico, $gineco)->get(route('clinica.pacientes.historia', $pacienteId))
            ->assertOk()
            ->assertDontSee('bi-droplet', false)
            ->assertDontSee('bi-image', false);
    }
}
