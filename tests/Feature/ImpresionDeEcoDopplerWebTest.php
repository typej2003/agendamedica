<?php

namespace Tests\Feature;

use App\Clinica\Agenda\CitaDeAgenda;
use App\Clinica\Impresion\DocumentoImpreso;
use App\Clinica\Impresion\DocumentosParaImprimir;
use App\Clinica\Impresion\DocumentosPorCita;
use App\Models\Cola;
use App\Models\Consulta;
use App\Models\EcoDoppler;
use App\Models\Medico;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **Imprimir el informe del eco Doppler perinatal** (WEB-6.3): media hoja con el membrete del médico, la ficha
 * (gesta, FUR, EG y destinatario), el texto del estudio, la grilla de cuatro vasos, los tres desplegables, las
 * sugerencias y la conclusión —los rótulos de la hoja, que no son los de la pantalla—; la secretaría lo
 * imprime desde la cita atendida sin ver el contenido clínico; y la historia del paciente lo lista.
 *
 * Que el cuerpo real del navegador no se desborde lo comprueba el verificador de Chrome de la sección.
 */
class ImpresionDeEcoDopplerWebTest extends TestCase
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
        $secretaria = $this->secretariaConAcceso($medico);
        [$pacienteId, $consulta] = $this->pacienteYConsulta($medico, 1001, 1);
        $cita = $this->cita($medico, 1001, $this->hoy->toDateString(), ['atendido' => 1, 'estado' => 1]);

        return [$user, $medico, $gineco, $consulta, $secretaria, $cita, $pacienteId];
    }

    private function como(User $user, Medico $medico, Specialty $gineco): self
    {
        return $this->conContexto($user, $medico, $gineco)->actingAs($user);
    }

    private function hoja(Consulta $consulta, string $documento = DocumentoImpreso::ECO_DOPPLER): string
    {
        return route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => $documento]);
    }

    /** El eco Doppler de la consulta como lo escribe el escritorio (la clave es `historia` + `consulta`). */
    private function doppler(Medico $medico, array $extra = []): EcoDoppler
    {
        return EcoDoppler::create(array_merge([
            'reg_medico' => $medico->reg_medico,
            'historia' => 1001,
            'consulta' => 1,
            'fecha' => '2026-06-01',
            'fur' => '2026-05-20',
            'gesta' => 2,
            'para' => '1 cesárea',
            'arteria_uraina_menor' => '0.85',
            'arteria_uraina_mayor' => '0.62',
            'relacion_ud' => '2.4',
            'pico_ud' => '45',
            'arteria_cerebral_menor' => '1.1',
            'ductos_venosos' => 'S',
            'ven_umbilical' => 'N',
            'relac_aorta' => 'N',
            'texto' => "Estudio dentro de límites normales.\nSin signos de redistribución.",
            'sugerencias' => 'Control en 4 semanas.',
            'conclusion' => 'Doppler normal.',
        ], $extra));
    }

    /** @return array<int,string> las claves de lo que la consulta ofrece imprimir */
    private function ofrecidos(Medico $medico, Consulta $consulta): array
    {
        return array_column(app(DocumentosParaImprimir::class)->disponibles($consulta, [$medico->reg_medico]), 'clave');
    }

    // ========================================================================== La hoja del médico

    public function test_el_informe_sale_con_los_rotulos_de_la_hoja_y_los_datos(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->doppler($medico);

        $hoja = $this->como($user, $medico, $gineco)->get($this->hoja($consulta));

        $hoja->assertOk()
            ->assertSee(DocumentoImpreso::TITULOS[DocumentoImpreso::ECO_DOPPLER][0])
            ->assertSee('ECO DOPPLER PERINATAL')
            ->assertSee('PACIENTE:')->assertSee('Ana Alvarez')
            ->assertSee('C.I.:')->assertSee('V-14555666')
            ->assertSee('FECHA:')
            // La ficha del encabezado, como en su DataWindow de impresión.
            ->assertSee('Gesta:')->assertSee('FUR:')->assertSee('20/05/2026')
            ->assertSee('EG:')->assertSee('20 semanas y 0 días')
            ->assertSee('Para:')->assertSee('1 cesárea')
            // El memo grande del estudio va primero y sin rótulo.
            ->assertSee('Estudio dentro de límites normales.')
            ->assertSee('Sin signos de redistribución.')
            // La grilla de cuatro vasos, con el título del bloque, sus cuatro ejes y una fila por vaso.
            ->assertSee('Eco Doppler Obstétrico en 4 Vasos')
            ->assertSee('Arteria Uterina Der.')
            ->assertSee('Índice de Pulsatibilidad')->assertSee('Índice de Resistencia')
            ->assertSee('Relación S / D')->assertSee('Pico de Velocidad Sistólica')
            ->assertSeeInOrder(['0.85', '0.62', '2.4', '45'])
            ->assertSee('Arteria Cerebral Media')->assertSee('1.1')
            // Los tres desplegables, con todas sus opciones y la elegida marcada (WEB-11.13). El rótulo del
            // ductus es el corregido de la errata del escritorio («Ductos Venosos», 2026-10-17).
            ->assertSeeInOrder(['Ductus venoso:', '○', 'Trifásico con Reversa', '●', 'Trifásico sin Reversa'], false)
            ->assertSeeInOrder(['Señal Venoso Umbilical', '●', 'Sí', '○', 'No'], false)
            ->assertSeeInOrder(['Relación Aorta-Cava:', '●', 'Normal', '○', 'Anormal'], false)
            // Las dos áreas del final, con los rótulos de la pantalla.
            ->assertSee('Observaciones:')->assertSee('Control en 4 semanas.')
            ->assertSee('Conclusiones:')->assertSee('Doppler normal.')
            ->assertSee('Ginecología y Obstetricia');

        // Una sola firma, en la mitad donde termina el informe.
        $this->assertSame(1, substr_count($hoja->getContent(), 'class="firmas"'));
    }

    public function test_una_sola_medida_imprime_la_grilla_y_los_desplegables_en_blanco(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        // Sólo una medida de la arteria umbilical: el informe es el formulario (WEB-11.13), con lo demás sin llenar.
        $this->doppler($medico, [
            'arteria_umbilical_menor' => '0.9',
            'arteria_uraina_menor' => null, 'arteria_uraina_mayor' => null,
            'relacion_ud' => null, 'pico_ud' => null, 'arteria_cerebral_menor' => null,
            'ductos_venosos' => null, 'ven_umbilical' => null, 'relac_aorta' => null,
            'texto' => null, 'sugerencias' => null, 'conclusion' => null,
        ]);

        $hoja = $this->como($user, $medico, $gineco)->get($this->hoja($consulta));

        $hoja->assertOk()
            ->assertSee('Eco Doppler Obstétrico en 4 Vasos')
            ->assertSee('Arteria Umbilical')->assertSee('0.9')
            ->assertSeeInOrder(['Ductus venoso:', '○', 'Trifásico con Reversa', '○', 'Trifásico sin Reversa'], false)
            ->assertDontSee('●', false);
    }

    public function test_una_fila_con_solo_la_ficha_no_se_ofrece_ni_se_imprime(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        // La ficha sola (fecha, FUR, gesta y `para`) no es un documento.
        $this->doppler($medico, [
            'arteria_uraina_menor' => null, 'arteria_uraina_mayor' => null,
            'relacion_ud' => null, 'pico_ud' => null, 'arteria_cerebral_menor' => null,
            'ductos_venosos' => null, 'ven_umbilical' => null, 'relac_aorta' => null,
            'texto' => null, 'sugerencias' => null, 'conclusion' => null,
        ]);

        $this->assertNotContains(DocumentoImpreso::ECO_DOPPLER, $this->ofrecidos($medico, $consulta));
        $this->como($user, $medico, $gineco)->get($this->hoja($consulta))->assertOk()->assertSee('Guarda los datos del estudio para poder imprimir.');
    }

    public function test_el_informe_en_texto_libre_del_escritorio_se_imprime_tal_cual(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        // El modo «Plantillas» del escritorio: sin ningún campo del estudio, sólo el informe escrito.
        $this->doppler($medico, [
            'fur' => null, 'gesta' => null, 'para' => null,
            'arteria_uraina_menor' => null, 'arteria_uraina_mayor' => null,
            'relacion_ud' => null, 'pico_ud' => null, 'arteria_cerebral_menor' => null,
            'ductos_venosos' => null, 'ven_umbilical' => null, 'relac_aorta' => null,
            'texto' => null, 'sugerencias' => null, 'conclusion' => null,
            'puro_texto' => "INFORME DEL ESCRITORIO\nSegunda línea.",
        ]);

        $this->assertContains(DocumentoImpreso::ECO_DOPPLER, $this->ofrecidos($medico, $consulta));

        $this->como($user, $medico, $gineco)->get($this->hoja($consulta))
            ->assertOk()
            ->assertSee('Informe en texto libre')
            ->assertSee('INFORME DEL ESCRITORIO')
            ->assertSee('Segunda línea.');
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
        $this->doppler($medico, ['conclusion' => 'CONCLUSION RESERVADA']);

        // Con un solo documento, la lista se salta y va directo a la hoja.
        $this->lista($secretaria, $medico, $gineco, $cita)
            ->assertRedirect(route('clinica.agenda.documentos.imprimir', [$cita->id, 'documento' => DocumentoImpreso::ECO_DOPPLER]));

        $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita)->assertOk()
            ->assertSee('ECO DOPPLER PERINATAL')
            ->assertSee('CONCLUSION RESERVADA');
    }

    public function test_la_lista_de_la_cita_nombra_el_informe_sin_contenido_clinico(): void
    {
        [, $medico, $gineco, , $secretaria, $cita] = $this->escenario();
        $this->doppler($medico, ['conclusion' => 'CONCLUSION RESERVADA']);
        // Otro documento, para que la lista no se salte.
        DB::table('dieta_paciente')->insert([
            'reg_medico' => $medico->reg_medico, 'histroria' => 1001, 'consulta' => 1,
            'dieta' => 'DIETA', 'descripcion' => 'Sin sal.', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->lista($secretaria, $medico, $gineco, $cita)
            ->assertOk()
            ->assertSee('Documentos de Ana Alvarez')
            ->assertSee('Eco Doppler perinatal')
            ->assertSee('Informe del eco Doppler perinatal')
            ->assertSee('documento=' . DocumentoImpreso::ECO_DOPPLER, false)
            ->assertDontSee('CONCLUSION RESERVADA')
            ->assertDontSee('0.85');
    }

    public function test_la_hoja_es_la_misma_para_el_medico_y_para_la_secretaria(): void
    {
        [$user, $medico, $gineco, $consulta, $secretaria, $cita] = $this->escenario();
        $this->doppler($medico);

        $cuerpo = fn (string $html) => substr($html, strpos($html, '<main class="recipe-hojas"'), strpos($html, '</main>') - strpos($html, '<main class="recipe-hojas"'));

        $delMedico = $this->como($user, $medico, $gineco)->get($this->hoja($consulta))->getContent();
        $deLaSecretaria = $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita)->getContent();

        $this->assertSame($cuerpo($delMedico), $cuerpo($deLaSecretaria));
    }

    /**
     * La fila de la agenda ofrece «Imprimir documentos» cuando la cita tiene algo: el lote lo decide
     * `DocumentosPorCita::conDocumentos`, que es SQL sobre `eco_doppler` (su clave es `historia` +
     * `consulta`, no `numhistoria`/`nroconsulta`).
     */
    public function test_la_agenda_cuenta_el_doppler_entre_los_documentos_de_la_cita(): void
    {
        [, $medico, , , , $cita, $pacienteId] = $this->escenario();
        $this->doppler($medico);

        $deLaAgenda = new CitaDeAgenda(
            id: $cita->id, fecha: $this->hoy->toDateString(), horaIni: '10:30:00', numOrden: 1,
            estado: 1, atendido: 1, monto: null, montoPagado: null, numHistoria: 1001,
            pacienteSinHistoriaId: null, pacienteId: $pacienteId, paciente: 'Ana Alvarez',
            centroId: null, motivo: null, tipo: null, medico: null,
        );

        $this->assertArrayHasKey(
            $cita->id,
            app(DocumentosPorCita::class)->conDocumentos($medico->reg_medico, collect([$deLaAgenda])),
        );

        // Con la ficha sola no hay nada que imprimir: la cita no lleva el botón.
        EcoDoppler::where('reg_medico', $medico->reg_medico)->update([
            'arteria_uraina_menor' => null, 'arteria_uraina_mayor' => null, 'relacion_ud' => null,
            'pico_ud' => null, 'arteria_cerebral_menor' => null, 'ductos_venosos' => null,
            'ven_umbilical' => null, 'relac_aorta' => null, 'texto' => null, 'sugerencias' => null,
            'conclusion' => null,
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
        $this->doppler($medico);

        $this->como($user, $medico, $gineco)->get(route('clinica.pacientes.historia', $pacienteId))
            ->assertOk()
            ->assertSee('Eco Doppler perinatal')
            ->assertSee('Informe del eco Doppler perinatal');
    }

    public function test_la_historia_no_lista_un_estudio_sin_datos(): void
    {
        [$user, $medico, $gineco, , , , $pacienteId] = $this->escenario();
        $this->doppler($medico, [
            'arteria_uraina_menor' => null, 'arteria_uraina_mayor' => null, 'relacion_ud' => null,
            'pico_ud' => null, 'arteria_cerebral_menor' => null, 'ductos_venosos' => null,
            'ven_umbilical' => null, 'relac_aorta' => null, 'texto' => null, 'sugerencias' => null,
            'conclusion' => null,
        ]);

        $this->como($user, $medico, $gineco)->get(route('clinica.pacientes.historia', $pacienteId))
            ->assertOk()
            ->assertDontSee('Eco Doppler perinatal');
    }
}
