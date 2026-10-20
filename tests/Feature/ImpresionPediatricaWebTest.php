<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Medico;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **Los impresos de pediatría llevan la cabecera del escritorio** (2026-10-20): el sexo, el representante, el número de
 * historia, la edad compacta («21A 9M 6D») y, en la mitad de *Indicaciones* del récipe, las medidas (peso, talla,
 * circunferencia cefálica y tensión).
 *
 * Lo que fijan estos casos:
 *
 *  - se decide por la **especialidad del contexto** (`AlcanceDeLaEspecialidad`), no por la edad: el paciente de la prueba
 *    tiene **21 años** —como el de la captura del escritorio— y su récipe igual lleva el bloque;
 *  - vale para **los dos caminos** de la hoja: la consulta del médico y la secretaría desde la cita atendida (que reusa
 *    `HojasImpresasVista`);
 *  - vale para los **documentos** (constancia), no sólo para el récipe, y las medidas **sólo** en la mitad de indicaciones;
 *  - el **representante** sale de `representante` (la web) y, si ahí no hay nombre, de la familia del escritorio con
 *    `familia_bebe.status`; sin ninguno de los dos, el rótulo `Rep.:` **no se imprime**;
 *  - **fuera de pediatría la hoja queda como estaba**: los mismos datos cargados en una consulta de ginecología no
 *    imprimen nada de esto y la edad sigue en años.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class ImpresionPediatricaWebTest extends TestCase
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
     * Una consulta con todo lo que imprime el escritorio de pediatría: un paciente de 21 años (nacido el 1.º de enero de
     * 2005 → «21A 9M 6D» el 07/10/2026), las medidas cargadas y un representante. La historia es la 25, que se imprime con
     * cinco dígitos (`00025`).
     *
     * @return array{0:User, 1:Medico, 2:Specialty, 3:int, 4:Consulta}
     */
    private function escenario(string $especialidad = 'pediatria', bool $conRepresentante = true): array
    {
        [$user, $medico, $delContexto] = $this->medicoConAcceso('Medico', $especialidad);
        $this->vademecum($medico);
        $this->membrete($medico);

        [$pacienteId, $consulta] = $this->pacienteYConsulta($medico, 25, 1, ['fnacimiento' => '2005-01-01']);
        // El récipe es del mismo día de la consulta: la edad sale igual en las dos hojas.
        $this->recipe($medico, 25, 1, 1, [['0001', 2, 'Una cápsula cada 6 horas.']], '2026-10-07');

        // Las medidas del examen físico de la consulta (`consultas`), como las carga el escritorio.
        DB::table('consultas')->where('id', $consulta->id)->update([
            'peso' => 20,
            'talla' => 100,
            'circcefalica' => 48.5,
            'tasentado' => '120/80',
        ]);

        if ($conRepresentante) {
            $this->representante($medico, 25, 'ELDRIS MENDOZA');
        }

        return [$user, $medico, $delContexto, $pacienteId, $consulta->refresh()];
    }

    private function representante(Medico $medico, int $historia, string $nombre): void
    {
        DB::table('representante')->insert([
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => $historia,
            'nombre' => $nombre,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** La familia del escritorio de pediatría: `status` dice qué padre es el representante. */
    private function familia(Medico $medico, int $historia, string $status, string $madre = 'ROSA GOMEZ', string $padre = 'LUIS PEREZ'): void
    {
        DB::table('familia_bebe')->insert([
            'reg_medico' => $medico->reg_medico,
            'familia' => 7,
            'status' => $status,
            'nom_madre' => $madre,
            'nom_padre' => $padre,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('paciente_chamo')->insert([
            'reg_medico' => $medico->reg_medico,
            'familia' => 7,
            'historia' => $historia,
            'nombre' => 'ALEXANDRA TOCUYO',
            'sexo' => 'F',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** La constancia del paciente (su texto propio), para imprimir un documento de la consulta. */
    private function constancia(Medico $medico): void
    {
        DB::table('constancia_obs')->insert([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 25, 'numconsulta' => 1,
            'observacion01' => 'Acudió a control.', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function como(User $user, Medico $medico, Specialty $especialidad): self
    {
        return $this->conContexto($user, $medico, $especialidad)->actingAs($user);
    }

    // ============================================================================ El récipe

    public function test_el_recipe_de_pediatria_lleva_la_cabecera_del_escritorio_y_las_medidas(): void
    {
        [$user, $medico, $pediatria, , $consulta] = $this->escenario();

        $respuesta = $this->como($user, $medico, $pediatria)->get($this->urlRecipe($consulta));

        $respuesta->assertOk();
        // La cabecera del escritorio, con sus rótulos: sexo, representante, historia y la edad compacta (no «21 años»).
        $respuesta->assertSee('SEXO:');
        $respuesta->assertSee('Rep.:');
        $respuesta->assertSee('ELDRIS MENDOZA');
        $respuesta->assertSee('Historia:');
        $respuesta->assertSee('00025');
        $respuesta->assertSee('EDAD:');
        $respuesta->assertSee('21A 9M 6D');
        $respuesta->assertDontSee('21 años');
        // El sexo y el representante van en las dos mitades.
        $this->assertSame(2, substr_count($respuesta->getContent(), 'SEXO:'));
        $this->assertSame(2, substr_count($respuesta->getContent(), 'Rep.:'));
        // Las medidas, con sus rótulos y unidades, y **sólo** en la mitad de indicaciones: una vez cada una.
        foreach (['PESO:', 'TALLA:', 'C. CEFÁLICA:', 'T.A.:'] as $rotulo) {
            $this->assertSame(1, substr_count($respuesta->getContent(), $rotulo), $rotulo);
        }
        $respuesta->assertSee('20 Kg');
        $respuesta->assertSee('100 Cm');
        $respuesta->assertSee('48.5 Cm');
        $respuesta->assertSee('120/80');
        // Los datos de siempre no se perdieron.
        $respuesta->assertSee('Ana Alvarez');
        $respuesta->assertSee('V-14555666');
        $respuesta->assertSee('07/10/2026');
        $respuesta->assertSee('Av. Bolívar, edificio Sol, Barquisimeto');
    }

    public function test_el_representante_sale_de_la_familia_del_escritorio_si_la_tabla_de_la_web_no_lo_tiene(): void
    {
        [$user, $medico, $pediatria, , $consulta] = $this->escenario('pediatria', false);

        // Sin `representante`, la familia del escritorio: `status` F = la madre, M = el padre.
        $this->familia($medico, 25, 'F');

        $this->como($user, $medico, $pediatria)->get($this->urlRecipe($consulta))->assertSee('ROSA GOMEZ');

        DB::table('familia_bebe')->where('reg_medico', $medico->reg_medico)->update(['status' => 'M']);

        $this->como($user, $medico, $pediatria)->get($this->urlRecipe($consulta))
            ->assertSee('LUIS PEREZ')
            ->assertDontSee('ROSA GOMEZ');
    }

    public function test_sin_nombre_de_representante_no_se_imprime_el_rotulo(): void
    {
        [$user, $medico, $pediatria, , $consulta] = $this->escenario('pediatria', false);

        $respuesta = $this->como($user, $medico, $pediatria)->get($this->urlRecipe($consulta));

        // No queda un «Rep.:» colgando; el resto de la cabecera pediátrica sí sale.
        $respuesta->assertDontSee('Rep.:');
        $respuesta->assertSee('SEXO:');
        $respuesta->assertSee('Historia:');
    }

    public function test_la_secretaria_imprime_el_recipe_de_pediatria_desde_la_cita_atendida_con_la_cabecera(): void
    {
        [, $medico, $pediatria, , $consulta] = $this->escenario();
        $secretaria = $this->secretariaConAcceso($medico);
        $cita = $this->cita($medico, 25, '2026-10-07', ['atendido' => 1, 'estado' => 1]);

        $respuesta = $this->como($secretaria, $medico, $pediatria)
            ->get(route('clinica.agenda.documentos.imprimir', [$cita->id, 'documento' => 'recipe-1']));

        $respuesta->assertOk();
        $respuesta->assertSee('Rep.:');
        $respuesta->assertSee('ELDRIS MENDOZA');
        $respuesta->assertSee('Historia:');
        $respuesta->assertSee('21A 9M 6D');
        $respuesta->assertSee('PESO:');
        // El récipe sale con los datos de la consulta que se atendió.
        $respuesta->assertSee($consulta->fecha->format('d/m/Y'));
    }

    public function test_el_recipe_de_ginecologia_no_lleva_el_bloque_de_pediatria(): void
    {
        // Los mismos datos (paciente de 21 años, representante y medidas cargadas) en una consulta de ginecología.
        [$user, $medico, $gineco, , $consulta] = $this->escenario('ginecologia-y-obstetricia');

        $respuesta = $this->como($user, $medico, $gineco)->get($this->urlRecipe($consulta));

        $respuesta->assertOk();
        $respuesta->assertDontSee('SEXO:');
        $respuesta->assertDontSee('Rep.:');
        $respuesta->assertDontSee('Historia:');
        $respuesta->assertDontSee('PESO:');
        $respuesta->assertDontSee('C. CEFÁLICA:');
        $respuesta->assertDontSee('T.A.:');
        $respuesta->assertDontSee('21A 9M 6D');
        // La hoja de siempre: la edad en años.
        $respuesta->assertSee('EDAD:');
        $respuesta->assertSee('21 años');
    }

    // ============================================================================ Los documentos

    public function test_la_constancia_de_pediatria_lleva_la_cabecera_del_escritorio(): void
    {
        [$user, $medico, $pediatria, , $consulta] = $this->escenario();
        $this->constancia($medico);

        $respuesta = $this->como($user, $medico, $pediatria)
            ->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'constancia']));

        $respuesta->assertOk();
        $respuesta->assertSee('CONSTANCIA');
        $respuesta->assertSee('SEXO:');
        $respuesta->assertSee('Rep.:');
        $respuesta->assertSee('ELDRIS MENDOZA');
        $respuesta->assertSee('Historia:');
        $respuesta->assertSee('00025');
        $respuesta->assertSee('21A 9M 6D');
        $respuesta->assertDontSee('21 años');
        // Las medidas son del récipe: un documento no las lleva.
        $respuesta->assertDontSee('PESO:');
    }

    public function test_la_constancia_de_ginecologia_no_lleva_la_cabecera_de_pediatria(): void
    {
        [$user, $medico, $gineco, , $consulta] = $this->escenario('ginecologia-y-obstetricia');
        $this->constancia($medico);

        $respuesta = $this->como($user, $medico, $gineco)
            ->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'constancia']));

        $respuesta->assertOk();
        $respuesta->assertSee('CONSTANCIA');
        $respuesta->assertSee('21 años');
        $respuesta->assertDontSee('SEXO:');
        $respuesta->assertDontSee('Rep.:');
        $respuesta->assertDontSee('Historia:');
        $respuesta->assertDontSee('21A 9M 6D');
    }

    public function test_la_secretaria_imprime_la_constancia_de_pediatria_desde_la_cita(): void
    {
        [, $medico, $pediatria] = $this->escenario();
        $this->constancia($medico);
        $secretaria = $this->secretariaConAcceso($medico);
        $cita = $this->cita($medico, 25, '2026-10-07', ['atendido' => 1, 'estado' => 1]);

        $respuesta = $this->como($secretaria, $medico, $pediatria)
            ->get(route('clinica.agenda.documentos.imprimir', [$cita->id, 'documento' => 'constancia']));

        $respuesta->assertOk();
        $respuesta->assertSee('Rep.:');
        $respuesta->assertSee('Historia:');
        $respuesta->assertSee('21A 9M 6D');
    }

    /** La lista de la cita no cambia: el bloque es de la hoja, no de la pantalla de la secretaría. */
    public function test_la_lista_de_documentos_de_la_cita_no_muestra_el_bloque(): void
    {
        [, $medico, $pediatria] = $this->escenario();
        $this->constancia($medico);
        $secretaria = $this->secretariaConAcceso($medico);
        $cita = $this->cita($medico, 25, '2026-10-07', ['atendido' => 1, 'estado' => 1]);

        $respuesta = $this->como($secretaria, $medico, $pediatria)->get(route('clinica.agenda.documentos', $cita->id));

        $respuesta->assertOk();
        $respuesta->assertDontSee('ELDRIS MENDOZA');
        $respuesta->assertDontSee('21A 9M 6D');
    }
}
