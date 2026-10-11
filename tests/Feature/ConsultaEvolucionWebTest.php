<?php

namespace Tests\Feature;

use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **La evolución de una consulta** (WEB-4.6, ítem 9 del panel del legado).
 *
 * Lo que estos casos fijan:
 *
 *  - **Dónde vive el dato**: `consultas.evolucion`, la columna que el `INSERT`/`UPDATE` de
 *    `w_consulta_principal` escribe desde su pestaña *Evolución* (`:2738`, `:2835`) y que relee con
 *    `SELECT evolucion … WHERE numhistoria = :historia AND nroconsulta = :consulta` (`:4218-4242`). Es
 *    texto **de la consulta**, así que dos consultas del mismo paciente no se mezclan.
 *  - Que la sección **no** toca `consultas.observaciones`: en la fuente esa columna es la **colposcopia**
 *    del examen ginecológico (`:2757`, `:2836`), no un campo de la evolución. Un dato que el escritorio
 *    dejó ahí **sobrevive** a un guardado de la evolución.
 *  - El **texto del legado tal cual**: los saltos de línea y el `\r\n` del escritorio, y los "vacíos"
 *    (`NULL`, `''`, el literal `none`) leídos como "sin escribir".
 *  - La **escritura**: guardado explícito, rastro **por columna** en `sync_changes` (`source = web`) y el
 *    guard last-write-wins del sync.
 *  - Las **plantillas** de `formato_print` (`tipo = 'EV'`): las de este médico, con texto, y el estado
 *    vacío cuando no hay ninguna.
 *  - El **404** de la consulta de otro consultorio, el **403** de la secretaría y el invitado al login.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class ConsultaEvolucionWebTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hoy = Carbon::parse('2026-10-07 09:00:00');
        Carbon::setTestNow($this->hoy->copy());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // --------------------------------------------------------------------------------------- Ayudas

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $rol = 'Medico'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'evolucion-test-' . uniqid();

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'evolucion-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Evolucion',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function secretariaConAcceso(Medico $medico): User
    {
        $secretaria = User::create([
            'name'                 => 'Secretaria',
            'email'                => 'evolucion-secre-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        return $secretaria;
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico'   => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id'    => null,
        ]]);
    }

    /** Una plantilla de evolución del médico (`formato_print`, `tipo = 'EV'`). */
    private function plantilla(Medico $medico, string $codigo, string $titulo, ?string $descripcion, string $tipo = 'EV'): void
    {
        DB::table('formato_print')->insert([
            'reg_medico' => $medico->reg_medico,
            'codigo'     => $codigo,
            'titulo'     => $titulo,
            'descripcion' => $descripcion,
            'tipo'       => $tipo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function paciente(Medico $medico, int $numhistoria): int
    {
        $pacienteId = DB::table('pacientes')->insertGetId([
            'nombres'     => 'Ana',
            'apellidos'   => 'Alvarez',
            'cedula'      => (string) random_int(10000000, 30000000),
            'sexo'        => 'F',
            'fnacimiento' => '1990-05-04',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        MedicoPaciente::create([
            'medico_id'   => $medico->id,
            'paciente_id' => $pacienteId,
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => (string) $numhistoria,
        ]);

        Historia::create([
            'numhistoria' => (string) $numhistoria,
            'reg_medico'  => $medico->reg_medico,
            'paciente_id' => $pacienteId,
            'medico_id'   => $medico->id,
        ]);

        return $pacienteId;
    }

    /**
     * Un paciente con historia y su consulta, con la evolución que le pase.
     *
     * @return array{0:int, 1:Consulta}
     */
    private function pacienteYConsulta(
        Medico $medico,
        int $numhistoria = 1001,
        int $nroconsulta = 1,
        ?string $evolucion = null,
        ?string $observaciones = null,
    ): array {
        $pacienteId = $this->paciente($medico, $numhistoria);

        $consulta = Consulta::create([
            'reg_medico'   => $medico->reg_medico,
            'numhistoria'  => $numhistoria,
            'nroconsulta'  => $nroconsulta,
            'fecha'        => $this->hoy->toDateString(),
            'evolucion'    => $evolucion,
            'observaciones' => $observaciones,
        ]);

        return [$pacienteId, $consulta];
    }

    private function urlSeccion(Consulta $consulta): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_EVOLUCION]);
    }

    private function urlGuardar(Consulta $consulta): string
    {
        return route('clinica.consulta.evolucion.guardar', $consulta->id);
    }

    // ------------------------------------------------------------------------------------- La lectura

    public function test_la_seccion_muestra_la_evolucion_que_escribio_el_escritorio_tal_cual(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $texto = "UC AGOSTO DEL 2007 FUR 13-10-08\r\nSE LE INDICARON EXAMENES DE LABORATORIO\r\nSE LE ENTREGO CITO: BIEN";
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1, $texto);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();
        $respuesta->assertSee('Evolución de esta consulta', false);
        $respuesta->assertSee('Guardar la evolución', false);
        $respuesta->assertSee('Escrita', false);
        // El mismo dato, sin reformatear: los saltos del escritorio se ven en el `<textarea>`.
        $respuesta->assertSee('SE LE INDICARON EXAMENES DE LABORATORIO', false);
        $respuesta->assertSee('SE LE ENTREGO CITO: BIEN', false);
    }

    public function test_una_consulta_sin_evolucion_muestra_el_estado_vacio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();
        $respuesta->assertSee('Sin escribir', false);
        $respuesta->assertSee('Cómo sigue el paciente', false);
    }

    public function test_los_vacios_del_legado_se_leen_como_sin_evolucion(): void
    {
        // Las tres formas de "no hay texto" del legado: `''`, el literal `none` del DataWindow y el `NULL`.
        // (El `''` lo convierte el middleware en `NULL` al escribir por HTTP, pero el escritorio lo deja.)
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $vacia] = $this->pacienteYConsulta($medico, 1001, 1, '');
        [, $none] = $this->pacienteYConsulta($medico, 1002, 1, 'none');

        $contexto = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        foreach ([$vacia, $none] as $consulta) {
            $respuesta = $contexto->get($this->urlSeccion($consulta));

            $respuesta->assertOk();
            $respuesta->assertSee('Sin escribir', false);
            // El literal no se muestra como si fuera un texto escrito.
            $respuesta->assertDontSee('>none<', false);
        }
    }

    public function test_dos_consultas_del_mismo_paciente_no_mezclan_su_evolucion(): void
    {
        // La evolución es de **la consulta** (`numhistoria` + `nroconsulta`): la de la 1 no aparece en la 2.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $primera] = $this->pacienteYConsulta($medico, 1001, 1, 'evolucion de la primera');
        $segunda = Consulta::create([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'nroconsulta' => 2,
            'fecha' => $this->hoy->toDateString(), 'evolucion' => 'evolucion de la segunda',
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($segunda));

        $respuesta->assertOk();
        $respuesta->assertSee('evolucion de la segunda', false);
        $respuesta->assertDontSee('evolucion de la primera', false);

        // Y la de la primera sigue intacta.
        $this->assertSame('evolucion de la primera', DB::table('consultas')->where('id', $primera->id)->value('evolucion'));
    }

    // ------------------------------------------------------------------------------------ La escritura

    public function test_guardar_escribe_la_evolucion_y_deja_el_rastro_por_columna(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'evolucion' => "Paciente estable.\nControl en 30 días.",
        ]);

        $respuesta->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'Evolución guardada'));
        $respuesta->assertRedirect($this->urlSeccion($consulta));

        $this->assertSame("Paciente estable.\nControl en 30 días.", DB::table('consultas')->where('id', $consulta->id)->value('evolucion'));

        // El rastro es **por columna** y con el valor nuevo: es lo que el sync compara para no pisar.
        $this->assertDatabaseHas('sync_changes', [
            'table_name'  => 'consultas',
            'record_id'   => $consulta->id,
            'operation'   => 'updated',
            'column_name' => 'evolucion',
            'value'       => "Paciente estable.\nControl en 30 días.",
            'source'      => 'web',
        ]);
        // Una sola fila: sólo cambió una columna.
        $this->assertSame(1, SyncChange::where('record_id', $consulta->id)->count());
    }

    public function test_guardar_deja_la_evolucion_vacia_con_null_y_una_sola_fila_de_rastro(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1, 'lo viejo');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'evolucion' => '',
        ]);

        $this->assertNull(DB::table('consultas')->where('id', $consulta->id)->value('evolucion'));
        $this->assertSame(1, SyncChange::where('record_id', $consulta->id)->where('column_name', 'evolucion')->count());
    }

    public function test_el_literal_none_del_escritorio_no_se_guarda_como_texto(): void
    {
        // Si el campo llegara con el `none` del DataWindow, el guardado deja la columna en `NULL`: no se
        // propaga la basura del legado a un dato que la web escribió.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'evolucion' => 'none',
        ]);

        $this->assertNull(DB::table('consultas')->where('id', $consulta->id)->value('evolucion'));
    }

    public function test_el_guardado_no_toca_las_otras_columnas_de_la_consulta(): void
    {
        // La evolución es una columna: lo que el escritorio escribió en las demás se queda como está. Es
        // clave para `observaciones`, que en la fuente **no** es la evolución sino la **colposcopia** del
        // examen ginecológico (`w_consulta_principal.srw:2757`, `:2836`): la sección no la toca. Y tampoco
        // se ensucia el rastro con columnas que nadie cambió.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1, 'lo viejo', 'COLPO: ectopia leve');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'evolucion' => 'lo nuevo',
        ]);

        $fila = DB::table('consultas')->where('id', $consulta->id)->first();
        $this->assertSame('lo nuevo', $fila->evolucion);
        $this->assertSame('COLPO: ectopia leve', $fila->observaciones);
        $this->assertSame(1, SyncChange::where('record_id', $consulta->id)->count());
    }

    public function test_una_edicion_mas_nueva_del_sync_no_se_pisa(): void
    {
        // El escritorio (o el teléfono) editó la evolución un minuto después: gana esa, y la pantalla lo
        // dice en vez de mostrar un "Guardado" falso.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1, 'lo viejo');

        SyncChange::create([
            'reg_medico'  => $medico->reg_medico,
            'table_name'  => 'consultas',
            'record_id'   => $consulta->id,
            'operation'   => 'updated',
            'column_name' => 'evolucion',
            'value'       => 'lo nuevo del escritorio',
            'occurred_at' => $this->hoy->copy()->addMinute(),
            // El sync del escritorio firma así sus cambios (`CambiosEscritorio`: `source = 'escritorio'`).
            'source'      => 'escritorio',
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'evolucion' => 'lo de la web',
        ]);

        $respuesta->assertSessionHas('error');
        $this->assertSame('lo viejo', DB::table('consultas')->where('id', $consulta->id)->value('evolucion'));
        // El rastro nuevo no se escribió: la fila que hay sigue siendo la del escritorio.
        $this->assertSame(1, SyncChange::where('record_id', $consulta->id)->count());
    }

    public function test_el_texto_libre_del_legado_se_escapa_en_la_pantalla(): void
    {
        // Un texto clínico con `<` o `&` es un dato, no HTML: se muestra escapado y no rompe la página.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1, 'TA < 90/60 & mareos');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();
        $respuesta->assertSee('TA &lt; 90/60 &amp; mareos', false);
        $respuesta->assertDontSee('TA < 90/60 & mareos', false);
    }

    // ------------------------------------------------------------------------------------ Las plantillas

    public function test_la_seccion_lista_las_plantillas_de_evolucion_del_medico(): void
    {
        // La lista sale de `formato_print` con `tipo = 'EV'` (el `retrieve('EV')` del `dw_84`). El texto
        // viaja en un `data-*` porque el script lo copia al campo, como el doble clic del escritorio.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->plantilla($medico, '0001', 'CONTROL POST-OPERATORIO', 'Herida sin signos de infección.');
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();
        $respuesta->assertSee('CONTROL POST-OPERATORIO', false);
        $respuesta->assertSee('data-evolucion-plantilla="Herida sin signos de infección."', false);
        $respuesta->assertDontSee('Sin plantillas de evolución', false);
    }

    public function test_una_plantilla_de_otro_tipo_o_de_otro_medico_no_se_ofrece(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        // Otro tipo del mismo médico (la enfermedad actual usa 'EA') y una plantilla del otro médico.
        $this->plantilla($medico, '0001', 'ENFERMEDAD ACTUAL', 'Texto de enfermedad actual', 'EA');
        $this->plantilla($otro, '0002', 'AJENA', 'Texto de otro médico');
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();
        $respuesta->assertDontSee('ENFERMEDAD ACTUAL', false);
        $respuesta->assertDontSee('AJENA', false);
        // Y sin ninguna plantilla de evolución, la sección lo dice.
        $respuesta->assertSee('Sin plantillas de evolución', false);
    }

    public function test_una_plantilla_sin_texto_no_es_una_plantilla(): void
    {
        // El escritorio muestra su `titulo` y al elegirla copiaría un vacío: se descarta. El caso real es
        // el literal `none` que el DataWindow deja en sus campos.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->plantilla($medico, '0001', 'SIN TEXTO', null);
        $this->plantilla($medico, '0002', 'CON NONE', 'none');
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();
        $respuesta->assertSee('Sin plantillas de evolución', false);
        $respuesta->assertDontSee('SIN TEXTO', false);
        $respuesta->assertDontSee('CON NONE', false);
    }

    // ----------------------------------------------------------------------------------------- El panel

    public function test_el_panel_marca_la_evolucion_como_disponible(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();
        $respuesta->assertSee('8 de 13 disponibles', false);
        // El enlace del panel abre esta misma sección.
        $respuesta->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => 'evolucion']), false);
        // Y el ítem ya no dice que llega después.
        $respuesta->assertDontSee('Llega con WEB-4.6', false);
    }

    // ------------------------------------------------------------------------------- El 404 y el 403

    public function test_la_consulta_de_otro_medico_da_404_al_leer_y_al_guardar(): void
    {
        [, $otro] = $this->medicoConAcceso();
        [, $consultaAjena] = $this->pacienteYConsulta($otro, 1001, 1, 'lo del otro');

        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $contexto = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        $contexto->get(route('clinica.consulta', [$consultaAjena->id, 'seccion' => 'evolucion']))->assertNotFound();
        $contexto->post(route('clinica.consulta.evolucion.guardar', $consultaAjena->id), ['evolucion' => 'lo de la web'])->assertNotFound();

        // Nada se escribió en los datos del otro médico.
        $this->assertSame('lo del otro', DB::table('consultas')->where('id', $consultaAjena->id)->value('evolucion'));
        $this->assertSame(0, SyncChange::where('record_id', $consultaAjena->id)->count());
        // Y la consulta del contexto sigue sin evolución.
        $this->assertNull(DB::table('consultas')->where('id', $consulta->id)->value('evolucion'));
    }

    public function test_la_secretaria_no_entra_a_la_consulta(): void
    {
        // La consulta es sólo del médico (PLAN-WEB.md §3.14): la secretaría recibe **403**, no un redirect
        // silencioso. Es la misma regla de la ruta del shell (trampa 19 de AGENTS.md).
        [, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico, 1001, 1, 'lo del escritorio');
        $secretaria = $this->secretariaConAcceso($medico);

        $contexto = $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria);

        $contexto->get($this->urlSeccion($consulta))->assertForbidden();
        $contexto->post($this->urlGuardar($consulta), ['evolucion' => 'lo de la secretaria'])->assertForbidden();

        $this->assertSame('lo del escritorio', DB::table('consultas')->where('id', $consulta->id)->value('evolucion'));
        $this->assertSame(0, SyncChange::where('record_id', $consulta->id)->count());
    }

    public function test_un_invitado_va_al_login(): void
    {
        [, $medico] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->get($this->urlSeccion($consulta))->assertRedirect(route('login'));
        $this->post($this->urlGuardar($consulta), ['evolucion' => 'x'])->assertRedirect(route('login'));
    }
}
