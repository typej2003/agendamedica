<?php

namespace Tests\Feature;

use App\Clinica\Consulta\EcoMamario;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\TiposDeEstudio;
use App\Models\Consulta;
use App\Models\Medico;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\UltraMama;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\PreparaRecipesParaImprimir;
use Tests\TestCase;

/**
 * **El eco mamario de la consulta** (WEB-6.4, ítem 5 del panel del legado), dentro del shell.
 *
 * Lo que estos casos fijan:
 *
 *  - **La sección**: el sexto tipo del ítem 5 se ofrece como enlace, abre con su formulario de tres bloques y su
 *    parcial existe (un tipo sin parcial tira 500: trampa 103). Su único desplegable —el médico referente— sale
 *    de la libreta de colegas con la opción vacía adelante (trampa 112).
 *  - **La clave del legado**: una fila por consulta, por **`historia` + `consulta`** —no
 *    `numhistoria`/`nroconsulta` como el eco pélvico—, con `fecha` propuesta en hoy; guardar dos veces **no
 *    duplica** la fila y `tranductor` se guarda con ese nombre (el typo del legado, regla 2 de `AGENTS.md`).
 *  - **El rastro por columna en `sync_changes`** (`source = web`) y el guard last-write-wins del sync.
 *  - **Nada de filas vacías**: un estudio sin datos no es un documento, no se guarda y no ofrece su hoja.
 *  - El **404** de la consulta de otro consultorio, el **403** de la secretaría y el invitado al login.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class ConsultaMamarioWebTest extends TestCase
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
            'tipo' => TiposDeEstudio::MAMARIO,
        ]);
    }

    private function urlGuardar(Consulta $consulta): string
    {
        return route('clinica.consulta.estudio.guardar', [$consulta->id, TiposDeEstudio::MAMARIO]);
    }

    /**
     * Lo que manda el formulario: **las 11 columnas** del mapa, vacías las que no se cargaron. Es lo que hace
     * el navegador, y por eso el caso de "sólo lo que cambió" se prueba con esto y no con un POST parcial.
     *
     * @param  array<string,string>  $valores
     * @return array<string,string>
     */
    private function formulario(array $valores = []): array
    {
        $campos = [];
        foreach (EcoMamario::columnas() as $columna) {
            $campos[$columna] = $valores[$columna] ?? '';
        }

        return $campos;
    }

    /** Un médico en la libreta de colegas del médico del contexto. */
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

    // ------------------------------------------------------------------------------------ La sección

    public function test_la_seccion_abre_con_el_formulario_del_mamario_y_sus_tres_bloques(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->como($user, $medico, $gineco)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();

        // Los seis tipos del ítem 5, con el mamario entre ellos.
        $respuesta->assertSee('Eco ginecológico')
            ->assertSee('Eco obstétrico (1.er trimestre)')
            ->assertSee('Eco obstétrico (3.er trimestre)')
            ->assertSee('Eco Doppler perinatal')
            ->assertSee('Eco mamario')
            ->assertSee('Ecocardiograma fetal');

        // Sus tres bloques, con los rótulos del DataWindow vivo.
        foreach (['El encabezado', 'Las mamas y las axilas', 'La conclusión'] as $bloque) {
            $respuesta->assertSee($bloque);
        }

        // Los once rótulos del formulario.
        foreach (['Fecha', 'Referido por', 'Transductor', 'Menarquia', 'Quirúrgicos', 'Hormonas',
            'Mama derecha', 'Axila derecha', 'Mama izquierda', 'Axila izquierda', 'Conclusión'] as $rotulo) {
            $respuesta->assertSee($rotulo);
        }

        // Y el formulario apunta a la acción de guardar del tipo que se está mirando.
        $respuesta->assertSee($this->urlGuardar($consulta), false);
        $respuesta->assertSee('Guardar el estudio');
    }

    /**
     * El desplegable del médico referente sale de la **libreta de colegas** (`doctores`) y trae la opción
     * **vacía adelante** (trampa 112): sin ella el navegador mandaría el primer médico y el estudio nacería con
     * un referido que nadie eligió.
     */
    public function test_el_desplegable_de_medicos_trae_la_libreta_y_la_opcion_vacia_adelante(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->doctor($medico, 10432, 'José', 'Pérez');
        $this->doctor($medico, 20877, 'María', 'Rojas');

        $html = $this->como($user, $medico, $gineco)->get($this->urlSeccion($consulta))->assertOk()->getContent();

        $this->assertStringContainsString('name="referido"', $html);
        $this->assertMatchesRegularExpression('/<option value=""\s+selected\s*>/', $this->selectDe($html, 'referido'));
        $this->assertStringContainsString('<option value="10432"', $html);
        $this->assertStringContainsString('José Pérez', $html);
        $this->assertStringContainsString('<option value="20877"', $html);
        $this->assertStringContainsString('María Rojas', $html);

        // El rótulo del DataWindow.
        $this->assertStringContainsString('Referido por', $html);
    }

    /** El `<select>` de una columna, recortado de la pantalla. */
    private function selectDe(string $html, string $columna): string
    {
        $desde = strpos($html, 'name="' . $columna . '"');

        return $desde === false ? '' : substr($html, $desde, 2000);
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
    }

    // ------------------------------------------------------------------------------------ El guardado

    public function test_guardar_crea_la_fila_con_la_clave_del_datawindow_y_su_rastro(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->doctor($medico);

        $respuesta = $this->como($user, $medico, $gineco)->post($this->urlGuardar($consulta), $this->formulario([
            'referido' => '10432',
            // ⚠️ La columna se llama `tranductor` (typo del legado): se guarda con ese nombre.
            'tranductor' => 'Lineal 7,5 MHz',
            'menarquia' => '12 años',
            'quirurgicos' => 'Apendicectomía',
            'hormonas' => 'Anticonceptivos orales',
            'mama_derecha' => 'Sin hallazgos.',
            'axila_derecha' => 'Sin adenopatías.',
            'mama_izquierda' => 'Nódulo de 8 mm.',
            'axila_izquierda' => 'Sin adenopatías.',
            'conclusion' => 'Nódulo simple en la mama izquierda.',
        ]));

        $respuesta->assertRedirect($this->urlSeccion($consulta));
        $respuesta->assertSessionHas('estado', 'Eco mamario guardado.');

        $fila = UltraMama::where('reg_medico', $medico->reg_medico)->where('historia', 1001)->first();

        $this->assertNotNull($fila);
        // La clave del DataWindow: `historia` + `consulta` (no `numhistoria`/`nroconsulta`), acotada al médico.
        $this->assertSame(1, (int) $fila->consulta);
        $this->assertSame($medico->reg_medico, $fila->reg_medico);
        $this->assertSame(10432, (int) $fila->referido);
        $this->assertSame('Lineal 7,5 MHz', $fila->tranductor);
        $this->assertSame('12 años', $fila->menarquia);
        $this->assertSame('Apendicectomía', $fila->quirurgicos);
        $this->assertSame('Anticonceptivos orales', $fila->hormonas);
        $this->assertSame('Nódulo de 8 mm.', $fila->mama_izquierda);
        $this->assertSame('Nódulo simple en la mama izquierda.', $fila->conclusion);

        // `para` está oculto en el DataWindow: la web no lo toca (queda nulo).
        $this->assertNull($fila->para);

        // La fecha la propone el servidor con hoy: el escritorio la completa al insertar la fila.
        $this->assertSame($this->hoy->toDateString(), $fila->fecha->toDateString());

        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'ultra_mama', 'record_id' => $fila->id, 'operation' => 'created', 'source' => 'web',
        ]);
    }

    public function test_guardar_dos_veces_no_duplica_la_fila_y_solo_escribe_lo_que_cambio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->como($user, $medico, $gineco)
            ->post($this->urlGuardar($consulta), $this->formulario(['tranductor' => 'Lineal 7,5 MHz']))
            ->assertSessionHas('estado');

        $this->como($user, $medico, $gineco)
            ->post($this->urlGuardar($consulta), $this->formulario([
                'tranductor' => 'Lineal 7,5 MHz',
                'conclusion' => 'Sin hallazgos.',
            ]))
            ->assertSessionHas('estado', 'Eco mamario guardado.');

        // Una fila por consulta: la segunda vuelta corrigió la misma.
        $this->assertSame(1, UltraMama::where('reg_medico', $medico->reg_medico)->where('historia', 1001)->count());

        $fila = UltraMama::where('reg_medico', $medico->reg_medico)->first();
        $this->assertSame('Lineal 7,5 MHz', $fila->tranductor);
        $this->assertSame('Sin hallazgos.', $fila->conclusion);

        // El rastro: sólo la columna que cambió la segunda vez (la otra no se reescribió).
        $this->assertSame(1, SyncChange::where('table_name', 'ultra_mama')->where('column_name', 'conclusion')->count());
        $this->assertSame(0, SyncChange::where('table_name', 'ultra_mama')->where('column_name', 'tranductor')->count());
    }

    public function test_un_estudio_sin_datos_no_se_guarda_ni_ofrece_su_hoja(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        // Sólo la fecha (que la completa el servidor): no es un hallazgo del estudio.
        $this->como($user, $medico, $gineco)
            ->post($this->urlGuardar($consulta), $this->formulario(['fecha' => '2026-10-07']))
            ->assertSessionHas('error');

        $this->assertSame(0, UltraMama::where('reg_medico', $medico->reg_medico)->count());

        // Y la pantalla no ofrece el informe de un estudio que no existe.
        $this->como($user, $medico, $gineco)->get($this->urlSeccion($consulta))
            ->assertOk()
            ->assertDontSee('Imprimir el informe');
    }

    public function test_un_texto_mas_largo_que_su_tope_no_se_guarda(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        // El tope de `tranductor` es 100 (su `edit.limit` del DataWindow).
        $this->como($user, $medico, $gineco)
            ->post($this->urlGuardar($consulta), $this->formulario(['tranductor' => str_repeat('x', 101)]))
            ->assertSessionHasErrors('tranductor');

        $this->assertSame(0, UltraMama::where('reg_medico', $medico->reg_medico)->count());
    }

    // ------------------------------------------------------------------------------------- Permisos

    public function test_la_consulta_de_otro_medico_da_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        [$otroUser, $otroMedico, $otraGineco] = $this->medicoConAcceso();

        $this->como($otroUser, $otroMedico, $otraGineco)->get($this->urlSeccion($consulta))->assertNotFound();

        $this->como($otroUser, $otroMedico, $otraGineco)
            ->post($this->urlGuardar($consulta), $this->formulario(['conclusion' => 'Ajeno']))
            ->assertNotFound();

        $this->assertSame(0, UltraMama::where('reg_medico', $otroMedico->reg_medico)->count());
    }

    public function test_la_secretaria_no_entra_a_la_seccion_ni_puede_guardar(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $secretaria = $this->secretariaConAcceso($medico);

        $this->como($secretaria, $medico, $gineco)->get($this->urlSeccion($consulta))->assertForbidden();

        $this->como($secretaria, $medico, $gineco)
            ->post($this->urlGuardar($consulta), $this->formulario(['conclusion' => 'Ajeno']))
            ->assertForbidden();

        $this->assertSame(0, UltraMama::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_un_invitado_va_al_login(): void
    {
        [, $medico] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->post($this->urlGuardar($consulta), $this->formulario(['conclusion' => 'Ajeno']))
            ->assertRedirect(route('login'));
    }

    /**
     * El rastro de una fila que ya existía se bloquea con el last-write-wins: si el escritorio escribió esa
     * columna después, la web no la pisa y lo dice. Es la misma regla de los otros ecos.
     */
    public function test_una_edicion_mas_nueva_del_escritorio_no_se_pisa(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $fila = UltraMama::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'consulta' => 1,
            'conclusion' => 'Lo del escritorio',
        ]);

        DB::table('sync_changes')->insert([
            'table_name' => 'ultra_mama', 'record_id' => $fila->id, 'column_name' => 'conclusion',
            'value' => 'Lo más nuevo del escritorio', 'operation' => 'updated', 'source' => 'escritorio',
            'reg_medico' => $medico->reg_medico,
            // Más nueva que la escritura de la web, que ocurre «ahora» (el test-now).
            'occurred_at' => $this->hoy->copy()->addHours(2),
            'created_at' => $this->hoy->copy()->addHours(2),
        ]);

        $respuesta = $this->como($user, $medico, $gineco)->post($this->urlGuardar($consulta), $this->formulario([
            'conclusion' => 'Lo de la web',
            'tranductor' => 'Lineal 7,5 MHz',
        ]));

        $respuesta->assertSessionHas('error');

        $fila->refresh();
        // La columna ajena queda como estaba; la otra sí se escribe (el last-write-wins es por columna).
        $this->assertSame('Lo del escritorio', $fila->conclusion);
        $this->assertSame('Lineal 7,5 MHz', $fila->tranductor);
    }

    /** La vuelta del guardado cae en el mismo tipo: el `&tipo=mamario` no se pierde. */
    public function test_la_vuelta_del_guardado_queda_en_el_mamario(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->como($user, $medico, $gineco)
            ->post($this->urlGuardar($consulta), $this->formulario(['conclusion' => 'Normal.']))
            ->assertRedirect($this->urlSeccion($consulta));

        $this->como($user, $medico, $gineco)->get($this->urlSeccion($consulta))
            ->assertOk()
            ->assertSee('Eco mamario')
            ->assertSee('Imprimir el informe')
            // Y relee lo guardado en su formulario.
            ->assertSee('Normal.')
            ->assertSee('documento=eco-mamario', false);
    }

    /**
     * El informe en texto libre del escritorio (su modo «Plantillas», `formato_print` tipo `UM`) se muestra y
     * no se edita: no está en las columnas del formulario.
     *
     * ⚠️ Pero **no** es un documento: la hoja del mamario no tiene párrafos de texto libre (su modo «Plantillas»
     * es otro DataWindow de impresión, que la web no imprime), así que una fila que sólo trae ese texto se
     * muestra sin ofrecer un informe vacío. Es la diferencia con el eco pélvico y el Doppler.
     */
    public function test_el_informe_en_texto_libre_del_escritorio_se_muestra_y_no_se_edita(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        UltraMama::create([
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
            // Y como la hoja del mamario no imprime ese modo, no ofrece un informe que saldría vacío.
            ->assertDontSee('Imprimir el informe')
            ->assertDontSee('documento=eco-mamario', false);
    }

    /** Un estudio con datos cargados **sí** ofrece la hoja, además del texto libre del escritorio. */
    public function test_un_estudio_con_datos_ofrece_la_hoja(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        UltraMama::create([
            'reg_medico' => $medico->reg_medico, 'historia' => 1001, 'consulta' => 1,
            'conclusion' => 'Nódulo simple.',
            'puro_texto' => 'INFORME DEL ESCRITORIO',
        ]);

        $this->como($user, $medico, $gineco)->get($this->urlSeccion($consulta))
            ->assertOk()
            ->assertSee('Imprimir el informe')
            ->assertSee('documento=eco-mamario', false);
    }
}
