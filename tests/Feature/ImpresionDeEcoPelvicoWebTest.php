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
 * **Imprimir el informe del eco pélvico** (WEB-6.1): media hoja con el membrete del médico, los datos del
 * estudio (medidas, útero, ovarios, hallazgos y conclusiones) y la firma; la secretaría lo imprime desde la
 * cita atendida sin ver el contenido clínico; y la historia del paciente lo lista.
 *
 * Los rótulos, los códigos y las unidades salen del mapa del dato (`EcoGinecologico`), el mismo que pinta el
 * formulario: acá se fija que la hoja los use y que un bloque sin datos no se imprima.
 *
 * Que el cuerpo real del navegador no se desborde lo comprueba el verificador de Chrome
 * (`.agent-tools/verificar-consulta-eco-web.js`).
 */
class ImpresionDeEcoPelvicoWebTest extends TestCase
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

    private function hoja(Consulta $consulta, string $documento = 'eco-pelvico'): string
    {
        return route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => $documento]);
    }

    /** El eco de la consulta, como lo escribiría el escritorio. */
    private function eco(Medico $medico, array $extra = []): void
    {
        DB::table('eco_pelvico')->insert(array_merge([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => 1001,
            'nroconsulta' => 1,
            'fecha'       => '2026-06-01',
            'fur'         => '2026-05-20',
            'gesta'       => 2,
            'PARA'        => '1 cesárea',
            'trans_convex' => '3.5',
            'vagina'      => 'H',
            'posicionavf' => '1',
            'fondosacolibre' => '1',
            'bordeutero'  => '1',
            'patron'      => '1',
            'cuerpo'      => 62.5,
            'transverso'  => 45,
            'antero'      => 30,
            'linea'       => '1',
            'espesor'     => 8,
            'ovarioderlong' => 41,
            'volumen_der' => 4.5,
            'observacion' => 'Anexos libres.',
            'idxecografico' => 'Útero en anteversión.',
            'sugerencias' => 'Control en 6 meses.',
            'created_at'  => now(),
            'updated_at'  => now(),
        ], $extra));
    }

    /** @return array<int,string> las claves de lo que la consulta ofrece imprimir */
    private function ofrecidos(Medico $medico, Consulta $consulta): array
    {
        return array_column(app(DocumentosParaImprimir::class)->disponibles($consulta, [$medico->reg_medico]), 'clave');
    }

    // ========================================================================== La hoja del médico

    public function test_el_informe_sale_con_los_datos_del_estudio_y_las_conclusiones(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        $this->eco($medico);

        $hoja = $this->como($user, $medico, $gineco)->get($this->hoja($consulta));

        $hoja->assertOk()
            ->assertSee(DocumentoImpreso::TITULOS[DocumentoImpreso::ECO_PELVICO][0])
            ->assertSee('PACIENTE:')->assertSee('Ana Alvarez')
            ->assertSee('C.I.:')->assertSee('V-14555666')
            // La fecha de la cabecera es la de la consulta, como en el escritorio.
            ->assertSee('FECHA:')
            // Los datos del estudio, con los rótulos y las unidades del informe.
            ->assertSee('FUR:')->assertSee('20/05/2026')
            ->assertSee('Gesta:')->assertSee('II')
            ->assertSee('PARA:')->assertSee('1 cesárea')
            ->assertSee('Estudio del')->assertSee('01/06/2026')
            ->assertSee('Convex 3.5 MHz')
            ->assertSee('Vejiga:')->assertSee('Llena')
            ->assertSee('Posición:')->assertSee('AVF')
            ->assertSee('Forma:')->assertSee('Globosa')
            ->assertSee('Cuerpo (longitud) 62.5 mm')
            ->assertSee('Transverso 45 mm')
            ->assertSee('Ovario derecho')
            ->assertSee('Volumen 4.5 cc')
            ->assertSee('Anexos:')->assertSee('Anexos libres.')
            ->assertSee('Observaciones:')->assertSee('Útero en anteversión.')
            ->assertSee('Conclusiones:')->assertSee('Control en 6 meses.')
            ->assertSee('Ginecología y Obstetricia');

        // Una sola firma, en la mitad donde termina el informe.
        $this->assertSame(1, substr_count($hoja->getContent(), 'class="firmas"'));
    }

    public function test_los_bloques_sin_datos_no_se_imprimen(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        // Un estudio con la FUR y la gesta, y nada más: los bloques sin dato no se imprimen.
        $this->eco($medico, [
            'trans_convex' => null, 'vagina' => null, 'vagina_llena' => null,
            'posicionavf' => null, 'fondosacolibre' => null, 'bordeutero' => null,
            'patron' => null, 'cuerpo' => null, 'transverso' => null, 'antero' => null,
            'linea' => null, 'espesor' => null, 'observacion' => null,
            'ovarioderlong' => null, 'volumen_der' => null, 'observacionovario' => null,
            'idxecografico' => null, 'sugerencias' => null,
        ]);

        $hoja = $this->como($user, $medico, $gineco)->get($this->hoja($consulta));

        $hoja->assertOk()
            ->assertSee(DocumentoImpreso::TITULOS[DocumentoImpreso::ECO_PELVICO][0])
            ->assertSee('FUR:')->assertSee('20/05/2026')
            ->assertSee('Gesta:')->assertSee('II')
            ->assertSee('Estudio del')->assertSee('01/06/2026')
            ->assertDontSee('Convex')
            ->assertDontSee('Vejiga:')
            ->assertDontSee('Posición:')
            ->assertDontSee('Medidas')
            ->assertDontSee('Ovario derecho')
            ->assertDontSee('Anexos:')
            ->assertDontSee('Conclusiones:');
    }

    public function test_el_informe_en_texto_libre_del_escritorio_se_imprime_tal_cual(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        // El modo "puro texto": sin ningún campo estructurado, sólo el informe escrito.
        $this->eco($medico, [
            'fur' => null, 'gesta' => null, 'PARA' => null, 'trans_convex' => null, 'vagina' => null,
            'posicionavf' => null, 'fondosacolibre' => null, 'bordeutero' => null, 'patron' => null,
            'cuerpo' => null, 'transverso' => null, 'antero' => null, 'linea' => null, 'espesor' => null,
            'ovarioderlong' => null, 'volumen_der' => null, 'observacion' => null,
            'idxecografico' => null, 'sugerencias' => null,
            'puro_texto' => "INFORME DEL ESCRITORIO\nSegunda línea.",
        ]);

        $this->assertContains(DocumentoImpreso::ECO_PELVICO, $this->ofrecidos($medico, $consulta));

        $this->como($user, $medico, $gineco)->get($this->hoja($consulta))
            ->assertOk()
            ->assertSee('Informe en texto libre')
            ->assertSee('INFORME DEL ESCRITORIO')
            ->assertSee('Segunda línea.');
    }

    public function test_una_fila_con_solo_la_fecha_no_se_ofrece_ni_se_imprime(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        // La fila que el escritorio puede dejar con sólo la fecha: no es el informe de nadie.
        $this->eco($medico, [
            'fur' => null, 'gesta' => null, 'PARA' => null, 'trans_convex' => null, 'vagina' => null,
            'posicionavf' => null, 'fondosacolibre' => null, 'bordeutero' => null, 'patron' => null,
            'cuerpo' => null, 'transverso' => null, 'antero' => null, 'linea' => null, 'espesor' => null,
            'ovarioderlong' => null, 'volumen_der' => null, 'observacion' => null,
            'idxecografico' => null, 'sugerencias' => null,
        ]);

        $this->assertNotContains(DocumentoImpreso::ECO_PELVICO, $this->ofrecidos($medico, $consulta));
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

    public function test_la_secretaria_lo_ve_en_la_cita_atendida_sin_el_contenido_clinico(): void
    {
        [, $medico, $gineco, , $secretaria, $cita] = $this->escenario();
        $this->eco($medico, ['sugerencias' => 'CONCLUSION RESERVADA', 'idxecografico' => 'OBSERVACION RESERVADA']);

        // Con un solo documento, la lista se salta y va directo a la hoja.
        $lista = $this->lista($secretaria, $medico, $gineco, $cita);
        $lista->assertRedirect(route('clinica.agenda.documentos.imprimir', [$cita->id, 'documento' => DocumentoImpreso::ECO_PELVICO]));

        $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita)->assertOk()
            ->assertSee(DocumentoImpreso::TITULOS[DocumentoImpreso::ECO_PELVICO][0]);
    }

    public function test_la_lista_de_la_cita_nombra_el_informe_sin_contenido_clinico(): void
    {
        [, $medico, $gineco, , $secretaria, $cita] = $this->escenario();
        $this->eco($medico, ['sugerencias' => 'CONCLUSION RESERVADA']);
        // Otro documento, para que la lista no se salte.
        DB::table('dieta_paciente')->insert([
            'reg_medico' => $medico->reg_medico, 'histroria' => 1001, 'consulta' => 1,
            'dieta' => 'DIETA', 'descripcion' => 'Sin sal.', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $lista = $this->lista($secretaria, $medico, $gineco, $cita);

        $lista->assertOk()
            ->assertSee('Documentos de Ana Alvarez')
            ->assertSee('Eco pélvico')
            ->assertSee('Informe del eco pélvico')
            ->assertSee('documento=' . DocumentoImpreso::ECO_PELVICO, false)
            ->assertDontSee('CONCLUSION RESERVADA')
            ->assertDontSee('62.5');
    }

    public function test_la_hoja_es_la_misma_para_el_medico_y_para_la_secretaria(): void
    {
        [$user, $medico, $gineco, $consulta, $secretaria, $cita] = $this->escenario();
        $this->eco($medico);

        $cuerpo = fn (string $html) => substr($html, strpos($html, '<main class="recipe-hojas"'), strpos($html, '</main>') - strpos($html, '<main class="recipe-hojas"'));

        $delMedico = $this->como($user, $medico, $gineco)->get($this->hoja($consulta))->getContent();
        $deLaSecretaria = $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita)->getContent();

        $this->assertSame($cuerpo($delMedico), $cuerpo($deLaSecretaria));
    }

    // ======================================================================== La historia del paciente

    public function test_la_historia_del_paciente_lista_el_informe(): void
    {
        [$user, $medico, $gineco, , , , $pacienteId] = $this->escenario();
        $this->eco($medico);

        $historia = $this->como($user, $medico, $gineco)->get(route('clinica.pacientes.historia', $pacienteId));

        $historia->assertOk()
            ->assertSee('Eco pélvico')
            ->assertSee('Informe del eco pélvico');
    }
}
