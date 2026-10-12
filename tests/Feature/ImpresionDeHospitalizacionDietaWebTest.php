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
 * **Imprimir la orden de hospitalización y la dieta / recomendaciones** (WEB-5.3b): cada una en media hoja, con el membrete, la firma y
 * el pie del formato del médico; el texto que no entra sigue en la otra mitad; y la secretaría las imprime desde la cita atendida
 * («Imprimir documentos») sin ver el contenido clínico.
 *
 * Que el cuerpo real del navegador no se desborde lo comprueba el verificador de Chrome
 * (`.agent-tools/verificar-hospitalizacion-dietas-web.js`); acá se fija el contenido, el reparto y la lista de la cita.
 */
class ImpresionDeHospitalizacionDietaWebTest extends TestCase
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

    private function orden(Medico $medico, array $extra = []): void
    {
        DB::table('hospitalizacion')->insert(array_merge([
            'reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1,
            'hospital' => 'CLINICA SANTA MONICA', 'diagprev' => 'EMBARAZO DE 39 SEMANAS', 'dieta' => 'Ayuno y traer exámenes.',
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function dieta(Medico $medico, array $extra = []): void
    {
        DB::table('dieta_paciente')->insert(array_merge([
            'reg_medico' => $medico->reg_medico, 'histroria' => 1001, 'consulta' => 1,
            'dieta' => 'DIETA HIPOSÓDICA', 'descripcion' => "Evitar sal.\nBeber 2 litros de agua.", 'ejecutada' => 'S',
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    private function textoLargo(int $palabras): string
    {
        return collect(range(1, $palabras))->map(fn ($n) => 'palabra' . $n)->implode(' ');
    }

    /** Las palabras de lo impreso en el cuerpo de las hojas (para comprobar que no se pierde ninguna al partir). */
    private function palabrasDelCuerpo(string $html): array
    {
        preg_match_all('#<p class="documento-parrafo">(.*?)</p>#s', $html, $parrafos);
        // El rótulo «aparte» va pegado al texto en el HTML (el CSS lo pone en su propia línea): se separa para contar palabras.
        $texto = html_entity_decode(strip_tags(str_replace('</strong>', '</strong> ', implode(' ', $parrafos[1]))));

        return preg_split('/\s+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY);
    }

    // ========================================================================== Cada documento

    public function test_la_orden_sale_con_el_lugar_el_diagnostico_las_recomendaciones_y_los_datos_del_paciente(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->orden($medico);

        $hoja = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'hospitalizacion'));

        $hoja->assertOk()
            ->assertSee('ORDEN DE HOSPITALIZACIÓN')
            ->assertSee('PACIENTE:')->assertSee('Ana Alvarez')
            ->assertSee('C.I.:')->assertSee('V-14555666')
            ->assertSee('FECHA:')->assertSee('07/10/2026')
            ->assertSee('HOSPITALIZAR EN:')->assertSee('CLINICA SANTA MONICA')
            ->assertSee('DIAGNÓSTICO PROVISIONAL:')->assertSee('EMBARAZO DE 39 SEMANAS')
            ->assertSee('RECOMENDACIONES:')->assertSee('Ayuno y traer exámenes.')
            ->assertSee('Ginecología y Obstetricia');
        $this->assertSame(1, substr_count($hoja->getContent(), 'class="firmas"'));
    }

    public function test_la_orden_omite_lo_que_no_esta_escrito_y_no_imprime_indiprev_ni_habitacion(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->orden($medico, ['hospital' => null, 'dieta' => '', 'indiprev' => 'INDICACION PREVIA SECRETA', 'habitacion' => '4']);

        $hoja = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'hospitalizacion'));

        $hoja->assertOk()
            ->assertSee('DIAGNÓSTICO PROVISIONAL:')
            ->assertDontSee('HOSPITALIZAR EN:')
            ->assertDontSee('RECOMENDACIONES:')
            ->assertDontSee('INDICACION PREVIA SECRETA')
            ->assertDontSee('Suite');
    }

    public function test_la_dieta_sale_con_su_titulo_en_negrita_y_el_texto_en_renglones(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->dieta($medico);

        $hoja = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'dieta'));

        $hoja->assertOk()
            ->assertSee('DIETA / RECOMENDACIONES')
            ->assertSee('DIETA:')->assertSee('DIETA HIPOSÓDICA')
            ->assertSee('Evitar sal.')->assertSee('Beber 2 litros de agua.')
            ->assertSee('07/10/2026');
        $this->assertSame(2, substr_count($hoja->getContent(), 'class="documento-parrafo"'));
    }

    public function test_la_dieta_sin_titulo_no_imprime_el_rotulo_vacio(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->dieta($medico, ['dieta' => null]);

        $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'dieta'))
            ->assertOk()
            ->assertSee('DIETA / RECOMENDACIONES')
            ->assertDontSee('DIETA:')
            ->assertSee('Evitar sal.');
    }

    public function test_los_dos_llevan_el_membrete_y_la_linea_del_formato_del_medico(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        DB::table('recipe_formatos')->insert(['reg_medico' => $medico->reg_medico, 'color_linea' => 'azul', 'created_at' => now(), 'updated_at' => now()]);
        $this->orden($medico);
        $this->dieta($medico);

        foreach (['hospitalizacion', 'dieta'] as $clave) {
            $this->como($user, $medico, $gineco)->get($this->hoja($consulta, $clave))
                ->assertSee('linea-azul', false)
                ->assertSee('J-12345678-9')
                ->assertSee('0251-5551234');
        }
    }

    // ================================================================== El texto largo sigue en la otra mitad

    public function test_una_orden_larga_sigue_en_la_otra_mitad_con_continua_y_continuacion_y_la_firma_solo_al_final(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $diagnostico = $this->textoLargo(200);
        $recomendaciones = collect(range(1, 140))->map(fn ($n) => 'rec' . $n)->implode(' ');
        $this->orden($medico, ['diagprev' => $diagnostico, 'dieta' => $recomendaciones]);

        $respuesta = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'hospitalizacion'));
        $html = $respuesta->getContent();

        $respuesta->assertOk()->assertSee('Continúa')->assertSee('ORDEN DE HOSPITALIZACIÓN (continuación)');
        $this->assertSame(1, $this->hojasDe($html));
        $this->assertSame(2, substr_count($html, 'recipe-mitad-documento'));
        $this->assertSame(1, substr_count($html, 'class="firmas"'));
        $this->assertSame(1, substr_count($html, 'class="membrete '));
        // Nada se pierde ni se reordena al partir.
        $this->assertSame(
            array_merge(['DIAGNÓSTICO', 'PROVISIONAL:'], explode(' ', $diagnostico), ['RECOMENDACIONES:'], explode(' ', $recomendaciones)),
            $this->palabrasDelCuerpo($html),
        );
    }

    public function test_una_dieta_larga_sigue_en_la_otra_mitad_y_una_larguisima_en_otra_hoja_sin_perder_palabras(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $texto = $this->textoLargo(330);
        $this->dieta($medico, ['descripcion' => $texto]);

        $respuesta = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'dieta'));
        $html = $respuesta->getContent();

        $respuesta->assertSee('Continúa')->assertSee('DIETA / RECOMENDACIONES (continuación)');
        $this->assertSame(2, substr_count($html, 'recipe-mitad-documento'));
        $this->assertSame(1, substr_count($html, 'class="firmas"'));
        $this->assertSame(explode(' ', $texto), $this->palabrasDelCuerpo($html));

        DB::table('dieta_paciente')->where('reg_medico', $medico->reg_medico)->update(['descripcion' => $this->textoLargo(1500)]);
        $html = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'dieta'))->getContent();
        $this->assertGreaterThan(1, $this->hojasDe($html));
        $this->assertSame(1, substr_count($html, 'class="firmas"'));
        $this->assertSame(explode(' ', $this->textoLargo(1500)), $this->palabrasDelCuerpo($html));
    }

    // ================================================================================== «todos» y 404

    public function test_todos_suma_la_orden_y_la_dieta_y_las_filas_vacias_dan_404(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->orden($medico, ['hospital' => null, 'diagprev' => null, 'dieta' => null]);   // la fila vacía del escritorio
        $this->dieta($medico, ['descripcion' => '']);

        foreach (['hospitalizacion', 'dieta', 'todos'] as $clave) {
            $this->como($user, $medico, $gineco)->get($this->hoja($consulta, $clave))->assertNotFound();
        }

        DB::table('hospitalizacion')->where('reg_medico', $medico->reg_medico)->update(['hospital' => 'CLINICA A']);
        DB::table('dieta_paciente')->where('reg_medico', $medico->reg_medico)->update(['descripcion' => 'Dieta liviana.']);

        $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'todos'))
            ->assertOk()
            ->assertSee('2 documentos')
            ->assertSee('ORDEN DE HOSPITALIZACIÓN')
            ->assertSee('DIETA / RECOMENDACIONES');
    }

    public function test_las_hojas_de_antes_no_cambian_con_los_dos_documentos_nuevos(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        DB::table('informe')->insert(['reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'para' => 'A QUIEN CORRESPONDA',
            'descripcion' => "Paciente en control.\nSin hallazgos.", 'fe_cha' => '2026-10-06', 'created_at' => now(), 'updated_at' => now()]);
        $solo = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'informe'))->getContent();

        $this->orden($medico);
        $this->dieta($medico);
        $con = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'informe'))->getContent();

        $this->assertSame($solo, $con);
        $disponibles = app(DocumentosParaImprimir::class)->disponibles($consulta, [$medico->reg_medico]);
        $this->assertSame(['hospitalizacion', 'informe', 'dieta'], array_column($disponibles, 'clave'), 'el orden de las pestañas del escritorio');
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

    public function test_la_secretaria_ve_la_orden_y_la_dieta_en_la_lista_sin_el_contenido_clinico(): void
    {
        [, $medico, $gineco, , $secretaria, $cita] = $this->escenario();
        $this->orden($medico, ['hospital' => 'HOSPITAL RESERVADO', 'diagprev' => 'DIAGNOSTICO RESERVADO', 'dieta' => 'RECOMENDACION RESERVADA']);
        $this->dieta($medico, ['dieta' => 'TITULO DE DIETA', 'descripcion' => 'TEXTO RESERVADO DE LA DIETA']);

        $respuesta = $this->lista($secretaria, $medico, $gineco, $cita);

        $respuesta->assertOk()
            ->assertSee('Documentos de Ana Alvarez')
            ->assertSee('Hospitalización')->assertSee('Dieta / Recomendaciones')
            ->assertSee('Imprimir todos')
            ->assertSee('documento=hospitalizacion', false)->assertSee('documento=dieta', false)->assertSee('documento=todos', false);
        foreach (['HOSPITAL RESERVADO', 'DIAGNOSTICO RESERVADO', 'RECOMENDACION RESERVADA', 'TITULO DE DIETA', 'TEXTO RESERVADO'] as $clinico) {
            $respuesta->assertDontSee($clinico);
        }
    }

    public function test_la_secretaria_imprime_la_orden_y_la_dieta_y_la_hoja_es_la_misma_que_la_del_medico(): void
    {
        [$user, $medico, $gineco, $consulta, $secretaria, $cita] = $this->escenario();
        $this->orden($medico, ['dieta' => $this->textoLargo(200)]);
        $this->dieta($medico);

        $cuerpo = fn (string $html) => substr($html, strpos($html, '<main class="recipe-hojas"'), strpos($html, '</main>') - strpos($html, '<main class="recipe-hojas"'));

        foreach (['hospitalizacion' => 'ORDEN DE HOSPITALIZACIÓN', 'dieta' => 'DIETA / RECOMENDACIONES'] as $clave => $titulo) {
            $secretariaVe = $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita, $clave);
            $secretariaVe->assertOk()->assertSee($titulo);
            $medicoVe = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, $clave))->getContent();
            $this->assertSame($cuerpo($medicoVe), $cuerpo($secretariaVe->getContent()), $clave);
        }

        $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita, 'todos')->assertOk()->assertSee('2 documentos');
    }

    public function test_la_secretaria_no_recibe_la_fila_vacia_del_escritorio_y_la_agenda_no_le_ofrece_el_boton(): void
    {
        [, $medico, $gineco, , $secretaria, $cita] = $this->escenario();
        $this->orden($medico, ['hospital' => null, 'diagprev' => null, 'dieta' => null]);

        $this->lista($secretaria, $medico, $gineco, $cita)->assertOk()->assertSee('Esta consulta no tiene documentos para imprimir');
        $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita, 'hospitalizacion')->assertNotFound();
        $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita, 'dieta')->assertNotFound();
        $this->como($secretaria, $medico, $gineco)->get('/clinica/agenda?vista=dia&fecha=2026-10-07&sede=todas')->assertDontSee('Imprimir documentos');
    }

    public function test_con_un_solo_documento_nuevo_la_lista_se_salta_y_va_directo_a_la_hoja(): void
    {
        [, $medico, $gineco, , $secretaria, $cita] = $this->escenario();
        $this->dieta($medico);

        $this->lista($secretaria, $medico, $gineco, $cita)->assertRedirect(route('clinica.agenda.documentos.imprimir', [$cita->id, 'documento' => 'dieta']));
        $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita)->assertOk()->assertSee('DIETA / RECOMENDACIONES');
    }

    public function test_la_orden_y_la_dieta_de_otro_medico_no_se_imprimen(): void
    {
        [, $medico, , , , $cita] = $this->escenario();
        $this->orden($medico);
        $this->dieta($medico);
        [$otroUser, $otroMedico, $otraGineco] = $this->medicoConAcceso();
        $otraSecretaria = $this->secretariaConAcceso($otroMedico);

        foreach ([$otroUser, $otraSecretaria] as $quien) {
            $this->lista($quien, $otroMedico, $otraGineco, $cita)->assertNotFound();
            $this->hojaDeLaCita($quien, $otroMedico, $otraGineco, $cita, 'dieta')->assertNotFound();
        }
    }

    public function test_el_lote_de_la_agenda_y_la_consulta_coinciden_en_que_es_una_orden_o_una_dieta(): void
    {
        [, $medico, , $consulta] = $this->escenario();
        $secretaria = $this->secretariaConAcceso($medico);
        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();

        $casos = [
            'orden vacía del escritorio' => [fn () => $this->orden($medico, ['hospital' => null, 'diagprev' => null, 'dieta' => null]), false],
            'orden de cadenas vacías' => [fn () => DB::table('hospitalizacion')->where('reg_medico', $medico->reg_medico)->update(['hospital' => '', 'diagprev' => '  ', 'dieta' => '']), false],
            'orden sólo con indiprev y habitación' => [fn () => DB::table('hospitalizacion')->where('reg_medico', $medico->reg_medico)->update(['indiprev' => 'X', 'habitacion' => '2']), false],
            'orden con el lugar' => [fn () => DB::table('hospitalizacion')->where('reg_medico', $medico->reg_medico)->update(['hospital' => 'CLINICA']), true],
            'orden con sólo el diagnóstico' => [fn () => DB::table('hospitalizacion')->where('reg_medico', $medico->reg_medico)->update(['hospital' => null, 'diagprev' => 'DX']), true],
            'orden con sólo las recomendaciones' => [fn () => DB::table('hospitalizacion')->where('reg_medico', $medico->reg_medico)->update(['diagprev' => null, 'dieta' => 'REC']), true],
            'orden borrada' => [fn () => DB::table('hospitalizacion')->where('reg_medico', $medico->reg_medico)->delete(), false],
            'dieta sin texto' => [fn () => $this->dieta($medico, ['descripcion' => null]), false],
            'dieta con texto' => [fn () => DB::table('dieta_paciente')->where('reg_medico', $medico->reg_medico)->update(['descripcion' => 'Liviana']), true],
            'dieta borrada' => [fn () => DB::table('dieta_paciente')->where('reg_medico', $medico->reg_medico)->delete(), false],
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
