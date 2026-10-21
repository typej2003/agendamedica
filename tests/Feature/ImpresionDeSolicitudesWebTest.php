<?php

namespace Tests\Feature;

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
 * **Imprimir las solicitudes de la consulta** (WEB-5.3a): constancia, reposo, referencia e informe en media hoja, con el membrete,
 * la firma y el pie del formato del médico; el texto que no entra sigue en la otra mitad; y la secretaría imprime desde la cita
 * atendida («Imprimir documentos»), sin entrar a la consulta y sin ver más clínica que lo que sale en la hoja.
 *
 * Que el cuerpo real del navegador no se desborde lo comprueba el verificador de Chrome
 * (`.agent-tools/verificar-solicitudes-documentos-web.js`); acá se fija el contenido y el reparto.
 */
class ImpresionDeSolicitudesWebTest extends TestCase
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
        $this->vademecum($medico);
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

    private function constancia(Medico $medico, string $observacion = 'Acudió a control.', ?string $diagnosticos = null): void
    {
        DB::table('constancia_obs')->insert([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'numconsulta' => 1, 'observacion' => $diagnosticos,
            'observacion01' => $observacion, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function reposo(Medico $medico, int $dias = 8, string $desde = '2026-10-10', ?string $observacion = 'Reposo por infección.'): void
    {
        DB::table('reposo_paciente')->insert([
            'reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'codereposo' => 'M', 'fdesde' => $desde,
            'numdias' => $dias, 'obser_reposo' => $observacion, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function referencia(Medico $medico, int $cedula = 7, string $motivo = 'Valoración cardiológica.', array $doctor = []): void
    {
        DB::table('especial')->updateOrInsert(['reg_medico' => $medico->reg_medico, 'codeespecial' => '002'], ['especialidad' => 'CARDIOLOGIA', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('doctores')->insert(array_merge([
            'reg_medico' => $medico->reg_medico, 'cedula' => $cedula, 'apellidos' => 'PEREZ', 'nombres' => 'LUIS', 'codeespecial' => '002',
            'clinica' => 'CLINICA SAN JOSE', 'direccion' => 'Av. 20 con calle 5', 'telefono' => '0251-5551111', 'created_at' => now(), 'updated_at' => now(),
        ], $doctor));
        DB::table('referencia')->insert([
            'reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'ceduladoctor' => $cedula, 'referencia' => $motivo,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function informe(Medico $medico, string $texto = "Paciente en control.\nSin hallazgos.", ?string $para = 'A QUIEN CORRESPONDA', ?string $fecha = '2026-10-06'): void
    {
        DB::table('informe')->insert([
            'reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'para' => $para, 'descripcion' => $texto,
            'fe_cha' => $fecha, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Las palabras de lo impreso en el cuerpo de las hojas (para comprobar que no se pierde ninguna al partir). */
    private function palabrasDelCuerpo(string $html): array
    {
        preg_match_all('#<p class="documento-parrafo">(.*?)</p>#s', $html, $parrafos);
        $texto = html_entity_decode(strip_tags(implode(' ', $parrafos[1])));

        return preg_split('/\s+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY);
    }

    // ========================================================================== Cada documento

    public function test_la_constancia_sale_con_el_texto_base_los_diagnosticos_la_observacion_y_los_datos_del_paciente(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        DB::table('diagnosticos')->insert(['reg_medico' => $medico->reg_medico, 'codediagnostico' => '0001', 'descripcion' => 'HIPERTENSION ARTERIAL', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('diagnostico_paciente')->insert(['reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'codediagnostico' => '0001', 'created_at' => now(), 'updated_at' => now()]);
        $this->constancia($medico);

        $respuesta = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'constancia'));

        $respuesta->assertOk()
            ->assertSee('CONSTANCIA')
            ->assertSee('Dra. Ana Imprime')
            ->assertSee('PACIENTE:')->assertSee('Ana Alvarez')
            ->assertSee('V-14555666')->assertSee('36 años')->assertSee('07/10/2026')
            ->assertSee('Por medio de la presente se hace constar que la (el) paciente Ana Alvarez, asistió a CONSULTA MÉDICA el día 07/10/2026.')
            ->assertSee('IDX:')->assertSee('HIPERTENSION ARTERIAL.')
            ->assertSee('Acudió a control.')
            ->assertSee('Av. Principal, Torre Salud, piso 3')
            ->assertSee('Constancia · Ana Alvarez');
        $this->assertSame(1, $this->hojasDe($respuesta->getContent()));
        // A4 vertical: el documento ocupa la página, sin mitad derecha en blanco.
        $this->assertSame(1, substr_count($respuesta->getContent(), 'recipe-mitad-documento'));
        $this->assertSame(0, substr_count($respuesta->getContent(), 'recipe-mitad-vacia'));
        $this->assertSame(1, substr_count($respuesta->getContent(), 'class="firmas"'));
        // Una constancia no tiene el diálogo de opciones del récipe.
        $respuesta->assertDontSee('data-abrir-opciones', false);
    }

    public function test_los_diagnosticos_editados_de_la_constancia_mandan_sobre_los_de_la_consulta(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        DB::table('diagnosticos')->insert(['reg_medico' => $medico->reg_medico, 'codediagnostico' => '0001', 'descripcion' => 'HIPERTENSION ARTERIAL', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('diagnostico_paciente')->insert(['reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'codediagnostico' => '0001', 'created_at' => now(), 'updated_at' => now()]);
        $this->constancia($medico, 'Obs', 'GASTRITIS AGUDA.');

        $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'constancia'))
            ->assertSee('GASTRITIS AGUDA.')->assertDontSee('HIPERTENSION ARTERIAL');
    }

    public function test_el_medico_imprime_la_constancia_base_aunque_no_tenga_nada_propio(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'constancia'))
            ->assertOk()->assertSee('asistió a CONSULTA MÉDICA el día 07/10/2026.')->assertDontSee('IDX:');
    }

    public function test_el_reposo_sale_con_los_dias_el_inicio_el_ultimo_dia_el_reintegro_y_la_fecha_del_inicio(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->reposo($medico, 8, '2026-10-10');

        $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'reposo'))
            ->assertOk()
            ->assertSee('REPOSO MÉDICO')
            ->assertSee('REPOSO DURANTE:')->assertSee('8 día(s)')
            ->assertSee('A PARTIR DE LA FECHA:')->assertSee('10/10/2026')
            ->assertSee('HASTA EL DÍA:')->assertSee('17/10/2026')
            ->assertSee('REINTEGRO A SUS LABORES EL DÍA:')->assertSee('18/10/2026')
            ->assertSee('Reposo por infección.')
            // La cabecera se fecha con el inicio del reposo (como el escritorio: «Fecha: fdesde»).
            ->assertSee('FECHA:');
    }

    public function test_el_reposo_de_un_dia_con_justificacion_cuenta_el_mismo_dia_como_ultimo_y_la_plantilla_vacia_no_se_imprime(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->reposo($medico, 1, '2026-12-31', 'Reposo de un día.');

        // 31/12 + 1 − 1 = 31/12 y reintegro el 01/01 (cruza el año).
        $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'reposo'))
            ->assertSee('31/12/2026')->assertSee('01/01/2027');

        DB::table('reposo_paciente')->where('reg_medico', $medico->reg_medico)->update(['obser_reposo' => null]);
        $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'reposo'))->assertOk()->assertSee('Guarda los datos del estudio para poder imprimir.');
    }

    public function test_la_referencia_sale_con_el_medico_del_catalogo_y_el_motivo_y_omite_lo_que_no_tiene(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->referencia($medico, 7, 'Valoración cardiológica por soplo.', ['telefono' => null]);

        $hoja = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'referencia-7'));

        $hoja->assertOk()
            ->assertSee('REFERENCIA')
            ->assertSee('REFERIDO A:')->assertSee('LUIS PEREZ')
            ->assertSee('ESPECIALIDAD:')->assertSee('CARDIOLOGIA')
            ->assertSee('CLÍNICA:')->assertSee('CLINICA SAN JOSE')
            ->assertSee('DIRECCIÓN:')->assertSee('Av. 20 con calle 5')
            ->assertSee('MOTIVO DE LA REFERENCIA:')->assertSee('Valoración cardiológica por soplo.')
            ->assertDontSee('TELÉFONO:');
    }

    public function test_el_informe_sale_con_el_para_el_texto_en_renglones_y_su_fecha(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->informe($medico);

        $hoja = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'informe'));

        $hoja->assertOk()
            ->assertSee('INFORME MÉDICO')
            ->assertSee('PARA:')->assertSee('A QUIEN CORRESPONDA')
            ->assertSee('Paciente en control.')->assertSee('Sin hallazgos.')
            ->assertSee('06/10/2026');
        // Cada renglón del texto es un párrafo.
        $this->assertSame(2, substr_count($hoja->getContent(), 'class="documento-parrafo"'));
    }

    public function test_las_solicitudes_salen_en_a4_vertical_grande_por_defecto_y_pequena_a_eleccion(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->informe($medico);
        $this->reposo($medico, 3, '2026-10-10');

        foreach (['informe', 'reposo', 'constancia'] as $documento) {
            $grande = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, $documento))->assertOk()->getContent();
            $this->assertStringContainsString('recipe-hoja recipe-hoja-completa tamano-', $grande, $documento);
            $this->assertStringNotContainsString('recipe-mitad-vacia', $grande, $documento);
            $this->assertMatchesRegularExpression('/pagina=grande"\s+aria-current="true"\s*>Grande</', $grande);

            $pequena = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, $documento) . '&pagina=pequena')->assertOk()->getContent();
            $this->assertStringContainsString('recipe-hoja recipe-hoja-completa recipe-hoja-mitad-superior', $pequena, $documento);
            $this->assertMatchesRegularExpression('/pagina=pequena"\s+aria-current="true"\s*>Pequeña</', $pequena);
        }

        // Con un récipe en el lote la elección no le cambia la hoja: sigue en media hoja horizontal.
        $this->recipe($medico, 1001, 1, 1, ['0001'], '2026-10-05');
        $todos = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'todos') . '&pagina=pequena')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/class="recipe-hoja tamano-/', $todos);
    }

    public function test_los_documentos_llevan_el_membrete_y_la_linea_del_formato_del_medico(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        DB::table('recipe_formatos')->insert([
            'reg_medico' => $medico->reg_medico, 'color_linea' => 'azul', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->informe($medico);

        $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'informe'))
            ->assertSee('linea-azul', false)
            ->assertSee('Ginecología y Obstetricia')
            ->assertSee('J-12345678-9')
            ->assertSee('0251-5551234');
    }

    // ================================================================== El texto largo sigue en la otra mitad

    private function textoLargo(int $palabras): string
    {
        return collect(range(1, $palabras))->map(fn ($n) => 'palabra' . $n)->implode(' ');
    }

    public function test_un_informe_largo_en_pagina_pequena_sigue_en_otra_pagina_con_continua_y_continuacion_y_la_firma_solo_al_final(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $texto = $this->textoLargo(330);
        $this->informe($medico, $texto);

        $respuesta = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'informe') . '&pagina=pequena');
        $html = $respuesta->getContent();

        $respuesta->assertOk()->assertSee('Continúa')->assertSee('INFORME MÉDICO (continuación)');
        $this->assertSame(2, $this->hojasDe($html), 'la mitad superior lleva un trozo por página');
        $this->assertSame(2, substr_count($html, 'recipe-mitad-documento'));
        $this->assertSame(1, substr_count($html, 'class="firmas"'), 'la firma va sólo en la última mitad');
        $this->assertSame(1, substr_count($html, 'class="documento-continua"'));
        // La continuación no repite el membrete: sólo el encabezado corto.
        $this->assertSame(1, substr_count($html, 'class="membrete '));
        $this->assertSame(1, substr_count($html, 'documento-continuacion-datos'));
        // Nada se pierde ni se reordena al partir.
        $this->assertSame(explode(' ', $texto), $this->palabrasDelCuerpo($html));
    }

    public function test_un_texto_larguisimo_sigue_en_otra_hoja_sin_perder_palabras(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $texto = $this->textoLargo(1500);
        $this->informe($medico, $texto);

        $html = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'informe') . '&pagina=pequena')->getContent();

        $this->assertGreaterThan(1, $this->hojasDe($html));
        $this->assertSame(1, substr_count($html, 'class="firmas"'));
        $this->assertSame(explode(' ', $texto), $this->palabrasDelCuerpo($html));
        // Con tanto texto la letra baja al escalón más chico.
        $this->assertStringContainsString('tamano-8', $html);
    }

    public function test_un_texto_corto_se_imprime_a_10_pt_y_en_una_sola_mitad(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->informe($medico, 'Texto breve.');

        $html = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'informe'))->getContent();

        $this->assertStringContainsString('tamano-10', $html);
        $this->assertSame(1, substr_count($html, 'recipe-mitad-documento'));
        $this->assertStringNotContainsString('Continúa', $html);
    }

    // ================================================================================== «todos» y 404

    public function test_todos_imprime_lo_que_la_consulta_tiene_y_la_constancia_vacia_no_entra_en_todos(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->reposo($medico);
        $this->informe($medico);
        $this->recipe($medico, 1001, 1, 1, ['0001'], '2026-10-05');

        $respuesta = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'todos'));

        $respuesta->assertOk()
            ->assertSee('Documentos de Ana Alvarez')
            ->assertSee('Un récipe')
            ->assertSee('2 documentos')
            ->assertSee('3 hojas')
            ->assertSee('KEFORAL')->assertSee('REPOSO MÉDICO')->assertSee('INFORME MÉDICO')
            ->assertDontSee('CONSTANCIA');
        // Hay un récipe entre los documentos: se ofrecen las opciones de la hoja.
        $respuesta->assertSee('data-abrir-opciones', false);
    }

    public function test_un_documento_que_la_consulta_no_tiene_o_sin_elegir_avisa_que_no_hay_nada_que_imprimir(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->reposo($medico, 1, '2026-10-07', null); // la plantilla vacía del escritorio

        // O-16: no es un 404 —la consulta y el documento son válidos—; la página dice qué falta para poder imprimir.
        foreach (['reposo', 'informe', 'referencia-7', 'recipe-1', 'abc', 'todos', ''] as $clave) {
            $this->como($user, $medico, $gineco)->get($this->hoja($consulta, $clave))
                ->assertOk()
                ->assertSee('Guarda los datos del estudio para poder imprimir.');
        }

        $this->como($user, $medico, $gineco)->get(route('clinica.consulta.documentos.imprimir', $consulta->id))
            ->assertOk()
            ->assertSee('Guarda los datos del estudio para poder imprimir.');
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

    public function test_la_secretaria_ve_la_lista_de_todo_lo_imprimible_sin_el_contenido_clinico(): void
    {
        [, $medico, $gineco, , $secretaria, $cita] = $this->escenario();
        $this->recipe($medico, 1001, 1, 1, [['0001', 2, 'Una cápsula cada 6 horas.']], '2026-10-05');
        $this->constancia($medico, 'Observación reservada del médico.');
        $this->reposo($medico, 8, '2026-10-10', 'Justificación reservada.');
        $this->referencia($medico, 7, 'Motivo reservado.');
        $this->informe($medico, 'Texto reservado del informe.');

        $respuesta = $this->lista($secretaria, $medico, $gineco, $cita);

        $respuesta->assertOk()
            ->assertSee('Documentos de Ana Alvarez')
            ->assertSee('Récipe 1')->assertSee('Constancia')->assertSee('Reposo médico')->assertSee('8 días')
            ->assertSee('Referencia')->assertSee('Informe médico')
            ->assertSee('Imprimir todos');
        $respuesta->assertSee('documento=constancia', false)->assertSee('documento=reposo', false)->assertSee('documento=referencia-7', false)
            ->assertSee('documento=informe', false)->assertSee('documento=todos', false)->assertSee('documento=recipe-1', false);
        // El récipe pasa por las opciones de la hoja; la constancia, el reposo, la referencia y el informe van directo.
        $respuesta->assertSee('id="dialogo-imprimir-recipe"', false);
        foreach (['Observación reservada', 'Justificación reservada', 'Motivo reservado', 'Texto reservado', 'KEFORAL', 'Una cápsula'] as $clinico) {
            $respuesta->assertDontSee($clinico);
        }
    }

    public function test_la_secretaria_imprime_cada_documento_y_todos_juntos(): void
    {
        [, $medico, $gineco, , $secretaria, $cita] = $this->escenario();
        $this->recipe($medico, 1001, 1, 1, ['0001'], '2026-10-05');
        $this->constancia($medico);
        $this->reposo($medico);
        $this->referencia($medico);
        $this->informe($medico);

        $como = fn (string $documento) => $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita, $documento);

        $como('constancia')->assertOk()->assertSee('CONSTANCIA')->assertSee('Acudió a control.');
        $como('reposo')->assertOk()->assertSee('REPOSO MÉDICO')->assertSee('18/10/2026');
        $como('referencia-7')->assertOk()->assertSee('REFERENCIA')->assertSee('LUIS PEREZ');
        $como('informe')->assertOk()->assertSee('INFORME MÉDICO');
        $como('recipe-1')->assertOk()->assertSee('KEFORAL / CAP. 500 MG');

        $todos = $como('todos');
        $todos->assertOk()->assertSee('Un récipe')->assertSee('4 documentos');
        foreach (['KEFORAL', 'CONSTANCIA', 'REPOSO MÉDICO', 'REFERENCIA', 'INFORME MÉDICO'] as $texto) {
            $todos->assertSee($texto);
        }
    }

    public function test_la_hoja_de_la_secretaria_es_la_misma_que_la_del_medico(): void
    {
        [$user, $medico, $gineco, $consulta, $secretaria, $cita] = $this->escenario();
        $this->informe($medico, $this->textoLargo(330));

        $cuerpo = fn (string $html) => substr($html, strpos($html, '<main class="recipe-hojas"'), strpos($html, '</main>') - strpos($html, '<main class="recipe-hojas"'));

        $medicoVe = $this->como($user, $medico, $gineco)->get($this->hoja($consulta, 'informe'))->getContent();
        $secretariaVe = $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita, 'informe')->getContent();

        $this->assertSame($cuerpo($medicoVe), $cuerpo($secretariaVe));
    }

    public function test_con_un_solo_documento_la_lista_se_salta_y_va_directo_a_la_hoja(): void
    {
        [, $medico, $gineco, , $secretaria, $cita] = $this->escenario();
        $this->informe($medico);

        $this->lista($secretaria, $medico, $gineco, $cita)->assertRedirect(route('clinica.agenda.documentos.imprimir', [$cita->id, 'documento' => 'informe']));
        // Sin `documento` imprime el único que tenga.
        $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita)->assertOk()->assertSee('INFORME MÉDICO');
    }

    public function test_la_secretaria_no_recibe_la_constancia_base_ni_las_plantillas_vacias_del_escritorio(): void
    {
        [, $medico, $gineco, , $secretaria, $cita] = $this->escenario();
        // Lo que crea el escritorio al abrir la consulta: constancia sin nada, reposo de 1 día, informe `.`.
        DB::table('constancia_obs')->insert(['reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'numconsulta' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->reposo($medico, 1, '2026-10-07', null);
        $this->informe($medico, '.', 'INFORME MÉDICO', '2026-10-07');

        $this->lista($secretaria, $medico, $gineco, $cita)->assertOk()->assertSee('Esta consulta no tiene documentos para imprimir');
        foreach (['constancia', 'reposo', 'informe'] as $clave) {
            $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita, $clave)->assertNotFound();
        }

        // Y la agenda no le ofrece el botón.
        $agenda = $this->como($secretaria, $medico, $gineco)->get('/clinica/agenda?vista=dia&fecha=2026-10-07&sede=todas');
        $agenda->assertDontSee('Imprimir documentos');
    }

    public function test_el_boton_de_la_agenda_aparece_con_un_documento_que_no_es_un_recipe(): void
    {
        [$user, $medico, $gineco, , $secretaria, $cita] = $this->escenario();
        $this->referencia($medico);

        foreach ([$secretaria, $user] as $quien) {
            $this->como($quien, $medico, $gineco)->get('/clinica/agenda?vista=dia&fecha=2026-10-07&sede=todas')
                ->assertOk()
                ->assertSee('Imprimir documentos')
                ->assertSee('href="' . route('clinica.agenda.documentos', $cita->id) . '"', false);
        }
    }

    public function test_la_secretaria_solo_imprime_lo_que_la_cita_tiene_y_no_entra_a_la_consulta(): void
    {
        [, $medico, $gineco, $consulta, $secretaria, $cita] = $this->escenario();
        $this->reposo($medico);

        $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita, 'informe')->assertNotFound();
        $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita, 'abc')->assertNotFound();
        $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita, 'reposo')->assertOk();

        $como = fn (string $url) => $this->como($secretaria, $medico, $gineco)->get($url);
        $como(route('clinica.consulta', $consulta->id))->assertForbidden();
        $como($this->hoja($consulta, 'reposo'))->assertForbidden();
    }

    public function test_una_cita_sin_atender_o_movida_no_imprime_nada(): void
    {
        [, $medico, $gineco, , $secretaria] = $this->escenario();
        $this->reposo($medico);
        $sinAtender = $this->cita($medico, 1001, '2026-10-07', ['atendido' => 0, 'numorden' => 2]);
        $movida = $this->cita($medico, 1001, '2026-10-07', ['atendido' => 1, 'movida_escritorio' => 1, 'numorden' => 3]);

        $this->lista($secretaria, $medico, $gineco, $sinAtender)->assertOk()->assertSee('Esta cita todavía no fue atendida');
        $this->hojaDeLaCita($secretaria, $medico, $gineco, $sinAtender, 'reposo')->assertNotFound();
        $this->hojaDeLaCita($secretaria, $medico, $gineco, $movida, 'reposo')->assertNotFound();
    }

    public function test_la_cita_de_otro_medico_da_404_y_el_invitado_va_al_login(): void
    {
        [, $medico, , , , $cita] = $this->escenario();
        $this->reposo($medico);
        [$otroUser, $otroMedico, $otraGineco] = $this->medicoConAcceso();
        $otraSecretaria = $this->secretariaConAcceso($otroMedico);

        foreach ([$otroUser, $otraSecretaria] as $quien) {
            $this->lista($quien, $otroMedico, $otraGineco, $cita)->assertNotFound();
            $this->hojaDeLaCita($quien, $otroMedico, $otraGineco, $cita, 'reposo')->assertNotFound();
        }
    }

    public function test_el_invitado_va_al_login_desde_la_cita(): void
    {
        [, $medico, , , , $cita] = $this->escenario();
        $this->reposo($medico);

        // Va en su propio test: después de un `actingAs` el siguiente `get` «de invitado» sigue autenticado (trampa 47).
        $this->get(route('clinica.agenda.documentos', $cita->id))->assertRedirect(route('login'));
        $this->get(route('clinica.agenda.documentos.imprimir', [$cita->id, 'documento' => 'reposo']))->assertRedirect(route('login'));
    }

    // ============================================== Una sola regla de «qué es un documento» (conjunto y consulta)

    public function test_el_lote_de_la_agenda_y_la_consulta_coinciden_en_que_es_un_documento(): void
    {
        [, $medico, , $consulta] = $this->escenario();

        $casos = [
            // El resumen médico (WEB-5.3c) es una hoja auto-generada: es documento si algún bloque de la
            // consulta tiene datos, y deja de serlo cuando no queda ninguno.
            'resumen con enfermedad actual' => [fn () => DB::table('consultas')->where('reg_medico', $medico->reg_medico)->update(['enfermedadactual' => 'Dolor de dos días.']), true],
            'resumen vuelto a vaciar' => [fn () => DB::table('consultas')->where('reg_medico', $medico->reg_medico)->update(['enfermedadactual' => null]), false],
            'constancia vacía' => [fn () => DB::table('constancia_obs')->insert(['reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'numconsulta' => 1, 'created_at' => now(), 'updated_at' => now()]), false],
            'constancia con observación' => [fn () => DB::table('constancia_obs')->where('reg_medico', $medico->reg_medico)->update(['observacion01' => 'Algo']), true],
            'constancia con diagnósticos editados' => [fn () => DB::table('constancia_obs')->where('reg_medico', $medico->reg_medico)->update(['observacion01' => null, 'observacion' => 'GASTRITIS.']), true],
            'constancia vuelta a vacía' => [fn () => DB::table('constancia_obs')->where('reg_medico', $medico->reg_medico)->delete(), false],
            'reposo de un día sin nada' => [fn () => $this->reposo($medico, 1, '2026-10-07', null), false],
            'reposo de un día con justificación' => [fn () => DB::table('reposo_paciente')->where('reg_medico', $medico->reg_medico)->update(['obser_reposo' => 'Motivo']), true],
            'reposo de varios días' => [fn () => DB::table('reposo_paciente')->where('reg_medico', $medico->reg_medico)->update(['obser_reposo' => null, 'numdias' => 3]), true],
            'reposo borrado' => [fn () => DB::table('reposo_paciente')->where('reg_medico', $medico->reg_medico)->delete(), false],
            'informe con punto' => [fn () => $this->informe($medico, '.'), false],
            'informe con texto' => [fn () => DB::table('informe')->where('reg_medico', $medico->reg_medico)->update(['descripcion' => 'Texto']), true],
            'informe borrado' => [fn () => DB::table('informe')->where('reg_medico', $medico->reg_medico)->delete(), false],
            'referencia' => [fn () => $this->referencia($medico), true],
        ];

        $secretaria = $this->secretariaConAcceso($medico);
        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();

        foreach ($casos as $nombre => [$preparar, $esperado]) {
            $preparar();

            $deLaConsulta = collect(app(SolicitudesParaImprimir::class)->disponibles($consulta, [$medico->reg_medico], false))->isNotEmpty();
            $this->assertSame($esperado, $deLaConsulta, 'consulta: ' . $nombre);

            // Y el lote de la agenda (la consulta de conjunto en SQL) decide lo mismo: el botón aparece si y sólo si hay documento.
            $agenda = $this->como($secretaria, $medico, $gineco)->get('/clinica/agenda?vista=dia&fecha=2026-10-07&sede=todas')->assertOk()->getContent();
            $this->assertSame($esperado, str_contains($agenda, 'Imprimir documentos'), 'agenda: ' . $nombre);
        }
    }
}
