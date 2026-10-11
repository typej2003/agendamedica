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
 * **Los antecedentes del paciente en la consulta** (WEB-4.4, ítem 3 del panel del legado).
 *
 * Lo que estos casos fijan:
 *
 *  - **De quién son**: `antece_paciente` se llavea por `numhistoria` (la tabla **no** tiene columna de
 *    consulta), así que la sección escribe los antecedentes de la **historia del paciente** y dos
 *    consultas del mismo paciente ven lo mismo. Es lo que la fuente del escritorio hace
 *    (`w_consulta_principal.srw:7416`, `:7636`, `:7754`).
 *  - El **catálogo** (`antecedentes` + `tipo_antecedente`): agregar copia `descripcion` y `tipo` como el
 *    `INSERT` del escritorio, quitar **no** toca el catálogo, y el alta del que falta usa el correlativo
 *    de cuatro dígitos.
 *  - El **rastro por columna en `sync_changes`** (`source = web`) y el guard last-write-wins del sync.
 *  - La **casilla de Alerta**: `A` encendida y `null` apagada (la `B` del DataWindow se lee, no se
 *    escribe), y las filas del legado sin marcar no se ensucian por guardar el formulario.
 *  - El **404** de la consulta de otro consultorio, el **403** de la secretaría y el invitado al login.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class ConsultaAntecedentesWebTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hoy = Carbon::parse('2026-10-06 09:00:00');
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
        $regMedico = 'antecedentes-test-' . uniqid();

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'antecedentes-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Antecedentes',
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
            'email'                => 'antecedentes-secre-' . uniqid() . '@example.com',
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

    /**
     * El catálogo de tipos del médico, como lo sube el escritorio (`tipo_antecedente`).
     *
     * Es de la instalación y no de cada médico, pero la lectura prefiere el del propio `reg_medico` y el de
     * cualquier otro como respaldo: los tests crean el suyo para no depender de la base sembrada.
     */
    private function tipos(Medico $medico): void
    {
        foreach ([
            ['01', 'PERSONAL', 'P'],
            ['02', 'FAMILIAR', 'F'],
            ['04', 'OBSTETRICOS', 'O'],
            ['07', 'VARIOS', 'V'],
        ] as [$codigo, $nombre, $letra]) {
            DB::table('tipo_antecedente')->insert([
                'reg_medico' => $medico->reg_medico,
                'codetipo' => $codigo,
                'descripcion' => $nombre,
                'tipoantecedente' => $letra,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** Un antecedente del catálogo del médico. */
    private function catalogo(Medico $medico, string $codigo, string $descripcion, string $codtipo): void
    {
        DB::table('antecedentes')->insert([
            'reg_medico' => $medico->reg_medico,
            'codantecedente' => $codigo,
            'descripcion' => $descripcion,
            'codtipo' => $codtipo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Un antecedente **del paciente**, como lo deja el escritorio (el `INSERT` de
     * `w_consulta_principal.srw:7754-7764`).
     */
    private function antecedente(
        Medico $medico,
        int $numhistoria,
        string $codigo,
        string $descripcion,
        string $tipo,
        ?string $detalles = null,
        ?string $tipo2 = null,
    ): int {
        return (int) DB::table('antece_paciente')->insertGetId([
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => $numhistoria,
            'codeantecedente' => $codigo,
            'detalles' => $detalles,
            'descripcion' => $descripcion,
            'tipo' => $tipo,
            'tipo2' => $tipo2,
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
        return route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_ANTECEDENTES]);
    }

    private function urlGuardar(Consulta $consulta): string
    {
        return route('clinica.consulta.antecedentes.guardar', $consulta->id);
    }

    private function urlCrear(Consulta $consulta): string
    {
        return route('clinica.consulta.antecedentes.crear', $consulta->id);
    }

    // ------------------------------------------------------------------------------------- La lectura

    public function test_la_seccion_muestra_los_antecedentes_agrupados_con_su_detalle_y_su_alerta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        $this->catalogo($medico, '0002', 'DIABETES', '02');
        $this->catalogo($medico, '0285', 'HIPERTENSION', '01');
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->antecedente($medico, 1001, '0002', 'DIABETES', '02', 'la madre');
        $this->antecedente($medico, 1001, '0285', 'HIPERTENSION', '01', null, 'A');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();
        $respuesta->assertSee('Antecedentes del paciente', false);
        $respuesta->assertSee('Guardar los antecedentes', false);

        // Los grupos son los del catálogo de tipos, con el nombre del tipo.
        $respuesta->assertSee('PERSONAL', false);
        $respuesta->assertSee('FAMILIAR', false);

        // El detalle va en el control de la fila y la alerta tildada.
        $respuesta->assertSee('value="la madre"', false);
        $respuesta->assertSee('Alerta', false);
        $respuesta->assertSee('2 antecedentes', false);

        // La ayuda dice de quién son los antecedentes: es la decisión de la sección.
        $respuesta->assertSee('son del paciente, no de esta consulta', false);
    }

    /**
     * **Los antecedentes son del paciente y no de la consulta**: dos consultas de la misma historia ven la
     * misma lista, y lo que se carga en una se ve en la otra (y en la historia clínica, WEB-3.5).
     */
    public function test_dos_consultas_del_mismo_paciente_ven_los_mismos_antecedentes(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        $this->catalogo($medico, '0285', 'HIPERTENSION', '01');
        [, $primera] = $this->pacienteYConsulta($medico, 1001, 1);

        $segunda = Consulta::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => 1001,
            'nroconsulta' => 2,
            'fecha'       => $this->hoy->toDateString(),
        ]);

        $contexto = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        // Se carga desde la primera…
        $contexto->post($this->urlGuardar($primera), ['agregar' => ['0285' => '0285']]);

        // …y la segunda lo ve, sin que nadie haya escrito nada en ella.
        $respuesta = $contexto->get($this->urlSeccion($segunda));
        $respuesta->assertOk();
        $respuesta->assertSee('HIPERTENSION', false);

        // Y la fila quedó con la clave del paciente, sin ninguna columna de consulta.
        $this->assertSame(1, DB::table('antece_paciente')
            ->where('reg_medico', $medico->reg_medico)
            ->where('numhistoria', 1001)
            ->where('codeantecedente', '0285')
            ->count());
    }

    public function test_un_paciente_sin_antecedentes_muestra_el_estado_vacio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();
        $respuesta->assertSee('Sin cargar', false);
        $respuesta->assertSee('Este paciente no tiene antecedentes cargados', false);
    }

    /**
     * Los "vacíos" del legado (`NULL`, `''` y el literal `none`) no se muestran como un dato, y un
     * antecedente que ya no está en el catálogo se muestra igual con el aviso.
     */
    public function test_los_vacios_del_legado_y_el_antecedente_sin_catalogo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->antecedente($medico, 1001, '9999', '', '01', 'none');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();
        // El nombre y el detalle vacíos no inventan texto: se muestra el código y la raya del dato ausente.
        $respuesta->assertSee('9999', false);
        $respuesta->assertDontSee('>none<', false);
        $respuesta->assertSee('ya no está en tu catálogo', false);
    }

    // ------------------------------------------------------------------------------------- El guardado

    public function test_guardar_escribe_el_detalle_la_alerta_y_deja_el_rastro_por_columna(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        $this->catalogo($medico, '0285', 'HIPERTENSION', '01');
        [, $consulta] = $this->pacienteYConsulta($medico);
        $fila = $this->antecedente($medico, 1001, '0285', 'HIPERTENSION', '01');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'filas' => [$fila => ['detalle' => 'Desde 2019, controlada.', 'alerta' => 'A']],
        ]);

        $respuesta->assertRedirect($this->urlSeccion($consulta));
        $respuesta->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'HIPERTENSION'));

        $guardada = DB::table('antece_paciente')->where('id', $fila)->first();
        $this->assertSame('Desde 2019, controlada.', $guardada->detalles);
        $this->assertSame('A', $guardada->tipo2);
        // El nombre y el tipo de la fila que ya existía no se tocan (el DataWindow sólo actualiza
        // `detalles` y `tipo2`).
        $this->assertSame('HIPERTENSION', $guardada->descripcion);
        $this->assertSame('01', $guardada->tipo);

        foreach (['detalles', 'tipo2'] as $columna) {
            $this->assertDatabaseHas('sync_changes', [
                'table_name'  => 'antece_paciente',
                'record_id'   => $fila,
                'operation'   => 'updated',
                'column_name' => $columna,
                'source'      => 'web',
            ]);
        }
        // Y no se registró ninguna columna que la web no escriba.
        $this->assertSame(0, SyncChange::where('table_name', 'antece_paciente')
            ->whereIn('column_name', ['descripcion', 'tipo', 'codeantecedente', 'numhistoria'])->count());
    }

    /**
     * La casilla apagada de una fila del legado (`tipo2` nulo, que es como quedan las del botón *Pasar*) no
     * se ensucia: guardar el formulario sin tocarla **no** escribe ni deja rastro.
     */
    public function test_guardar_sin_tocar_la_casilla_no_ensucia_las_filas_del_legado(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $fila = $this->antecedente($medico, 1001, '0285', 'HIPERTENSION', '01');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'filas' => [$fila => ['detalle' => '', 'alerta' => 'B']],
        ]);

        $respuesta->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'no tenían cambios'));
        $this->assertNull(DB::table('antece_paciente')->where('id', $fila)->value('tipo2'));
        $this->assertSame(0, SyncChange::where('table_name', 'antece_paciente')->count());
    }

    /** Apagar una alerta que estaba encendida sí se escribe, y queda en `null` (no en la `B` del legado). */
    public function test_apagar_una_alerta_la_deja_sin_marcar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $fila = $this->antecedente($medico, 1001, '0285', 'HIPERTENSION', '01', 'algo', 'A');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'filas' => [$fila => ['detalle' => 'algo', 'alerta' => 'B']],
        ]);

        $this->assertNull(DB::table('antece_paciente')->where('id', $fila)->value('tipo2'));
        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'antece_paciente',
            'record_id' => $fila,
            'column_name' => 'tipo2',
            'source' => 'web',
        ]);
    }

    public function test_agregar_del_catalogo_copia_el_nombre_y_el_tipo_como_el_escritorio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        $this->catalogo($medico, '0013', 'DIABETES MELLITUS', '02');
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'agregar' => ['0013' => '0013'],
        ]);

        $respuesta->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'DIABETES MELLITUS'));

        $fila = DB::table('antece_paciente')->where('reg_medico', $medico->reg_medico)->first();
        $this->assertSame('0013', $fila->codeantecedente);
        $this->assertSame('DIABETES MELLITUS', $fila->descripcion);
        $this->assertSame('02', $fila->tipo);
        $this->assertSame('', $fila->detalles);
        $this->assertNull($fila->tipo2);

        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'antece_paciente',
            'record_id' => $fila->id,
            'operation' => 'created',
            'source' => 'web',
        ]);

        // El catálogo no se tocó.
        $this->assertSame(1, DB::table('antecedentes')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_agregar_dos_veces_el_mismo_antecedente_no_duplica_la_fila(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        $this->catalogo($medico, '0013', 'DIABETES MELLITUS', '02');
        [, $consulta] = $this->pacienteYConsulta($medico);

        $contexto = $this->conContexto($user, $medico, $gineco)->actingAs($user);
        $contexto->post($this->urlGuardar($consulta), ['agregar' => ['0013' => '0013']]);
        $contexto->post($this->urlGuardar($consulta), ['agregar' => ['0013' => '0013']]);

        $this->assertSame(1, DB::table('antece_paciente')
            ->where('reg_medico', $medico->reg_medico)->where('codeantecedente', '0013')->count());
    }

    public function test_quitar_lo_saca_del_paciente_y_no_del_catalogo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        $this->catalogo($medico, '0285', 'HIPERTENSION', '01');
        [, $consulta] = $this->pacienteYConsulta($medico);
        $fila = $this->antecedente($medico, 1001, '0285', 'HIPERTENSION', '01');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'quitar' => ['0285'],
        ]);

        $respuesta->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'quitado'));

        // La relación del paciente se fue…
        $this->assertSame(0, DB::table('antece_paciente')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'antece_paciente',
            'record_id' => $fila,
            'operation' => 'deleted',
            'source' => 'web',
        ]);
        // …y el catálogo sigue igual (el escritorio sólo desasocia).
        $this->assertSame(1, DB::table('antecedentes')->where('reg_medico', $medico->reg_medico)->count());
    }

    /**
     * Un código que **no** está en el catálogo del médico no se agrega: la pantalla no lo ofrece y aceptarlo
     * dejaría una fila huérfana sin nombre ni tipo. El aviso lo dice sin pisar nada.
     */
    public function test_un_codigo_que_no_esta_en_el_catalogo_no_se_agrega(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'agregar' => ['7777' => '7777'],
        ]);

        $respuesta->assertSessionHas('error');
        $this->assertSame(0, DB::table('antece_paciente')->where('reg_medico', $medico->reg_medico)->count());
    }

    // ------------------------------------------------------------------------- Last-write-wins

    public function test_una_edicion_mas_nueva_del_sync_no_se_pisa(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $fila = $this->antecedente($medico, 1001, '0285', 'HIPERTENSION', '01', 'lo viejo', 'B');

        // Otro equipo editó el detalle un minuto después: gana esa.
        SyncChange::create([
            'reg_medico' => $medico->reg_medico,
            'table_name' => 'antece_paciente',
            'record_id'  => $fila,
            'operation'  => 'updated',
            'column_name' => 'detalles',
            'value'      => 'lo nuevo del escritorio',
            'occurred_at' => $this->hoy->copy()->addMinute(),
            'source'     => 'mobile',
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'filas' => [$fila => ['detalle' => 'lo de la web', 'alerta' => 'A']],
        ]);

        $respuesta->assertSessionHas('error');
        // La fila entera se deja como estaba: no se pisa ni el detalle ni la casilla de esa fila.
        $this->assertSame('lo viejo', DB::table('antece_paciente')->where('id', $fila)->value('detalles'));
        $this->assertSame('B', DB::table('antece_paciente')->where('id', $fila)->value('tipo2'));
        $this->assertSame(1, DB::table('antece_paciente')->where('reg_medico', $medico->reg_medico)->count());
    }

    // ------------------------------------------------------------------ El alta del catálogo

    public function test_crear_un_antecedente_lo_da_de_alta_en_el_catalogo_y_lo_agrega_al_paciente(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        $this->catalogo($medico, '0013', 'DIABETES MELLITUS', '02');
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlCrear($consulta), [
            'descripcion' => 'artritis reumatoide',
            'tipo' => '01',
        ]);

        $respuesta->assertRedirect($this->urlSeccion($consulta));
        $respuesta->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'ARTRITIS REUMATOIDE'));

        // El correlativo de cuatro dígitos, en mayúsculas y con el tipo elegido.
        $nuevo = DB::table('antecedentes')->where('reg_medico', $medico->reg_medico)
            ->where('descripcion', 'ARTRITIS REUMATOIDE')->first();
        $this->assertNotNull($nuevo);
        $this->assertSame('0014', $nuevo->codantecedente);
        $this->assertSame('01', $nuevo->codtipo);

        // Y quedó agregado al paciente con la copia del nombre y del tipo.
        $this->assertDatabaseHas('antece_paciente', [
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => 1001,
            'codeantecedente' => '0014',
            'descripcion' => 'ARTRITIS REUMATOIDE',
            'tipo' => '01',
        ]);
    }

    public function test_crear_uno_que_ya_existe_usa_el_del_catalogo_y_no_lo_duplica(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        $this->catalogo($medico, '0013', 'DIABETES MELLITUS', '02');
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlCrear($consulta), [
            'descripcion' => 'diabetes mellitus',
            'tipo' => '02',
        ]);

        $this->assertSame(1, DB::table('antecedentes')->where('reg_medico', $medico->reg_medico)
            ->where('descripcion', 'DIABETES MELLITUS')->count());
        $this->assertDatabaseHas('antece_paciente', ['codeantecedente' => '0013']);
    }

    public function test_crear_sin_nombre_o_sin_tipo_valido_no_escribe_nada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $contexto = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        // Sin nombre: la Action devuelve el error de negocio y el diálogo se reabre.
        $sinNombre = $contexto->post($this->urlCrear($consulta), ['descripcion' => '   ', 'tipo' => '01']);
        $sinNombre->assertSessionHasErrors('descripcion');
        $sinNombre->assertSessionHas('crear_antecedente');

        // Con un tipo que no está en `tipo_antecedente`: tampoco.
        $contexto->post($this->urlCrear($consulta), ['descripcion' => 'ALGO', 'tipo' => '99'])
            ->assertSessionHasErrors('descripcion');

        $this->assertSame(0, DB::table('antecedentes')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(0, DB::table('antece_paciente')->where('reg_medico', $medico->reg_medico)->count());
    }

    // ------------------------------------------------------------------------------------ Los roles

    public function test_la_secretaria_no_entra_a_la_seccion_ni_puede_guardar(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = $this->secretariaConAcceso($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $contexto = $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria);

        $contexto->get($this->urlSeccion($consulta))->assertForbidden();
        $contexto->post($this->urlGuardar($consulta), ['agregar' => ['0001' => '0001']])->assertForbidden();
        $contexto->post($this->urlCrear($consulta), ['descripcion' => 'ALGO', 'tipo' => '01'])->assertForbidden();
    }

    public function test_un_invitado_va_al_login(): void
    {
        [, $medico] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->get($this->urlSeccion($consulta))->assertRedirect(route('login'));
        $this->post($this->urlGuardar($consulta), [])->assertRedirect(route('login'));
        $this->post($this->urlCrear($consulta), ['descripcion' => 'ALGO', 'tipo' => '01'])->assertRedirect(route('login'));
    }

    // --------------------------------------------------------------------------------- El 404 ajeno

    public function test_la_consulta_de_otro_medico_da_404_al_leer_y_al_guardar(): void
    {
        [, $otro] = $this->medicoConAcceso();
        $this->tipos($otro);
        $this->catalogo($otro, '0285', 'HIPERTENSION', '01');
        [, $consultaAjena] = $this->pacienteYConsulta($otro);

        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $contexto = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        $contexto->get(route('clinica.consulta', [$consultaAjena->id, 'seccion' => 'antecedentes']))->assertNotFound();
        $contexto->post(route('clinica.consulta.antecedentes.guardar', $consultaAjena->id), ['agregar' => ['0285' => '0285']])->assertNotFound();
        $contexto->post(route('clinica.consulta.antecedentes.crear', $consultaAjena->id), ['descripcion' => 'ALGO', 'tipo' => '01'])->assertNotFound();

        // Nada se escribió en los datos del otro médico.
        $this->assertSame(0, DB::table('antece_paciente')->where('reg_medico', $otro->reg_medico)->count());
        // Y la consulta propia sigue sin datos: el POST ajeno no escribió en la de este médico.
        $this->assertSame(0, DB::table('antece_paciente')->where('reg_medico', $medico->reg_medico)->count());
    }

    /**
     * Un antecedente de **otro** `reg_medico` con el mismo número de historia no se toca: el `id` de la fila
     * que llega por el formulario se resuelve contra lo que el paciente de este médico tiene.
     */
    public function test_una_fila_de_otro_registro_con_la_misma_historia_no_se_toca(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        // La fila del otro consultorio, con el mismo número de historia.
        $ajena = (int) DB::table('antece_paciente')->insertGetId([
            'reg_medico' => 'otro-registro',
            'numhistoria' => 1001,
            'codeantecedente' => '0285',
            'detalles' => 'no se toca',
            'descripcion' => 'HIPERTENSION',
            'tipo' => '01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'filas' => [$ajena => ['detalle' => 'pisado', 'alerta' => 'A']],
        ]);

        $this->assertSame('no se toca', DB::table('antece_paciente')->where('id', $ajena)->value('detalles'));
        $this->assertNull(DB::table('antece_paciente')->where('id', $ajena)->value('tipo2'));
    }

    // ------------------------------------------------------------------------- El buscador (JSON)

    public function test_el_buscador_del_catalogo_no_distingue_tildes_y_marca_lo_que_ya_esta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        $this->catalogo($medico, '0013', 'DIABETES MELLITUS', '02');
        $this->catalogo($medico, '0285', 'HIPERTENSIÓN', '01');
        // El catálogo del legado trae repetidos: se ofrece uno solo, el de código más bajo.
        $this->catalogo($medico, '0290', 'HIPERTENSION', '01');
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->antecedente($medico, 1001, '0013', 'DIABETES MELLITUS', '02');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->getJson(route('clinica.consulta.antecedentes.buscar', [$consulta->id, 'q' => 'hipertension']));

        $respuesta->assertOk();
        $datos = $respuesta->json();

        $this->assertCount(1, $datos['antecedentes']);
        $this->assertSame('HIPERTENSIÓN', $datos['antecedentes'][0]['descripcion']);
        $this->assertSame('0285', $datos['antecedentes'][0]['codigo']);
        $this->assertSame('PERSONAL', $datos['antecedentes'][0]['tipo']);
        $this->assertFalse($datos['antecedentes'][0]['yaEsta']);
        // Lo escrito **ya es** un antecedente del catálogo (sin tilde, como lo escribió el médico): no se
        // ofrece crearlo.
        $this->assertTrue($datos['existe']);

        // Lo que el paciente ya tiene se marca para no ofrecerlo de nuevo. La búsqueda devuelve el que
        // existe y `existe` sigue en falso: lo escrito («diabetes») **no es** el antecedente del catálogo
        // («DIABETES MELLITUS»), así que el buscador sí ofrece crear «DIABETES».
        $yaEsta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->getJson(route('clinica.consulta.antecedentes.buscar', [$consulta->id, 'q' => 'diabetes']));
        $this->assertCount(1, $yaEsta->json()['antecedentes']);
        $this->assertTrue($yaEsta->json()['antecedentes'][0]['yaEsta']);
        $this->assertFalse($yaEsta->json()['existe']);

        // El 404 del médico ajeno también vale para el buscador.
        [, $otro] = $this->medicoConAcceso();
        [, $consultaAjena] = $this->pacienteYConsulta($otro);
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->getJson(route('clinica.consulta.antecedentes.buscar', [$consultaAjena->id, 'q' => 'x']))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------------- Los pendientes del buscador

    /**
     * El buscador es un `GET`: "Agregar" vuelve a la sección con el código en la query. Si el médico recarga
     * la página, los `hidden` del formulario se van pero **la query del `action` no**, así que lo tildado
     * igual se guarda (el controlador junta el `POST` y la query).
     */
    public function test_un_pendiente_del_buscador_se_guarda_aunque_la_pagina_se_recargue(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        $this->catalogo($medico, '0013', 'DIABETES MELLITUS', '02');
        [, $consulta] = $this->pacienteYConsulta($medico);

        // La sección con el pendiente en la URL: el aviso lo dice y el `action` del formulario lo lleva.
        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta) . '&agregar[]=0013');
        $respuesta->assertOk();
        $respuesta->assertSee('Tildaste un antecedente', false);
        // El formulario de guardado lleva el pendiente: como `hidden` y en la query de su `action`.
        $respuesta->assertSee('name="agregar[0013]"', false);
        $this->assertStringContainsString(
            'agregar[]=0013',
            html_entity_decode($respuesta->getContent(), ENT_QUOTES),
        );

        // El POST con el pendiente **sólo** en la query (como después de una recarga).
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta) . '?agregar[]=0013', [])
            ->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'DIABETES MELLITUS'));

        $this->assertDatabaseHas('antece_paciente', [
            'reg_medico' => $medico->reg_medico,
            'numhistoria' => 1001,
            'codeantecedente' => '0013',
        ]);
    }

    /**
     * Un `filas[id]` de una fila que se está quitando en el mismo POST no se escribe: el formulario puede
     * mandar los controles de una fila que ya no está (el JS los saca, pero la Action no depende del JS).
     */
    public function test_una_fila_que_se_quita_no_se_edita_en_el_mismo_post(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $fila = $this->antecedente($medico, 1001, '0285', 'HIPERTENSION', '01', 'lo viejo');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), [
            'filas' => [$fila => ['detalle' => 'lo de la web', 'alerta' => 'A']],
            'quitar' => ['0285'],
        ]);

        $this->assertSame(0, DB::table('antece_paciente')->where('reg_medico', $medico->reg_medico)->count());
        // No quedó ningún rastro de `updated` de una fila que se borró.
        $this->assertSame(0, SyncChange::where('table_name', 'antece_paciente')
            ->where('operation', 'updated')->count());
    }

    // ------------------------------------------------------------------------------------ El panel

    public function test_el_panel_marca_los_antecedentes_como_disponibles(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta', $consulta->id));

        $respuesta->assertOk();
        $respuesta->assertSee('5 de 13 disponibles', false);
        $respuesta->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => 'antecedentes']), false);
    }
}
