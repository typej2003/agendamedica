<?php

namespace Tests\Feature;

use App\Clinica\Impresion\DocumentoImpreso;
use App\Clinica\Impresion\DocumentosParaImprimir;
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
 * **El resumen médico de una consulta** (WEB-5.3c): la hoja que el escritorio arma sola con los datos de
 * toda la consulta (`d_resumen_medico_gra`), en media hoja A4 como los demás documentos, con el membrete,
 * el `informe.para` en la cabecera y la firma.
 *
 * Acá se fija lo que el motor de documentos no puede saber solo: que los ocho rótulos salgan **con los
 * datos que la fuente dice** (motivo del catálogo ordenado por descripción, diagnósticos por `orden` con
 * la descripción del catálogo, tratamiento desde `recipes` + vademécum), que un bloque sin datos no
 * imprima su rótulo, que una consulta sin nada no ofrezca la hoja, que una consulta de **otro médico** dé
 * 404 y que la secretaría no pueda entrar a la consulta (403) pero sí imprimir el resumen desde la cita
 * atendida.
 *
 * Que el cuerpo real del navegador no se desborde con la caja del «PARA» lo comprueba el verificador de
 * Chrome (`.agent-tools/verificar-resumen-medico-web.js`).
 */
class ImpresionDeResumenMedicoWebTest extends TestCase
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

    /** @return array{0:User, 1:Medico, 2:Specialty, 3:Consulta, 4:User, 5:Cola, 6:int} */
    private function escenario(): array
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->membrete($medico);
        $this->vademecum($medico);
        $secretaria = $this->secretariaConAcceso($medico);
        // La fecha de nacimiento es la del día siguiente al `fe_cha` del informe: la edad de la cabecera
        // distingue la fecha del documento (06/10) de la de la consulta (07/10).
        [$pacienteId, $consulta] = $this->pacienteYConsulta($medico, 1001, 1, ['fnacimiento' => '1990-10-07']);
        $cita = $this->cita($medico, 1001, $this->hoy->toDateString(), ['atendido' => 1, 'estado' => 1]);

        return [$user, $medico, $gineco, $consulta, $secretaria, $cita, $pacienteId];
    }

    private function como(User $user, Medico $medico, Specialty $gineco): self
    {
        return $this->conContexto($user, $medico, $gineco)->actingAs($user);
    }

    private function hoja(Consulta $consulta, string $documento = DocumentoImpreso::RESUMEN_MEDICO): string
    {
        return route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => $documento]);
    }

    /** La pestaña *Informe médico* de la consulta (donde vive el botón del resumen, como en el escritorio). */
    private function pestanaInforme(Consulta $consulta): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => 'solicitudes', 'solicitud' => 'informe']);
    }

    /** Los ocho bloques, con los datos repartidos como los guarda el escritorio. */
    private function resumen(Medico $medico, Consulta $consulta): void
    {
        DB::table('consultas')->where('id', $consulta->id)->update([
            'enfermedadactual' => 'Dolor abdominal de dos días.',
            'oido'             => 'Hemograma dentro de límites.',
            'laringe'          => 'Control en un mes.',
        ]);

        DB::table('examen_fisico_nuevo')->insert([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'consulta' => 1,
            'total_f' => 'Abdomen blando, depresible.', 'total_g' => 'Genitales externos normales.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // El catálogo de motivos, insertado al revés de su descripción: el resumen ordena por descripción.
        DB::table('motivos_consulta')->insert([
            ['reg_medico' => $medico->reg_medico, 'codemotivo' => '0002', 'descripcion' => 'DOLOR ABDOMINAL', 'created_at' => now(), 'updated_at' => now()],
            ['reg_medico' => $medico->reg_medico, 'codemotivo' => '0001', 'descripcion' => 'CONTROL', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('motivo_consulta_paciente')->insert([
            ['reg_medico' => $medico->reg_medico, 'codemotivo' => '0002', 'nrohistoria' => 1001, 'nroconsulta' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['reg_medico' => $medico->reg_medico, 'codemotivo' => '0001', 'nrohistoria' => 1001, 'nroconsulta' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Los diagnósticos: el catálogo da la descripción y el `orden` manda (se insertan al revés).
        DB::table('diagnosticos')->insert([
            ['reg_medico' => $medico->reg_medico, 'codediagnostico' => '0001', 'descripcion' => 'GASTRITIS', 'created_at' => now(), 'updated_at' => now()],
            ['reg_medico' => $medico->reg_medico, 'codediagnostico' => '0002', 'descripcion' => 'ANEMIA LEVE', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('diagnostico_paciente')->insert([
            ['reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'codediagnostico' => '0002', 'orden' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'codediagnostico' => '0001', 'orden' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // El tratamiento sale de los récipes de la consulta (nombre comercial + presentación del vademécum).
        $this->recipe($medico, 1001, 1, 1, ['0001', '0003'], '2026-10-06');

        // Y el informe: de ahí salen el «Para» de la cabecera y su fecha (su texto **no** entra al resumen).
        $this->informe($medico, 'Texto reservado del informe.', 'A QUIEN CORRESPONDA', '2026-10-06');
    }

    private function informe(Medico $medico, string $texto = 'Texto del informe.', ?string $para = 'A QUIEN CORRESPONDA', ?string $fecha = '2026-10-06'): void
    {
        DB::table('informe')->insert([
            'reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1,
            'para' => $para, 'descripcion' => $texto, 'fe_cha' => $fecha,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<int,string> las claves de lo que la consulta ofrece imprimir */
    private function ofrecidos(Medico $medico, Consulta $consulta): array
    {
        return array_column(app(DocumentosParaImprimir::class)->disponibles($consulta, [$medico->reg_medico]), 'clave');
    }

    // ========================================================================== La hoja del médico

    public function test_el_medico_obtiene_la_hoja_con_los_ocho_rotulos_y_los_datos_de_la_consulta(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->resumen($medico, $consulta);

        $hoja = $this->como($user, $medico, $gineco)->get($this->hoja($consulta));

        $hoja->assertOk()
            ->assertSee(DocumentoImpreso::TITULOS[DocumentoImpreso::RESUMEN_MEDICO][0])
            // La cabecera: el membrete del médico y los datos del paciente con la fecha del **informe**.
            ->assertSee('Ginecología y Obstetricia')
            ->assertSee('PACIENTE:')->assertSee('Ana Alvarez')
            ->assertSee('C.I.:')->assertSee('V-14555666')
            // La edad es la del día del documento (06/10/2026), no la de la consulta (07/10/2026).
            ->assertSee('EDAD:')->assertSee('35 años')
            ->assertSee('FECHA:')->assertSee('06/10/2026')
            // El destinatario del informe, en la cabecera y en su caja con borde.
            ->assertSee('A QUIEN CORRESPONDA')
            ->assertSee('class="documento-para"', false)
            // Los ocho bloques, con sus rótulos literales y sus datos.
            ->assertSee('MOTIVO DE CONSULTA:')
            // El catálogo manda y los motivos salen por descripción: CONTROL va antes que DOLOR ABDOMINAL.
            ->assertSee('CONTROL · DOLOR ABDOMINAL')
            ->assertSee('ENFERMEDAD ACTUAL:')
            ->assertSee('Dolor abdominal de dos días.')
            ->assertSee('EXAMEN FÍSICO:')->assertSee('Abdomen blando, depresible.')
            ->assertSee('EXAMEN GINECOLÓGICO:')->assertSee('Genitales externos normales.')
            ->assertSee('RESULTADO DE EXÁMENES:')->assertSee('Hemograma dentro de límites.')
            ->assertSee('DIAGNOSTICO')
            ->assertSee('GASTRITIS · ANEMIA LEVE')
            ->assertSee('TRATAMIENTO:')->assertSee('KEFORAL/Cap. 500 mg')->assertSee('ÁCIDO FÓLICO/Tabletas 5 mg')
            ->assertSee('PLAN DE TRATAMIENTO:')->assertSee('Control en un mes.');

        // La hoja no lleva el texto del informe (es de otra hoja) ni la firma repetida.
        $hoja->assertDontSee('Texto reservado del informe.');
        $this->assertSame(1, substr_count($hoja->getContent(), 'class="firmas"'));
        $this->assertSame(1, substr_count($hoja->getContent(), 'recipe-mitad-documento'));
    }

    public function test_el_orden_de_los_motivos_es_el_del_catalogo_y_el_de_los_diagnosticos_el_de_orden(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->resumen($medico, $consulta);

        $html = $this->como($user, $medico, $gineco)->get($this->hoja($consulta))->assertOk()->getContent();

        // Los motivos: `ORDER BY descripcion ASC` (CONTROL va antes que DOLOR ABDOMINAL, aunque se hayan
        // insertado al revés).
        $this->assertLessThan(strpos($html, 'DOLOR ABDOMINAL'), strpos($html, 'CONTROL'));
        // Los diagnósticos: por `orden` (GASTRITIS es el 1 y ANEMIA LEVE el 2, insertados al revés).
        $this->assertLessThan(strpos($html, 'ANEMIA LEVE'), strpos($html, 'GASTRITIS'));
    }

    public function test_un_bloque_sin_datos_no_imprime_su_rotulo(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        // Sólo la enfermedad actual y un diagnóstico: el resto de los bloques no se imprime.
        DB::table('consultas')->where('id', $consulta->id)->update(['enfermedadactual' => 'Dolor de dos días.']);
        DB::table('diagnosticos')->insert(['reg_medico' => $medico->reg_medico, 'codediagnostico' => '0001', 'descripcion' => 'GASTRITIS', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('diagnostico_paciente')->insert(['reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'codediagnostico' => '0001', 'orden' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $hoja = $this->como($user, $medico, $gineco)->get($this->hoja($consulta));

        $hoja->assertOk()
            ->assertSee('ENFERMEDAD ACTUAL:')->assertSee('Dolor de dos días.')
            ->assertSee('DIAGNOSTICO')->assertSee('GASTRITIS')
            ->assertDontSee('MOTIVO DE CONSULTA:')
            ->assertDontSee('EXAMEN FÍSICO:')
            ->assertDontSee('EXAMEN GINECOLÓGICO:')
            ->assertDontSee('RESULTADO DE EXÁMENES:')
            ->assertDontSee('TRATAMIENTO:')
            ->assertDontSee('PLAN DE TRATAMIENTO:');
    }

    public function test_una_consulta_sin_datos_no_ofrece_ni_imprime_el_resumen(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        // Sin ningún dato clínico, pero con el informe (que no hace un resumen por sí solo).
        $this->informe($medico);

        $this->assertNotContains(DocumentoImpreso::RESUMEN_MEDICO, $this->ofrecidos($medico, $consulta));
        $this->como($user, $medico, $gineco)->get($this->hoja($consulta))->assertNotFound();
    }

    public function test_una_consulta_cuyo_unico_dato_son_los_medicamentos_no_ofrece_el_resumen(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->recipe($medico, 1001, 1, 1, ['0001'], '2026-10-06');

        // El tratamiento es un bloque de la hoja, pero no alcanza para ofrecerla: los medicamentos ya
        // tienen su documento (el récipe) y la lista de la secretaría se queda como estaba.
        $this->assertNotContains(DocumentoImpreso::RESUMEN_MEDICO, $this->ofrecidos($medico, $consulta));
        $this->como($user, $medico, $gineco)->get($this->hoja($consulta))->assertNotFound();

        // Con un dato clínico más, la hoja sale y lleva el tratamiento con el resto.
        DB::table('consultas')->where('id', $consulta->id)->update(['enfermedadactual' => 'Dolor de dos días.']);

        $this->assertContains(DocumentoImpreso::RESUMEN_MEDICO, $this->ofrecidos($medico, $consulta));
        $this->como($user, $medico, $gineco)->get($this->hoja($consulta))
            ->assertOk()
            ->assertSee('ENFERMEDAD ACTUAL:')->assertSee('Dolor de dos días.')
            ->assertSee('TRATAMIENTO:')->assertSee('KEFORAL/Cap. 500 mg');
    }

    public function test_la_pestana_informe_ofrece_el_resumen_solo_si_hay_algo_que_resumir(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        // Sin datos: el texto del informe se puede escribir, pero no hay resumen que imprimir.
        $this->informe($medico);
        $this->como($user, $medico, $gineco)->get($this->pestanaInforme($consulta))
            ->assertOk()
            ->assertDontSee('Resumen médico');

        // Con datos: aparece el botón, que lleva a la hoja del resumen.
        DB::table('consultas')->where('id', $consulta->id)->update(['enfermedadactual' => 'Dolor de dos días.']);
        $this->como($user, $medico, $gineco)->get($this->pestanaInforme($consulta))
            ->assertOk()
            ->assertSee('Resumen médico')
            ->assertSee('documento=' . DocumentoImpreso::RESUMEN_MEDICO, false);
    }

    // ====================================================== Aislamiento: otro médico y la secretaría

    public function test_una_consulta_de_otro_medico_da_404(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->resumen($medico, $consulta);

        // El médico dueño la ve; el de otro consultorio no (ni siquiera se confirma que exista).
        $this->como($user, $medico, $gineco)->get($this->hoja($consulta))->assertOk();

        [$otroUser, $otroMedico, $otraGineco] = $this->medicoConAcceso();
        $this->como($otroUser, $otroMedico, $otraGineco)->get($this->hoja($consulta))->assertNotFound();
    }

    public function test_la_secretaria_recibe_403_en_la_consulta_pero_imprime_el_resumen_desde_la_cita(): void
    {
        [, $medico, $gineco, $consulta, $secretaria, $cita] = $this->escenario();
        $this->resumen($medico, $consulta);

        // La consulta es sólo del médico: la secretaría no entra, ni por la hoja del resumen.
        $this->como($secretaria, $medico, $gineco)->get($this->hoja($consulta))->assertForbidden();
        $this->como($secretaria, $medico, $gineco)->get(route('clinica.consulta', $consulta->id))->assertForbidden();

        // Pero desde la cita atendida imprime «Imprimir documentos»: el resumen está en la lista y sale.
        $lista = $this->como($secretaria, $medico, $gineco)->get(route('clinica.agenda.documentos', $cita->id));

        $lista->assertOk()
            ->assertSee('Documentos de Ana Alvarez')
            ->assertSee('Resumen médico')
            ->assertSee('Resumen médico de la consulta')
            ->assertSee('documento=' . DocumentoImpreso::RESUMEN_MEDICO, false)
            // Sin el contenido clínico en la lista (lo ve la secretaría).
            ->assertDontSee('Dolor abdominal de dos días.')
            ->assertDontSee('GASTRITIS');

        $this->como($secretaria, $medico, $gineco)
            ->get(route('clinica.agenda.documentos.imprimir', [$cita->id, 'documento' => DocumentoImpreso::RESUMEN_MEDICO]))
            ->assertOk()
            ->assertSee('MOTIVO DE CONSULTA:')
            ->assertSee('PLAN DE TRATAMIENTO:');
    }

    // ======================================================================== La historia del paciente

    public function test_la_historia_del_paciente_lista_el_resumen_medico(): void
    {
        [$user, $medico, $gineco, $consulta, , , $pacienteId] = $this->escenario();
        $this->resumen($medico, $consulta);

        $this->como($user, $medico, $gineco)->get(route('clinica.pacientes.historia', $pacienteId))
            ->assertOk()
            ->assertSee('Resumen médico')
            ->assertSee('documento=' . DocumentoImpreso::RESUMEN_MEDICO, false);
    }
}
