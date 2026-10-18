<?php

namespace Tests\Feature;

use App\Clinica\Consulta\EcoDoppler;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\TiposDeEstudio;
use App\Models\Consulta;
use App\Models\EcoDoppler as EcoDopplerModelo;
use App\Models\Medico;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **El eco Doppler perinatal de la consulta** (WEB-6.3, ítem 5 del panel del legado), dentro del shell.
 *
 * Lo que estos casos fijan:
 *
 *  - **La sección**: el cuarto tipo del ítem 5 se ofrece como enlace, abre con su formulario de cuatro bloques
 *    —la ficha, la grilla de cuatro vasos, los tres desplegables y las áreas de texto— y su parcial existe (un
 *    tipo sin parcial tira 500: trampa 103).
 *  - **La clave del legado**: una fila por consulta, por **`historia` + `consulta`** —no
 *    `numhistoria`/`nroconsulta` como el eco pélvico—, con `fecha` propuesta en hoy; guardar dos veces **no
 *    duplica** la fila.
 *  - **El rastro por columna en `sync_changes`** (`source = web`) y el guard last-write-wins del sync.
 *  - **Nada de filas vacías**: un estudio que sólo trae su ficha (FUR, gesta y `para`) no es un documento, no
 *    se guarda y no ofrece su hoja.
 *  - **El desplegable con su opción vacía adelante** (trampa 112): el formulario recién abierto no inventa
 *    hallazgos.
 *  - El **404** de la consulta de otro consultorio, el **403** de la secretaría y el invitado al login.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class ConsultaDopplerWebTest extends TestCase
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
            'tipo' => TiposDeEstudio::DOPPLER,
        ]);
    }

    private function urlGuardar(Consulta $consulta): string
    {
        return route('clinica.consulta.estudio.guardar', [$consulta->id, TiposDeEstudio::DOPPLER]);
    }

    /**
     * Lo que manda el formulario: **las 26 columnas** del mapa, vacías las que no se cargaron. Es lo que hace
     * el navegador, y por eso el caso de "sólo lo que cambió" se prueba con esto y no con un POST parcial.
     *
     * @param  array<string,string>  $valores
     * @return array<string,string>
     */
    private function formulario(array $valores = []): array
    {
        $campos = [];
        foreach (EcoDoppler::columnas() as $columna) {
            $campos[$columna] = $valores[$columna] ?? '';
        }

        return $campos;
    }

    // ------------------------------------------------------------------------------------ La sección

    public function test_la_seccion_abre_con_el_formulario_del_doppler_y_sus_cuatro_bloques(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->como($user, $medico, $gineco)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();

        // Los cuatro tipos del ítem 5, con el Doppler entre ellos.
        $respuesta->assertSee('Eco ginecológico')
            ->assertSee('Eco obstétrico (1.er trimestre)')
            ->assertSee('Eco obstétrico (3.er trimestre)')
            ->assertSee('Eco Doppler perinatal');

        // Sus cuatro bloques, con los rótulos del DataWindow vivo.
        foreach (['Ficha', 'Eco Doppler Obstétrico en 4 Vasos', 'El flujo venoso y la aorta',
            'Las observaciones y la conclusión'] as $bloque) {
            $respuesta->assertSee($bloque);
        }

        // Los ejes de la grilla y las arterias de sus filas.
        foreach (['Índice de Pulsatibilidad', 'Índice de Resistencia', 'Relación S / D',
            'Pico de Velocidad Sistólica', 'Arteria Uterina Der.', 'Arteria Uterina Izq.',
            'Arteria Umbilical', 'Arteria Cerebral Media'] as $rotulo) {
            $respuesta->assertSee($rotulo);
        }

        // Los tres desplegables y sus rótulos (la señal venoso umbilical lleva la «Pulsatilidad» del
        // DataWindow), y las dos áreas del final con los rótulos de la pantalla. El rótulo del ductus se
        // corrigió de la errata del escritorio («Ductos Venosos», 2026-10-17).
        $respuesta->assertSee('Ductus venoso')->assertSee('Señal Venoso Umbilical (Pulsatilidad)')
            ->assertSee('Relación Aorta-Cava')->assertSee('Observaciones')->assertSee('Conclusiones');
        $respuesta->assertDontSee('Ductos Venosos');
        $respuesta->assertSee('Trifásico con Reversa')->assertSee('Anormal');

        // Y el formulario apunta a la acción de guardar del tipo que se está mirando.
        $respuesta->assertSee($this->urlGuardar($consulta), false);
        $respuesta->assertSee('Guardar el estudio');

        // Las plantillas de texto del escritorio (revisión de Alexander, 2026-10-17): el tipo `ED` de la ventana
        // del Doppler y su memo grande (`texto`), más el enlace al catálogo filtrado por ese tipo.
        $respuesta->assertSee('Administrar plantillas')
            ->assertSee('data-tipo="ED"', false)
            ->assertSee('data-destino="#texto"', false)
            ->assertSee(route('clinica.catalogos.plantillas-texto', ['filtro' => 'ED']), false);
    }

    /**
     * La opción **vacía adelante** del desplegable (trampa 112): un `<select>` sin ninguna `selected` manda su
     * primera opción, así que el formulario recién abierto guardaba «Sí» o «Normal» en casillas que el médico
     * nunca tocó. Acá se fija que la opción vacía está y que las del escritorio también.
     */
    public function test_los_desplegables_traen_la_opcion_vacia_adelante(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $html = $this->como($user, $medico, $gineco)->get($this->urlSeccion($consulta))->assertOk()->getContent();

        // El formulario nace con los tres desplegables y la gesta en blanco.
        foreach (['ductos_venosos', 'ven_umbilical', 'relac_aorta', 'gesta'] as $columna) {
            $this->assertStringContainsString('name="' . $columna . '"', $html, "El desplegable {$columna}.");
            $this->assertMatchesRegularExpression(
                '/<option value=""\s+selected\s*>/',
                $this->selectDe($html, $columna),
                "La opción vacía de {$columna}.",
            );
        }

        // Y sus códigos del legado (la señal venoso umbilical guarda N para Sí y A para No).
        $umbilical = $this->selectDe($html, 'ven_umbilical');
        $this->assertMatchesRegularExpression('/<option value="N"\s*>Sí<\/option>/', $umbilical);
        $this->assertMatchesRegularExpression('/<option value="A"\s*>No<\/option>/', $umbilical);
    }

    /** El `<select>` de una columna, recortado de la pantalla. */
    private function selectDe(string $html, string $columna): string
    {
        $desde = strpos($html, 'name="' . $columna . '"');

        return $desde === false ? '' : substr($html, $desde, 1200);
    }

    public function test_el_panel_marca_el_estudio_como_disponible(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->como($user, $medico, $gineco)->get(route('clinica.consulta', $consulta->id));

        $respuesta->assertOk();
        // El ítem 5 ya estaba disponible desde WEB-6.1: el tipo nuevo no cambia el conteo del panel.
        $respuesta->assertSee('Pre-factura');
        $respuesta->assertSee('Estudio ecográfico');
        $respuesta->assertSee(
            route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_ESTUDIO]),
            false,
        );
    }

    // ------------------------------------------------------------------------------------ El guardado

    public function test_guardar_crea_la_fila_con_la_clave_del_datawindow_y_su_rastro(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->como($user, $medico, $gineco)->post($this->urlGuardar($consulta), $this->formulario([
            'fur' => '2026-05-20',
            'gesta' => '2',
            'para' => '1 cesárea',
            'arteria_uraina_menor' => '0.85',
            'arteria_uraina_mayor' => '0.62',
            'relacion_ud' => '2.4',
            'pico_ud' => '45',
            'ductos_venosos' => 'S',
            'ven_umbilical' => 'N',
            'relac_aorta' => 'N',
            'texto' => 'Estudio dentro de límites normales.',
            'sugerencias' => 'Control en 4 semanas.',
            'conclusion' => 'Doppler normal.',
        ]));

        $respuesta->assertRedirect($this->urlSeccion($consulta));
        $respuesta->assertSessionHas('estado', 'Eco Doppler guardado.');

        $fila = EcoDopplerModelo::where('reg_medico', $medico->reg_medico)->where('historia', 1001)->first();

        $this->assertNotNull($fila);
        // La clave del DataWindow: `historia` + `consulta` (no `numhistoria`/`nroconsulta`), acotada al médico.
        $this->assertSame(1, (int) $fila->consulta);
        $this->assertSame($medico->reg_medico, $fila->reg_medico);
        $this->assertSame('0.85', $fila->arteria_uraina_menor);
        $this->assertSame('S', $fila->ductos_venosos);
        $this->assertSame('N', $fila->ven_umbilical);
        $this->assertSame('2', (string) $fila->gesta);
        $this->assertSame('1 cesárea', $fila->para);

        // La fecha la propone el servidor con hoy: el escritorio no la puede cargar desde su pantalla.
        $this->assertSame($this->hoy->toDateString(), $fila->fecha->toDateString());

        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'eco_doppler', 'record_id' => $fila->id, 'operation' => 'created', 'source' => 'web',
        ]);
    }

    public function test_guardar_dos_veces_no_duplica_la_fila_y_solo_escribe_lo_que_cambio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->como($user, $medico, $gineco)
            ->post($this->urlGuardar($consulta), $this->formulario(['arteria_uraina_menor' => '0.85']))
            ->assertSessionHas('estado');

        $this->como($user, $medico, $gineco)
            ->post($this->urlGuardar($consulta), $this->formulario([
                'arteria_uraina_menor' => '0.85',
                'arteria_cerebral_menor' => '1.1',
            ]))
            ->assertSessionHas('estado');

        // Una fila por consulta: la segunda vuelta corrigió la misma.
        $this->assertSame(1, EcoDopplerModelo::where('reg_medico', $medico->reg_medico)->where('historia', 1001)->count());

        $fila = EcoDopplerModelo::where('reg_medico', $medico->reg_medico)->first();
        $this->assertSame('0.85', $fila->arteria_uraina_menor);
        $this->assertSame('1.1', $fila->arteria_cerebral_menor);

        // El rastro: sólo la columna que cambió la segunda vez (la otra no se reescribió).
        $this->assertSame(1, SyncChange::where('table_name', 'eco_doppler')->where('column_name', 'arteria_cerebral_menor')->count());
        $this->assertSame(0, SyncChange::where('table_name', 'eco_doppler')->where('column_name', 'arteria_uraina_menor')->count());
    }

    public function test_un_estudio_sin_datos_no_se_guarda_ni_ofrece_su_hoja(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        // Sólo la ficha: la FUR, la gesta y el `para` no son hallazgos del estudio.
        $this->como($user, $medico, $gineco)
            ->post($this->urlGuardar($consulta), $this->formulario([
                'fur' => '2026-05-20', 'gesta' => '2', 'para' => '1 cesárea',
            ]))
            ->assertSessionHas('error');

        $this->assertSame(0, EcoDopplerModelo::where('reg_medico', $medico->reg_medico)->count());

        // O-16/O-20: el **botón** sigue en la pantalla —imprime guardando antes—, pero no hay hoja que imprimir hasta
        // que el estudio tenga datos (y pedirla lo dice en vez de dar 404).
        $this->como($user, $medico, $gineco)->get($this->urlSeccion($consulta))
            ->assertOk()
            ->assertSee('name="imprimir" value="eco-doppler"', false);
    }

    // ------------------------------------------------------------------------------------- Permisos

    public function test_la_consulta_de_otro_medico_da_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        [$otroUser, $otroMedico, $otraGineco] = $this->medicoConAcceso();

        $this->como($otroUser, $otroMedico, $otraGineco)->get($this->urlSeccion($consulta))->assertNotFound();

        $this->como($otroUser, $otroMedico, $otraGineco)
            ->post($this->urlGuardar($consulta), $this->formulario(['pico_u' => '40']))
            ->assertNotFound();

        $this->assertSame(0, EcoDopplerModelo::where('reg_medico', $otroMedico->reg_medico)->count());
    }

    public function test_la_secretaria_no_entra_a_la_seccion_ni_puede_guardar(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $secretaria = $this->secretariaConAcceso($medico);

        $this->como($secretaria, $medico, $gineco)->get($this->urlSeccion($consulta))->assertForbidden();

        $this->como($secretaria, $medico, $gineco)
            ->post($this->urlGuardar($consulta), $this->formulario(['pico_u' => '40']))
            ->assertForbidden();

        $this->assertSame(0, EcoDopplerModelo::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_un_invitado_va_al_login(): void
    {
        [, $medico] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->post($this->urlGuardar($consulta), $this->formulario(['pico_u' => '40']))
            ->assertRedirect(route('login'));
    }

    /**
     * El rastro de una fila que ya existía se bloquea con el last-write-wins: si el escritorio escribió esa
     * columna después, la web no la pisa y lo dice. Es la misma regla del eco obstétrico.
     */
    public function test_una_edicion_mas_nueva_del_escritorio_no_se_pisa(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $fila = EcoDopplerModelo::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'consulta' => 1,
            'relacion_u' => '2.0',
        ]);

        DB::table('sync_changes')->insert([
            'table_name' => 'eco_doppler', 'record_id' => $fila->id, 'column_name' => 'relacion_u',
            'value' => '3.5', 'operation' => 'updated', 'source' => 'escritorio', 'reg_medico' => $medico->reg_medico,
            // Más nueva que la escritura de la web, que ocurre «ahora» (el test-now).
            'occurred_at' => $this->hoy->copy()->addHours(2),
            'created_at' => $this->hoy->copy()->addHours(2),
        ]);

        $respuesta = $this->como($user, $medico, $gineco)->post($this->urlGuardar($consulta), $this->formulario([
            'relacion_u' => '2.1',
            'pico_u' => '38',
        ]));

        $respuesta->assertSessionHas('error');

        $fila->refresh();
        // La columna ajena queda como estaba; la otra sí se escribe (el last-write-wins es por columna).
        $this->assertSame('2.0', $fila->relacion_u);
        $this->assertSame('38', $fila->pico_u);
    }

    /** La vuelta del guardado cae en el mismo tipo: el `&tipo=doppler` no se pierde. */
    public function test_la_vuelta_del_guardado_queda_en_el_doppler(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->como($user, $medico, $gineco)
            ->post($this->urlGuardar($consulta), $this->formulario(['pico_cm' => '45']))
            ->assertRedirect($this->urlSeccion($consulta));

        $this->como($user, $medico, $gineco)->get($this->urlSeccion($consulta))
            ->assertOk()
            ->assertSee('Eco Doppler perinatal')
            ->assertSee('Imprimir el informe')
            // Y relee lo guardado en su formulario.
            ->assertSee('value="45"', false)
            ->assertSee('documento=eco-doppler', false);
    }

    /**
     * El informe en texto libre del escritorio (su modo «Plantillas») se muestra y no se edita: no está en las
     * columnas del formulario.
     */
    public function test_el_informe_en_texto_libre_del_escritorio_se_muestra_y_no_se_edita(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        EcoDopplerModelo::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'consulta' => 1,
            'puro_texto' => "INFORME DEL ESCRITORIO\nSegunda línea.",
        ]);

        $this->como($user, $medico, $gineco)->get($this->urlSeccion($consulta))
            ->assertOk()
            ->assertSee('Informe en texto libre del escritorio')
            ->assertSee('INFORME DEL ESCRITORIO')
            ->assertSee('Segunda línea.')
            // El campo del escritorio no se pinta como un control del formulario.
            ->assertDontSee('name="puro_texto"', false)
            // Y como es lo que se imprime, la pantalla sí ofrece su hoja.
            ->assertSee('Imprimir el informe')
            ->assertSee('documento=eco-doppler', false);
    }
}
