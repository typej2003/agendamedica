<?php

namespace Tests\Feature;

use App\Clinica\Agenda\CitaDeAgenda;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\TiposDeEstudio;
use App\Clinica\Impresion\DocumentoImpreso;
use App\Clinica\Impresion\DocumentosParaImprimir;
use App\Clinica\Impresion\DocumentosPorCita;
use App\Clinica\Impresion\FormatosEnBlanco;
use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Medico;
use App\Models\Specialty;
use App\Models\UltraAbdominal;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **Imprimir el informe del eco abdominal** (WEB-6.4): media hoja con el membrete del médico, la cabecera del
 * paciente y los datos de la fuente **en su orden** (el transductor, el referido por, cada órgano, los cuatro
 * desplegables, las observaciones y las conclusiones); la secretaría lo imprime desde la cita atendida sin ver
 * el contenido clínico; y la historia del paciente lo lista.
 *
 * ⚠️ **`prostata` y `vejiga_urina` no se imprimen**: son de urología y en ginecología van ocultas en el
 * formulario y en la hoja del escritorio (`visible="0"`). Y **no hay formato en blanco** para este estudio: el
 * panel del escritorio lista seis formatos y el abdominal no está entre ellos.
 *
 * Que el cuerpo real del navegador no se desborde lo comprueba el verificador de Chrome de la sección.
 */
class ImpresionDeEcoAbdominalWebTest extends TestCase
{
    use DatabaseTransactions;
    use PreparaRecipesParaImprimir;

    /** La tabla y su clave: `historia` + `consulta`, una fila por consulta. */
    private const HISTORIA = 1001;

    private const CONSULTA = 1;

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
        $secretaria = $this->secretariaConAcceso($medico);
        [$pacienteId, $consulta] = $this->pacienteYConsulta($medico, self::HISTORIA, self::CONSULTA);
        $cita = $this->cita($medico, self::HISTORIA, $this->hoy->toDateString(), ['atendido' => 1, 'estado' => 1]);

