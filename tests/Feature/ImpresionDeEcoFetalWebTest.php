<?php

namespace Tests\Feature;

use App\Clinica\Agenda\CitaDeAgenda;
use App\Clinica\Impresion\DocumentoImpreso;
use App\Clinica\Impresion\DocumentosParaImprimir;
use App\Clinica\Impresion\DocumentosPorCita;
use App\Clinica\Consulta\EcoCardiogramaFetal;
use App\Models\Cola;
use App\Models\Consulta;
use App\Models\EcoCardiogramaFetal as EcoCardiogramaFetalModelo;
use App\Models\Medico;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **Imprimir el informe del ecocardiograma fetal** (WEB-6.3): media hoja con el membrete del médico, la ficha
 * obstetricia del encabezado (gesta, FUR, FPP, EG y el «REFERIDO POR:» que el legado saca de
 * `pacientes.ocupacion`), el motivo de consulta, la paridad, el texto del estudio, la biometría con sus
 * milímetros, los datos anatómicos, los placento-funiculares y la conclusión; la secretaría lo imprime desde la
 * cita atendida sin ver el contenido clínico; y la historia del paciente lo lista.
 *
 * Que el cuerpo real del navegador no se desborde lo comprueba el verificador de Chrome de la sección.
 */
class ImpresionDeEcoFetalWebTest extends TestCase
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

    /**
     * @param  bool  $conMotivo  ¿se carga un motivo de consulta? Sólo lo necesita el caso que comprueba que la
     *                           hoja lo imprime: un motivo hace que la consulta tenga además su **resumen
     *                           médico** (`conResumen` cuenta cualquier motivo), y eso agrega un documento a la
     *                           lista de la cita.
     * @return array{0:User, 1:Medico, 2:Specialty, 3:Consulta, 4:User, 5:Cola, 6:int}
     */
    private function escenario(bool $conMotivo = false): array
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->membrete($medico);
        $secretaria = $this->secretariaConAcceso($medico);
        // La ocupación del paciente es la que el escritorio imprime como «REFERIDO POR:».
        [$pacienteId, $consulta] = $this->pacienteYConsulta($medico, 1001, 1, ['ocupacion' => 'Abogada']);
        $cita = $this->cita($medico, 1001, $this->hoy->toDateString(), ['atendido' => 1, 'estado' => 1]);

        if ($conMotivo) {
            // El motivo de consulta, que la hoja imprime primero (el mismo dato que el resumen médico).
            DB::table('motivo_consulta_paciente')->insert([
                'reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1,
                'codemotivo' => '0001', 'descripcion' => 'CONTROL PRENATAL',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [$user, $medico, $gineco, $consulta, $secretaria, $cita, $pacienteId];
    }

    private function como(User $user, Medico $medico, Specialty $gineco): self
    {
        return $this->conContexto($user, $medico, $gineco)->actingAs($user);
    }

    private function hoja(Consulta $consulta, string $documento = DocumentoImpreso::ECO_CARDIOGRAMA_FETAL): string
    {
        return route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => $documento]);
    }

    /** El ecocardiograma de la consulta como lo escribe el escritorio (la clave es `historia` + `consulta`). */
    private function fetal(Medico $medico, array $extra = []): EcoCardiogramaFetalModelo
    {
        return EcoCardiogramaFetalModelo::create(array_merge([
            'reg_medico' => $medico->reg_medico,
            'historia' => 1001,
            'consulta' => 1,
            'fecha' => '2026-06-01',
            'fur' => '2026-05-20',
            'gesta' => 2,
            'parto' => 2,
            'aborto' => 0,
            'cesarea' => 1,
            'otros' => 'Ninguno',
            'biome_dbp' => 25,
            'biome_dof' => 30,
            'biome_cc' => 100,
            'biome_humero' => 20,
            'biome_femur' => 22,
            'biome_saco' => 18,
            'biome_ca' => 40,
            'biome_cardiaca' => 12,
            'biome_toraxica' => 30,
            'biome_relacion' => 45,
            'texto_inicio' => "SE REALIZA EXPLORACIÓN CON TRANSDUCTOR DE 3,5 MHZ  EVIDENCIÁNDOSE: FETO ÚNICO.\nMOVIMIENTOS Y ACTIVIDAD CARDÍACA PRESENTES.",
            'anatomi_datos' => 'CORAZON EN SITUS SOLITUS, TETRACAMERAL. TABIQUES INTERVENTRICULAR E INTERAURICULAR ASPECTO NORMAL.',
            'pla_funi_placenta' => 'A',
            'pla_funi_grado' => 'II',
            'pla_funi_grosor' => 12,
            'pla_funi_cordon_umb' => 'N',
            'pla_funi_liquido_anm' => 'O',
            'pla_funi_pef' => 2500,
            'pla_funi_sexo' => 'F',
            'conclusion' => 'Corazón estructuralmente normal.',
            'sugerencia' => 'Control en 4 semanas.',
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
        [$user, $medico, $gineco, $consulta] = $this->escenario(true);
        $this->fetal($medico);

        $hoja = $this->como($user, $medico, $gineco)->get($this->hoja($consulta));

        $hoja->assertOk()
            ->assertSee(DocumentoImpreso::TITULOS[DocumentoImpreso::ECO_CARDIOGRAMA_FETAL][0])
            ->assertSee('ECOCARDIOGRAMA FETAL')
            ->assertSee('PACIENTE:')->assertSee('Ana Alvarez')
            ->assertSee('C.I.:')->assertSee('V-14555666')
            ->assertSee('FECHA:')
            // La ficha obstetricia de su cabecera, como en su DataWindow de impresión.
            ->assertSee('Gesta:')->assertSee('II')
            ->assertSee('FUR:')->assertSee('20/05/2026')
            ->assertSee('FPP:')->assertSee('24/02/2027')
            ->assertSee('EG:')->assertSee('20 semanas y 0 días')
            // ⚠️ La rareza del legado: el «REFERIDO POR:» imprime `pacientes.ocupacion`, no la columna `referido`.
            ->assertSee('REFERIDO POR:')->assertSee('Abogada')
            // El motivo de consulta, primero como en el resumen médico.
            ->assertSee('MOTIVO DE CONSULTA:')->assertSee('CONTROL PRENATAL')
            // La paridad del encabezado del estudio.
            ->assertSee('Para:')->assertSee('Abortos:')->assertSee('Cesáreas:')->assertSee('Otros:')->assertSee('Ninguno')
            // El memo grande del estudio, con sus dos renglones.
            ->assertSee('SE REALIZA EXPLORACIÓN CON TRANSDUCTOR DE 3,5 MHZ')->assertSee('FETO ÚNICO.')
            ->assertSee('MOVIMIENTOS Y ACTIVIDAD CARDÍACA PRESENTES.')
            // La biometría, cada medida con sus milímetros y la «MEDIDA» con su percentil.
            ->assertSee('Datos biométricos')
            ->assertSee('DBP:')->assertSee('25 mm')->assertSee('DOF:')->assertSee('30 mm')->assertSee('CC:')->assertSee('100 mm')
            ->assertSee('Húmero:')->assertSee('Fémur:')->assertSee('Sacro:')
            ->assertSee('CT:')->assertSee('Cardíaca:')->assertSee('Torácica:')
            ->assertSee('Medida:')->assertSee('45 (Percentil)')
            // Los datos anatómicos.
            ->assertSee('Datos anatómicos')->assertSee('CORAZON EN SITUS SOLITUS, TETRACAMERAL.')
            // Los placento-funiculares, con todas las opciones y la elegida marcada (WEB-11.13).
            ->assertSee('Datos placento - funiculares')
            ->assertSeeInOrder(['Placenta:', '●', 'Anterior', '○', 'Posterior'], false)
            ->assertSee('Groso:')->assertSee('12 mm')
            ->assertSeeInOrder(['Cordón umbilical:', '●', '3 ELEMENTOS VASCULARES', '○', 'ANORMAL'], false)
            ->assertSeeInOrder(['Líquido amniótico:', '○', 'Normal', '●', 'Oligohidramnios'], false)
            ->assertSee('Peso fetal:')->assertSee('2500')
            ->assertSeeInOrder(['Sexo:', '○', 'Masculino', '●', 'Femenino'], false)
            // La conclusión: los signos y las sugerencias.
            ->assertSee('Signos ecográficos sugestivos de:')->assertSee('Corazón estructuralmente normal.')
            ->assertSee('Sugerencias:')->assertSee('Control en 4 semanas.')
            ->assertSee('Ginecología y Obstetricia');

        // Una sola firma, en la mitad donde termina el informe.
        $this->assertSame(1, substr_count($hoja->getContent(), 'class="firmas"'));
    }

    public function test_una_sola_medida_imprime_el_formulario_con_lo_demas_en_blanco(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        // Sólo una medida: el informe es el formulario (WEB-11.13), con lo demás sin llenar.
        $this->fetal($medico, [
            'biome_dbp' => 25, 'biome_dof' => null, 'biome_cc' => null, 'biome_humero' => null,
            'biome_femur' => null, 'biome_saco' => null, 'biome_ca' => null, 'biome_cardiaca' => null,
            'biome_toraxica' => null, 'biome_relacion' => null,
            'texto_inicio' => null, 'anatomi_datos' => null,
            'pla_funi_placenta' => null, 'pla_funi_grado' => null, 'pla_funi_grosor' => null,
            'pla_funi_cordon_umb' => null, 'pla_funi_liquido_anm' => null, 'pla_funi_pef' => null,
            'pla_funi_sexo' => null, 'conclusion' => null, 'sugerencia' => null,
        ]);

        $hoja = $this->como($user, $medico, $gineco)->get($this->hoja($consulta));

        $hoja->assertOk()
            ->assertSee('Datos biométricos')
            ->assertSee('DBP:')->assertSee('25 mm')
            ->assertSee('DOF:')
            ->assertSee('Datos placento - funiculares')
            ->assertSeeInOrder(['Placenta:', '○', 'Anterior', '○', 'Posterior'], false)
            ->assertDontSee('●', false);
    }

    public function test_una_fila_con_solo_la_ficha_no_se_ofrece_ni_se_imprime(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();
        // La ficha sola (la fecha, la FUR, la gesta y la paridad) no es un documento.
        $this->fetal($medico, [
            'biome_dbp' => null, 'biome_dof' => null, 'biome_cc' => null, 'biome_humero' => null,
            'biome_femur' => null, 'biome_saco' => null, 'biome_ca' => null, 'biome_cardiaca' => null,
            'biome_toraxica' => null, 'biome_relacion' => null,
            'texto_inicio' => null, 'anatomi_datos' => null,
            'pla_funi_placenta' => null, 'pla_funi_grado' => null, 'pla_funi_grosor' => null,
            'pla_funi_cordon_umb' => null, 'pla_funi_liquido_anm' => null, 'pla_funi_pef' => null,
            'pla_funi_sexo' => null, 'conclusion' => null, 'sugerencia' => null,
        ]);

        $this->assertNotContains(DocumentoImpreso::ECO_CARDIOGRAMA_FETAL, $this->ofrecidos($medico, $consulta));
        $this->como($user, $medico, $gineco)->get($this->hoja($consulta))->assertOk()->assertSee('Guarda los datos del estudio para poder imprimir.');
    }

    /** Una consulta sin fila no tiene informe: la lectura de la hoja no aplica la precarga del escritorio. */
    public function test_una_consulta_sin_fila_no_ofrece_la_hoja(): void
    {
        [$user, $medico, $gineco, $consulta] = $this->escenario();

        $this->assertSame(0, EcoCardiogramaFetalModelo::where('reg_medico', $medico->reg_medico)->count());
        $this->assertNotContains(DocumentoImpreso::ECO_CARDIOGRAMA_FETAL, $this->ofrecidos($medico, $consulta));
        $this->como($user, $medico, $gineco)->get($this->hoja($consulta))->assertOk()->assertSee('Guarda los datos del estudio para poder imprimir.');
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
        $this->fetal($medico, ['conclusion' => 'CONCLUSION RESERVADA']);

        // Con un solo documento, la lista se salta y va directo a la hoja.
        $this->lista($secretaria, $medico, $gineco, $cita)
            ->assertRedirect(route('clinica.agenda.documentos.imprimir', [$cita->id, 'documento' => DocumentoImpreso::ECO_CARDIOGRAMA_FETAL]));

        $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita)->assertOk()
            ->assertSee('ECOCARDIOGRAMA FETAL')
            ->assertSee('CONCLUSION RESERVADA');
    }

    public function test_la_lista_de_la_cita_nombra_el_informe_sin_contenido_clinico(): void
    {
        [, $medico, $gineco, , $secretaria, $cita] = $this->escenario();
        $this->fetal($medico, ['conclusion' => 'CONCLUSION RESERVADA']);
        // Otro documento, para que la lista no se salte.
        DB::table('dieta_paciente')->insert([
            'reg_medico' => $medico->reg_medico, 'histroria' => 1001, 'consulta' => 1,
            'dieta' => 'DIETA', 'descripcion' => 'Sin sal.', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->lista($secretaria, $medico, $gineco, $cita)
            ->assertOk()
            ->assertSee('Documentos de Ana Alvarez')
            ->assertSee('Ecocardiograma fetal')
            ->assertSee('Informe del ecocardiograma fetal')
            ->assertSee('documento=' . DocumentoImpreso::ECO_CARDIOGRAMA_FETAL, false)
            ->assertDontSee('CONCLUSION RESERVADA')
            ->assertDontSee('Corazón estructuralmente normal.');
    }

    public function test_la_hoja_es_la_misma_para_el_medico_y_para_la_secretaria(): void
    {
        [$user, $medico, $gineco, $consulta, $secretaria, $cita] = $this->escenario();
        $this->fetal($medico);

        $cuerpo = fn (string $html) => substr($html, strpos($html, '<main class="recipe-hojas"'), strpos($html, '</main>') - strpos($html, '<main class="recipe-hojas"'));

        $delMedico = $this->como($user, $medico, $gineco)->get($this->hoja($consulta))->getContent();
        $deLaSecretaria = $this->hojaDeLaCita($secretaria, $medico, $gineco, $cita)->getContent();

        $this->assertSame($cuerpo($delMedico), $cuerpo($deLaSecretaria));
    }

    /**
     * La fila de la agenda ofrece «Imprimir documentos» cuando la cita tiene algo: el lote lo decide
     * `DocumentosPorCita::conDocumentos`, que es SQL sobre `ecocadiograma_fetal` (su clave es `historia` +
     * `consulta` y su tabla trae el error de tipeo del legado).
     */
    public function test_la_agenda_cuenta_el_fetal_entre_los_documentos_de_la_cita(): void
    {
        [, $medico, , , , $cita, $pacienteId] = $this->escenario();
        $this->fetal($medico);

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
        EcoCardiogramaFetalModelo::where('reg_medico', $medico->reg_medico)->update([
            'biome_dbp' => null, 'biome_dof' => null, 'biome_cc' => null, 'biome_humero' => null,
            'biome_femur' => null, 'biome_saco' => null, 'biome_ca' => null, 'biome_cardiaca' => null,
            'biome_toraxica' => null, 'biome_relacion' => null,
            'texto_inicio' => null, 'anatomi_datos' => null,
            'pla_funi_placenta' => null, 'pla_funi_grado' => null, 'pla_funi_grosor' => null,
            'pla_funi_cordon_umb' => null, 'pla_funi_liquido_anm' => null, 'pla_funi_pef' => null,
            'pla_funi_sexo' => null, 'conclusion' => null, 'sugerencia' => null,
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
        $this->fetal($medico);

        $this->como($user, $medico, $gineco)->get(route('clinica.pacientes.historia', $pacienteId))
            ->assertOk()
            ->assertSee('Ecocardiograma fetal')
            ->assertSee('Informe del ecocardiograma fetal');
    }

    public function test_la_historia_no_lista_un_estudio_sin_datos(): void
    {
        [$user, $medico, $gineco, , , , $pacienteId] = $this->escenario();
        $this->fetal($medico, [
            'biome_dbp' => null, 'biome_dof' => null, 'biome_cc' => null, 'biome_humero' => null,
            'biome_femur' => null, 'biome_saco' => null, 'biome_ca' => null, 'biome_cardiaca' => null,
            'biome_toraxica' => null, 'biome_relacion' => null,
            'texto_inicio' => null, 'anatomi_datos' => null,
            'pla_funi_placenta' => null, 'pla_funi_grado' => null, 'pla_funi_grosor' => null,
            'pla_funi_cordon_umb' => null, 'pla_funi_liquido_anm' => null, 'pla_funi_pef' => null,
            'pla_funi_sexo' => null, 'conclusion' => null, 'sugerencia' => null,
        ]);

        $this->como($user, $medico, $gineco)->get(route('clinica.pacientes.historia', $pacienteId))
            ->assertOk()
            ->assertDontSee('Ecocardiograma fetal');
    }

    /**
     * La hoja no tiene **formato en blanco**: el panel del escritorio
     * (`miconsultat/w_panel_impresion_formatos.srw`) lista seis formatos y el ecocardiograma fetal no está entre
     * ellos. No es un olvido: es lo que la fuente tiene.
     */
    public function test_el_escritorio_no_tiene_formato_en_blanco_para_este_estudio(): void
    {
        $this->assertNotContains(DocumentoImpreso::ECO_CARDIOGRAMA_FETAL, \App\Clinica\Impresion\FormatosEnBlanco::claves());
        // Y su clave del documento es la de la hoja real.
        $this->assertSame('eco-cardiograma-fetal', DocumentoImpreso::ECO_CARDIOGRAMA_FETAL);
    }
}
