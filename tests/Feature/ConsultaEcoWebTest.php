<?php

namespace Tests\Feature;

use App\Clinica\Consulta\EcoGinecologico;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Models\Consulta;
use App\Models\EcoPelvico;
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
 * **El eco pélvico de la consulta** (WEB-6.1, ítem 5 del panel del legado), dentro del shell.
 *
 * Lo que estos casos fijan:
 *
 *  - **La clave del legado**: una fila por consulta, por `numhistoria` + `nroconsulta`
 *    (`d_eco_pelvico.srd:6` y `:19`), con `fecha` propuesta en hoy y **404** si el tipo de estudio no
 *    existe. Es la misma trampa que la evaluación física: buscar por el id de la consulta dejaría la fila
 *    huérfana.
 *  - **El rastro por columna en `sync_changes`** (`source = web`) y el guard last-write-wins del sync.
 *  - **Nada de filas vacías**: un estudio sin ningún dato (la fecha sola no cuenta) no es un documento y no
 *    se guarda.
 *  - **Las cinco columnas que la pantalla no toca** (`puro_texto`, `centrar`, `cuello`, `anterocuello`,
 *    `formautero`) quedan como estaban, y el informe en texto libre del escritorio se muestra.
 *  - **La FUR del control prenatal**, que el escritorio copia al entrar a la pestaña
 *    (`w_consulta_principal.srw:21391-21405`).
 *  - El **404** de la consulta de otro consultorio, el **403** de la secretaría y el invitado al login.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class ConsultaEcoWebTest extends TestCase
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
        $regMedico = 'eco-test-' . uniqid();

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'eco-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Eco',
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
            'email'                => 'eco-secre-' . uniqid() . '@example.com',
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

    /** @return array{0:int, 1:Consulta} */
    private function pacienteYConsulta(Medico $medico, int $numhistoria = 1001, int $nroconsulta = 1): array
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

        $consulta = Consulta::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => $numhistoria,
            'nroconsulta' => $nroconsulta,
            'fecha'       => $this->hoy->toDateString(),
        ]);

        return [$pacienteId, $consulta];
    }

    private function urlSeccion(Consulta $consulta, string $tipo = 'ginecologico'): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_ESTUDIO, 'tipo' => $tipo]);
    }

    private function urlGuardar(Consulta $consulta, string $tipo = 'ginecologico'): string
    {
        return route('clinica.consulta.estudio.guardar', [$consulta->id, $tipo]);
    }

    /**
     * Lo que manda el formulario: **las 35 columnas**, vacías las que no se cargaron. Es lo que hace el
     * navegador, y por eso el caso de "sólo lo que cambió" se prueba con esto y no con un POST parcial.
     *
     * @param  array<string,string>  $valores
     * @return array<string,string>
     */
    private function formulario(array $valores = []): array
    {
        $campos = [];
        foreach (array_keys(EcoGinecologico::columnas()) as $columna) {
            $campos[$columna] = $valores[$columna] ?? '';
        }

        return $campos;
    }

    // ------------------------------------------------------------------------------------ La sección

    public function test_la_seccion_abre_con_el_formulario_del_eco(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();
        $respuesta->assertSee('13 de 14 disponibles');

        // El contenedor con su tipo y el formulario con sus bloques.
        $respuesta->assertSee('Eco ginecológico');
        $respuesta->assertSee('Eco pélvico');
        foreach (['Datos del estudio', 'Transductor', 'Vejiga', 'Útero', 'Fondo de saco de Douglas', 'Anexos',
            'Ovario derecho', 'Ovario izquierdo', 'Hallazgos, observaciones y conclusiones'] as $grupo) {
            $respuesta->assertSee($grupo);
        }

        // Los rótulos del escritorio que no coinciden con el nombre de la columna.
        $respuesta->assertSee('Patrón miometrial');
        $respuesta->assertSee('Fondo de saco de Douglas');

        // Y el formulario apunta a la acción de guardar del tipo que se está mirando.
        $respuesta->assertSee($this->urlGuardar($consulta), false);
        $respuesta->assertSee('Guardar el estudio');
    }

    public function test_el_panel_marca_el_estudio_como_disponible(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get(route('clinica.consulta', $consulta->id));

        $respuesta->assertOk();
        $respuesta->assertSee('13 de 14 disponibles');
        $respuesta->assertSee('Estudio ecográfico');
        // El enlace del panel es el de la sección a secas: el tipo por defecto es el ginecológico.
        $respuesta->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_ESTUDIO]), false);
        // El ítem 6 sigue pendiente: es de WEB-6.6.
        $respuesta->assertSee('Control de embarazo');
        $respuesta->assertSee('Próximamente');
    }

    // ------------------------------------------------------------------------------------ El guardado

    public function test_guardar_crea_la_fila_con_la_clave_del_datawindow(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), $this->formulario([
            'gesta' => '2',
            'fur' => '2026-06-01',
            'PARA' => '1 cesárea',
            'posicionavf' => '1',
            'cuerpo' => '62.5',
            'bordeutero' => '1',
            'ovarioderlong' => '41',
            'idxecografico' => 'Útero en anteversión.',
            'sugerencias' => 'Control en 6 meses.',
        ]));

        $respuesta->assertRedirect($this->urlSeccion($consulta));
        $respuesta->assertSessionHas('estado');

        $fila = EcoPelvico::where('reg_medico', $medico->reg_medico)
            ->where('numhistoria', 1001)->where('nroconsulta', 1)->first();

        $this->assertNotNull($fila);
        // La fecha la propone hoy, como el escritorio al crear la fila.
        $this->assertSame($this->hoy->toDateString(), $fila->fecha);
        $this->assertSame('2026-06-01', $fila->fur);
        $this->assertSame('1 cesárea', $fila->PARA);
        $this->assertSame(2, $fila->gesta);
        $this->assertSame(62.5, $fila->cuerpo);
        $this->assertSame(41.0, $fila->ovarioderlong);
        $this->assertSame('Útero en anteversión.', $fila->idxecografico);

        // El rastro: el `created` de la fila (la creación se anota sola, como en hospitalización) con
        // `source = web`.
        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'eco_pelvico',
            'record_id'  => $fila->id,
            'operation'  => 'created',
            'source'     => 'web',
        ]);
    }

    public function test_guardar_corrige_solo_lo_que_cambio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $fila = EcoPelvico::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => 1001,
            'nroconsulta' => 1,
            'fecha'       => $this->hoy->toDateString(),
            'cuerpo'      => 60,
            'espesor'     => 8,
        ]);

        $antes = $fila->updated_at;

        // Un minuto después, como el segundo guardado de una consulta real (el test tiene el reloj
        // congelado: sin moverlo, el `updated_at` nuevo sería el mismo).
        Carbon::setTestNow($this->hoy->copy()->addMinute());

        // El formulario manda todo: lo único distinto es `espesor`.
        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), $this->formulario([
            'cuerpo' => '60',
            'espesor' => '9',
        ]));

        $respuesta->assertSessionHas('estado');

        $this->assertSame(60.0, $fila->fresh()->cuerpo);
        $this->assertSame(9.0, $fila->fresh()->espesor);

        $this->assertSame(1, SyncChange::where('table_name', 'eco_pelvico')->where('column_name', 'espesor')->count());
        $this->assertSame(0, SyncChange::where('table_name', 'eco_pelvico')->where('column_name', 'cuerpo')->count());
        // El `updated_at` se mueve: es lo que mira el delta del teléfono.
        $this->assertTrue($fila->fresh()->updated_at->greaterThan($antes));
    }

    public function test_un_eco_sin_datos_no_se_guarda(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), $this->formulario());

        $respuesta->assertSessionHas('error');
        $this->assertSame(0, EcoPelvico::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_una_columna_con_edicion_mas_nueva_no_se_pisa(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $fila = EcoPelvico::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => 1001,
            'nroconsulta' => 1,
            'fecha'       => $this->hoy->toDateString(),
            'cuerpo'      => 60,
        ]);

        // Otro equipo (el escritorio) editó `cuerpo` un minuto después: gana esa.
        SyncChange::create([
            'reg_medico'  => $medico->reg_medico,
            'table_name'  => 'eco_pelvico',
            'record_id'   => $fila->id,
            'operation'   => 'updated',
            'column_name' => 'cuerpo',
            'value'       => '60',
            'occurred_at' => $this->hoy->copy()->addMinute(),
            'source'      => 'desktop',
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), $this->formulario([
            'cuerpo' => '72',
            'espesor' => '7',
        ]));

        // La columna bloqueada no se pisa y la pantalla lo dice con su rótulo…
        $respuesta->assertSessionHas('error');
        $this->assertSame(60.0, $fila->fresh()->cuerpo);
        // …y la otra sí se escribe: una columna con edición más nueva no bloquea a las demás.
        $this->assertSame(7.0, $fila->fresh()->espesor);
    }

    public function test_las_columnas_que_la_pantalla_no_toca_quedan_como_estan(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $fila = EcoPelvico::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => 1001,
            'nroconsulta' => 1,
            'fecha'       => $this->hoy->toDateString(),
            'centrar'     => '1',
            'cuello'      => 30,
            'anterocuello' => 12,
            'formautero'  => 'En retroversión',
            'puro_texto'  => 'INFORME EN TEXTO LIBRE DEL ESCRITORIO',
        ]);

        // El modo texto libre del escritorio se muestra y no se edita.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta))
            ->assertOk()
            ->assertSee('INFORME EN TEXTO LIBRE DEL ESCRITORIO')
            ->assertSee('Informe en texto libre del escritorio');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), $this->formulario([
            'cuerpo' => '58',
        ]))->assertSessionHas('estado');

        $fresca = $fila->fresh();
        $this->assertSame('1', $fresca->centrar);
        $this->assertSame(30.0, $fresca->cuello);
        $this->assertSame(12.0, $fresca->anterocuello);
        $this->assertSame('En retroversión', $fresca->formautero);
        $this->assertSame('INFORME EN TEXTO LIBRE DEL ESCRITORIO', $fresca->puro_texto);
    }

    public function test_un_codigo_desconocido_del_escritorio_se_muestra_y_no_se_cambia(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        EcoPelvico::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => 1001,
            'nroconsulta' => 1,
            'fecha'       => $this->hoy->toDateString(),
            'posicionavf' => 'Z',
        ]);

        // El desplegable ofrece el código guardado aunque no esté en la lista del DataWindow.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta))
            ->assertOk()
            ->assertSee('Z (del escritorio)');

        // Y si el médico guarda sin tocar ese campo, el código sigue ahí.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), $this->formulario([
            'posicionavf' => 'Z',
            'cuerpo' => '60',
        ]))->assertSessionHas('estado');

        $this->assertSame('Z', EcoPelvico::where('numhistoria', 1001)->first()->posicionavf);
    }

    // -------------------------------------------------------------------------------- La FUR del prenatal

    public function test_la_fur_se_trae_del_control_prenatal_y_se_guarda(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        DB::table('pre_natal_observaciones')->insert([
            'reg_medico' => $medico->reg_medico,
            'historia'   => 1001,
            'fur'        => '2026-05-20',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // El formulario llega con la FUR del control prenatal y dice de dónde salió.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta))
            ->assertOk()
            ->assertSee('value="2026-05-20"', false)
            ->assertSee('La trajimos del control prenatal de esta historia.');

        // Y al guardar queda escrita, como cuando el escritorio entra a la pestaña.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), $this->formulario([
            'fur' => '2026-05-20',
            'cuerpo' => '60',
        ]))->assertSessionHas('estado');

        $this->assertSame('2026-05-20', EcoPelvico::where('numhistoria', 1001)->first()->fur);
    }

    public function test_sin_control_prenatal_la_fur_queda_vacia(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta))
            ->assertOk()
            ->assertDontSee('La trajimos del control prenatal de esta historia.');
    }

    // ------------------------------------------------------------------------------------ Permisos

    public function test_un_tipo_de_estudio_que_no_existe_da_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta, 'inventado'), $this->formulario(['cuerpo' => '60']))
            ->assertNotFound();

        $this->assertSame(0, EcoPelvico::where('reg_medico', $medico->reg_medico)->count());

        // Y un tipo que no está en el catálogo tampoco abre sección: se cae al que existe.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta, 'inventado'))
            ->assertOk()
            ->assertSee('Eco ginecológico');
    }

    public function test_la_secretaria_no_entra_a_la_seccion_ni_puede_guardar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $secretaria = $this->secretariaConAcceso($medico);

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->get($this->urlSeccion($consulta))->assertForbidden();

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->post($this->urlGuardar($consulta), $this->formulario(['cuerpo' => '60']))->assertForbidden();

        $this->assertSame(0, EcoPelvico::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_un_invitado_va_al_login(): void
    {
        [$user, $medico] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->post($this->urlGuardar($consulta), $this->formulario(['cuerpo' => '60']))
            ->assertRedirect(route('login'));
    }

    public function test_la_consulta_de_otro_medico_da_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        [$otroUser, $otroMedico, $otraGineco] = $this->medicoConAcceso();

        $this->conContexto($otroUser, $otroMedico, $otraGineco)->actingAs($otroUser)
            ->get($this->urlSeccion($consulta))->assertNotFound();

        $this->conContexto($otroUser, $otroMedico, $otraGineco)->actingAs($otroUser)
            ->post($this->urlGuardar($consulta), $this->formulario(['cuerpo' => '60']))->assertNotFound();
    }

    // ------------------------------------------------ El eco obstétrico (WEB-6.2), el otro tipo del ítem 5

    public function test_el_tipo_obstetrico_abre_con_su_formulario_y_sus_bloques(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta, 'obstetrico'));

        $respuesta->assertOk();

        // Los dos tipos del ítem 5 se ofrecen como enlaces.
        $respuesta->assertSee('Eco ginecológico')->assertSee('Eco obstétrico (1.er trimestre)');

        // Sus seis bloques y sus rótulos propios (el mapa del DataWindow vivo del 1er trimestre).
        foreach (['El embarazo', 'El útero y los ovarios', 'El saco y la vitalidad',
            'Las medidas y los marcadores', 'El riesgo del embarazo temprano', 'La conclusión'] as $bloque) {
            $respuesta->assertSee($bloque);
        }
        foreach (['Saco gestacional', 'LCC', 'TN', 'Ductus venoso', 'Huevo anembrionado',
            'Embarazo ectópico', 'Conclusión', 'Observaciones'] as $rotulo) {
            $respuesta->assertSee($rotulo);
        }
        // La opción del desplegable muestra el texto y guarda el código.
        $respuesta->assertSee('No visible');
    }

    public function test_guardar_el_eco_obstetrico_desde_la_seccion_y_su_hoja_no_se_ofrece(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta, 'obstetrico'), [
                'fur' => '2026-06-01', 'gesta' => '2', 'lcc' => '45', 'tn' => '1.8',
                'actividad_cardiaca' => 'S', 'saco_gestacional' => '1',
                'dc_venoso' => 'N', 'observacion' => 'Embarazo temprano normal.',
            ]);

        $respuesta->assertRedirect();
        $respuesta->assertSessionHas('estado');

        $fila = \App\Models\EcoObstetrico::where('reg_medico', $medico->reg_medico)->where('historia', 1001)->first();

        $this->assertNotNull($fila);
        $this->assertSame('45', (string) $fila->lcc);
        $this->assertSame('1', (string) $fila->saco_gestacional);
        $this->assertSame('Embarazo temprano normal.', $fila->observacion);
        // La FUR es la que se tecleó (el escritorio no la copia del control prenatal).
        $this->assertSame('2026-06-01', (string) $fila->fur);

        $this->assertDatabaseHas('sync_changes', [
            'table_name' => 'eco_obstetrico', 'record_id' => $fila->id, 'operation' => 'created', 'source' => 'web',
        ]);

        // La vuelta cae en el mismo tipo, y con el estudio cargado ya se ofrece su hoja.
        $pantalla = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta, 'obstetrico'));

        $pantalla->assertOk()
            ->assertSee('Eco obstétrico guardado.')
            ->assertSee('Imprimir el informe')
            // «Visualizar» (WEB-11.24): el botón y el diálogo de la vista previa, comunes a todas las solicitudes, récipes y ecos.
            ->assertSee('data-visualizar="', false)->assertSee('id="dialogo-visualizar"', false)
            ->assertSee('documento=eco-obstetrico', false);

        // Y la hoja sale con su título, la FUR, la EG y lo que se cargó (con su FPP, que suma 283).
        $hoja = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get(route(
            'clinica.consulta.documentos.imprimir',
            [$consulta->id, 'documento' => \App\Clinica\Impresion\DocumentoImpreso::ECO_OBSTETRICO],
        ));

        $hoja->assertOk()
            ->assertSee('ECOSONOGRAFÍA OBSTÉTRICA · PRIMER TRIMESTRE')
            ->assertSee('FUR:')->assertSee('01/06/2026')
            ->assertSee('LCC:')->assertSee('45')
            // El informe es el formulario lleno (WEB-11.13): los bloques sin datos también salen, con sus casilleros en blanco.
            ->assertSee('El riesgo del embarazo temprano')
            ->assertSee('Conclusión:');
    }

    public function test_un_eco_obstetrico_sin_datos_no_se_guarda(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta, 'obstetrico'), ['fur' => '2026-06-01', 'gesta' => '1'])
            ->assertSessionHas('error');

        $this->assertSame(0, \App\Models\EcoObstetrico::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_el_segundo_trimestre_es_un_estudio_aparte_del_tercero(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $web = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        $web->get($this->urlSeccion($consulta, 'segundo'))->assertOk()
            ->assertSee('Eco obstétrico (2.º trimestre)')->assertSee('Datos biométricos');

        $web->post($this->urlGuardar($consulta, 'segundo'), ['bio_peso_fetal' => '1100', 'conclusion' => 'Segundo.'])
            ->assertSessionHas('estado');

        // Vive en la tabla hermana y no pisa al tercero.
        $this->assertSame(1, DB::table('eco_obstetrico_tercer_2')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(0, DB::table('eco_obstetrico_tercer')->where('reg_medico', $medico->reg_medico)->count());

        $web->post($this->urlGuardar($consulta, 'tercero'), ['bio_peso_fetal' => '3200', 'conclusion' => 'Tercero.'])
            ->assertSessionHas('estado');
        $this->assertSame('1100', (string) DB::table('eco_obstetrico_tercer_2')->where('reg_medico', $medico->reg_medico)->value('bio_peso_fetal'));
        $this->assertSame('3200', (string) DB::table('eco_obstetrico_tercer')->where('reg_medico', $medico->reg_medico)->value('bio_peso_fetal'));

        // Cada uno ofrece su propia hoja, con el trimestre en el título.
        $hoja = $web->get(route('clinica.consulta.documentos.imprimir', [$consulta->id, 'documento' => 'eco-obstetrico-segundo']));
        $hoja->assertOk()->assertSee('SEGUNDO TRIMESTRE');
    }

    public function test_en_ginecologia_el_eco_abdominal_no_se_ofrece_como_chip(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $pantalla = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta, 'ginecologico'));

        $pantalla->assertOk()->assertSee('Eco obstétrico (2.º trimestre)')->assertDontSee('Eco abdominal');
        // Pero la dirección directa sigue abriendo (lo usa pediatría).
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta, 'abdominal'))->assertOk()->assertSee('Eco abdominal');
    }

    public function test_en_pediatria_el_panel_no_trae_el_embarazo_y_el_estudio_es_solo_la_eco_abdominal(): void
    {
        [$user, $medico] = $this->medicoConAcceso();
        $pediatria = Specialty::firstOrCreate(['slug' => 'pediatria'], ['name' => 'Pediatría']);
        $medico->specialties()->attach($pediatria->id);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $pediatra = $this->conContexto($user, $medico, $pediatria)->actingAs($user);

        // El panel: sin «Control de embarazo» y sin el chip de ningún eco ginecológico.
        $pediatra->get(route('clinica.consulta', $consulta->id))->assertOk()
            ->assertDontSee('Control de embarazo')->assertSee('Estudio ecográfico');
        $pediatra->get($this->urlSeccion($consulta, 'ginecologico'))->assertOk()->assertSee('Eco abdominal');
        $this->conContexto($user, $medico, $pediatria)->actingAs($user)->get(route('clinica.consulta', [$consulta->id, 'seccion' => 'estudio']))
            ->assertOk()->assertSee('Eco abdominal')->assertDontSee('Eco ginecológico')->assertDontSee('Eco obstétrico (2.º trimestre)');
    }

    public function test_el_tipo_tercero_abre_con_sus_bloques_y_guarda_en_su_tabla(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        // Los TRES tipos del ítem 5 que existen hoy, y los bloques propios del 2.º/3.er trimestre.
        $pantalla = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta, 'tercero'));

        $pantalla->assertOk()
            ->assertSee('Eco obstétrico (3.er trimestre)')
            ->assertSee('Datos biométricos')->assertSee('Datos anatómicos')
            ->assertSee('Placenta y líquido')->assertSee('El feto')
            ->assertSee('Peso F.')->assertSee('ILA (Phelan)');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta, 'tercero'), [
                'bio_peso_fetal' => '3200', 'fun_dp_grado' => 'II', 'ana_corazon' => 'N',
                'conclusion' => 'Embarazo a término.',
            ])
            ->assertSessionHas('estado');

        $fila = \App\Models\EcoObstetricoTercer::where('reg_medico', $medico->reg_medico)->where('consulta', 1)->first();

        $this->assertNotNull($fila);
        $this->assertSame('3200', (string) $fila->bio_peso_fetal);
        $this->assertSame('II', $fila->fun_dp_grado);
        // Es otro estudio: no toca la tabla del primer trimestre.
        $this->assertSame(0, \App\Models\EcoObstetrico::where('historia', 1001)->count());

        // Y con el estudio cargado la pantalla ya ofrece su hoja, que sale con el título del trimestre.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta, 'tercero'))
            ->assertOk()->assertSee('Imprimir el informe')
            ->assertSee('documento=eco-obstetrico-tercero', false);

        $hoja = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get(route(
            'clinica.consulta.documentos.imprimir',
            [$consulta->id, 'documento' => \App\Clinica\Impresion\DocumentoImpreso::ECO_OBSTETRICO_TERCERO],
        ));

        $hoja->assertOk()
            ->assertSee('ECOSONOGRAFÍA OBSTÉTRICA · TERCER TRIMESTRE')
            ->assertSee('Datos biométricos')
            ->assertSee('Peso F.:')->assertSee('3200')
            ->assertSee('Conclusiones:')->assertSee('Embarazo a término.');
    }
}