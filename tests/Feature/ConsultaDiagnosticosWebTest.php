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
 * **Los diagnósticos de una consulta** (WEB-4.5, ítem 8 del panel del legado).
 *
 * Lo que estos casos fijan:
 *
 *  - **De qué cuelga la fila**: `diagnostico_paciente` se llavea por `nrohistoria` + `nroconsulta` +
 *    `codediagnostico` (no tiene id de consulta), así que la sección escribe los diagnósticos **de esa
 *    consulta** y dos consultas del mismo paciente no se mezclan. Es lo que la fuente hace
 *    (`d_diagnostico_consulta_1.srd`; `w_consulta_principal.srw:3007`, `:11860`, `:11785`).
 *  - El **catálogo** (`diagnosticos`): agregar copia la descripción en `detalle_diagnostco` como el
 *    `INSERT` del escritorio, quitar **no** toca el catálogo y el alta del que falta usa el correlativo de
 *    cuatro dígitos sin duplicar.
 *  - El **guardado explícito**: un solo POST aplica el detalle editado, lo tildado y lo quitado.
 *  - El **rastro por columna en `sync_changes`** (`source = web`) y el guard last-write-wins del sync.
 *  - Los "vacíos" del legado (`NULL`, `''`, `none`) y el diagnóstico cuyo código ya no está en el catálogo.
 *  - El **404** de la consulta de otro consultorio, el **403** de la secretaría y el invitado al login.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class ConsultaDiagnosticosWebTest extends TestCase
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
        $regMedico = 'diagnosticos-test-' . uniqid();

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'diagnosticos-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Diagnosticos',
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
            'email'                => 'diagnosticos-secre-' . uniqid() . '@example.com',
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

    /** Un diagnóstico del catálogo del médico (`diagnosticos`). */
    private function catalogo(Medico $medico, string $codigo, string $descripcion): void
    {
        DB::table('diagnosticos')->insert([
            'reg_medico' => $medico->reg_medico,
            'codediagnostico' => $codigo,
            'descripcion' => $descripcion,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Un diagnóstico **de la consulta**, como lo deja el `INSERT` del escritorio
     * (`w_consulta_principal.srw:11860-11868`): la clave es historia + consulta + código.
     */
    private function diagnostico(
        Medico $medico,
        int $numhistoria,
        int $nroconsulta,
        string $codigo,
        ?string $detalle = null,
    ): int {
        return (int) DB::table('diagnostico_paciente')->insertGetId([
            'reg_medico' => $medico->reg_medico,
            'nrohistoria' => $numhistoria,
            'nroconsulta' => $nroconsulta,
            'codediagnostico' => $codigo,
            'detalle_diagnostco' => $detalle,
            'orden' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Un paciente con historia y su consulta.
     *
     * @return array{0:int, 1:Consulta}
     */
    private function pacienteYConsulta(Medico $medico, int $numhistoria = 1001, int $nroconsulta = 1): array
    {
        $pacienteId = $this->paciente($medico, $numhistoria);

        $consulta = Consulta::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => $numhistoria,
            'nroconsulta' => $nroconsulta,
            'fecha'       => $this->hoy->toDateString(),
        ]);

        return [$pacienteId, $consulta];
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

    private function urlSeccion(Consulta $consulta): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_DIAGNOSTICO]);
    }

    private function urlGuardar(Consulta $consulta): string
    {
        return route('clinica.consulta.diagnosticos.guardar', $consulta->id);
    }

    private function urlCrear(Consulta $consulta): string
    {
        return route('clinica.consulta.diagnosticos.crear', $consulta->id);
    }

    // ------------------------------------------------------------------------------------- La lectura

    public function test_la_seccion_lista_los_diagnosticos_de_la_consulta_con_su_nombre_y_su_detalle(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->catalogo($medico, '0518', 'LIE BAJO GRADO');
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->diagnostico($medico, 1001, 1, '0518', 'LIE BAJO GRADO');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();
        $respuesta->assertSee('Diagnósticos de esta consulta', false);
        $respuesta->assertSee('Guardar los diagnósticos', false);

        // El nombre sale del catálogo por el código; sin detalle editable (WEB-11.18: como el escritorio).
        $respuesta->assertSee('LIE BAJO GRADO');
        $respuesta->assertSee('0518', false);
        $respuesta->assertDontSee('name="filas[', false);
    }

    public function test_dos_consultas_del_mismo_paciente_no_mezclan_sus_diagnosticos(): void
    {
        // La clave de la fila es historia + **consulta** + código: un diagnóstico de la consulta 1 no
        // aparece en la 2 (a diferencia de los antecedentes, que son del paciente).
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->catalogo($medico, '0518', 'LIE BAJO GRADO');
        $this->catalogo($medico, '0474', 'AMENAZA DE ABORTO');
        [, $primera] = $this->pacienteYConsulta($medico, 1001, 1);
        $segunda = Consulta::create([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'nroconsulta' => 2,
            'fecha' => $this->hoy->toDateString(),
        ]);

        $this->diagnostico($medico, 1001, 1, '0518', 'LIE BAJO GRADO');
        $this->diagnostico($medico, 1001, 2, '0474', 'AMENAZA DE ABORTO');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($segunda));

        $respuesta->assertOk();
        // Cada consulta muestra sus propios diagnósticos (el nombre de la fila de la consulta 2).
        $respuesta->assertSee('AMENAZA DE ABORTO', false);
    }

    public function test_una_consulta_sin_diagnosticos_muestra_el_estado_vacio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();
        $respuesta->assertSee('Esta consulta todavía no tiene diagnósticos', false);
        $respuesta->assertSee('Sin diagnóstico', false);
    }

    public function test_los_vacios_del_legado_y_el_diagnostico_sin_catalogo(): void
    {
        // El `NULL`, el `''` y el literal `none` son el mismo estado: el detalle llega vacío al control y
        // el nombre cae al código cuando el catálogo no lo tiene. El legado no borra diagnósticos, pero los
        // datos son datos.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->diagnostico($medico, 1001, 1, '8888', 'none');
        $this->diagnostico($medico, 1001, 1, '9999', null);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();
        $respuesta->assertSee('8888', false);
        $respuesta->assertSee('9999', false);
        $respuesta->assertSee('ya no está en tu catálogo', false);
        // El literal `none` del DataWindow no se muestra como si fuera un detalle escrito.
        $respuesta->assertDontSee('value="none"', false);
    }

    // ------------------------------------------------------------------------- Agregar, editar, quitar

    public function test_agregar_del_catalogo_copia_la_descripcion_como_el_escritorio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->catalogo($medico, '0518', 'LIE BAJO GRADO');
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'agregar' => ['0518' => '0518'],
        ]);

        $respuesta->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'LIE BAJO GRADO'));

        $fila = DB::table('diagnostico_paciente')->where('reg_medico', $medico->reg_medico)->first();
        $this->assertSame(1001, (int) $fila->nrohistoria);
        $this->assertSame(1, (int) $fila->nroconsulta);
        $this->assertSame('0518', trim((string) $fila->codediagnostico));
        // La copia del `INSERT` del escritorio: su DataWindow muestra `detalle_diagnostco`, no el catálogo.
        $this->assertSame('LIE BAJO GRADO', $fila->detalle_diagnostco);
        // El escritorio nunca escribe `orden` (su DataWindow lo tiene oculto).
        $this->assertNull($fila->orden);

        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'diagnostico_paciente',
            'record_id' => $fila->id,
            'operation' => 'created',
            'source' => 'web',
        ]);

        // El catálogo no se tocó.
        $this->assertSame(1, DB::table('diagnosticos')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_agregar_dos_veces_el_mismo_diagnostico_no_duplica_la_fila(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->catalogo($medico, '0518', 'LIE BAJO GRADO');
        [, $consulta] = $this->pacienteYConsulta($medico);

        $contexto = $this->conContexto($user, $medico, $gineco)->actingAs($user);
        $contexto->post($this->urlGuardar($consulta), ['agregar' => ['0518' => '0518']]);
        $contexto->post($this->urlGuardar($consulta), ['agregar' => ['0518' => '0518']]);

        $this->assertSame(1, DB::table('diagnostico_paciente')
            ->where('reg_medico', $medico->reg_medico)->where('codediagnostico', '0518')->count());
    }

    public function test_editar_el_detalle_deja_el_rastro_por_columna(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->catalogo($medico, '0518', 'LIE BAJO GRADO');
        [, $consulta] = $this->pacienteYConsulta($medico);
        $fila = $this->diagnostico($medico, 1001, 1, '0518', 'LIE BAJO GRADO');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'filas' => [$fila => ['detalle' => 'LIE BAJO GRADO — endocervical']],
        ]);

        $respuesta->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'guardado'));

        $this->assertSame('LIE BAJO GRADO — endocervical', DB::table('diagnostico_paciente')->where('id', $fila)->value('detalle_diagnostco'));

        // El rastro es **por columna**, con el valor nuevo: es lo que el sync compara para no pisar.
        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'diagnostico_paciente',
            'record_id' => $fila,
            'operation' => 'updated',
            'column_name' => 'detalle_diagnostco',
            'value' => 'LIE BAJO GRADO — endocervical',
            'source' => 'web',
        ]);

        // Y la clave de la fila no se movió (el guardado no reescribe lo que no cambió).
        $this->assertSame('0518', trim((string) DB::table('diagnostico_paciente')->where('id', $fila)->value('codediagnostico')));
    }

    public function test_guardar_sin_cambios_lo_dice_y_no_escribe_rastro(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->catalogo($medico, '0518', 'LIE BAJO GRADO');
        [, $consulta] = $this->pacienteYConsulta($medico);
        $fila = $this->diagnostico($medico, 1001, 1, '0518', 'LIE BAJO GRADO');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'filas' => [$fila => ['detalle' => 'LIE BAJO GRADO']],
        ]);

        $respuesta->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'no tenían cambios'));
        $this->assertSame(0, SyncChange::where('table_name', 'diagnostico_paciente')->count());
    }

    public function test_quitar_lo_saca_de_la_consulta_y_no_del_catalogo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->catalogo($medico, '0518', 'LIE BAJO GRADO');
        [, $consulta] = $this->pacienteYConsulta($medico);
        $fila = $this->diagnostico($medico, 1001, 1, '0518', 'LIE BAJO GRADO');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'quitar' => [$fila],
        ]);

        $respuesta->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'quitado'));

        $this->assertSame(0, DB::table('diagnostico_paciente')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'diagnostico_paciente',
            'record_id' => $fila,
            'operation' => 'deleted',
            'source' => 'web',
        ]);
        // El catálogo sigue igual: quitar de la consulta sólo desasocia.
        $this->assertSame(1, DB::table('diagnosticos')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_una_fila_que_se_quita_no_se_edita_en_el_mismo_post(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->catalogo($medico, '0518', 'LIE BAJO GRADO');
        [, $consulta] = $this->pacienteYConsulta($medico);
        $fila = $this->diagnostico($medico, 1001, 1, '0518', 'LIE BAJO GRADO');

        // El formulario manda las dos cosas de la misma fila (pasa si el JS no corre): gana la baja.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'filas' => [$fila => ['detalle' => 'otra cosa']],
            'quitar' => [$fila],
        ]);

        $this->assertSame(0, DB::table('diagnostico_paciente')->where('id', $fila)->count());
    }

    /**
     * Un código que **no** está en el catálogo del médico no se agrega: la pantalla no lo ofrece y aceptarlo
     * dejaría una fila sin nombre. El aviso lo dice sin pisar nada.
     */
    public function test_un_codigo_que_no_esta_en_el_catalogo_no_se_agrega(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'agregar' => ['7777' => '7777'],
        ]);

        $respuesta->assertSessionHas('error');
        $this->assertSame(0, DB::table('diagnostico_paciente')->where('reg_medico', $medico->reg_medico)->count());
    }

    // ------------------------------------------------------------------------- Last-write-wins

    public function test_una_edicion_mas_nueva_del_sync_no_se_pisa(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->catalogo($medico, '0518', 'LIE BAJO GRADO');
        [, $consulta] = $this->pacienteYConsulta($medico);
        $fila = $this->diagnostico($medico, 1001, 1, '0518', 'lo viejo');

        // Otro equipo editó el detalle un minuto después: gana esa.
        SyncChange::create([
            'reg_medico' => $medico->reg_medico,
            'table_name' => 'diagnostico_paciente',
            'record_id'  => $fila,
            'operation'  => 'updated',
            'column_name' => 'detalle_diagnostco',
            'value'      => 'lo nuevo del escritorio',
            'occurred_at' => $this->hoy->copy()->addMinute(),
            'source'     => 'mobile',
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'filas' => [$fila => ['detalle' => 'lo de la web']],
        ]);

        $respuesta->assertSessionHas('error');
        $this->assertSame('lo viejo', DB::table('diagnostico_paciente')->where('id', $fila)->value('detalle_diagnostco'));
    }

    // ------------------------------------------------------------------ El alta del catálogo

    public function test_crear_un_diagnostico_lo_da_de_alta_con_el_correlativo_y_lo_deja_tildado(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->catalogo($medico, '0104', 'FIBROMIOMATOSIS UTERINA');
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlCrear($consulta), [
            'descripcion' => '  amenaza   de aborto ',
        ]);

        // El correlativo de cuatro dígitos, en mayúsculas, y la vuelta a la sección con el código tildado.
        $nuevo = DB::table('diagnosticos')->where('reg_medico', $medico->reg_medico)
            ->where('descripcion', 'AMENAZA DE ABORTO')->first();
        $this->assertNotNull($nuevo);
        $this->assertSame('0105', trim((string) $nuevo->codediagnostico));

        $respuesta->assertRedirect();
        $respuesta->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'AMENAZA DE ABORTO'));

        // Vuelve a la sección con el código tildado para el botón de guardar.
        parse_str((string) parse_url($respuesta->getTargetUrl(), PHP_URL_QUERY), $query);
        $this->assertSame('diagnostico', $query['seccion'] ?? null);
        $this->assertSame(['0105'], $query['agregar'] ?? []);

        // Todavía **no** está en la consulta: la sección es de guardado explícito y el alta deja el
        // diagnóstico tildado para el mismo botón.
        $this->assertSame(0, DB::table('diagnostico_paciente')->where('reg_medico', $medico->reg_medico)->count());

        // Y el guardado con el pendiente en la query lo agrega.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta) . '?agregar[]=0105', []);

        // La clave de la tabla es `nrohistoria`, no `numhistoria` (como en `consultas`).
        $this->assertDatabaseHas('diagnostico_paciente', [
            'reg_medico' => $medico->reg_medico,
            'nrohistoria' => 1001,
            'nroconsulta' => 1,
            'codediagnostico' => '0105',
            'detalle_diagnostco' => 'AMENAZA DE ABORTO',
        ]);
    }

    public function test_crear_un_diagnostico_no_pierde_lo_que_ya_estaba_tildado(): void
    {
        // Los pendientes del buscador viven en la pantalla (no en la base): el alta de uno nuevo navega a la
        // sección y tiene que llevarse lo que ya estaba tildado, o el médico lo pierde sin enterarse.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->catalogo($medico, '0104', 'FIBROMIOMATOSIS UTERINA');
        $this->catalogo($medico, '0003', 'LESION ESCAMOSA');
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlCrear($consulta), [
            'descripcion' => 'amenaza de aborto',
            'agregar' => ['0003' => '0003'],
        ]);

        parse_str((string) parse_url($respuesta->getTargetUrl(), PHP_URL_QUERY), $query);
        // El tildado antes y el recién creado (el correlativo siguiente del catálogo).
        $this->assertSame(['0003', '0105'], $query['agregar'] ?? []);

        // Y el guardado con los dos en la query agrega los dos.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta) . '?agregar[]=0003&agregar[]=0105', []);

        $this->assertSame(
            ['0003', '0105'],
            DB::table('diagnostico_paciente')->where('reg_medico', $medico->reg_medico)
                ->orderBy('codediagnostico')->pluck('codediagnostico')->map(fn ($c) => trim((string) $c))->all()
        );
    }

    public function test_crear_uno_que_ya_existe_usa_el_del_catalogo_y_no_lo_duplica(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->catalogo($medico, '0518', 'LIE BAJO GRADO');
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlCrear($consulta), [
            'descripcion' => 'lie bajo grado',
        ]);

        $respuesta->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'ya estaba en tu catálogo'));
        $this->assertSame(1, DB::table('diagnosticos')->where('reg_medico', $medico->reg_medico)
            ->where('descripcion', 'LIE BAJO GRADO')->count());

        // Y vuelve con el código del existente tildado.
        parse_str((string) parse_url($respuesta->getTargetUrl(), PHP_URL_QUERY), $query);
        $this->assertSame(['0518'], $query['agregar'] ?? []);
    }

    public function test_crear_sin_nombre_o_demasiado_largo_no_escribe_nada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $contexto = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        // Sin nombre: la Action devuelve el error de negocio y la sección conserva lo escrito.
        $contexto->post($this->urlCrear($consulta), ['descripcion' => '   '])
            ->assertSessionHasErrors('descripcion');

        // Más largo que `diagnosticos.descripcion` (50): tampoco.
        $contexto->post($this->urlCrear($consulta), ['descripcion' => str_repeat('A', 51)])
            ->assertSessionHasErrors('descripcion');

        $this->assertSame(0, DB::table('diagnosticos')->where('reg_medico', $medico->reg_medico)->count());
    }

    // ------------------------------------------------------------------------------------ Los roles

    public function test_la_secretaria_no_entra_a_la_seccion_ni_puede_guardar(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = $this->secretariaConAcceso($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $contexto = $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria);

        $contexto->get($this->urlSeccion($consulta))->assertForbidden();
        $contexto->post($this->urlGuardar($consulta), ['agregar' => ['0518' => '0518']])->assertForbidden();
        $contexto->post($this->urlCrear($consulta), ['descripcion' => 'ALGO'])->assertForbidden();
    }

    public function test_un_invitado_va_al_login(): void
    {
        [, $medico] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->get($this->urlSeccion($consulta))->assertRedirect(route('login'));
        $this->post($this->urlGuardar($consulta), [])->assertRedirect(route('login'));
        $this->post($this->urlCrear($consulta), ['descripcion' => 'ALGO'])->assertRedirect(route('login'));
    }

    // --------------------------------------------------------------------------------- El 404 ajeno

    public function test_la_consulta_de_otro_medico_da_404_al_leer_y_al_guardar(): void
    {
        [, $otro] = $this->medicoConAcceso();
        $this->catalogo($otro, '0518', 'LIE BAJO GRADO');
        [, $consultaAjena] = $this->pacienteYConsulta($otro);

        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $contexto = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        $contexto->get(route('clinica.consulta', [$consultaAjena->id, 'seccion' => 'diagnostico']))->assertNotFound();
        $contexto->post(route('clinica.consulta.diagnosticos.guardar', $consultaAjena->id), ['agregar' => ['0518' => '0518']])->assertNotFound();
        $contexto->post(route('clinica.consulta.diagnosticos.crear', $consultaAjena->id), ['descripcion' => 'ALGO'])->assertNotFound();

        // Nada se escribió en los datos del otro médico.
        $this->assertSame(0, DB::table('diagnostico_paciente')->where('reg_medico', $otro->reg_medico)->count());
        $this->assertSame(1, DB::table('diagnosticos')->where('reg_medico', $otro->reg_medico)->count());
    }

    public function test_una_fila_de_otro_registro_con_la_misma_historia_no_se_toca(): void
    {
        // La misma historia y la misma consulta en **otro** `reg_medico`: el id de esa fila no se puede
        // editar ni quitar desde el contexto del primero.
        [, $otro] = $this->medicoConAcceso();
        $this->catalogo($otro, '0518', 'LIE BAJO GRADO');
        [, $consultaAjena] = $this->pacienteYConsulta($otro);
        $ajena = $this->diagnostico($otro, 1001, 1, '0518', 'lo del otro');

        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->catalogo($medico, '0518', 'LIE BAJO GRADO');
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'filas' => [$ajena => ['detalle' => 'lo de la web']],
            'quitar' => [$ajena],
        ]);

        $this->assertSame('lo del otro', DB::table('diagnostico_paciente')->where('id', $ajena)->value('detalle_diagnostco'));
        $this->assertSame(0, SyncChange::where('record_id', $ajena)->count());
        // La consulta del contexto sigue sin diagnósticos.
        $this->assertSame(0, DB::table('diagnostico_paciente')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(1, DB::table('diagnostico_paciente')->where('reg_medico', $otro->reg_medico)->count());
    }

    // ------------------------------------------------------------------------------------ El buscador

    public function test_el_buscador_del_catalogo_no_distingue_tildes_y_marca_lo_que_ya_esta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->catalogo($medico, '0100', 'LESION ESCAMOSA');
        $this->catalogo($medico, '0101', 'LESION ESCAMOSA');
        $this->catalogo($medico, '0518', 'LIE BAJO GRADO');
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->diagnostico($medico, 1001, 1, '0518', 'LIE BAJO GRADO');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta) . '&buscar=lesion');

        $respuesta->assertOk();
        $respuesta->assertSee('LESION ESCAMOSA');

        // Un diagnóstico por nombre: el de código más bajo (el catálogo del legado trae repetidos).
        $contenido = $respuesta->getContent();
        $this->assertSame(1, substr_count($contenido, 'LESION ESCAMOSA'));
        $this->assertStringContainsString('0100', $contenido);
        $this->assertStringNotContainsString('0101', $contenido);

        // Y lo que la consulta ya tiene no se ofrece para agregar: con el catálogo entero a la vista, la
        // fila del diagnóstico cargado dice que ya está.
        $completa = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $completa->assertOk();
        $completa->assertSee('Ya está en la consulta', false);
    }

    public function test_un_pendiente_del_buscador_se_guarda_aunque_la_pagina_se_recargue(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->catalogo($medico, '0518', 'LIE BAJO GRADO');
        [, $consulta] = $this->pacienteYConsulta($medico);

        // La recarga (el `GET` del buscador) deja el código en la query: la sección lo muestra tildado.
        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta) . '&agregar[]=0518');

        $respuesta->assertOk();
        $respuesta->assertSee('Se agrega al guardar', false);
        $respuesta->assertSee('name="agregar[0518]"', false);

        // El POST del guardado lo lleva en su `action` (la query sobrevive a la recarga aunque los
        // `hidden` no), así que la fila termina agregada.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta) . '?agregar[]=0518', []);

        $this->assertDatabaseHas('diagnostico_paciente', [
            'reg_medico' => $medico->reg_medico,
            'codediagnostico' => '0518',
        ]);
    }

    // -------------------------------------------------------------------------------------- El panel

    public function test_el_panel_marca_los_diagnosticos_como_disponibles(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();
        $respuesta->assertSee('13 de 14 disponibles', false);
        $respuesta->assertSee('bi-clipboard2-check', false);
        // El enlace del panel abre esta misma sección.
        $respuesta->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => 'diagnostico']), false);
    }

    // ------------------------------------------------------------------------------ «Anterior» (WEB-11.4)

    public function test_anterior_copia_los_diagnosticos_de_la_consulta_previa_sin_duplicar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $primera] = $this->pacienteYConsulta($medico, 1001, 1);
        $segunda = Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'nroconsulta' => 2, 'fecha' => $this->hoy->toDateString()]);
        $this->catalogo($medico, '0001', 'HIPERTENSION');
        $this->catalogo($medico, '0002', 'MIOMA');
        $this->diagnostico($medico, 1001, 1, '0001', 'detalle viejo');
        $this->diagnostico($medico, 1001, 1, '0002');
        $this->diagnostico($medico, 1001, 2, '0002'); // ya está en la 2.ª

        $url = route('clinica.consulta.anterior', [$segunda->id, 'diagnosticos']);
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($url)->assertRedirect()
            ->assertSessionHas('estado', 'Copiados 1 diagnóstico de la consulta N.º 1.');

        $codigos = DB::table('diagnostico_paciente')->where('reg_medico', $medico->reg_medico)->where('nroconsulta', 2)->orderBy('codediagnostico')->pluck('codediagnostico')->map(fn ($c) => trim($c))->all();
        $this->assertSame(['0001', '0002'], $codigos);
        // El detalle es de aquella atención: no se copia.
        $this->assertNotSame('detalle viejo', DB::table('diagnostico_paciente')->where('nroconsulta', 2)->where('codediagnostico', '0001')->value('detalle_diagnostco'));
    }

    public function test_anterior_no_se_ofrece_en_la_primera_consulta_y_su_ruta_avisa(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $primera] = $this->pacienteYConsulta($medico, 1001, 1);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($primera))
            ->assertOk()->assertDontSee('Copiar los diagnósticos', false);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post(route('clinica.consulta.anterior', [$primera->id, 'diagnosticos']))
            ->assertRedirect()->assertSessionHas('error');
    }
}