        return [$user, $medico, $gineco, $consulta, $secretaria, $cita, $pacienteId];
    }

    private function como(User $user, Medico $medico, Specialty $gineco): self
    {
        return $this->conContexto($user, $medico, $gineco)->actingAs($user);
    }

    private function hoja(Consulta $consulta, string $documento = DocumentoImpreso::ECO_ABDOMINAL): string
    {
        return route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => $documento]);
    }

    /** Un médico de la libreta, para el `referido`. */
    private function doctor(Medico $medico, int $cedula = 10432, string $nombres = 'José', string $apellidos = 'Pérez'): void
    {
        DB::table('doctores')->insert([
            'reg_medico' => $medico->reg_medico,
            'cedula' => $cedula,
            'nombres' => $nombres,
            'apellidos' => $apellidos,
            'codeespecial' => '01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** El eco abdominal de la consulta como lo escribe el escritorio (la clave es `historia` + `consulta`). */
    private function abdominal(Medico $medico, array $extra = []): UltraAbdominal
    {
        return UltraAbdominal::create(array_merge([
            'reg_medico' => $medico->reg_medico,
            'historia' => self::HISTORIA,
            'consulta' => self::CONSULTA,
            'fecha' => '2026-06-01',
            'transductor' => 'Convexo 3,5 MHz',
            'referido' => 10432,
            'higado' => 'De tamaño y ecogenicidad normales.',
            'vesicula_bilial' => 'Sin litiasis.',
            'porta_coledoco' => 'Sin dilatación.',
            'pancreas' => 'Sin lesiones.',
            'rinon_derecho' => 'Sin litiasis.',
            'rinon_izquierdo' => 'Sin litiasis.',
            'vol_res_v_p_m' => 'N',
            'ascitis' => 'A',
            'retroperitoneo' => 'N',
            'vasos_sanguineos' => 'A',
            'otros' => 'Sin otros hallazgos.',
            'conclusiones' => 'Estudio dentro de límites normales.',
        ], $extra));
    }

    /** @return array<int,string> las claves de lo que la consulta ofrece imprimir */
    private function ofrecidos(Medico $medico, Consulta $consulta): array
    {
        return array_column(app(DocumentosParaImprimir::class)->disponibles($consulta, [$medico->reg_medico]), 'clave');
    }

    // ========================================================================== La hoja del médico

    public function test_el_informe_sale_con_los_rotulos_de_la_hoja_y_sus_datos(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->doctor($medico);
        $this->abdominal($medico);

        $hoja = $this->como($user, $medico, $gineco)->get($this->hoja($consulta));

        $hoja->assertOk()
            ->assertSee(DocumentoImpreso::TITULOS[DocumentoImpreso::ECO_ABDOMINAL][0])
            ->assertSee('ULTRASONIDO ABDOMINAL')
            ->assertSee('PACIENTE:')->assertSee('Ana Alvarez')
            ->assertSee('C.I.:')->assertSee('V-14555666')
            ->assertSee('FECHA:')
            // Los dos datos cortos del encabezado, con el referido resuelto a su nombre.
            ->assertSee('Transductor:')->assertSee('Convexo 3,5 MHz')
            ->assertSee('Referido por:')->assertSee('José Pérez')
            // Los seis órganos.
            ->assertSee('Hígado:')->assertSee('De tamaño y ecogenicidad normales.')
            ->assertSee('Vesícula biliar:')->assertSee('Sin litiasis.')
            ->assertSee('Porta y colédoco:')->assertSee('Sin dilatación.')
            ->assertSee('Páncreas:')->assertSee('Sin lesiones.')
            ->assertSee('Rinón derecho:')
            ->assertSee('Rinón izquierdo:')
            // Los cuatro desplegables, con todas sus opciones y la elegida marcada (WEB-11.13).
            ->assertSeeInOrder(['Volumen residual vesical Post-Micción:', '●', 'Sí', '○', 'No'], false)
            ->assertSeeInOrder(['Ascitis:', '○', 'Sí', '●', 'No'], false)
            ->assertSeeInOrder(['Retroperitoneo:', '●', 'Normal', '○', 'Anormal'], false)
            ->assertSeeInOrder(['Vasos sanguíneos:', '○', 'Normal', '●', 'Anormal'], false)
            // El área `otros` rotulada como la hoja y las conclusiones.
            ->assertSee('Otros:')->assertSee('Sin otros hallazgos.')
            ->assertSee('Conclusiones:')->assertSee('Estudio dentro de límites normales.')
            ->assertSee('Ginecología y Obstetricia');

        // Una sola firma, en la mitad donde termina el informe.
        $this->assertSame(1, substr_count($hoja->getContent(), 'class="firmas"'));
    }

    /**
     * El **orden** de los catorce rótulos es el de la fuente (el DataWindow de impresión,
     * `d_ultasonido_abdominal_print`): el transductor y el referido primero, después cada órgano, los cuatro
     * desplegables, las observaciones y las conclusiones.
     */
    public function test_los_rotulos_salen_en_el_orden_de_la_fuente(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->doctor($medico);
        $this->abdominal($medico);

        $html = $this->como($user, $medico, $gineco)->get($this->hoja($consulta))->assertOk()->getContent();

        $rotulos = ['Transductor:', 'Referido por:', 'Hígado:', 'Vesícula biliar:', 'Porta y colédoco:',
            'Páncreas:', 'Rinón derecho:', 'Rinón izquierdo:', 'Volumen residual vesical Post-Micción:',
            'Ascitis:', 'Retroperitoneo:', 'Vasos sanguíneos:', 'Otros:', 'Conclusiones:'];

        $anterior = -1;
        foreach ($rotulos as $rotulo) {
            $posicion = strpos($html, $rotulo);

            $this->assertNotFalse($posicion, "El rótulo {$rotulo}.");
            $this->assertGreaterThan($anterior, $posicion, "El orden de {$rotulo}.");
            $anterior = $posicion;
        }
    }

    /**
     * ⚠️ **Próstata y vejiga urinaria no se imprimen**: son de urología y en ginecología van ocultas en el
     * DataWindow del escritorio (`visible="0"`). Aunque la fila las tenga cargadas, la hoja de la web no las
     * muestra.
     */
    public function test_la_hoja_no_imprime_prostata_ni_vejiga_urinaria(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->abdominal($medico, [
            'prostata' => 'Aumento del tamaño prostático.',
            'vejiga_urina' => 'A',
        ]);

        $this->como($user, $medico, $gineco)->get($this->hoja($consulta))
            ->assertOk()
            ->assertDontSee('Próstata:')
            ->assertDontSee('Vejiga urinaria:')
            ->assertDontSee('Aumento del tamaño prostático.');
    }

    public function test_un_estudio_con_solo_las_conclusiones_imprime_el_formulario_con_los_casilleros_en_blanco(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        // Sólo las conclusiones: el informe es el formulario (WEB-11.13), con lo demás sin llenar.
        $this->abdominal($medico, [
            'transductor' => null, 'referido' => null, 'higado' => null, 'vesicula_bilial' => null,
            'porta_coledoco' => null, 'pancreas' => null, 'rinon_derecho' => null, 'rinon_izquierdo' => null,
            'vol_res_v_p_m' => null, 'ascitis' => null, 'retroperitoneo' => null, 'vasos_sanguineos' => null,
            'otros' => null,
        ]);

        $hoja = $this->como($user, $medico, $gineco)->get($this->hoja($consulta));

        $hoja->assertOk()
            ->assertSee('Conclusiones:')
            ->assertSee('Hígado:')
            ->assertSee('Ascitis:')
            ->assertSeeInOrder(['Ascitis:', '○', 'Sí', '○', 'No'], false)
            ->assertDontSee('●', false);
    }

    /** Si el catálogo ya no tiene al médico referido, la hoja muestra su cédula en vez de inventar un nombre. */
    public function test_el_referido_sin_catalogo_muestra_la_cedula(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->abdominal($medico, ['referido' => 99999]);

        $this->como($user, $medico, $gineco)->get($this->hoja($consulta))
            ->assertOk()
            ->assertSee('Referido por:')
            ->assertSee('Médico 99999');
    }

    public function test_una_fila_sin_datos_no_se_ofrece_ni_se_imprime(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        // Sólo la fecha: no es un documento (la fecha la completa el servidor). Y aunque las columnas de
        // urología traigan algo, sigue sin serlo: no están en el mapa.
        $this->abdominal($medico, [
            'transductor' => null, 'referido' => null, 'higado' => null, 'vesicula_bilial' => null,
            'porta_coledoco' => null, 'pancreas' => null, 'rinon_derecho' => null, 'rinon_izquierdo' => null,
            'vol_res_v_p_m' => null, 'ascitis' => null, 'retroperitoneo' => null, 'vasos_sanguineos' => null,
            'otros' => null, 'conclusiones' => null,
            'prostata' => 'Aumento del tamaño prostático.', 'vejiga_urina' => 'A',
        ]);

        $this->assertNotContains(DocumentoImpreso::ECO_ABDOMINAL, $this->ofrecidos($medico, $consulta));
        $this->como($user, $medico, $gineco)->get($this->hoja($consulta))->assertNotFound();
    }

    /**
     * El informe en texto libre del escritorio (`puro_texto`, su modo «Plantillas») **no** entra en esta hoja:
     * ese modo es **otro** DataWindow de impresión (`d_ultasonido_abdominal_print_puro`), que la web no imprime.
     * Una fila que sólo trae ese texto no es un documento y no ofrece un informe vacío.
     */
    public function test_el_texto_libre_del_escritorio_no_es_un_documento_de_esta_hoja(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->abdominal($medico, [
            'transductor' => null, 'referido' => null, 'higado' => null, 'vesicula_bilial' => null,
            'porta_coledoco' => null, 'pancreas' => null, 'rinon_derecho' => null, 'rinon_izquierdo' => null,
            'vol_res_v_p_m' => null, 'ascitis' => null, 'retroperitoneo' => null, 'vasos_sanguineos' => null,
            'otros' => null, 'conclusiones' => null,
            'puro_texto' => "INFORME DEL ESCRITORIO\nSegunda línea.",
        ]);

        $this->assertNotContains(DocumentoImpreso::ECO_ABDOMINAL, $this->ofrecidos($medico, $consulta));
        $this->como($user, $medico, $gineco)->get($this->hoja($consulta))->assertNotFound();
    }

    /**
     * El «Volver» de la hoja cae en **su** sección con **su** tipo (el ítem 5 del panel, `tipo=abdominal`), no
     * en la sección a secas: es la entrada de `ImpresionDeDocumentosController::urlDeLaConsulta()`.
     */
    public function test_el_volver_apunta_a_su_seccion_con_su_tipo(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->abdominal($medico);

        $esperado = e(route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_ESTUDIO,
            'tipo' => TiposDeEstudio::ABDOMINAL,
        ]));

        $this->como($user, $medico, $gineco)->get($this->hoja($consulta))
            ->assertOk()
            ->assertSee('Volver a la consulta')
            ->assertSee($esperado, false);
    }

    // ================================================================== La secretaría desde la cita

    private function lista(User $user, Medico $medico, Specialty $gineco, Cola $cita)
    {
        return $this->como($user, $medico, $gineco)->get(route('clinica.agenda.documentos', $cita->id));
    }

    private function hojaDeLaCita(User $user, Medico $medico, Specialty $gineco, Cola $cita, ?string $documento = null)
    {
        $parametros = $documento === null ? [$cita->id] : [$cita->id, 'documento' => $documento];

        return $this->como($user, $medico, $gineco)->get(route('clinica.agenda.documentos.imprimir', $parametros));
    }

    public function test_la_secretaria_lo_imprime_desde_la_cita_atendida(): void
    {
        [, $medico, $gineco, , $secretaria, $cita] = $this->escenario();
        $this->abdominal($medico, ['conclusiones' => 'CONCLUSION RESERVADA']);

        // Con un solo documento, la lista se salta y va directo a la hoja.
        $this->lista($secretaria, $medico, $gineco, $cita)
            ->assertRedirect(route('clinica.agenda.documentos.imprimir', [$cita->id, 'documento' => DocumentoImpreso::ECO_ABDOMINAL]));

        $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita)->assertOk()
            ->assertSee('ULTRASONIDO ABDOMINAL')
            ->assertSee('CONCLUSION RESERVADA');
    }

    public function test_la_lista_de_la_cita_nombra_el_informe_sin_contenido_clinico(): void
    {
        [, $medico, $gineco, , $secretaria, $cita] = $this->escenario();
        $this->abdominal($medico, ['conclusiones' => 'CONCLUSION RESERVADA']);
        // Otro documento, para que la lista no se salte.
        DB::table('dieta_paciente')->insert([
            'reg_medico' => $medico->reg_medico, 'histroria' => self::HISTORIA, 'consulta' => self::CONSULTA,
            'dieta' => 'DIETA', 'descripcion' => 'Sin sal.', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->lista($secretaria, $medico, $gineco, $cita)
            ->assertOk()
            ->assertSee('Documentos de Ana Alvarez')
            ->assertSee('Eco abdominal')
            ->assertSee('Informe del eco abdominal')
            ->assertSee('documento=' . DocumentoImpreso::ECO_ABDOMINAL, false)
            ->assertDontSee('CONCLUSION RESERVADA')
            ->assertDontSee('Sin litiasis.');
    }

    public function test_la_hoja_es_la_misma_para_el_medico_y_para_la_secretaria(): void
    {
        [$user, $medico, $gineco, $consulta, $secretaria, $cita] = $this->escenario();
        $this->doctor($medico);
        $this->abdominal($medico);

        $cuerpo = fn (string $html) => substr($html, strpos($html, '<main class="recipe-hojas"'), strpos($html, '</main>') - strpos($html, '<main class="recipe-hojas"'));

        $delMedico = $this->como($user, $medico, $gineco)->get($this->hoja($consulta))->getContent();
        $deLaSecretaria = $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita)->getContent();

        $this->assertSame($cuerpo($delMedico), $cuerpo($deLaSecretaria));
    }

    /**
     * La fila de la agenda ofrece «Imprimir documentos» cuando la cita tiene algo: el lote lo decide
     * `DocumentosPorCita::conDocumentos`, que es SQL sobre `ultra_abdominal` (su clave es `historia` +
     * `consulta`, no `numhistoria`/`nroconsulta`, y su DataWindow vivo no se llama como la tabla).
     */
    public function test_la_agenda_cuenta_el_abdominal_entre_los_documentos_de_la_cita(): void
    {
        [, $medico, , , , $cita, $pacienteId] = $this->escenario();
        $this->abdominal($medico);

        $deLaAgenda = new CitaDeAgenda(
            id: $cita->id, fecha: $this->hoy->toDateString(), horaIni: '10:30:00', numOrden: 1,
            estado: 1, atendido: 1, monto: null, montoPagado: null, numHistoria: self::HISTORIA,
            pacienteSinHistoriaId: null, pacienteId: $pacienteId, paciente: 'Ana Alvarez',
            centroId: null, motivo: null, tipo: null, medico: null,
        );

        $this->assertArrayHasKey(
            $cita->id,
            app(DocumentosPorCita::class)->conDocumentos($medico->reg_medico, collect([$deLaAgenda])),
        );

        // Con la fecha sola no hay nada que imprimir: la cita no lleva el botón. Y las columnas de urología no
        // cuentan: no están en el mapa, así que una fila que sólo las trae tampoco.
        UltraAbdominal::where('reg_medico', $medico->reg_medico)->update([
            'transductor' => null, 'referido' => null, 'higado' => null, 'vesicula_bilial' => null,
            'porta_coledoco' => null, 'pancreas' => null, 'rinon_derecho' => null, 'rinon_izquierdo' => null,
            'vol_res_v_p_m' => null, 'ascitis' => null, 'retroperitoneo' => null, 'vasos_sanguineos' => null,
            'otros' => null, 'conclusiones' => null, 'puro_texto' => null,
            'prostata' => 'Aumento del tamaño prostático.', 'vejiga_urina' => 'A',
        ]);

        $this->assertSame(
            [],
            app(DocumentosPorCita::class)->conDocumentos($medico->reg_medico, collect([$deLaAgenda])),
        );
    }

    // ======================================================================== La historia del paciente

    public function test_la_historia_del_paciente_lista_el_informe(): void
    {
        [$user, $medico, $gineco, , , , $pacienteId] = $this->escenario();
        $this->abdominal($medico);

        $this->como($user, $medico, $gineco)->get(route('clinica.pacientes.historia', $pacienteId))
            ->assertOk()
            ->assertSee('Eco abdominal')
            ->assertSee('Informe del eco abdominal');
    }

    public function test_la_historia_no_lista_un_estudio_sin_datos(): void
    {
        [$user, $medico, $gineco, , , , $pacienteId] = $this->escenario();
        $this->abdominal($medico, [
            'transductor' => null, 'referido' => null, 'higado' => null, 'vesicula_bilial' => null,
            'porta_coledoco' => null, 'pancreas' => null, 'rinon_derecho' => null, 'rinon_izquierdo' => null,
            'vol_res_v_p_m' => null, 'ascitis' => null, 'retroperitoneo' => null, 'vasos_sanguineos' => null,
            'otros' => null, 'conclusiones' => null,
        ]);

        $this->como($user, $medico, $gineco)->get(route('clinica.pacientes.historia', $pacienteId))
            ->assertOk()
            ->assertDontSee('Eco abdominal');
    }

    /**
     * La hoja no tiene **formato en blanco**: el panel del escritorio
     * (`miconsultat/w_panel_impresion_formatos.srw`) lista seis formatos y el eco abdominal no está entre ellos.
     * No es un olvido: es lo que la fuente tiene.
     */
    public function test_el_escritorio_no_tiene_formato_en_blanco_para_este_estudio(): void
    {
        $this->assertNotContains(DocumentoImpreso::ECO_ABDOMINAL, FormatosEnBlanco::claves());
        // Y su clave del documento es la de la hoja real.
        $this->assertSame('eco-abdominal', DocumentoImpreso::ECO_ABDOMINAL);
    }
}
