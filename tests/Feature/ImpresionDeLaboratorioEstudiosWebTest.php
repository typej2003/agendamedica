<?php

namespace Tests\Feature;

use App\Clinica\Impresion\DocumentosParaImprimir;
use App\Clinica\Impresion\SolicitudesParaImprimir;
use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Medico;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **Imprimir la orden de laboratorio y la solicitud de estudios** (WEB-5.4): cada una en media hoja, con el membrete, la firma y el pie del
 * formato del médico; los exámenes **agrupados por tipo**; lo que no entra **sigue en la otra mitad** sin partir un examen; y la
 * secretaría las imprime desde la cita atendida («Imprimir documentos») sin ver el contenido clínico.
 *
 * Que el cuerpo real del navegador no se desborde lo comprueba el verificador de Chrome (`.agent-tools/verificar-laboratorio-estudios-web.js`);
 * acá se fija el contenido, el reparto y la lista de la cita.
 */
class ImpresionDeLaboratorioEstudiosWebTest extends TestCase
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

    /** @return array{0:User, 1:Medico, 2:Specialty, 3:Consulta, 4:User, 5:Cola} el médico, su secretaría y la cita atendida de hoy */
    private function escenario(): array
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->membrete($medico);
        $secretaria = $this->secretariaConAcceso($medico);
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1, ['fnacimiento' => '1990-10-06']);
        $cita = $this->cita($medico, 1001, '2026-10-07', ['atendido' => 1, 'estado' => 1]);

        foreach ([['001', 'LABORATORIO'], ['004', 'PREOPERATORIOS'], ['002', 'ESTUDIO DE IMAGENES']] as [$codigo, $tipo]) {
            DB::table('tipos_examenes')->insert(['reg_medico' => $medico->reg_medico, 'codetipo' => $codigo, 'tipo' => $tipo, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([
            ['001', 'GLICEMIA', '001'], ['002', 'HEMATOLOGÍA COMPLETA', '001'], ['003', 'UREA', '001'],
            ['010', 'ELECTROCARDIOGRAMA', '004'], ['011', 'TIEMPO DE PROTROMBINA', '004'],
            ['099', 'DENSITOMETRIA OSEA', '002'], ['100', 'MAMOGRAFIA', '002'], ['114', 'ECOGRAFIA MAMARIA', '002'],
        ] as [$codigo, $nombre, $tipo]) {
            DB::table('examenes')->insert(['reg_medico' => $medico->reg_medico, 'codeexamen' => $codigo, 'examen' => $nombre, 'codetipo' => $tipo, 'created_at' => now(), 'updated_at' => now()]);
        }

        return [$user, $medico, $gineco, $consulta, $secretaria, $cita];
    }

    private function como(User $user, Medico $medico, Specialty $gineco): self
    {
        return $this->conContexto($user, $medico, $gineco)->actingAs($user);
    }

    private function hoja(Consulta $consulta, string $documento): string
    {
        return route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => $documento]);
    }

    private function examenes(Medico $medico, array $codigos, int $consulta = 1): void
    {
        foreach ($codigos as $codigo) {
            DB::table('examen_paciente')->insert(['reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => $consulta, 'codeexamen' => $codigo, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function estudios(Medico $medico, array $codigos): void
    {
        foreach (array_values($codigos) as $posicion => $codigo) {
            DB::table('radiologia_paciente')->insert(['reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'coderadio' => $codigo, 'nroopcion' => 1, 'orden' => $posicion + 1, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function resumen(Medico $medico, string $tabla, ?string $texto): void
    {
        DB::table($tabla)->insert(['reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'numconsulta' => 1, 'observacion' => $texto, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Un catálogo grande del tipo LABORATORIO («EXAMEN 001 DE PRUEBA»…) y su orden: sirve para probar el reparto en mitades. */
    private function ordenGrande(Medico $medico, int $cuantos): array
    {
        $nombres = [];
        foreach (range(1, $cuantos) as $n) {
            $codigo = 'G' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $nombre = 'EXAMEN ' . str_pad((string) $n, 3, '0', STR_PAD_LEFT) . ' DE PRUEBA';
            DB::table('examenes')->insert(['reg_medico' => $medico->reg_medico, 'codeexamen' => $codigo, 'examen' => $nombre, 'codetipo' => $n % 2 === 0 ? '001' : '004', 'created_at' => now(), 'updated_at' => now()]);
            $nombres[] = $nombre;
        }
        $this->examenes($medico, array_map(fn ($n) => 'G' . str_pad((string) $n, 4, '0', STR_PAD_LEFT), range(1, $cuantos)));

        return $nombres;
    }

    // ========================================================================== Cada documento

    public function test_la_orden_sale_agrupada_por_tipo_con_el_resumen_y_los_datos_del_paciente(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->examenes($medico, ['011', '003', '001', '010', '002']);
        $this->resumen($medico, 'examen_obs', "Control prenatal.\nDescartar anemia.");

        $hoja = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'laboratorio'));
        $html = $hoja->getContent();

        $hoja->assertOk()
            ->assertSee('SOLICITUD DE EXÁMENES DE LABORATORIO')
            ->assertSee('PACIENTE:')->assertSee('Ana Alvarez')
            ->assertSee('C.I.:')->assertSee('V-14555666')
            ->assertSee('FECHA:')->assertSee('07/10/2026')
            ->assertSee('LABORATORIO:')->assertSee('PREOPERATORIOS:')
            ->assertSee('RESUMEN CLÍNICO:')->assertSee('Control prenatal.')->assertSee('Descartar anemia.')
            ->assertSee('Ginecología y Obstetricia');
        $this->assertSame(1, substr_count($html, 'class="firmas"'));
        $this->assertSame(1, substr_count($html, 'class="membrete '));

        // Cada grupo es un párrafo: sus exámenes por nombre y unidos con « · »; los grupos, por nombre de tipo.
        $this->assertStringContainsString('GLICEMIA · HEMATOLOGÍA COMPLETA · UREA', $html);
        $this->assertStringContainsString('ELECTROCARDIOGRAMA · TIEMPO DE PROTROMBINA', $html);
        $this->assertLessThan(strpos($html, 'PREOPERATORIOS:'), strpos($html, 'LABORATORIO:'));
        $this->assertLessThan(strpos($html, 'RESUMEN CLÍNICO:'), strpos($html, 'PREOPERATORIOS:'));
        // Dos grupos y las dos líneas del resumen: el resumen no lleva diagnósticos ni nada que el médico no haya escrito.
        $this->assertSame(4, substr_count($html, 'class="documento-parrafo"'));
    }

    public function test_los_examenes_que_no_estan_en_el_catalogo_salen_en_otros_examenes_al_final(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->examenes($medico, ['001', '777']);

        $html = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'laboratorio'))->assertOk()->getContent();

        $this->assertStringContainsString('OTROS EXÁMENES:', $html);
        $this->assertLessThan(strpos($html, 'OTROS EXÁMENES:'), strpos($html, 'LABORATORIO:'));
        $this->assertStringContainsString('777', $html);
    }

    public function test_una_orden_solo_con_resumen_imprime_el_resumen_sin_grupos(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->resumen($medico, 'examen_obs', 'HIV, VDRL');

        $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'laboratorio'))
            ->assertOk()->assertSee('RESUMEN CLÍNICO:')->assertSee('HIV, VDRL')->assertDontSee('LABORATORIO:');
    }

    public function test_la_solicitud_de_estudios_sale_numerada_en_el_orden_de_la_lista_con_el_resumen(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->estudios($medico, ['114', '099']);
        $this->resumen($medico, 'radiologia_obs', 'Mastalgia izquierda');

        $hoja = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'estudios'));
        $html = $hoja->getContent();

        $hoja->assertOk()
            ->assertSee('SOLICITUD DE ESTUDIOS')
            ->assertSee('PACIENTE:')->assertSee('Ana Alvarez')
            ->assertSee('ESTUDIOS SOLICITADOS:')
            ->assertSee('1. ECOGRAFIA MAMARIA')->assertSee('2. DENSITOMETRIA OSEA')
            ->assertSee('RESUMEN CLÍNICO:')->assertSee('Mastalgia izquierda');
        $this->assertLessThan(strpos($html, '2. DENSITOMETRIA OSEA'), strpos($html, '1. ECOGRAFIA MAMARIA'));
        $this->assertSame(1, substr_count($html, 'class="firmas"'));
    }

    public function test_los_dos_llevan_el_membrete_y_la_linea_del_formato_del_medico(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        DB::table('recipe_formatos')->insert(['reg_medico' => $medico->reg_medico, 'color_linea' => 'azul', 'created_at' => now(), 'updated_at' => now()]);
        $this->examenes($medico, ['001']);
        $this->estudios($medico, ['099']);

        foreach (['laboratorio', 'estudios'] as $clave) {
            $this->como($user, $medico, $gineco)->get($this->hoja($consulta, $clave))
                ->assertSee('linea-azul', false)
                ->assertSee('J-12345678-9')
                ->assertSee('0251-5551234');
        }
    }

    // ================================================================== Lo largo sigue en la otra mitad

    public function test_una_orden_de_80_examenes_cabe_y_cada_examen_sale_una_sola_vez_completo(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $nombres = $this->ordenGrande($medico, 80);

        $respuesta = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'laboratorio'));
        $html = $respuesta->getContent();

        $respuesta->assertOk();
        $this->assertSame(1, $this->hojasDe($html));
        $this->assertSame(1, substr_count($html, 'class="firmas"'));
        foreach ($nombres as $nombre) {
            $this->assertSame(1, substr_count($html, $nombre), $nombre . ' sale una sola vez, completo');
        }
    }

    public function test_una_orden_larguisima_sigue_en_la_otra_mitad_con_continua_y_el_rotulo_del_grupo_sin_partir_ningun_examen(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $nombres = $this->ordenGrande($medico, 260);
        $this->resumen($medico, 'examen_obs', 'RESUMEN AL FINAL');

        $respuesta = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'laboratorio'));
        $html = $respuesta->getContent();

        $respuesta->assertOk()->assertSee('Continúa')->assertSee('SOLICITUD DE EXÁMENES DE LABORATORIO (continuación)');
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'recipe-mitad-documento'));
        $this->assertSame(1, substr_count($html, 'class="firmas"'), 'la firma va sólo en la última mitad');
        $this->assertSame(1, substr_count($html, 'class="membrete '), 'el membrete sólo en la primera');
        $this->assertStringContainsString('(cont.):', $html, 'la parte que sigue repite el rótulo del grupo');
        foreach ($nombres as $nombre) {
            $this->assertSame(1, substr_count($html, $nombre), $nombre . ' no se pierde ni se parte');
        }
        $this->assertSame(1, substr_count($html, 'RESUMEN AL FINAL'));
        $this->assertLessThan(strpos($html, 'RESUMEN AL FINAL'), strrpos($html, 'EXAMEN 260 DE PRUEBA'), 'el resumen va después de los exámenes');
    }

    public function test_una_solicitud_de_muchos_estudios_sigue_en_la_otra_mitad_sin_perder_ninguno(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $codigos = [];
        foreach (range(1, 70) as $n) {
            $codigo = 'E' . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            DB::table('examenes')->insert(['reg_medico' => $medico->reg_medico, 'codeexamen' => $codigo, 'examen' => 'ESTUDIO NUMERO ' . $n . ' LARGO DE IMAGEN', 'codetipo' => '002', 'created_at' => now(), 'updated_at' => now()]);
            $codigos[] = $codigo;
        }
        $this->estudios($medico, $codigos);

        $html = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'estudios'))->assertOk()->assertSee('Continúa')->getContent();

        $this->assertSame(1, substr_count($html, 'class="firmas"'));
        foreach (range(1, 70) as $n) {
            $this->assertSame(1, substr_count($html, $n . '. ESTUDIO NUMERO ' . $n . ' LARGO DE IMAGEN'), 'el estudio ' . $n);
        }
    }

    // ================================================================================== «todos» y 404

    public function test_todos_suma_las_dos_solicitudes_y_sin_nada_que_imprimir_da_404(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        // La fila de resumen vacía que deja el escritorio no es un documento.
        $this->resumen($medico, 'examen_obs', null);
        $this->resumen($medico, 'radiologia_obs', '  ');

        foreach (['laboratorio', 'estudios', 'todos'] as $clave) {
            $this->como($user, $medico, $gineco)->get($this->hoja($consulta, $clave))->assertOk()->assertSee('Guarda los datos del estudio para poder imprimir.');
        }

        $this->examenes($medico, ['001']);
        $this->estudios($medico, ['099']);

        $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'todos'))
            ->assertOk()->assertSee('2 documentos')->assertSee('SOLICITUD DE EXÁMENES DE LABORATORIO')->assertSee('SOLICITUD DE ESTUDIOS');
    }

    public function test_las_hojas_de_antes_no_cambian_y_el_orden_de_las_ofertas_es_el_de_las_pestanas(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        DB::table('informe')->insert(['reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'para' => 'A QUIEN CORRESPONDA',
            'descripcion' => "Paciente en control.\nSin hallazgos.", 'fe_cha' => '2026-10-06', 'created_at' => now(), 'updated_at' => now()]);
        $solo = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'informe'))->getContent();

        $this->examenes($medico, ['001']);
        $this->estudios($medico, ['099']);
        $con = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'informe'))->getContent();

        $this->assertSame($solo, $con);
        $disponibles = app(DocumentosParaImprimir::class)->disponibles($consulta, [$medico->reg_medico]);
        $this->assertSame(['laboratorio', 'estudios', 'informe'], array_column($disponibles, 'clave'), 'el orden de las pestañas del escritorio');
    }

    // ====================================================================== La secretaría desde la cita

    private function lista(User $user, Medico $medico, Specialty $gineco, Cola $cita)
    {
        return $this->como($user, $medico, $gineco)->get(route('clinica.agenda.documentos', $cita->id));
    }

    private function hojaDeLaCita(User $user, Medico $medico, Specialty $gineco, Cola $cita, ?string $documento = null)
    {
        $parametros = $documento === null ? [$cita->id] : [$cita->id, 'documento' => $documento];

        return $this->como($user, $medico, $gineco)->get(route('clinica.agenda.documentos.imprimir', $parametros));
    }

    public function test_la_secretaria_ve_las_dos_solicitudes_en_la_lista_sin_los_examenes_ni_el_resumen(): void
    {
        [, $medico, $gineco, , $secretaria, $cita] = $this->escenario();
        $this->examenes($medico, ['001', '010']);
        $this->estudios($medico, ['099']);
        $this->resumen($medico, 'examen_obs', 'RESUMEN RESERVADO DE LABORATORIO');
        $this->resumen($medico, 'radiologia_obs', 'RESUMEN RESERVADO DE ESTUDIOS');

        $respuesta = $this->lista($secretaria, $medico, $gineco, $cita);

        $respuesta->assertOk()
            ->assertSee('Documentos de Ana Alvarez')
            ->assertSee('Orden de laboratorio')->assertSee('Solicitud de estudios')
            ->assertSee('Imprimir todos')
            ->assertSee('documento=laboratorio', false)->assertSee('documento=estudios', false)->assertSee('documento=todos', false);
        foreach (['GLICEMIA', 'ELECTROCARDIOGRAMA', 'DENSITOMETRIA', 'RESERVADO', 'PREOPERATORIOS'] as $clinico) {
            $respuesta->assertDontSee($clinico);
        }
    }

    public function test_la_secretaria_imprime_las_dos_y_la_hoja_es_la_misma_que_la_del_medico(): void
    {
        [$user, $medico, $gineco, $consulta, $secretaria, $cita] = $this->escenario();
        $this->ordenGrande($medico, 120);
        $this->estudios($medico, ['099', '100']);

        $cuerpo = fn (string $html) => substr($html, strpos($html, '<main class="recipe-hojas"'), strpos($html, '</main>') - strpos($html, '<main class="recipe-hojas"'));

        foreach (['laboratorio' => 'SOLICITUD DE EXÁMENES DE LABORATORIO', 'estudios' => 'SOLICITUD DE ESTUDIOS'] as $clave => $titulo) {
            $secretariaVe = $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita, $clave);
            $secretariaVe->assertOk()->assertSee($titulo);
            $medicoVe = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, $clave))->getContent();
            $this->assertSame($cuerpo($medicoVe), $cuerpo($secretariaVe->getContent()), $clave);
        }

        $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita, 'todos')->assertOk()->assertSee('2 documentos');
    }

    public function test_la_secretaria_no_recibe_las_filas_vacias_del_escritorio_y_la_agenda_no_le_ofrece_el_boton(): void
    {
        [, $medico, $gineco, , $secretaria, $cita] = $this->escenario();
        $this->resumen($medico, 'examen_obs', null);
        $this->resumen($medico, 'radiologia_obs', 'none');

        $this->lista($secretaria, $medico, $gineco, $cita)->assertOk()->assertSee('Esta consulta no tiene documentos para imprimir');
        $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita, 'laboratorio')->assertNotFound();
        $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita, 'estudios')->assertNotFound();
        $this->como($secretaria, $medico, $gineco)->get('/clinica/agenda?vista=dia&fecha=2026-10-07&sede=todas')->assertDontSee('Imprimir documentos');
    }

    public function test_con_una_sola_solicitud_la_lista_se_salta_y_va_directo_a_la_hoja(): void
    {
        [, $medico, $gineco, , $secretaria, $cita] = $this->escenario();
        $this->estudios($medico, ['099']);

        $this->lista($secretaria, $medico, $gineco, $cita)->assertRedirect(route('clinica.agenda.documentos.imprimir', [$cita->id, 'documento' => 'estudios']));
        $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita)->assertOk()->assertSee('SOLICITUD DE ESTUDIOS');
    }

    public function test_lo_de_otro_medico_no_se_imprime(): void
    {
        [, $medico, , , , $cita] = $this->escenario();
        $this->examenes($medico, ['001']);
        $this->estudios($medico, ['099']);
        [$otroUser, $otroMedico, $otraGineco] = $this->medicoConAcceso();
        $otraSecretaria = $this->secretariaConAcceso($otroMedico);

        foreach ([$otroUser, $otraSecretaria] as $quien) {
            $this->lista($quien, $otroMedico, $otraGineco, $cita)->assertNotFound();
            $this->hojaDeLaCita($quien, $otroMedico, $otraGineco, $cita, 'laboratorio')->assertNotFound();
        }
    }

    public function test_el_lote_de_la_agenda_y_la_consulta_coinciden_en_que_es_una_orden_o_una_solicitud(): void
    {
        [, $medico, , $consulta] = $this->escenario();
        $secretaria = $this->secretariaConAcceso($medico);
        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();

        $borrar = fn (string $tabla) => fn () => DB::table($tabla)->where('reg_medico', $medico->reg_medico)->delete();
        $casos = [
            'resumen de laboratorio vacío' => [fn () => $this->resumen($medico, 'examen_obs', null), false],
            'resumen de laboratorio con el literal none' => [fn () => DB::table('examen_obs')->where('reg_medico', $medico->reg_medico)->update(['observacion' => 'None']), false],
            'resumen de laboratorio con texto' => [fn () => DB::table('examen_obs')->where('reg_medico', $medico->reg_medico)->update(['observacion' => 'HIV']), true],
            'resumen de laboratorio borrado' => [$borrar('examen_obs'), false],
            'un examen' => [fn () => $this->examenes($medico, ['001']), true],
            'examen y resumen' => [fn () => $this->resumen($medico, 'examen_obs', 'HIV'), true],
            'laboratorio borrado' => [function () use ($medico) {
                DB::table('examen_paciente')->where('reg_medico', $medico->reg_medico)->delete();
                DB::table('examen_obs')->where('reg_medico', $medico->reg_medico)->delete();
            }, false],
            'resumen de estudios con espacios' => [fn () => $this->resumen($medico, 'radiologia_obs', '   '), false],
            'resumen de estudios con texto' => [fn () => DB::table('radiologia_obs')->where('reg_medico', $medico->reg_medico)->update(['observacion' => 'RX']), true],
            'resumen de estudios borrado' => [$borrar('radiologia_obs'), false],
            'un estudio' => [fn () => $this->estudios($medico, ['099']), true],
            'estudios borrados' => [$borrar('radiologia_paciente'), false],
        ];

        foreach ($casos as $nombre => [$preparar, $esperado]) {
            $preparar();

            $deLaConsulta = collect(app(SolicitudesParaImprimir::class)->disponibles($consulta, [$medico->reg_medico], false))->isNotEmpty();
            $this->assertSame($esperado, $deLaConsulta, 'consulta: ' . $nombre);

            $agenda = $this->como($secretaria, $medico, $gineco)->get('/clinica/agenda?vista=dia&fecha=2026-10-07&sede=todas')->assertOk()->getContent();
            $this->assertSame($esperado, str_contains($agenda, 'Imprimir documentos'), 'agenda: ' . $nombre);
        }
    }
}
