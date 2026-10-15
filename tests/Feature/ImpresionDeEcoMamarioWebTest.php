<?php

namespace Tests\Feature;

use App\Clinica\Agenda\CitaDeAgenda;
use App\Clinica\Impresion\DocumentoImpreso;
use App\Clinica\Impresion\DocumentosParaImprimir;
use App\Clinica\Impresion\DocumentosPorCita;
use App\Clinica\Impresion\FormatosEnBlanco;
use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Medico;
use App\Models\Specialty;
use App\Models\UltraMama;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **Imprimir el informe del eco mamario** (WEB-6.4): media hoja con el membrete del médico, la cabecera del
 * paciente y los diez datos de la fuente **en su orden** (el referido por, el transductor, la menarquia, los
 * quirúrgicos, las hormonas, cada mama y cada axila y la conclusión); la secretaría lo imprime desde la cita
 * atendida sin ver el contenido clínico; y la historia del paciente lo lista.
 *
 * Que el cuerpo real del navegador no se desborde lo comprueba el verificador de Chrome de la sección.
 */
class ImpresionDeEcoMamarioWebTest extends TestCase
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

    private function hoja(Consulta $consulta, string $documento = DocumentoImpreso::ECO_MAMARIO): string
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

    /** El eco mamario de la consulta como lo escribe el escritorio (la clave es `historia` + `consulta`). */
    private function mamario(Medico $medico, array $extra = []): UltraMama
    {
        return UltraMama::create(array_merge([
            'reg_medico' => $medico->reg_medico,
            'historia' => self::HISTORIA,
            'consulta' => self::CONSULTA,
            'fecha' => '2026-06-01',
            'referido' => 10432,
            'tranductor' => 'Lineal 7,5 MHz',
            'menarquia' => '12 años',
            'quirurgicos' => 'Apendicectomía',
            'hormonas' => 'Anticonceptivos orales',
            'mama_derecha' => 'Sin hallazgos.',
            'axila_derecha' => 'Sin adenopatías.',
            'mama_izquierda' => 'Nódulo de 8 mm.',
            'axila_izquierda' => 'Sin adenopatías.',
            'conclusion' => 'Nódulo simple en la mama izquierda.',
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
        $this->mamario($medico);

        $hoja = $this->como($user, $medico, $gineco)->get($this->hoja($consulta));

        $hoja->assertOk()
            ->assertSee(DocumentoImpreso::TITULOS[DocumentoImpreso::ECO_MAMARIO][0])
            ->assertSee('ULTRASONIDO DE MAMAS')
            ->assertSee('PACIENTE:')->assertSee('Ana Alvarez')
            ->assertSee('C.I.:')->assertSee('V-14555666')
            ->assertSee('FECHA:')
            // Los cinco datos cortos del encabezado, con el referido resuelto a su nombre.
            ->assertSee('Referido por:')->assertSee('José Pérez')
            ->assertSee('Transductor:')->assertSee('Lineal 7,5 MHz')
            ->assertSee('Menarquia:')->assertSee('12 años')
            ->assertSee('Quirúrgicos:')->assertSee('Apendicectomía')
            ->assertSee('Hormonas:')->assertSee('Anticonceptivos orales')
            // Los cuatro hallazgos y la conclusión.
            ->assertSee('Mama derecha:')->assertSee('Sin hallazgos.')
            ->assertSee('Axila derecha:')->assertSee('Sin adenopatías.')
            ->assertSee('Mama izquierda:')->assertSee('Nódulo de 8 mm.')
            ->assertSee('Axila izquierda:')
            ->assertSee('Conclusión:')->assertSee('Nódulo simple en la mama izquierda.')
            ->assertSee('Ginecología y Obstetricia');

        // Una sola firma, en la mitad donde termina el informe.
        $this->assertSame(1, substr_count($hoja->getContent(), 'class="firmas"'));
    }

    /**
     * El **orden** de los diez rótulos es el de la fuente (el DataWindow de impresión,
     * `d_ultasonido_mamas_print`): el referido, el transductor, la menarquia y los quirúrgicos y las hormonas
     * primero (los datos cortos), y después cada mama y cada axila y la conclusión.
     */
    public function test_los_diez_rotulos_salen_en_el_orden_de_la_fuente(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->doctor($medico);
        $this->mamario($medico);

        $html = $this->como($user, $medico, $gineco)->get($this->hoja($consulta))->assertOk()->getContent();

        $rotulos = ['Referido por:', 'Transductor:', 'Menarquia:', 'Quirúrgicos:', 'Hormonas:',
            'Mama derecha:', 'Axila derecha:', 'Mama izquierda:', 'Axila izquierda:', 'Conclusión:'];

        $anterior = -1;
        foreach ($rotulos as $rotulo) {
            $posicion = strpos($html, $rotulo);

            $this->assertNotFalse($posicion, "El rótulo {$rotulo}.");
            $this->assertGreaterThan($anterior, $posicion, "El orden de {$rotulo}.");
            $anterior = $posicion;
        }
    }

    public function test_los_bloques_sin_datos_no_se_imprimen(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        // Sólo la conclusión: los demás no se imprimen.
        $this->mamario($medico, [
            'referido' => null, 'tranductor' => null, 'menarquia' => null, 'quirurgicos' => null,
            'hormonas' => null, 'mama_derecha' => null, 'axila_derecha' => null,
            'mama_izquierda' => null, 'axila_izquierda' => null,
        ]);

        $hoja = $this->como($user, $medico, $gineco)->get($this->hoja($consulta));

        $hoja->assertOk()
            ->assertSee('Conclusión:')
            ->assertDontSee('Referido por:')
            ->assertDontSee('Transductor:')
            ->assertDontSee('Menarquia:')
            ->assertDontSee('Quirúrgicos:')
            ->assertDontSee('Hormonas:')
            ->assertDontSee('Mama derecha:')
            ->assertDontSee('Axila derecha:')
            ->assertDontSee('Mama izquierda:')
            ->assertDontSee('Axila izquierda:');
    }

    /** Si el catálogo ya no tiene al médico referido, la hoja muestra su cédula en vez de inventar un nombre. */
    public function test_el_referido_sin_catalogo_muestra_la_cedula(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->mamario($medico, ['referido' => 99999]);

        $this->como($user, $medico, $gineco)->get($this->hoja($consulta))
            ->assertOk()
            ->assertSee('Referido por:')
            ->assertSee('Médico 99999');
    }

    public function test_una_fila_sin_datos_no_se_ofrece_ni_se_imprime(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        // Sólo la fecha: no es un documento (la fecha la completa el servidor).
        $this->mamario($medico, [
            'referido' => null, 'tranductor' => null, 'menarquia' => null, 'quirurgicos' => null,
            'hormonas' => null, 'mama_derecha' => null, 'axila_derecha' => null,
            'mama_izquierda' => null, 'axila_izquierda' => null, 'conclusion' => null,
        ]);

        $this->assertNotContains(DocumentoImpreso::ECO_MAMARIO, $this->ofrecidos($medico, $consulta));
        $this->como($user, $medico, $gineco)->get($this->hoja($consulta))->assertNotFound();
    }

    /**
     * El informe en texto libre del escritorio (`puro_texto`, su modo «Plantillas») **no** entra en esta hoja:
     * ese modo es **otro** DataWindow de impresión (`d_ultasonido_mamas_print_puro`), que la web no imprime. Una
     * fila que sólo trae ese texto no es un documento y no ofrece un informe vacío (a diferencia del eco
     * pélvico y el Doppler, cuya hoja sí lo imprime).
     */
    public function test_el_texto_libre_del_escritorio_no_es_un_documento_de_esta_hoja(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        // El modo «Plantillas» del escritorio: sin ningún dato del estudio, sólo el informe escrito.
        $this->mamario($medico, [
            'referido' => null, 'tranductor' => null, 'menarquia' => null, 'quirurgicos' => null,
            'hormonas' => null, 'mama_derecha' => null, 'axila_derecha' => null,
            'mama_izquierda' => null, 'axila_izquierda' => null, 'conclusion' => null,
            'puro_texto' => "INFORME DEL ESCRITORIO\nSegunda línea.",
        ]);

        $this->assertNotContains(DocumentoImpreso::ECO_MAMARIO, $this->ofrecidos($medico, $consulta));
        $this->como($user, $medico, $gineco)->get($this->hoja($consulta))->assertNotFound();
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
        $this->mamario($medico, ['conclusion' => 'CONCLUSION RESERVADA']);

        // Con un solo documento, la lista se salta y va directo a la hoja.
        $this->lista($secretaria, $medico, $gineco, $cita)
            ->assertRedirect(route('clinica.agenda.documentos.imprimir', [$cita->id, 'documento' => DocumentoImpreso::ECO_MAMARIO]));

        $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita)->assertOk()
            ->assertSee('ULTRASONIDO DE MAMAS')
            ->assertSee('CONCLUSION RESERVADA');
    }

    public function test_la_lista_de_la_cita_nombra_el_informe_sin_contenido_clinico(): void
    {
        [, $medico, $gineco, , $secretaria, $cita] = $this->escenario();
        $this->mamario($medico, ['conclusion' => 'CONCLUSION RESERVADA']);
        // Otro documento, para que la lista no se salte.
        DB::table('dieta_paciente')->insert([
            'reg_medico' => $medico->reg_medico, 'histroria' => self::HISTORIA, 'consulta' => self::CONSULTA,
            'dieta' => 'DIETA', 'descripcion' => 'Sin sal.', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->lista($secretaria, $medico, $gineco, $cita)
            ->assertOk()
            ->assertSee('Documentos de Ana Alvarez')
            ->assertSee('Eco mamario')
            ->assertSee('Informe del eco mamario')
            ->assertSee('documento=' . DocumentoImpreso::ECO_MAMARIO, false)
            ->assertDontSee('CONCLUSION RESERVADA')
            ->assertDontSee('Nódulo de 8 mm.');
    }

    public function test_la_hoja_es_la_misma_para_el_medico_y_para_la_secretaria(): void
    {
        [$user, $medico, $gineco, $consulta, $secretaria, $cita] = $this->escenario();
        $this->doctor($medico);
        $this->mamario($medico);

        $cuerpo = fn (string $html) => substr($html, strpos($html, '<main class="recipe-hojas"'), strpos($html, '</main>') - strpos($html, '<main class="recipe-hojas"'));

        $delMedico = $this->como($user, $medico, $gineco)->get($this->hoja($consulta))->getContent();
        $deLaSecretaria = $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita)->getContent();

        $this->assertSame($cuerpo($delMedico), $cuerpo($deLaSecretaria));
    }

    /**
     * La fila de la agenda ofrece «Imprimir documentos» cuando la cita tiene algo: el lote lo decide
     * `DocumentosPorCita::conDocumentos`, que es SQL sobre `ultra_mama` (su clave es `historia` + `consulta`,
     * no `numhistoria`/`nroconsulta`, y su DataWindow vivo no se llama como la tabla).
     */
    public function test_la_agenda_cuenta_el_mamario_entre_los_documentos_de_la_cita(): void
    {
        [, $medico, , , , $cita, $pacienteId] = $this->escenario();
        $this->mamario($medico);

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

        // Con la fecha sola no hay nada que imprimir: la cita no lleva el botón.
        UltraMama::where('reg_medico', $medico->reg_medico)->update([
            'referido' => null, 'tranductor' => null, 'menarquia' => null, 'quirurgicos' => null,
            'hormonas' => null, 'mama_derecha' => null, 'axila_derecha' => null,
            'mama_izquierda' => null, 'axila_izquierda' => null, 'conclusion' => null, 'puro_texto' => null,
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
        $this->mamario($medico);

        $this->como($user, $medico, $gineco)->get(route('clinica.pacientes.historia', $pacienteId))
            ->assertOk()
            ->assertSee('Eco mamario')
            ->assertSee('Informe del eco mamario');
    }

    public function test_la_historia_no_lista_un_estudio_sin_datos(): void
    {
        [$user, $medico, $gineco, , , , $pacienteId] = $this->escenario();
        $this->mamario($medico, [
            'referido' => null, 'tranductor' => null, 'menarquia' => null, 'quirurgicos' => null,
            'hormonas' => null, 'mama_derecha' => null, 'axila_derecha' => null,
            'mama_izquierda' => null, 'axila_izquierda' => null, 'conclusion' => null,
        ]);

        $this->como($user, $medico, $gineco)->get(route('clinica.pacientes.historia', $pacienteId))
            ->assertOk()
            ->assertDontSee('Eco mamario');
    }

    /**
     * La hoja no tiene **formato en blanco**: el panel del escritorio
     * (`miconsultat/w_panel_impresion_formatos.srw`) lista seis formatos y el eco mamario no está entre ellos.
     * No es un olvido: es lo que la fuente tiene.
     */
    public function test_el_escritorio_no_tiene_formato_en_blanco_para_este_estudio(): void
    {
        $this->assertNotContains(DocumentoImpreso::ECO_MAMARIO, FormatosEnBlanco::claves());
        // Y su clave del documento es la de la hoja real.
        $this->assertSame('eco-mamario', DocumentoImpreso::ECO_MAMARIO);
    }
}
