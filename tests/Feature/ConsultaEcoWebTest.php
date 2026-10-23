<?php

namespace Tests\Feature;

use App\Clinica\Consulta\EcoGinecologico;
use App\Clinica\Consulta\MapaDelEstudio;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\TiposDeEstudio;
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
        $regMedico = 'eco-test-' . substr(uniqid(), -6);

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'eco-' . substr(uniqid(), -6) . '@example.com',
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
            'email'                => 'eco-secre-' . substr(uniqid(), -6) . '@example.com',
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
        $respuesta->assertSee('Pre-factura');

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

        // Las plantillas de texto del escritorio (revisión de Alexander, 2026-10-17): el tipo `UG` de la ventana
        // del eco pélvico y el área libre que edita la pantalla, más el enlace al catálogo filtrado por ese tipo.
        $respuesta->assertSee('Administrar plantillas')
            ->assertSee('data-tipo="UG"', false)
            ->assertSee('data-destino="#observacion"', false)
            ->assertSee(route('clinica.catalogos.plantillas-texto', ['filtro' => 'UG']), false);
    }

    public function test_el_panel_marca_el_estudio_como_disponible(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get(route('clinica.consulta', $consulta->id));

        $respuesta->assertOk();
        $respuesta->assertSee('Pre-factura');
        $respuesta->assertSee('Estudio ecográfico');
        // El enlace del panel es el de la sección a secas: el tipo por defecto es el ginecológico.
        $respuesta->assertSee(route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_ESTUDIO]), false);
        // El control de embarazo (WEB-6.6) también está disponible, y desde F9 la pre-factura: ningún ítem va deshabilitado.
        $respuesta->assertSee('Control de embarazo');
        $respuesta->assertDontSee('aria-disabled="true"', false);
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
            ->assertSee('Informe en texto libre');

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
            ->assertSee('Z (valor anterior)');

        // Y si el médico guarda sin tocar ese campo, el código sigue ahí.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), $this->formulario([
            'posicionavf' => 'Z',
            'cuerpo' => '60',
        ]))->assertSessionHas('estado');

        $this->assertSame('Z', EcoPelvico::where('numhistoria', 1001)->first()->posicionavf);
    }

    // -------------------------------------------------------------------------------- La FUR del prenatal

    public function test_la_fur_no_se_prellena_del_control_prenatal_aunque_exista(): void
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

        // El estudio nuevo abre con la FUR vacía: el médico la digita.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta))
            ->assertOk()
            ->assertDontSee('value="2026-05-20"', false)
            ->assertDontSee('del control prenatal');

        // Y al escribirla y guardar, queda escrita y se vuelve a mostrar al editar.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->post($this->urlGuardar($consulta), $this->formulario([
            'fur' => '2026-05-20',
            'cuerpo' => '60',
        ]))->assertSessionHas('estado');

        $this->assertSame('2026-05-20', EcoPelvico::where('numhistoria', 1001)->first()->fur);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta))
            ->assertOk()
            ->assertSee('value="2026-05-20"', false);
    }

    public function test_sin_control_prenatal_la_fur_queda_vacia(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta))
            ->assertOk()
            ->assertDontSee('value="2026-05-20"', false);
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

        // Las plantillas del obstétrico del primer trimestre: el tipo `O1` y su columna de conclusión.
        $respuesta->assertSee('Administrar plantillas')
            ->assertSee('data-tipo="O1"', false)
            ->assertSee('data-destino="#observacion"', false)
            ->assertSee(route('clinica.catalogos.plantillas-texto', ['filtro' => 'O1']), false);
    }

    public function test_guardar_el_eco_obstetrico_desde_la_seccion_y_su_hoja_no_se_ofrece(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta, 'obstetrico'), [
                'fur' => '2026-06-01', 'gesta' => '2', 'lcc' => '45', 'tn' => '2',
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

    /**
     * **La EG y la FPP de los ecos obstétricos, en pantalla y sin guardar**: el escritorio las muestra con dos
     * `compute` de su DataWindow y no las guarda (no hay columna); la web las calcula de la FUR con
     * `EdadGestacional` y `consulta.js` las rehace al teclearla.
     */
    public function test_los_ecos_obstetricos_muestran_la_eg_y_la_fpp_calculadas_de_la_fur(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        // El estudio del 1.er trimestre con su FUR: lo único que hace falta para las dos cuentas.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta, 'obstetrico'), ['fur' => '10/02/2026', 'lcc' => '45'])
            ->assertSessionHas('estado');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta, 'obstetrico'));

        $respuesta->assertOk()
            // El bloque y lo que necesita `consulta.js` para recalcularlo en vivo.
            ->assertSee('data-consulta-obstetrico', false)
            ->assertSee('data-hoy="2026-10-06"', false)
            ->assertSee('data-dias-fpp="280"', false)
            ->assertSee('id="obstetrico-eg"', false)
            ->assertSee('id="obstetrico-fpp"', false)
            // Las dos cuentas de la FUR guardada: 238 días vividos y FUR + 280 días.
            ->assertSee('34 semanas y 0 días')
            ->assertSee('17/11/2026');

        // Los tres trimestres usan el mismo parcial, así que los tres muestran el bloque.
        foreach (['segundo', 'tercero'] as $tipo) {
            $this->conContexto($user, $medico, $gineco)->actingAs($user)
                ->get($this->urlSeccion($consulta, $tipo))
                ->assertOk()
                ->assertSee('id="obstetrico-eg"', false)
                ->assertSee('id="obstetrico-fpp"', false);
        }
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

    /**
     * **El formato del escritorio** (las `editmask` del DataWindow): `lcc`, `dbp`, `tn` y `sa_vit` son
     * enteros (máscaras `###`/`##`) y las medidas del útero y los ovarios son decimales (`###.00`). La web
     * lo impone en el `<input>` y el servidor lo vuelve a validar: antes eran texto libre y `25 mm` llegaba
     * al `INSERT` de una columna `INT`.
     */
    public function test_las_medidas_del_eco_obstetrico_tienen_el_formato_del_escritorio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $web = $this->conContexto($user, $medico, $gineco)->actingAs($user);

        // Texto y decimal en un campo entero: el escritorio no lo dejaba escribir.
        $web->post($this->urlGuardar($consulta, 'obstetrico'), ['fur' => '2026-06-01', 'gesta' => '1', 'lcc' => '25 mm'])
            ->assertSessionHasErrors('lcc');
        $web->post($this->urlGuardar($consulta, 'obstetrico'), ['fur' => '2026-06-01', 'gesta' => '1', 'lcc' => '12.5'])
            ->assertSessionHasErrors('lcc');

        $this->assertSame(0, \App\Models\EcoObstetrico::where('reg_medico', $medico->reg_medico)->count());

        // Con el formato correcto se guarda, y las medidas decimales conservan su decimal.
        $web->post($this->urlGuardar($consulta, 'obstetrico'), [
            'fur' => '2026-06-01', 'gesta' => '1', 'lcc' => '25', 'dbp' => '30', 'tn' => '2', 'sa_vit' => '5',
            'cuerpo' => '62.5', 'transverso' => '45', 'ovarioderlong' => '41.5', 'observacion' => 'Con medidas.',
        ])->assertSessionHasNoErrors();

        $fila = \App\Models\EcoObstetrico::where('reg_medico', $medico->reg_medico)->first();
        $this->assertNotNull($fila);
        $this->assertSame('25', (string) $fila->lcc);
        $this->assertSame(62.5, (float) $fila->cuerpo);
        $this->assertSame(41.5, (float) $fila->ovarioderlong);
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
        // Pero la dirección directa sigue abriendo (es lo que el escritorio hace con su botón oculto).
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta, 'abdominal'))->assertOk()->assertSee('Eco abdominal');
    }

    public function test_en_pediatria_el_panel_no_trae_el_embarazo_ni_el_estudio_ecografico(): void
    {
        [$user, $medico] = $this->medicoConAcceso();
        $pediatria = Specialty::firstOrCreate(['slug' => 'pediatria'], ['name' => 'Pediatría']);
        $medico->specialties()->attach($pediatria->id);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $pediatra = $this->conContexto($user, $medico, $pediatria)->actingAs($user);

        // El panel: sin «Control de embarazo» y sin «Estudio ecográfico» (Alexander, 2026-10-19: en pediatría no
        // va el estudio; la eco abdominal de su escritorio es un procedimiento, no este ítem).
        $pediatra->get(route('clinica.consulta', $consulta->id))->assertOk()
            ->assertDontSee('Control de embarazo')->assertDontSee('Estudio ecográfico');

        // La dirección directa ya no abre la sección: no está en el catálogo de la especialidad.
        $pediatra->get(route('clinica.consulta', [$consulta->id, 'seccion' => 'estudio']))->assertOk()
            ->assertSee('Elige una sección en el menú')->assertDontSee('Eco abdominal');
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

    // ------------------------------------------------------- O-13: la fecha del estudio abre con hoy

    /**
     * **Los ocho tipos de estudio abren su fecha con hoy del consultorio** (O-13), sin fila guardada.
     *
     * El `setUp` deja el reloj en `2026-10-06 09:00` UTC y la consulta es de ese día, así que "hoy" y la
     * fecha de la consulta coinciden: lo que se prueba acá es que el campo **no llega vacío**.
     */
    public function test_la_fecha_del_estudio_abre_con_hoy_en_todos_los_tipos(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        foreach (TiposDeEstudio::claves() as $tipo) {
            $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta, $tipo));

            $respuesta->assertOk();
            $this->assertMatchesRegularExpression(
                '/<input type="date"[^>]*name="fecha"[^>]*value="' . preg_quote($this->hoy->toDateString(), '/') . '"/s',
                $respuesta->getContent(),
                'El estudio ' . $tipo . ' no abrió su fecha con hoy.',
            );
        }
    }

    /**
     * **La fecha es la del consultorio, no la del servidor** (trampa 59): a las 20:30 de Caracas el reloj UTC
     * ya está en el día siguiente y `now()` daría otro día. `FechaDelConsultorio::hoy()` da el 6.
     */
    public function test_la_fecha_del_estudio_es_la_del_consultorio_a_las_2030_de_caracas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        // 20:30 del 6 de octubre en Caracas (UTC-4) = 00:30 del 7 en UTC.
        Carbon::setTestNow(Carbon::parse('2026-10-07 00:30:00'));

        $contenido = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="fecha"[^>]*value="2026-10-06"/s', $contenido);
        $this->assertDoesNotMatchRegularExpression('/name="fecha"[^>]*value="2026-10-07"/s', $contenido);
    }

    /** Una fecha ya guardada manda: el default es sólo para el campo vacío (O-13). */
    public function test_la_fecha_guardada_no_se_pisa_con_la_de_hoy(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        EcoPelvico::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => 1001,
            'nroconsulta' => 1,
            'fecha'       => '2026-01-15',
            'cuerpo'      => 60,
        ]);

        $contenido = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="fecha"[^>]*value="2026-01-15"/s', $contenido);
        $this->assertDoesNotMatchRegularExpression('/name="fecha"[^>]*value="' . $this->hoy->toDateString() . '"/s', $contenido);
    }

    // ------------------------------------- O-15: el `-1` del legado es «sin dato» y los mensajes en español

    /** El `-1` del escritorio se lee vacío, no se pinta; y el incremento arranca en 0. */
    public function test_el_menos_uno_del_legado_se_lee_vacio_y_el_incremento_empieza_en_cero(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        EcoPelvico::create([
            'reg_medico'   => $medico->reg_medico,
            'numhistoria'  => 1001,
            'nroconsulta'  => 1,
            'fecha'        => $this->hoy->toDateString(),
            'cuerpo'       => -1,
            'ovarioderap'  => -1,
            'volumen_der'  => -1,
        ]);

        $contenido = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta))->assertOk()->getContent();

        // Los tres campos del marcador se pintan vacíos y el `-1` no aparece como valor de ningún control.
        foreach (['cuerpo', 'ovarioderap', 'volumen_der'] as $columna) {
            $this->assertMatchesRegularExpression(
                '/id="' . $columna . '"[^>]*value=""/s',
                $contenido,
                'El campo ' . $columna . ' con -1 no se pintó vacío.',
            );
        }
        $this->assertStringNotContainsString('value="-1"', $contenido);

        // El incremento empieza en 0: el control numérico declara el mismo piso que valida el servidor.
        $this->assertMatchesRegularExpression('/min="0"[^>]*id="cuerpo"/s', $contenido);
        $this->assertMatchesRegularExpression('/min="0"[^>]*id="ovarioderap"/s', $contenido);
    }

    /**
     * Guardar el estudio con el marcador **escribe «sin dato»**: lo guardado no se migra, pero al guardar se
     * escribe `NULL` (O-15).
     */
    public function test_guardar_un_estudio_con_el_marcador_escribe_sin_dato(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $fila = EcoPelvico::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => 1001,
            'nroconsulta' => 1,
            'fecha'       => $this->hoy->toDateString(),
            'cuerpo'      => -1,
            'ovarioderap' => -1,
        ]);

        // El navegador manda los campos como se ven: el del marcador va vacío.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta), $this->formulario([
                'observacion' => 'Sin particularidades.',
            ]))
            ->assertSessionHas('estado');

        $this->assertNull($fila->fresh()->cuerpo);
        $this->assertNull($fila->fresh()->ovarioderap);
        $this->assertSame('Sin particularidades.', $fila->fresh()->observacion);
    }

    /** Un número negativo da **su mensaje en español, con el rótulo de la pantalla** (O-15). */
    public function test_un_numero_negativo_da_su_mensaje_en_espanol_con_el_rotulo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta), $this->formulario([
                'ovarioderap' => '-1',
                'cuerpo'      => '60',
            ]));

        $respuesta->assertSessionHasErrors([
            'ovarioderap' => 'El campo transverso del ovario derecho no puede ser negativo (el mínimo es 0).',
        ]);
    }

    /** Una fecha imposible da su mensaje en español con el rótulo, y no se guarda nada (O-15). */
    public function test_una_fecha_imposible_da_su_mensaje_en_espanol_con_el_rotulo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        // La FUR del obstétrico se **teclea** `dd/mm/aaaa` (O-18): el 32/13 no existe y el mensaje dice cómo
        // se escribe, con el rótulo de la pantalla.
        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta, 'obstetrico'), ['fur' => '32/13/2026']);

        $errores = $respuesta->assertSessionHasErrors('fur')->baseResponse->getSession()->get('errors');
        $mensaje = $errores->first('fur');

        $this->assertStringContainsString('FUR', $mensaje);
        $this->assertStringContainsString('no es una fecha válida', $mensaje);
        $this->assertStringContainsString('dd/mm/aaaa', $mensaje);
        $this->assertStringNotContainsString('The ', $mensaje);
    }

    /**
     * **Ningún campo numérico de ningún estudio tiene su mensaje en inglés** (O-15): se recorre el mapa del
     * dato de los ocho tipos y se manda el `-1` del marcador en cada primer campo numérico.
     */
    public function test_ningun_campo_numerico_de_los_ecos_tiene_su_mensaje_en_ingles(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $conNumericos = 0;

        foreach (TiposDeEstudio::claves() as $tipo) {
            $numerico = null;
            foreach (MapaDelEstudio::campos($tipo) as $campo) {
                if (($campo['tipo'] ?? 'texto') === 'numero') {
                    $numerico = $campo['columna'];
                    break;
                }
            }

            // Los estudios que declaran sus medidas como texto no pasan por las reglas numéricas: el `-1`
            // igual se lee vacío (lo hace `EstudioEcografico::limpiar`), pero no hay mensaje que revisar.
            if ($numerico === null) {
                continue;
            }

            $conNumericos++;

            $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
                ->post($this->urlGuardar($consulta, $tipo), [$numerico => '-1']);

            $errores = $respuesta->baseResponse->getSession()->get('errors');
            $this->assertNotNull($errores, 'El estudio ' . $tipo . ' no rechazó el -1.');
            $mensaje = (string) $errores->first($numerico);

            $this->assertStringContainsString('no puede ser negativo', $mensaje, 'Mensaje raro en ' . $tipo . ': ' . $mensaje);
            $this->assertStringNotContainsString('The ', $mensaje, 'Mensaje en inglés en ' . $tipo . ': ' . $mensaje);
        }

        $this->assertGreaterThanOrEqual(3, $conNumericos, 'Se esperaban los estudios con medidas numéricas.');
    }
}