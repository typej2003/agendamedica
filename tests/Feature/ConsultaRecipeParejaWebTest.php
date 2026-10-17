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
 * **El récipe de la pareja de una consulta** (WEB-5.1b, dentro del ítem 10 del panel del legado).
 *
 * Lo que estos casos fijan:
 *
 *  - **Uno por consulta**, sin cabecera ni número (`recipes_pareja`): crear con la cédula y el nombre de la
 *    pareja repetidos en **todas** las filas, y que la pantalla los lea de la primera.
 *  - **Los datos de la pareja son obligatorios y texto libre**: cédula (tope 10) y nombre (tope 200), sin crear
 *    ni enlazar ningún paciente, y el récipe **no aparece en la historia del paciente**.
 *  - **Editar en el lugar** (cantidad, indicaciones, medicamentos y los datos de la pareja, que se reescriben en
 *    todas las filas, sin tocar el vademécum) y **eliminar** el récipe entero.
 *  - **El rastro en `sync_changes`** (`source = web`): `created` por fila, `updated` por columna, `deleted` por
 *    fila y el last-write-wins del sync.
 *  - Que el **récipe del paciente (WEB-5.1)** siga igual y no se mezcle con el de la pareja.
 *  - El **404** de la consulta de otro consultorio, el **403** de la secretaría y el invitado al login.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class ConsultaRecipeParejaWebTest extends TestCase
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
        $regMedico = 'pareja-test-' . uniqid();

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'pareja-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Pareja',
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
            'email'                => 'pareja-secre-' . uniqid() . '@example.com',
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

    private function medicamento(Medico $medico, string $codigo, string $comercial, ?string $generico = null, ?string $presentacion = null, ?string $uso = null): void
    {
        DB::table('vademecum')->insert([
            'reg_medico'      => $medico->reg_medico,
            'codemedicina'    => $codigo,
            'nombrecomercial' => $comercial,
            'nombregenerico'  => $generico,
            'presentacion'    => $presentacion,
            'uso'             => $uso,
            'created_at'      => '2026-01-01 00:00:00',
            'updated_at'      => '2026-01-01 00:00:00',
        ]);
    }

    private function vademecum(Medico $medico): void
    {
        $this->medicamento($medico, '0001', 'KEFORAL', 'CEFALEXINA', 'Cap. 500 mg', 'Tomar una cápsula cada 6 horas.');
        $this->medicamento($medico, '0002', 'DIFLUCAN G', 'FLUCONAZOL', 'Cap. 150 mg', 'Tomar 1 cápsula los dos.');
        $this->medicamento($medico, '0003', 'ÁCIDO FÓLICO', 'ACIDO FOLICO', 'Tabletas 5 mg', 'Una al día.');
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

    /** Una fila de `recipes_pareja` como la deja el escritorio (con `orden` desde 0 y sin rastro en el sync). */
    private function filaDelEscritorio(Medico $medico, int $historia, int $consulta, string $codigo, int $orden, array $extra = []): int
    {
        return (int) DB::table('recipes_pareja')->insertGetId(array_merge([
            'reg_medico'   => $medico->reg_medico,
            'nrohistoria'  => $historia,
            'nroconsulta'  => $consulta,
            'codemedicina' => $codigo,
            'cantidad'     => 1,
            'indicaciones' => 'Indicación de ' . $codigo,
            'descripcion'  => 'DESC ' . $codigo,
            'orden'        => $orden,
            'cedula'       => '1234567888',
            'nombre'       => 'mariana',
            'created_at'   => '2026-01-01 00:00:00',
            'updated_at'   => '2026-01-01 00:00:00',
        ], $extra));
    }

    private function urlSeccion(Consulta $consulta, $recipe = ConsultaShellVista::RECIPE_PAREJA): string
    {
        $query = ['seccion' => ConsultaShellVista::SECCION_RECIPES];
        if ($recipe !== null) {
            $query['recipe'] = $recipe;
        }

        return route('clinica.consulta', [$consulta->id] + $query);
    }

    private function urlGuardar(Consulta $consulta): string
    {
        return route('clinica.consulta.recipe-pareja.guardar', $consulta->id);
    }

    private function urlEliminar(Consulta $consulta): string
    {
        return route('clinica.consulta.recipe-pareja.eliminar', $consulta->id);
    }

    private function item(string $codigo, $cantidad = 1, ?string $indicaciones = null): array
    {
        return ['codemedicina' => $codigo, 'cantidad' => $cantidad, 'indicaciones' => $indicaciones];
    }

    /** El cuerpo del formulario del editor de la pareja. */
    private function cuerpo(array $items, string $cedula = '12345678', string $nombre = 'Mariana Pérez'): array
    {
        return ['editor' => 1, 'cedula' => $cedula, 'nombre' => $nombre, 'items' => $items];
    }

    /** @return \Illuminate\Support\Collection<int,object> las filas de `recipes_pareja` de la consulta, en el orden de la pantalla */
    private function filasDe(Medico $medico, int $historia = 1001, int $consulta = 1)
    {
        return DB::table('recipes_pareja')->where('reg_medico', $medico->reg_medico)
            ->where('nrohistoria', $historia)->where('nroconsulta', $consulta)
            ->orderBy('orden')->orderBy('id')->get();
    }

    private function trazas(array $ids): \Illuminate\Support\Collection
    {
        return SyncChange::where('table_name', 'recipes_pareja')->whereIn('record_id', $ids ?: [0])->orderBy('id')->get();
    }

    private function medico(User $user, Medico $medico, Specialty $gineco): self
    {
        return $this->conContexto($user, $medico, $gineco)->actingAs($user);
    }

    // ------------------------------------------------------------------------------------- La lectura

    public function test_la_pestana_de_la_pareja_vacia_muestra_el_formulario_sin_eliminar_y_el_panel_sigue_en_nueve(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $panel = $this->medico($user, $medico, $gineco)->get(route('clinica.consulta', $consulta->id));
        $panel->assertOk()->assertSee('Pre-factura', false);

        $respuesta = $this->medico($user, $medico, $gineco)->get($this->urlSeccion($consulta));
        $respuesta->assertOk();
        $respuesta->assertSee('Récipe de la pareja');
        $respuesta->assertSee('Récipes del paciente');
        $respuesta->assertSee('Sin récipe de la pareja');
        $respuesta->assertSee('La pareja todavía no tiene medicamentos');
        $respuesta->assertSee('name="cedula"', false);
        $respuesta->assertSee('name="nombre"', false);
        $respuesta->assertSee('Guardar el récipe de la pareja');
        // La caja de herramientas es la del récipe del paciente.
        $respuesta->assertSee('id="recipe-herramientas"', false);
        $respuesta->assertSee(route('clinica.consulta.recipes.medicamentos', $consulta->id), false);
        // Nada que eliminar todavía.
        // (Guardar y eliminar comparten la URL y cambian el verbo: se mide por el formulario, no por el href.)
        $respuesta->assertDontSee('data-recipe-salir', false);
        $respuesta->assertDontSee('Eliminar el récipe de la pareja');
        $respuesta->assertSee('action="' . $this->urlGuardar($consulta) . '"', false);
    }

    public function test_la_lista_del_paciente_ofrece_las_dos_pestanas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->medico($user, $medico, $gineco)->get($this->urlSeccion($consulta, null));
        $respuesta->assertOk();
        $respuesta->assertSee('Récipes del paciente');
        $respuesta->assertSee('Récipe de la pareja');
        $respuesta->assertSee('recipe=pareja', false);
    }

    public function test_el_editor_de_un_recipe_del_paciente_no_ofrece_las_pestanas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->medico($user, $medico, $gineco)->get($this->urlSeccion($consulta, 'nuevo'));
        $respuesta->assertOk();
        $respuesta->assertDontSee('recipe-pestanas', false);
        $respuesta->assertDontSee('Récipe de la pareja');
        $respuesta->assertSee('Guardar el récipe');
    }

    public function test_la_pantalla_lee_la_pareja_de_la_primera_fila_y_resuelve_los_nombres_por_el_vademecum(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->filaDelEscritorio($medico, 1001, 1, '0003', 0, ['cedula' => '9876543', 'nombre' => 'CARLOS RUIZ']);
        $this->filaDelEscritorio($medico, 1001, 1, '0001', 1, ['cedula' => '9876543', 'nombre' => 'CARLOS RUIZ']);

        $respuesta = $this->medico($user, $medico, $gineco)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();
        $respuesta->assertSee('value="9876543"', false);
        $respuesta->assertSee('value="CARLOS RUIZ"', false);
        $respuesta->assertSeeInOrder(['ÁCIDO FÓLICO/Tabletas 5 mg', 'KEFORAL/Cap. 500 mg']);
        $respuesta->assertSee('2 medicamentos');
        $respuesta->assertSee('data-recipe-salir', false);
        $respuesta->assertSee('Eliminar el récipe de la pareja');
    }

    public function test_el_recipe_de_la_pareja_de_otra_consulta_no_se_mezcla(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->filaDelEscritorio($medico, 1001, 2, '0001', 0, ['nombre' => 'DE OTRA CONSULTA']);
        $this->filaDelEscritorio($medico, 1002, 1, '0002', 0, ['nombre' => 'DE OTRA HISTORIA']);

        $respuesta = $this->medico($user, $medico, $gineco)->get($this->urlSeccion($consulta));

        $respuesta->assertOk()->assertDontSee('DE OTRA CONSULTA')->assertDontSee('DE OTRA HISTORIA');
        $respuesta->assertSee('Sin récipe de la pareja');
    }

    // ------------------------------------------------------------------------------------- Crear

    public function test_crear_escribe_una_fila_por_medicamento_con_la_pareja_en_todas_y_deja_el_created_por_fila(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [$pacienteId, $consulta] = $this->pacienteYConsulta($medico);
        $pacientesAntes = DB::table('pacientes')->count();

        $this->medico($user, $medico, $gineco)
            ->post($this->urlGuardar($consulta), $this->cuerpo([
                $this->item('0003', 1, 'Una al día antes del embarazo'),
                $this->item('0001', 2, null),
            ], ' 12345678 ', ' Mariana Pérez '))
            ->assertRedirect($this->urlSeccion($consulta))
            ->assertSessionHas('estado');

        $filas = $this->filasDe($medico);
        $this->assertCount(2, $filas);
        $this->assertSame(['0003', '0001'], $filas->pluck('codemedicina')->all());
        $this->assertSame([1, 2], $filas->pluck('orden')->map(fn ($o) => (int) $o)->all(), 'orden desde 1, como GuardarRecipe');
        $this->assertSame(['12345678', '12345678'], $filas->pluck('cedula')->all(), 'recortada y repetida en todas las filas');
        $this->assertSame(['Mariana Pérez', 'Mariana Pérez'], $filas->pluck('nombre')->all());
        $this->assertSame('ÁCIDO FÓLICO/Tabletas 5 mg', $filas[0]->descripcion, 'el nombre lo pone el servidor, como el escritorio');
        $this->assertSame('Una al día antes del embarazo', $filas[0]->indicaciones);
        $this->assertSame(2, (int) $filas[1]->cantidad);
        $this->assertNull($filas[1]->indicaciones);
        $this->assertNotNull($filas[0]->created_at);

        // Un `created` por fila, de la web: es lo que las protege del reemplazo de la tabla del escritorio.
        $trazas = $this->trazas($filas->pluck('id')->all());
        $this->assertCount(2, $trazas);
        $this->assertSame(['created', 'created'], $trazas->pluck('operation')->all());
        $this->assertSame(['web', 'web'], $trazas->pluck('source')->all());

        // Texto libre: no se crea ni se enlaza ningún paciente.
        $this->assertSame($pacientesAntes, DB::table('pacientes')->count());
    }

    public function test_el_recipe_de_la_pareja_vuelve_de_la_base_tras_guardar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->medico($user, $medico, $gineco)
            ->post($this->urlGuardar($consulta), $this->cuerpo([$this->item('0002', 3, 'Dosis única')], '7654321', 'Luis Mora'));

        $pantalla = $this->medico($user, $medico, $gineco)->get($this->urlSeccion($consulta));
        $pantalla->assertSee('value="7654321"', false)->assertSee('value="Luis Mora"', false);
        $pantalla->assertSee('DIFLUCAN G/Cap. 150 mg')->assertSee('Dosis única')->assertSee('1 medicamento');
        $pantalla->assertSee('Eliminar el récipe de la pareja');
    }

    public function test_la_cedula_y_el_nombre_son_obligatorios_y_tienen_tope_y_no_se_escribe_nada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $cliente = fn () => $this->medico($user, $medico, $gineco);

        $cliente()->post($this->urlGuardar($consulta), $this->cuerpo([$this->item('0001')], '', 'Mariana'))
            ->assertSessionHasErrors(['cedula' => 'Escribe la cédula de la pareja.']);
        $cliente()->post($this->urlGuardar($consulta), $this->cuerpo([$this->item('0001')], '12345678', ''))
            ->assertSessionHasErrors(['nombre' => 'Escribe el nombre de la pareja.']);
        $cliente()->post($this->urlGuardar($consulta), $this->cuerpo([$this->item('0001')], '12345678901', 'Mariana'))
            ->assertSessionHasErrors(['cedula' => 'La cédula de la pareja admite hasta 10 caracteres.']);
        $cliente()->post($this->urlGuardar($consulta), $this->cuerpo([$this->item('0001')], '12345678', str_repeat('N', 201)))
            ->assertSessionHasErrors(['nombre' => 'El nombre de la pareja admite hasta 200 caracteres.']);
        $cliente()->post($this->urlGuardar($consulta), $this->cuerpo([], '12345678', 'Mariana'))
            ->assertSessionHasErrors(['items' => 'Agrega al menos un medicamento al récipe.']);

        $this->assertSame(0, $this->filasDe($medico)->count());

        // Justo en los topes sí entra.
        $cliente()->post($this->urlGuardar($consulta), $this->cuerpo([$this->item('0001')], '1234567890', str_repeat('N', 200)))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, $this->filasDe($medico)->count());
    }

    public function test_un_error_vuelve_al_editor_de_la_pareja_con_lo_escrito(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        // Sin cédula: el editor vuelve con el nombre y con el medicamento agregado (sin releer la base).
        $this->medico($user, $medico, $gineco)->from($this->urlSeccion($consulta))
            ->post($this->urlGuardar($consulta), $this->cuerpo([$this->item('0001', 2, 'Escrito a mano') + ['nombre' => 'KEFORAL/Cap. 500 mg']], '', 'Mariana Pérez'))
            ->assertRedirect($this->urlSeccion($consulta));

        $pantalla = $this->medico($user, $medico, $gineco)->get($this->urlSeccion($consulta));
        $pantalla->assertOk()->assertSee('Escribe la cédula de la pareja.')
            ->assertSee('value="Mariana Pérez"', false)->assertSee('Escrito a mano')->assertSee('KEFORAL/Cap. 500 mg');
    }

    public function test_un_medicamento_nuevo_tiene_que_estar_en_el_vademecum_o_en_un_tratamiento(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->medico($user, $medico, $gineco)
            ->post($this->urlGuardar($consulta), $this->cuerpo([$this->item('0001'), $this->item('9999')]))
            ->assertSessionHasErrors('items');
        $this->assertSame(0, $this->filasDe($medico)->count());

        // El de un tratamiento del médico sí se acepta aunque el vademécum ya no lo tenga.
        DB::table('recipe_grupo_detalle')->insert([
            'reg_medico' => $medico->reg_medico, 'codigo' => '0001', 'codemedicina' => '8888', 'descripcion' => 'DEL TRATAMIENTO/Tab.',
            'cantidad' => 1, 'orden' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->medico($user, $medico, $gineco)
            ->post($this->urlGuardar($consulta), $this->cuerpo([$this->item('8888')]))->assertSessionHasNoErrors();
        $this->assertSame('DEL TRATAMIENTO/Tab.', $this->filasDe($medico)->first()->descripcion);
    }

    public function test_un_tratamiento_se_guarda_como_un_recipe_de_la_pareja_con_todos_sus_medicamentos(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        DB::table('recipe_grupo')->insert(['reg_medico' => $medico->reg_medico, 'codigo' => '0001', 'tratamiento' => 'Infección de transmisión sexual', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('recipe_grupo_detalle')->insert([
            ['reg_medico' => $medico->reg_medico, 'codigo' => '0001', 'codemedicina' => '0001', 'cantidad' => 2, 'indicaciones' => 'Del tratamiento', 'orden' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['reg_medico' => $medico->reg_medico, 'codigo' => '0001', 'codemedicina' => '0002', 'cantidad' => 1, 'indicaciones' => null, 'orden' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Los buscadores son los mismos del récipe del paciente.
        $json = $this->medico($user, $medico, $gineco)
            ->getJson(route('clinica.consulta.recipes.tratamientos', $consulta->id) . '?q=transmision');
        $json->assertOk();
        $this->assertSame(['0001', '0002'], collect($json->json('tratamientos.0.items'))->pluck('codigo')->all());

        $this->medico($user, $medico, $gineco)->post($this->urlGuardar($consulta), $this->cuerpo([
            $this->item('0001', 2, 'Del tratamiento'),
            $this->item('0002', 1, 'Tomar 1 cápsula los dos.'),
        ]))->assertSessionHasNoErrors();

        $this->assertSame(['0001', '0002'], $this->filasDe($medico)->pluck('codemedicina')->all());
    }

    public function test_el_buscador_de_medicamentos_es_el_del_vademecum_sin_tildes(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $json = $this->medico($user, $medico, $gineco)
            ->getJson(route('clinica.consulta.recipes.medicamentos', $consulta->id) . '?q=acido+folico');

        $json->assertOk();
        $this->assertSame(['0003'], collect($json->json('medicamentos'))->pluck('codigo')->all());
    }

    // ------------------------------------------------------------------------------------- Editar

    public function test_editar_en_el_lugar_reescribe_la_pareja_en_todas_las_filas_y_deja_el_rastro_por_columna(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $a = $this->filaDelEscritorio($medico, 1001, 1, '0001', 0);
        $b = $this->filaDelEscritorio($medico, 1001, 1, '0002', 1);
        $vademecumAntes = DB::table('vademecum')->where('reg_medico', $medico->reg_medico)->orderBy('id')->get()->all();

        $this->medico($user, $medico, $gineco)
            ->post($this->urlGuardar($consulta), $this->cuerpo([
                $this->item('0001', 3, 'Indicación nueva'),
                $this->item('0002', 1, 'Indicación de 0002'),
            ], '55555555', 'NUEVA PAREJA'))
            ->assertRedirect()->assertSessionHas('estado');

        $filas = $this->filasDe($medico);
        $this->assertSame($a, (int) $filas[0]->id, 'se edita en el lugar, la fila no se recrea');
        $this->assertSame(['55555555', '55555555'], $filas->pluck('cedula')->all());
        $this->assertSame(['NUEVA PAREJA', 'NUEVA PAREJA'], $filas->pluck('nombre')->all());
        $this->assertSame(3, (int) $filas[0]->cantidad);
        $this->assertSame('Indicación nueva', $filas[0]->indicaciones);
        $this->assertSame('DESC 0001', $filas[0]->descripcion, 'la descripción de una fila que ya estaba no se toca');
        $this->assertSame([0, 1], $filas->pluck('orden')->map(fn ($o) => (int) $o)->all(), 'las filas del escritorio no se renumeran');
        $this->assertNotSame('2026-01-01 00:00:00', (string) $filas[0]->updated_at, 'cambia updated_at');

        $trazas = $this->trazas([$a, $b])->where('operation', 'updated');
        $this->assertEqualsCanonicalizing(
            ["$a:cantidad", "$a:indicaciones", "$a:cedula", "$a:nombre", "$b:cedula", "$b:nombre"],
            $trazas->map(fn ($t) => $t->record_id . ':' . $t->column_name)->all(),
            'sólo lo que cambió, una traza por columna'
        );
        $this->assertSame(['web'], $trazas->pluck('source')->unique()->values()->all());

        // Editar las indicaciones no toca el vademécum.
        $this->assertEquals($vademecumAntes, DB::table('vademecum')->where('reg_medico', $medico->reg_medico)->orderBy('id')->get()->all());
    }

    public function test_agregar_y_quitar_medicamentos_al_editar_sigue_el_orden_y_deja_el_deleted_y_el_created(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $a = $this->filaDelEscritorio($medico, 1001, 1, '0001', 0);
        $b = $this->filaDelEscritorio($medico, 1001, 1, '0002', 1);

        // Se queda 0001 (sin cambios), se quita 0002 y se agrega 0003.
        $this->medico($user, $medico, $gineco)
            ->post($this->urlGuardar($consulta), $this->cuerpo([
                $this->item('0001', 1, 'Indicación de 0001'),
                $this->item('0003', 1, 'Una al día.'),
            ], '1234567888', 'mariana'))
            ->assertRedirect();

        $filas = $this->filasDe($medico);
        $this->assertSame(['0001', '0003'], $filas->pluck('codemedicina')->all());
        $this->assertSame(2, (int) $filas[1]->orden, 'sigue al mayor orden que había (el 0 y el 1 del escritorio)');
        $this->assertNull(DB::table('recipes_pareja')->find($b));
        $this->assertSame('mariana', $filas[1]->nombre, 'la fila nueva lleva la misma pareja');

        $this->assertSame(['deleted'], $this->trazas([$b])->pluck('operation')->all());
        $this->assertSame(['created'], $this->trazas([(int) $filas[1]->id])->pluck('operation')->all());
        $this->assertSame([], $this->trazas([$a])->all(), 'la fila que no cambió no deja rastro');
    }

    public function test_guardar_sin_cambios_lo_dice_y_no_escribe_rastro(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $a = $this->filaDelEscritorio($medico, 1001, 1, '0001', 0);

        $this->medico($user, $medico, $gineco)
            ->post($this->urlGuardar($consulta), $this->cuerpo([$this->item('0001', 1, 'Indicación de 0001')], '1234567888', 'mariana'))
            ->assertRedirect()->assertSessionHas('estado', 'El récipe de la pareja no tenía cambios.');

        $this->assertSame(0, SyncChange::where('table_name', 'recipes_pareja')->where('record_id', $a)->count());
        $this->assertSame('2026-01-01 00:00:00', (string) DB::table('recipes_pareja')->find($a)->updated_at);
    }

    public function test_una_edicion_mas_nueva_del_sync_no_se_pisa(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $a = $this->filaDelEscritorio($medico, 1001, 1, '0001', 0);
        SyncChange::create([
            'reg_medico' => $medico->reg_medico, 'table_name' => 'recipes_pareja', 'record_id' => $a, 'operation' => 'updated',
            'column_name' => 'cantidad', 'value' => '9', 'occurred_at' => $this->hoy->copy()->addMinutes(5), 'source' => 'mobile',
        ]);

        $this->medico($user, $medico, $gineco)
            ->post($this->urlGuardar($consulta), $this->cuerpo([$this->item('0001', 4, 'Indicación de 0001')], '1234567888', 'mariana'))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame(1, (int) DB::table('recipes_pareja')->find($a)->cantidad, 'la edición más nueva manda');
    }

    // ------------------------------------------------------------------------------------- Eliminar

    public function test_eliminar_borra_todas_las_filas_deja_el_deleted_y_no_toca_lo_demas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $a = $this->filaDelEscritorio($medico, 1001, 1, '0001', 0);
        $b = $this->filaDelEscritorio($medico, 1001, 1, '0002', 1);
        $otraConsulta = $this->filaDelEscritorio($medico, 1001, 2, '0001', 0);
        $otroRegistro = $this->filaDelEscritorio($medico, 1001, 1, '0001', 0, ['reg_medico' => 'otro-registro']);
        // Un récipe del paciente de la misma consulta: no es de la pareja y no se toca.
        $delPaciente = DB::table('recipes')->insertGetId([
            'reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'codemedicina' => '0001',
            'cantidad' => 1, 'recipe' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->medico($user, $medico, $gineco)->delete($this->urlEliminar($consulta))
            ->assertRedirect($this->urlSeccion($consulta))->assertSessionHas('estado');

        $this->assertSame(0, $this->filasDe($medico)->count());
        $this->assertNotNull(DB::table('recipes_pareja')->find($otraConsulta));
        $this->assertNotNull(DB::table('recipes_pareja')->find($otroRegistro));
        $this->assertNotNull(DB::table('recipes')->find($delPaciente));
        $this->assertEqualsCanonicalizing(['deleted', 'deleted'], $this->trazas([$a, $b])->pluck('operation')->all());
        $this->assertSame(['web', 'web'], $this->trazas([$a, $b])->pluck('source')->all());
    }

    public function test_eliminar_una_consulta_sin_recipe_de_la_pareja_da_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->medico($user, $medico, $gineco)->delete($this->urlEliminar($consulta))->assertNotFound();
    }

    // ------------------------------------------------------------------ Separación del récipe del paciente

    public function test_el_recipe_de_la_pareja_no_se_mezcla_con_los_del_paciente_ni_aparece_en_su_historia(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [$pacienteId, $consulta] = $this->pacienteYConsulta($medico);

        // El récipe del paciente, por su camino de siempre (WEB-5.1).
        $this->medico($user, $medico, $gineco)
            ->post(route('clinica.consulta.recipes.crear', $consulta->id), ['editor' => 1, 'items' => [$this->item('0001', 1, 'Del paciente')]])
            ->assertRedirect();
        // Y el de la pareja, con un nombre y una cédula inconfundibles.
        $this->medico($user, $medico, $gineco)
            ->post($this->urlGuardar($consulta), $this->cuerpo([$this->item('0002', 1, 'De la pareja')], '31415926', 'PAREJA INCONFUNDIBLE'))
            ->assertRedirect();

        $this->assertSame(1, DB::table('recipes')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(1, DB::table('recipe_detalle')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(1, $this->filasDe($medico)->count());

        // La lista del paciente sigue con un solo récipe y sin nada de la pareja.
        $lista = $this->medico($user, $medico, $gineco)->get($this->urlSeccion($consulta, null));
        $lista->assertOk()->assertSee('1 récipe')->assertSee('Del paciente')
            ->assertDontSee('PAREJA INCONFUNDIBLE')->assertDontSee('De la pareja')->assertDontSee('31415926');

        // La historia del paciente (consultas y documentos) no sabe de la pareja.
        $historia = $this->medico($user, $medico, $gineco)->get(route('clinica.pacientes.historia', $pacienteId));
        $historia->assertOk()->assertDontSee('PAREJA INCONFUNDIBLE')->assertDontSee('31415926')
            ->assertDontSee('De la pareja')->assertDontSee('DIFLUCAN');
        $imprimir = $this->medico($user, $medico, $gineco)->get(route('clinica.pacientes.historia', $pacienteId) . '/imprimir');
        $imprimir->assertOk()->assertDontSee('PAREJA INCONFUNDIBLE');

        // Y el nombre de la pareja no se volvió paciente.
        $this->assertSame(0, DB::table('pacientes')->where('apellidos', 'like', '%INCONFUNDIBLE%')->orWhere('nombres', 'like', '%INCONFUNDIBLE%')->count());
    }

    public function test_editar_el_recipe_del_paciente_no_toca_el_de_la_pareja(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $fila = $this->filaDelEscritorio($medico, 1001, 1, '0001', 0);
        DB::table('recipes')->insert([
            'reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'codemedicina' => '0001',
            'cantidad' => 1, 'recipe' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->medico($user, $medico, $gineco)
            ->post(route('clinica.consulta.recipes.guardar', [$consulta->id, 1]), ['editor' => 1, 'items' => [$this->item('0001', 7, 'Cambiada')]])
            ->assertRedirect();
        $this->medico($user, $medico, $gineco)->delete(route('clinica.consulta.recipes.eliminar', [$consulta->id, 1]))->assertRedirect();

        $this->assertSame(1, (int) DB::table('recipes_pareja')->find($fila)->cantidad);
        $this->assertSame(0, SyncChange::where('table_name', 'recipes_pareja')->where('record_id', $fila)->count());
    }

    // ------------------------------------------------------------------------------------- Permisos

    public function test_la_secretaria_no_entra_a_la_pestana_ni_a_las_acciones(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->filaDelEscritorio($medico, 1001, 1, '0001', 0);
        $secretaria = $this->secretariaConAcceso($medico);
        $cliente = fn () => $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria);

        $cliente()->get($this->urlSeccion($consulta))->assertForbidden();
        $cliente()->post($this->urlGuardar($consulta), $this->cuerpo([$this->item('0001', 9)]))->assertForbidden();
        $cliente()->delete($this->urlEliminar($consulta))->assertForbidden();

        $this->assertSame(1, $this->filasDe($medico)->count());
        $this->assertSame(1, (int) $this->filasDe($medico)->first()->cantidad, 'no se tocó nada');
    }

    public function test_un_invitado_va_al_login(): void
    {
        [, $medico] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->get($this->urlSeccion($consulta))->assertRedirect(route('login'));
        $this->post($this->urlGuardar($consulta), $this->cuerpo([$this->item('0001')]))->assertRedirect(route('login'));
        $this->delete($this->urlEliminar($consulta))->assertRedirect(route('login'));
    }

    public function test_la_consulta_de_otro_medico_da_404_en_todo_y_no_se_toca(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $propia = $this->filaDelEscritorio($medico, 1001, 1, '0001', 0);

        [$otroUser, $otro, $gineco2] = $this->medicoConAcceso();
        $this->vademecum($otro);
        $cliente = fn () => $this->conContexto($otroUser, $otro, $gineco2)->actingAs($otroUser);

        $cliente()->get($this->urlSeccion($consulta))->assertNotFound();
        $cliente()->post($this->urlGuardar($consulta), $this->cuerpo([$this->item('0001', 9)]))->assertNotFound();
        $cliente()->delete($this->urlEliminar($consulta))->assertNotFound();

        $fila = DB::table('recipes_pareja')->find($propia);
        $this->assertSame(1, (int) $fila->cantidad);
        $this->assertSame('mariana', $fila->nombre);
        $this->assertSame(1, DB::table('recipes_pareja')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(0, DB::table('recipes_pareja')->where('reg_medico', $otro->reg_medico)->count());
    }

    public function test_una_pareja_de_otro_registro_con_la_misma_historia_no_se_toca_ni_se_lee(): void
    {
        // El número de historia es por médico: la historia 1001 de otro `reg_medico` es otro paciente.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $ajena = DB::table('recipes_pareja')->insertGetId([
            'reg_medico' => 'otro-registro', 'nrohistoria' => 1001, 'nroconsulta' => 1, 'codemedicina' => '0001',
            'cantidad' => 1, 'cedula' => '999', 'nombre' => 'PAREJA AJENA', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->medico($user, $medico, $gineco)->get($this->urlSeccion($consulta))->assertOk()->assertDontSee('PAREJA AJENA');
        $this->medico($user, $medico, $gineco)->post($this->urlGuardar($consulta), $this->cuerpo([$this->item('0001')]))->assertRedirect();
        $this->medico($user, $medico, $gineco)->delete($this->urlEliminar($consulta))->assertRedirect();

        $this->assertNotNull(DB::table('recipes_pareja')->find($ajena), 'la fila del otro registro sigue ahí');
        $this->assertSame('PAREJA AJENA', DB::table('recipes_pareja')->find($ajena)->nombre);
    }
}
