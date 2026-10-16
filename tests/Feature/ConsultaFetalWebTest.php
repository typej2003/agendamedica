<?php

namespace Tests\Feature;

use App\Clinica\Consulta\EcoCardiogramaFetal;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\TiposDeEstudio;
use App\Models\Consulta;
use App\Models\EcoCardiogramaFetal as EcoCardiogramaFetalModelo;
use App\Models\Medico;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **El ecocardiograma fetal de la consulta** (WEB-6.3, ítem 5 del panel del legado), dentro del shell.
 *
 * Lo que estos casos fijan:
 *
 *  - **La sección**: el quinto tipo del ítem 5 se ofrece como enlace, abre con su formulario de seis bloques
 *    —la ficha, los dos memos, la biometría, los datos anatómicos, los placento-funiculares y la conclusión— y
 *    su parcial existe (un tipo sin parcial tira 500: trampa 103).
 *  - **La precarga del escritorio** (WEB-6.3): al abrir una consulta **sin fila**, los dos memos llegan
 *    escritos con los textos de la ventana, y la EG y la FPP se muestran calculadas de la FUR.
 *  - **La clave del legado**: una fila por consulta, por **`historia` + `consulta`** —no
 *    `numhistoria`/`nroconsulta` como el eco pélvico—, con `fecha` propuesta en hoy; guardar dos veces **no
 *    duplica** la fila.
 *  - **El rastro por columna en `sync_changes`** (`source = web`) y el guard last-write-wins del sync.
 *  - **Nada de filas vacías**: un estudio que sólo trae su ficha no es un documento, no se guarda y no ofrece su
 *    hoja.
 *  - **`eg` y `referido` no se tocan**: existen, van ocultos en el DataWindow y en ginecología no se editan.
 *  - El **404** de la consulta de otro consultorio, el **403** de la secretaría y el invitado al login.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class ConsultaFetalWebTest extends TestCase
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

    private function como(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->conContexto($user, $medico, $specialty)->actingAs($user);
    }

    private function urlSeccion(Consulta $consulta): string
    {
        return route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_ESTUDIO,
            'tipo' => TiposDeEstudio::FETAL,
        ]);
    }

    private function urlGuardar(Consulta $consulta): string
    {
        return route('clinica.consulta.estudio.guardar', [$consulta->id, TiposDeEstudio::FETAL]);
    }

    /**
     * Lo que manda el formulario: **las 28 columnas** del mapa, vacías las que no se cargaron. Es lo que hace
     * el navegador, y por eso el caso de "sólo lo que cambió" se prueba con esto y no con un POST parcial.
     *
     * @param  array<string,string>  $valores
     * @return array<string,string>
     */
    private function formulario(array $valores = []): array
    {
        $campos = [];
        foreach (EcoCardiogramaFetal::columnas() as $columna) {
            $campos[$columna] = $valores[$columna] ?? '';
        }

        return $campos;
    }

    // ------------------------------------------------------------------------------------ La sección

    public function test_la_seccion_abre_con_el_formulario_del_fetal_y_sus_seis_bloques(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->como($user, $medico, $gineco)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();

        // Los cinco tipos del ítem 5, con el ecocardiograma fetal entre ellos.
        $respuesta->assertSee('Eco ginecológico')
            ->assertSee('Eco obstétrico (1.er trimestre)')
            ->assertSee('Eco obstétrico (3.er trimestre)')
            ->assertSee('Eco Doppler perinatal')
            ->assertSee('Ecocardiograma fetal');

        // Sus seis bloques, con los rótulos del DataWindow vivo.
        foreach (['Ficha', 'El estudio', 'Datos biométricos', 'Datos anatómicos',
            'Datos placento - funiculares', 'Conclusión'] as $bloque) {
            $respuesta->assertSee($bloque);
        }

        // Los rótulos de la ficha y de los bloques, tal como los lee el médico.
        foreach (['Fecha', 'FUR', 'Gesta', 'Para', 'Abortos', 'Cesáreas', 'Otros',
            'DBP', 'DOF', 'CC', 'Húmero', 'Fémur', 'Sacro', 'CT', 'Cardíaca', 'Torácica', 'Medida',
            'Placenta', 'Grado', 'Groso', 'Cordón umbilical', 'Líquido amniótico', 'Peso fetal', 'Sexo',
            'Signos ecográficos sugestivos de', 'Sugerencias'] as $rotulo) {
            $respuesta->assertSee($rotulo, false);
        }

        // Y sus desplegables con las opciones del escritorio.
        $respuesta->assertSee('Anterior')->assertSee('Fúndica')->assertSee('Previa')
            ->assertSee('Oligohidramnios')->assertSee('Polihidramnios')
            ->assertSee('3 ELEMENTOS VASCULARES')->assertSee('Masculino')->assertSee('Femenino');

        // El formulario apunta a la acción de guardar del tipo que se está mirando.
        $respuesta->assertSee($this->urlGuardar($consulta), false);
        $respuesta->assertSee('Guardar el estudio');
    }

    /**
     * **La precarga del escritorio** (WEB-6.3): su ventana, al abrir una consulta sin fila, escribe los dos
     * memos con textos fijos (`w_cargar_visualizar_ecocardiograma_fetal.srw:174-182`). La web replica eso como
     * valor por defecto de la pantalla: la consulta no tiene fila y los dos textos aparecen escritos.
     */
    public function test_al_abrir_una_consulta_sin_fila_los_dos_memos_llegan_precargados(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->assertSame(0, EcoCardiogramaFetalModelo::where('reg_medico', $medico->reg_medico)->count());

        $respuesta = $this->como($user, $medico, $gineco)->get($this->urlSeccion($consulta));

        $respuesta->assertOk()
            ->assertSee('SE REALIZA EXPLORACIÓN CON TRANSDUCTOR DE 3,5 MHZ', false)
            ->assertSee('CORAZON EN SITUS SOLITUS, TETRACAMERAL.', false)
            ->assertSee('RELACION CARDIOTORACICA 1/3.', false)
            ->assertSee('Sin guardar')
            // La hoja sale de lo guardado: mientras no haya fila, no se ofrece (la impresión daría 404).
            ->assertDontSee('Imprimir el informe');

        // La precarga es **sólo de la pantalla**: no crea la fila.
        $this->assertSame(0, EcoCardiogramaFetalModelo::where('reg_medico', $medico->reg_medico)->count());
    }

    /** La EG y la FPP no son campos: se calculan de la FUR y la pantalla las muestra. */
    public function test_la_eg_y_la_fpp_se_muestran_calculadas_de_la_fur(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        EcoCardiogramaFetalModelo::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'consulta' => 1,
            'fur' => '2026-05-20', 'biome_dbp' => 25,
        ]);

        // Del 20/05/2026 al 07/10/2026 (el "hoy" del test) hay 140 días: 20 semanas y 0 días; la FPP es la FUR
        // más 283 días.
        $this->como($user, $medico, $gineco)->get($this->urlSeccion($consulta))
            ->assertOk()
            ->assertSee('20 semanas y 0 días')
            ->assertSee('24/02/2027');
    }

    /**
     * La opción **vacía adelante** del desplegable (trampa 112): un `<select>` sin ninguna `selected` manda su
     * primera opción, así que el formulario recién abierto guardaba «Anterior» o «Normal» en casillas que el
     * médico nunca tocó.
     */
    public function test_los_desplegables_traen_la_opcion_vacia_adelante(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $html = $this->como($user, $medico, $gineco)->get($this->urlSeccion($consulta))->assertOk()->getContent();

        foreach (['pla_funi_placenta', 'pla_funi_grado', 'pla_funi_cordon_umb', 'pla_funi_liquido_anm',
            'pla_funi_sexo', 'gesta'] as $columna) {
            $this->assertStringContainsString('name="' . $columna . '"', $html, 'El desplegable ' . $columna . '.');
            $this->assertMatchesRegularExpression(
                '/<option value=""\s+selected\s*>/',
                $this->selectDe($html, $columna),
                'La opción vacía de ' . $columna . '.',
            );
        }

        // Y los códigos del legado (la placenta desempata Previa con `M`).
        $placenta = $this->selectDe($html, 'pla_funi_placenta');
        $this->assertMatchesRegularExpression('/<option value="A"\s*>Anterior<\/option>/', $placenta);
        $this->assertMatchesRegularExpression('/<option value="M"\s*>Previa<\/option>/', $placenta);
    }

    /** El `<select>` de una columna, recortado de la pantalla. */
    private function selectDe(string $html, string $columna): string
    {
        $desde = strpos($html, 'name="' . $columna . '"');

        return $desde === false ? '' : substr($html, $desde, 1600);
    }

    public function test_el_panel_marca_el_estudio_como_disponible(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->como($user, $medico, $gineco)->get(route('clinica.consulta', $consulta->id));

        $respuesta->assertOk();
        // El ítem 5 ya estaba disponible desde WEB-6.1: el tipo nuevo no cambia el conteo del panel.
        $respuesta->assertSee('13 de 14 disponibles');
        $respuesta->assertSee('Estudio ecográfico');
    }

    // ------------------------------------------------------------------------------------ El guardado

    public function test_guardar_crea_la_fila_con_la_clave_del_datawindow_y_su_rastro(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->como($user, $medico, $gineco)->post($this->urlGuardar($consulta), $this->formulario([
            'fur' => '2026-05-20',
            'gesta' => '2',
            'parto' => '2',
            'aborto' => '0',
            'cesarea' => '1',
            'otros' => 'Ninguno',
            'biome_dbp' => '25',
            'biome_relacion' => '45',
            'pla_funi_placenta' => 'A',
            'pla_funi_grado' => 'II',
            'pla_funi_grosor' => '12',
            'pla_funi_cordon_umb' => 'N',
            'pla_funi_liquido_anm' => 'N',
            'pla_funi_pef' => '2500',
            'pla_funi_sexo' => 'F',
            'texto_inicio' => EcoCardiogramaFetal::TEXTO_INICIAL,
            'anatomi_datos' => EcoCardiogramaFetal::TEXTO_ANATOMICO,
            'conclusion' => 'Corazón estructuralmente normal.',
            'sugerencia' => 'Control en 4 semanas.',
        ]));

        $respuesta->assertRedirect($this->urlSeccion($consulta));
        $respuesta->assertSessionHas('estado', 'Ecocardiograma fetal guardado.');

        $fila = EcoCardiogramaFetalModelo::where('reg_medico', $medico->reg_medico)->where('historia', 1001)->first();

        $this->assertNotNull($fila);
        // La clave del DataWindow: `historia` + `consulta` (no `numhistoria`/`nroconsulta`), acotada al médico.
        $this->assertSame(1, (int) $fila->consulta);
        $this->assertSame($medico->reg_medico, $fila->reg_medico);
        $this->assertSame('25', (string) $fila->biome_dbp);
        $this->assertSame('45', (string) $fila->biome_relacion);
        $this->assertSame('A', $fila->pla_funi_placenta);
        $this->assertSame('II', $fila->pla_funi_grado);
        $this->assertSame('2', (string) $fila->gesta);

        // La fecha la propone el servidor con hoy: el escritorio no la puede cargar sin abrir la ventana.
        $this->assertSame($this->hoy->toDateString(), $fila->fecha->toDateString());

        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'ecocadiograma_fetal', 'record_id' => $fila->id, 'operation' => 'created', 'source' => 'web',
        ]);

        // ⚠️ `eg` y `referido` van ocultos y la web no los toca: siguen vacíos.
        $this->assertNull($fila->eg);
        $this->assertNull($fila->referido);
    }

    public function test_guardar_dos_veces_no_duplica_la_fila_y_solo_escribe_lo_que_cambio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->como($user, $medico, $gineco)
            ->post($this->urlGuardar($consulta), $this->formulario(['biome_dbp' => '25']))
            ->assertSessionHas('estado');

        $this->como($user, $medico, $gineco)
            ->post($this->urlGuardar($consulta), $this->formulario(['biome_dbp' => '25', 'biome_femur' => '30']))
            ->assertSessionHas('estado');

        // Una fila por consulta: la segunda vuelta corrigió la misma.
        $this->assertSame(1, EcoCardiogramaFetalModelo::where('reg_medico', $medico->reg_medico)->where('historia', 1001)->count());

        $fila = EcoCardiogramaFetalModelo::where('reg_medico', $medico->reg_medico)->first();
        $this->assertSame('25', (string) $fila->biome_dbp);
        $this->assertSame('30', (string) $fila->biome_femur);

        // El rastro: sólo la columna que cambió la segunda vez (la otra no se reescribió).
        $this->assertSame(1, SyncChange::where('table_name', 'ecocadiograma_fetal')->where('column_name', 'biome_femur')->count());
        $this->assertSame(0, SyncChange::where('table_name', 'ecocadiograma_fetal')->where('column_name', 'biome_dbp')->count());
    }

    /**
     * Lo que el navegador manda al guardar la pantalla recién abierta: la **precarga**. El estudio queda con los
     * dos memos y ya es un documento: es exactamente lo que hace el escritorio al abrir y guardar sin tocar
     * nada.
     */
    public function test_guardar_la_precarga_crea_el_estudio_con_sus_dos_memos(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->como($user, $medico, $gineco)
            ->post($this->urlGuardar($consulta), $this->formulario([
                'texto_inicio' => EcoCardiogramaFetal::TEXTO_INICIAL,
                'anatomi_datos' => EcoCardiogramaFetal::TEXTO_ANATOMICO,
            ]))
            ->assertSessionHas('estado');

        $fila = EcoCardiogramaFetalModelo::where('reg_medico', $medico->reg_medico)->first();
        $this->assertNotNull($fila);
        $this->assertSame(EcoCardiogramaFetal::TEXTO_INICIAL, $fila->texto_inicio);
        $this->assertSame(EcoCardiogramaFetal::TEXTO_ANATOMICO, $fila->anatomi_datos);

        // Y como es un documento, la pantalla ofrece su hoja.
        $this->como($user, $medico, $gineco)->get($this->urlSeccion($consulta))
            ->assertOk()
            ->assertSee('Imprimir el informe')
            ->assertSee('documento=' . \App\Clinica\Impresion\DocumentoImpreso::ECO_CARDIOGRAMA_FETAL, false);
    }

    public function test_un_estudio_sin_datos_no_se_guarda_ni_ofrece_su_hoja(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        // Sólo la ficha: la fecha, la FUR, la gesta y la paridad no son hallazgos del estudio.
        $this->como($user, $medico, $gineco)
            ->post($this->urlGuardar($consulta), $this->formulario([
                'fur' => '2026-05-20', 'gesta' => '2', 'parto' => '2', 'aborto' => '0',
                'cesarea' => '1', 'otros' => 'Ninguno',
            ]))
            ->assertSessionHas('error');

        $this->assertSame(0, EcoCardiogramaFetalModelo::where('reg_medico', $medico->reg_medico)->count());

        // Y la pantalla no ofrece el informe de un estudio que no existe.
        $this->como($user, $medico, $gineco)->get($this->urlSeccion($consulta))
            ->assertOk()
            ->assertDontSee('Imprimir el informe');
    }

    // ------------------------------------------------------------------------------------- Permisos

    public function test_la_consulta_de_otro_medico_da_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        [$otroUser, $otroMedico, $otraGineco] = $this->medicoConAcceso();

        $this->como($otroUser, $otroMedico, $otraGineco)->get($this->urlSeccion($consulta))->assertNotFound();

        $this->como($otroUser, $otroMedico, $otraGineco)
            ->post($this->urlGuardar($consulta), $this->formulario(['biome_dbp' => '25']))
            ->assertNotFound();

        $this->assertSame(0, EcoCardiogramaFetalModelo::where('reg_medico', $otroMedico->reg_medico)->count());
    }

    public function test_la_secretaria_no_entra_a_la_seccion_ni_puede_guardar(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $secretaria = $this->secretariaConAcceso($medico);

        $this->como($secretaria, $medico, $gineco)->get($this->urlSeccion($consulta))->assertForbidden();

        $this->como($secretaria, $medico, $gineco)
            ->post($this->urlGuardar($consulta), $this->formulario(['biome_dbp' => '25']))
            ->assertForbidden();

        $this->assertSame(0, EcoCardiogramaFetalModelo::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_un_invitado_va_al_login(): void
    {
        [, $medico] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->post($this->urlGuardar($consulta), $this->formulario(['biome_dbp' => '25']))
            ->assertRedirect(route('login'));
    }

    /**
     * El rastro de una fila que ya existía se bloquea con el last-write-wins: si el escritorio escribió esa
     * columna después, la web no la pisa y lo dice. Es la misma regla del eco Doppler.
     */
    public function test_una_edicion_mas_nueva_del_escritorio_no_se_pisa(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $fila = EcoCardiogramaFetalModelo::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'consulta' => 1,
            'biome_cc' => 20,
        ]);

        DB::table('sync_changes')->insert([
            'table_name' => 'ecocadiograma_fetal', 'record_id' => $fila->id, 'column_name' => 'biome_cc',
            'value' => '22', 'operation' => 'updated', 'source' => 'escritorio', 'reg_medico' => $medico->reg_medico,
            // Más nueva que la escritura de la web, que ocurre «ahora» (el test-now).
            'occurred_at' => $this->hoy->copy()->addHours(2),
            'created_at' => $this->hoy->copy()->addHours(2),
        ]);

        $respuesta = $this->como($user, $medico, $gineco)->post($this->urlGuardar($consulta), $this->formulario([
            'biome_cc' => '21',
            'biome_dbp' => '25',
        ]));

        $respuesta->assertSessionHas('error');

        $fila->refresh();
        // La columna ajena queda como estaba; la otra sí se escribe (el last-write-wins es por columna).
        $this->assertSame('20', (string) $fila->biome_cc);
        $this->assertSame('25', (string) $fila->biome_dbp);
    }

    /** La vuelta del guardado cae en el mismo tipo: el `&tipo=fetal` no se pierde. */
    public function test_la_vuelta_del_guardado_queda_en_el_fetal(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->como($user, $medico, $gineco)
            ->post($this->urlGuardar($consulta), $this->formulario(['biome_dbp' => '25']))
            ->assertRedirect($this->urlSeccion($consulta));

        $this->como($user, $medico, $gineco)->get($this->urlSeccion($consulta))
            ->assertOk()
            ->assertSee('Ecocardiograma fetal')
            ->assertSee('Imprimir el informe')
            // Y relee lo guardado en su formulario.
            ->assertSee('value="25"', false)
            ->assertSee('documento=eco-cardiograma-fetal', false);
    }
}
