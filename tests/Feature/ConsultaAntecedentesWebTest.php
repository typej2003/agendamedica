<?php

namespace Tests\Feature;

use App\Clinica\Consulta\TiposDeAntecedente;
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
 *  - **Agregar y Quitar son inmediatos** (O-07, 2026-10-17): un `POST` cada uno, una escritura por clic, y la
 *    fila aparece o desaparece del paciente en el acto; lo que sigue yendo con el botón es el detalle y la
 *    casilla de Alerta.
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
        $regMedico = 'antecedentes-' . substr(uniqid(), -6);

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'antecedentes-' . substr(uniqid(), -6) . '@example.com',
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
            'email'                => 'antecedentes-' . substr(uniqid(), -6) . '@example.com',
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
        foreach (TiposDeAntecedente::GINECOLOGIA as $codigo => $nombre) {
            DB::table('tipo_antecedente')->insert([
                'reg_medico' => $medico->reg_medico,
                'codetipo' => $codigo,
                'descripcion' => $nombre,
                'tipoantecedente' => TiposDeAntecedente::LETRAS_DE_GINECOLOGIA[$codigo] ?? null,
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

    /** El «Agregar» inmediato (O-07): un POST con el código del catálogo. */
    private function urlAgregar(Consulta $consulta): string
    {
        return route('clinica.consulta.antecedentes.agregar', $consulta->id);
    }

    /** El «Quitar» inmediato (O-07): un POST con el código del catálogo. */
    private function urlQuitar(Consulta $consulta): string
    {
        return route('clinica.consulta.antecedentes.quitar', $consulta->id);
    }

    private function urlCrear(Consulta $consulta): string
    {
        return route('clinica.consulta.antecedentes.crear', $consulta->id);
    }

    /**
     * Los chips de tipo de la sección, leídos del HTML: nombre, si es el elegido, su URL y su `aria-current`.
     *
     * @return array<int,array{nombre:string, activo:bool, url:string, aria:bool}>
     */
    private function chipsDeLaRespuesta(string $html): array
    {
        preg_match_all('/<a class="chip-filtro([^"]*)" href="([^"]*)"([^>]*)>([^<]*)<\/a>/', $html, $filas, PREG_SET_ORDER);

        return array_map(fn ($fila) => [
            'nombre' => trim(html_entity_decode($fila[4])),
            'activo' => str_contains($fila[1], 'activo'),
            'url'    => html_entity_decode($fila[2]),
            'aria'   => str_contains($fila[3], 'aria-current="true"'),
        ], $filas);
    }

    /** El bloque de la columna del catálogo (para mirar qué ofrece), sin el resto de la sección. */
    private function catalogoDeLaRespuesta(string $html): string
    {
        $desde = strpos($html, 'id="antecedente-buscar"');

        return $desde === false ? '' : substr($html, $desde);
    }

    // ------------------------------------------------------------------------------------- La lectura

    public function test_la_seccion_muestra_los_antecedentes_del_tipo_elegido_con_su_detalle_y_su_alerta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        $this->catalogo($medico, '0002', 'DIABETES', '02');
        $this->catalogo($medico, '0285', 'HIPERTENSION', '01');
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->antecedente($medico, 1001, '0002', 'DIABETES', '02', 'la madre');
        $filaHipertension = $this->antecedente($medico, 1001, '0285', 'HIPERTENSION', '01', null, 'A');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();
        $respuesta->assertSee('Antecedentes del paciente', false);
        $respuesta->assertSee('Guardar los antecedentes', false);

        // Los chips son los del catálogo de tipos (los siete de ginecología) y el elegido es el primero
        // **con antecedentes** (Per.).
        $chips = $this->chipsDeLaRespuesta($respuesta->getContent());
        $this->assertSame(array_values(TiposDeAntecedente::GINECOLOGIA), array_column($chips, 'nombre'));
        $this->assertSame(['PERSONAL'], array_column(array_filter($chips, fn ($c) => $c['activo']), 'nombre'));

        // La columna muestra **sólo** los de ese tipo: el detalle de la fila y la alerta tildada.
        $respuesta->assertSee('data-antecedente-fila="' . $filaHipertension . '"', false);
        $respuesta->assertDontSee('value="la madre"', false);
        $respuesta->assertSee('Alerta', false);
        $respuesta->assertSee('2 antecedentes', false);

        // Y con el otro chip, el mismo paciente al revés. (No se mira «HIPERTENSION» en toda la página: el
        // aviso de alertas del encabezado del shell lo repite porque esa fila está marcada; se mira la fila.)
        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta) . '&tipo=02');

        $respuesta->assertOk();
        $respuesta->assertSee('value="la madre"', false);
        $respuesta->assertDontSee('data-antecedente-fila="' . $filaHipertension . '"', false);
        $this->assertSame(['FAMILIAR'], array_column(array_filter(
            $this->chipsDeLaRespuesta($respuesta->getContent()),
            fn ($c) => $c['activo'],
        ), 'nombre'));
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
        $contexto->post($this->urlAgregar($primera), ['codigo' => '0285']);

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
        // La pastilla «Sin cargar» se quitó (revisión de Alexander, 2026-10-20): el contador y el vacío lo dicen.
        $respuesta->assertDontSee('Sin cargar', false);
        $respuesta->assertSee('Este paciente no tiene antecedentes cargados', false);
        // Sin antecedentes, el tipo por defecto es el primero del catálogo: el alta arranca en un tipo concreto.
        $this->assertSame(['PERSONAL'], array_column(array_filter(
            $this->chipsDeLaRespuesta($respuesta->getContent()),
            fn ($chip) => $chip['activo'],
        ), 'nombre'));
    }

    // ------------------------------------------------------------------------------ Los chips de tipo (A-06)

    /**
     * El chip de tipo **filtra las dos columnas**: a la izquierda los antecedentes del paciente de ese tipo y a
     * la derecha el catálogo de ese tipo. Es la solapa del escritorio, que Alexander pidió de vuelta.
     */
    public function test_el_chip_de_tipo_filtra_las_dos_columnas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        $this->catalogo($medico, '0001', 'ASMA', '01');
        $this->catalogo($medico, '0002', 'DIABETES', '02');
        [, $consulta] = $this->pacienteYConsulta($medico);
        $fila = $this->antecedente($medico, 1001, '0001', 'ASMA', '01');

        $contexto = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        // Chip PERSONAL (el primero con antecedentes): la columna del paciente tiene ASMA y el catálogo, ASMA
        // (marcado como «ya está»); DIABETES no aparece en ninguna de las dos.
        $personal = $contexto->get($this->urlSeccion($consulta))->assertOk();
        $personal->assertSee('data-antecedente-fila="' . $fila . '"', false);
        $catalogoPersonal = $this->catalogoDeLaRespuesta($personal->getContent());
        $this->assertStringContainsString('ASMA', $catalogoPersonal);
        $this->assertStringContainsString('Ya está en el paciente', $catalogoPersonal);
        $this->assertStringNotContainsString('DIABETES', $personal->getContent());

        // Chip FAMILIAR: el paciente no tiene de ese tipo (lo dice) y el catálogo muestra el de ese tipo.
        $familiar = $contexto->get($this->urlSeccion($consulta) . '&tipo=02')->assertOk();
        $familiar->assertSee('Sin antecedentes de este tipo', false);
        $familiar->assertDontSee('Este paciente no tiene antecedentes cargados', false);
        $catalogoFamiliar = $this->catalogoDeLaRespuesta($familiar->getContent());
        $this->assertStringContainsString('DIABETES', $catalogoFamiliar);
        $this->assertStringNotContainsString('ASMA', $catalogoFamiliar);
        // El contador de la cabecera sigue mostrando el **total** del paciente, no lo filtrado.
        $familiar->assertSee('1 antecedente', false);
    }

    /** Un `?tipo=` que no es del catálogo no deja la pantalla vacía: cae en el primero con antecedentes. */
    public function test_un_tipo_que_no_existe_cae_en_el_tipo_por_defecto(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $fila = $this->antecedente($medico, 1001, '0285', 'HIPERTENSION', '02');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta) . '&tipo=99');

        $respuesta->assertOk();
        $respuesta->assertSee('data-antecedente-fila="' . $fila . '"', false);
        $this->assertSame(['FAMILIAR'], array_column(array_filter(
            $this->chipsDeLaRespuesta($respuesta->getContent()),
            fn ($chip) => $chip['activo'],
        ), 'nombre'));
    }

    /**
     * Un antecedente del paciente **sin tipo** (la fila del legado no lo tiene y el catálogo tampoco lo
     * resuelve) no puede quedar sin puerta de entrada: se agrega el chip «Sin clasificar» al final, y es el
     * elegido si es lo único que el paciente tiene.
     */
    public function test_un_antecedente_sin_tipo_se_ve_en_el_chip_sin_clasificar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $fila = $this->antecedente($medico, 1001, '9999', 'SIN CATALOGO', '');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();
        $chips = $this->chipsDeLaRespuesta($respuesta->getContent());
        $this->assertSame('Sin clasificar', end($chips)['nombre']);
        $this->assertTrue(end($chips)['activo'], 'Con lo único que hay sin tipo, la pantalla abre en ese chip.');
        $respuesta->assertSee('data-antecedente-fila="' . $fila . '"', false);
    }

    /**
     * Los chips conservan la sección y el tipo (el filtro), y **ya no** arrastran códigos pendientes: desde
     * O-07 «Agregar» escribe en el acto y no queda nada tildado que conservar.
     */
    public function test_los_chips_conservan_el_filtro_sin_pendientes(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        $this->catalogo($medico, '0013', 'DIABETES MELLITUS', '02');
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta) . '&tipo=01');

        $respuesta->assertOk();
        foreach ($this->chipsDeLaRespuesta($respuesta->getContent()) as $chip) {
            $this->assertStringContainsString('seccion=antecedentes', $chip['url']);
            $this->assertStringContainsString('tipo=', $chip['url']);
            $this->assertStringNotContainsString('agregar', $chip['url'], 'Ya no hay pendientes que conservar.');
        }
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

    public function test_agregar_del_catalogo_es_inmediato_y_copia_el_nombre_y_el_tipo_como_el_escritorio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        $this->catalogo($medico, '0013', 'DIABETES MELLITUS', '02');
        [, $consulta] = $this->pacienteYConsulta($medico);

        // O-07: un solo POST —el de «Agregar»— escribe la fila. No hay paso de guardado.
        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlAgregar($consulta), [
            'codigo' => '0013',
            'tipo' => '02',
        ]);

        $respuesta->assertRedirect(route('clinica.consulta', [$consulta->id, 'seccion' => 'antecedentes', 'tipo' => '02']));
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

        // Y la sección, al volver, ya muestra la fila nueva sin apretar ningún botón.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta) . '&tipo=02')
            ->assertOk()
            ->assertSee('DIABETES MELLITUS', false);
    }

    public function test_agregar_dos_veces_el_mismo_antecedente_no_duplica_la_fila(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        $this->catalogo($medico, '0013', 'DIABETES MELLITUS', '02');
        [, $consulta] = $this->pacienteYConsulta($medico);

        $contexto = $this->conContexto($user, $medico, $gineco)->actingAs($user);
        $contexto->post($this->urlAgregar($consulta), ['codigo' => '0013']);
        // El segundo clic no duplica: la Action no agrega lo que el paciente ya tiene y lo dice.
        $segundo = $contexto->post($this->urlAgregar($consulta), ['codigo' => '0013']);
        $segundo->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'ya estaba'));

        $this->assertSame(1, DB::table('antece_paciente')
            ->where('reg_medico', $medico->reg_medico)->where('codeantecedente', '0013')->count());
    }

    public function test_quitar_es_inmediato_y_saca_la_fila_del_paciente_y_no_del_catalogo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        $this->catalogo($medico, '0285', 'HIPERTENSION', '01');
        [, $consulta] = $this->pacienteYConsulta($medico);
        $fila = $this->antecedente($medico, 1001, '0285', 'HIPERTENSION', '01');

        // O-07: un solo POST —el del «Quitar» de la fila— la borra en el acto.
        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlQuitar($consulta), [
            'codigo' => '0285',
            'tipo' => '01',
        ]);

        $respuesta->assertRedirect(route('clinica.consulta', [$consulta->id, 'seccion' => 'antecedentes', 'tipo' => '01']));
        $respuesta->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'Quitado'));

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

        // La sección al volver ya no la tiene.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta) . '&tipo=01')
            ->assertOk()
            ->assertDontSee('data-antecedente-fila="' . $fila . '"', false);
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

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlAgregar($consulta), [
            'codigo' => '7777',
        ]);

        $respuesta->assertSessionHas('error', fn ($error) => str_contains($error, 'no está en tu catálogo'));
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
        $contexto->post($this->urlAgregar($consulta), ['codigo' => '0001'])->assertForbidden();
        $contexto->post($this->urlQuitar($consulta), ['codigo' => '0001'])->assertForbidden();
        $contexto->post($this->urlGuardar($consulta), ['filas' => []])->assertForbidden();
        $contexto->post($this->urlCrear($consulta), ['descripcion' => 'ALGO', 'tipo' => '01'])->assertForbidden();
    }

    public function test_un_invitado_va_al_login(): void
    {
        [, $medico] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->get($this->urlSeccion($consulta))->assertRedirect(route('login'));
        $this->post($this->urlAgregar($consulta), ['codigo' => '0001'])->assertRedirect(route('login'));
        $this->post($this->urlQuitar($consulta), ['codigo' => '0001'])->assertRedirect(route('login'));
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
        $contexto->post(route('clinica.consulta.antecedentes.agregar', $consultaAjena->id), ['codigo' => '0285'])->assertNotFound();
        $contexto->post(route('clinica.consulta.antecedentes.quitar', $consultaAjena->id), ['codigo' => '0285'])->assertNotFound();
        $contexto->post(route('clinica.consulta.antecedentes.guardar', $consultaAjena->id), ['filas' => []])->assertNotFound();
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

    // ------------------------------------------------------------------- El agregado es inmediato (O-07)

    /**
     * La sección **no** deja códigos pendientes en la URL: «Agregar» es un `POST` que escribe y vuelve con la
     * lista del paciente ya cambiada. Un `?agregar[]=` viejo (un enlace guardado de antes de O-07) no agrega
     * nada ni esconde el buscador: se ignora.
     */
    public function test_un_pendiente_viejo_en_la_url_ya_no_agrega_nada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        $this->catalogo($medico, '0013', 'DIABETES MELLITUS', '02');
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta) . '&agregar[]=0013');

        $respuesta->assertOk();
        $respuesta->assertDontSee('Tildaste un antecedente', false);
        $respuesta->assertDontSee('name="agregar[0013]"', false);
        $this->assertSame(0, DB::table('antece_paciente')->where('reg_medico', $medico->reg_medico)->count());
    }

    /**
     * Un `filas[id]` de una fila que ya no está (se quitó en el acto, O-07) no se escribe ni deja rastro: el
     * formulario no puede escribir sobre algo que la pantalla ya no muestra.
     */
    public function test_un_filas_de_una_fila_que_ya_no_esta_no_se_escribe(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->tipos($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $fila = $this->antecedente($medico, 1001, '0285', 'HIPERTENSION', '01', 'lo viejo');

        $contexto = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        // Se quita en el acto…
        $contexto->post($this->urlQuitar($consulta), ['codigo' => '0285']);
        // …y un POST de guardado con el `filas[id]` viejo no la resucita ni escribe nada.
        $contexto->post($this->urlGuardar($consulta), [
            'filas' => [$fila => ['detalle' => 'lo de la web', 'alerta' => 'A']],
        ]);

        $this->assertSame(0, DB::table('antece_paciente')->where('reg_medico', $medico->reg_medico)->count());
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
        $respuesta->assertSee('Pre-factura', false);
        $respuesta->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => 'antecedentes']), false);
    }

    // ---------------------------------------------------------------------------------- Alertas (WEB-11.6)

    public function test_las_alertas_se_ven_todas_y_fijas_y_se_avisan_una_sola_vez(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->antecedente($medico, 1001, '0001', 'ALERGIA A PENICILINA', 'P', 'Anafilaxia', 'A');
        $this->antecedente($medico, 1001, '0002', 'ANTICOAGULADO', 'P', null, 'A');
        $this->antecedente($medico, 1001, '0003', 'ASMA', 'P', null, 'B');

        $url = route('clinica.consulta', [$consulta->id, 'seccion' => 'motivos']);
        $primera = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($url);
        $primera->assertOk()->assertSee('Alertas de este paciente')
            ->assertSee('ALERGIA A PENICILINA', false)->assertSee('Anafilaxia', false)->assertSee('ANTICOAGULADO', false)
            ->assertDontSee('ASMA', false)
            ->assertSee('data-abrir-al-cargar="1"', false);

        // Otra sección de la misma atención: el banner sigue, el diálogo no se vuelve a abrir.
        $segunda = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get(route('clinica.consulta', [$consulta->id, 'seccion' => 'evolucion']));
        $segunda->assertSee('ALERGIA A PENICILINA', false)->assertDontSee('data-abrir-al-cargar="1"', false);
    }

    public function test_sin_alertas_no_hay_banner_ni_dialogo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->antecedente($medico, 1001, '0003', 'ASMA', 'P', null, 'B');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get(route('clinica.consulta', $consulta->id))
            ->assertOk()->assertDontSee('Alertas de este paciente');
    }
}
