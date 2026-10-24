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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * **Los récipes de una consulta** (WEB-5.1, ítem 10 del panel del legado).
 *
 * Lo que estos casos fijan:
 *
 *  - **La agrupación y el número**: `nrohistoria` + `nroconsulta` + `recipe`, con el `NULL` del escritorio
 *    como el récipe 1, y **la misma numeración en la web y en el móvil** (los dos pasan por `GuardarRecipe`).
 *  - **Crear** (cabecera + una fila por medicamento, con la forma que escribe el móvil), **editar en el
 *    lugar** (cantidad, indicaciones, medicamentos y fecha de emisión; sin renumerar las filas del
 *    escritorio y **sin tocar el vademécum**) y **eliminar** el récipe entero.
 *  - **El rastro en `sync_changes`** (`source = web`): `updated` por columna, `deleted` por fila (que es lo
 *    que el pull del móvil usa para soltarlas) y el `created` de la cabecera (la convención heredada del
 *    móvil), más el last-write-wins del sync.
 *  - El **vademécum** (búsqueda por comercial o genérico sin tildes), los **tratamientos** (con sus
 *    medicamentos) y el **alta del medicamento que falta**.
 *  - El **404** de la consulta de otro consultorio, el **403** de la secretaría y el invitado al login.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class ConsultaRecipesWebTest extends TestCase
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
        $regMedico = 'recipes-test-' . substr(uniqid(), -6);

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'recipes-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Recipes',
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
            'email'                => 'recipes-secre' . substr(uniqid(), -6) . '@example.com',
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

    /** Un medicamento del vademécum del médico (`vademecum`). */
    private function medicamento(
        Medico $medico,
        string $codigo,
        string $comercial,
        ?string $generico = null,
        ?string $presentacion = null,
        ?string $uso = null,
    ): void {
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

    /** El vademécum chico que usan casi todos los casos. */
    private function vademecum(Medico $medico): void
    {
        $this->medicamento($medico, '0001', 'KEFORAL', 'CEFALEXINA', 'Cap. 500 mg', 'Tomar una cápsula cada 6 horas.');
        $this->medicamento($medico, '0002', 'DIFLUCAN G', 'FLUCONAZOL', 'Cap. 150 mg', 'Tomar 1 cápsula los dos.');
        $this->medicamento($medico, '0003', 'ÁCIDO FÓLICO', 'ACIDO FOLICO', 'Tabletas 5 mg', 'Una al día.');
    }

    /**
     * Una fila de `recipes` como la deja el escritorio o el móvil. `recipe = null` es el récipe único del
     * escritorio.
     *
     * @param  array<string,mixed>  $extra
     */
    private function fila(Medico $medico, int $historia, int $consulta, string $codigo, ?int $recipe, array $extra = []): int
    {
        return (int) DB::table('recipes')->insertGetId(array_merge([
            'reg_medico'   => $medico->reg_medico,
            'nrohistoria'  => $historia,
            'nroconsulta'  => $consulta,
            'codemedicina' => $codigo,
            'cantidad'     => 1,
            'indicaciones' => 'Indicación de ' . $codigo,
            'orden'        => null,
            'descripcion'  => null,
            'fecha'        => null,
            'recipe'       => $recipe,
            'created_at'   => '2026-01-01 00:00:00',
            'updated_at'   => '2026-01-01 00:00:00',
        ], $extra));
    }

    private function cabecera(Medico $medico, int $historia, int $consulta, int $recipe, string $emision = '2026-10-07'): int
    {
        return (int) DB::table('recipe_detalle')->insertGetId([
            'reg_medico'  => $medico->reg_medico,
            'nrohistoria' => $historia,
            'nroconsulta' => $consulta,
            'recipe'      => $recipe,
            'fe_emision'  => $emision,
            'fe_vence'    => 30,
            'created_at'  => '2026-01-01 00:00:00',
            'updated_at'  => '2026-01-01 00:00:00',
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

    private function urlSeccion(Consulta $consulta, $recipe = null): string
    {
        $query = ['seccion' => ConsultaShellVista::SECCION_RECIPES];
        if ($recipe !== null) {
            $query['recipe'] = $recipe;
        }

        return route('clinica.consulta', [$consulta->id] + $query);
    }

    /** La **lista** de los récipes de la consulta (O-28): con el editor como puerta de entrada, vive acá. */
    private function urlLista(Consulta $consulta): string
    {
        return route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_RECIPES, 'lista' => 1]);
    }

    private function urlCrear(Consulta $consulta): string
    {
        return route('clinica.consulta.recipes.crear', $consulta->id);
    }

    private function urlGuardar(Consulta $consulta, int $numero): string
    {
        return route('clinica.consulta.recipes.guardar', [$consulta->id, $numero]);
    }

    private function urlEliminar(Consulta $consulta, int $numero): string
    {
        return route('clinica.consulta.recipes.eliminar', [$consulta->id, $numero]);
    }

    /** Un `item` del formulario del editor. */
    private function item(string $codigo, $cantidad = 1, ?string $indicaciones = null): array
    {
        return ['codemedicina' => $codigo, 'cantidad' => $cantidad, 'indicaciones' => $indicaciones];
    }

    /** @return array<int,object> las filas de `recipes` de la consulta, en el orden de la pantalla */
    private function filasDe(Medico $medico, int $historia, int $consulta)
    {
        return DB::table('recipes')->where('reg_medico', $medico->reg_medico)
            ->where('nrohistoria', $historia)->where('nroconsulta', $consulta)
            ->orderBy('orden')->orderBy('id')->get();
    }

    private function trazas(string $tabla, array $ids): \Illuminate\Support\Collection
    {
        return SyncChange::where('table_name', $tabla)->whereIn('record_id', $ids ?: [0])->orderBy('id')->get();
    }

    // ------------------------------------------------------------------------------------- La lectura

    public function test_entrar_sin_recipes_abre_el_editor_y_no_crea_ninguna_fila(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $panel = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get(route('clinica.consulta', $consulta->id));
        $panel->assertOk();
        $panel->assertSee('Pre-factura', false);
        $panel->assertSee('seccion=recipes', false);
        // Desde F9 (la pre-factura) no falta ninguno: el grupo «Consulta» del menú no dibuja ítems deshabilitados.
        $this->assertSame(0, substr_count($panel->getContent(), 'aria-disabled="true"'));

        // O-28: entrar a Récipes **es** el editor del récipe nuevo (todavía no hay ninguno)…
        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();
        $respuesta->assertSee('Récipe nuevo', false);
        $respuesta->assertSee('Guardar el récipe', false);
        $respuesta->assertSee('Todavía no hay medicamentos en este récipe', false);
        $respuesta->assertSee('data-url-medicamentos', false);
        // …y no el estado vacío con «Crear el primer récipe» como puerta de entrada.
        $respuesta->assertDontSee('Crear el primer récipe', false);

        // La regla que no se puede romper: abrir el editor **no** crea la fila.
        $this->assertSame(0, DB::table('recipes')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(0, DB::table('recipe_detalle')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_entrar_con_recipes_abre_el_primero_y_las_pestanas_listan_los_que_hay(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->fila($medico, 1001, 1, '0001', null, ['orden' => 0]);
        $this->fila($medico, 1001, 1, '0003', 2, ['orden' => 1]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta));

        $respuesta->assertOk();
        $contenido = $respuesta->getContent();

        // El editor del récipe actual (el 1) con lo que tiene…
        $respuesta->assertSee('KEFORAL/Cap. 500 mg');
        $this->assertStringContainsString('/recipes/1"', $contenido, 'el formulario postea al récipe 1');

        // …y las pestañas de los que hay más «+ Nuevo».
        $this->assertStringContainsString('recipe=1', $contenido);
        $this->assertStringContainsString('recipe=2', $contenido);
        $this->assertStringContainsString('Récipe 1', $contenido);
        $this->assertStringContainsString('Récipe 2', $contenido);
        $this->assertStringContainsString('+ Nuevo', $contenido);
        $this->assertStringContainsString('recipe=nuevo', $contenido);
        // La lista sigue existiendo, con su enlace desde el editor.
        $this->assertStringContainsString('lista=1', $contenido);
    }

    /** 2026-10-23 (Alexander): imprimir tiene que estar a la vista en el propio editor, no sólo en la lista. */
    public function test_el_editor_de_un_recipe_guardado_ofrece_imprimir_y_el_nuevo_no(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->fila($medico, 1001, 1, '0001', null, ['orden' => 0]);

        $guardado = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta))->assertOk()->getContent();

        $this->assertStringContainsString('data-imprimir-recipe', $guardado, 'El editor del récipe guardado no ofrece imprimir.');

        $nuevo = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta, 'nuevo'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-imprimir-recipe', $nuevo, 'Un récipe sin guardar no tiene qué imprimir.');
    }

    public function test_mas_nuevo_abre_un_editor_vacio_sin_crear_y_guardar_crea_el_que_corresponde(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->fila($medico, 1001, 1, '0001', null);

        // «+ Nuevo» abre el editor vacío y no crea nada.
        $nuevo = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta, 'nuevo'));
        $nuevo->assertOk();
        $nuevo->assertSee('Récipe nuevo', false);
        $nuevo->assertSee('Todavía no hay medicamentos en este récipe', false);
        $this->assertSame(1, DB::table('recipes')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(0, DB::table('recipe_detalle')->where('reg_medico', $medico->reg_medico)->count());

        // Al guardarlo, el récipe que corresponde es el 2 y la vuelta es **a su editor**.
        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlCrear($consulta), ['editor' => 1, 'items' => [$this->item('0001')]]);
        $respuesta->assertRedirect($this->urlSeccion($consulta, 2));
        $this->assertSame(2, (int) DB::table('recipe_detalle')->where('reg_medico', $medico->reg_medico)->value('recipe'));
    }

    public function test_la_lista_se_abre_con_lista_uno_y_muestra_lo_que_tiene(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        // Sin récipes: la lista no es la entrada, pero se llega por su URL y avisa.
        $vacia = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlLista($consulta));
        $vacia->assertOk();
        $vacia->assertSee('Esta consulta todavía no tiene récipes', false);
        $vacia->assertSee('Abrir el editor', false);

        $this->fila($medico, 1001, 1, '0001', null, ['orden' => 0]);
        $this->fila($medico, 1001, 1, '0002', null, ['orden' => 1]);

        $lista = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlLista($consulta));
        $lista->assertOk();
        $lista->assertSee('Récipes de esta consulta', false);
        $lista->assertSee('KEFORAL/Cap. 500 mg');
    }

    public function test_la_lista_agrupa_como_el_legado_y_resuelve_el_nombre_por_el_vademecum(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        // El récipe único del escritorio: `recipe` NULL, sin fecha ni cabecera.
        $this->fila($medico, 1001, 1, '0001', null, ['orden' => 0, 'indicaciones' => 'Una cápsula cada 6 horas']);
        $this->fila($medico, 1001, 1, '0002', null, ['orden' => 1, 'cantidad' => 2]);
        // El segundo, como lo escribe el móvil o la web: con número, cabecera y fecha.
        $this->fila($medico, 1001, 1, '0003', 2, ['orden' => 1, 'fecha' => '2026-10-05']);
        $this->cabecera($medico, 1001, 1, 2, '2026-10-05');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlLista($consulta));

        $respuesta->assertOk();
        $respuesta->assertSee('2 récipes', false);
        $respuesta->assertSee('Récipe 1');
        $respuesta->assertSee('Récipe 2');
        // `NOMBRE/presentación` del vademécum, como el escritorio (`compute_0007`).
        $respuesta->assertSee('KEFORAL/Cap. 500 mg');
        $respuesta->assertSee('DIFLUCAN G/Cap. 150 mg');
        $respuesta->assertSee('ÁCIDO FÓLICO/Tabletas 5 mg');
        $respuesta->assertSee('2 medicamentos', false);
        $respuesta->assertSee('1 medicamento', false);
        $respuesta->assertSee('Una cápsula cada 6 horas');
        // La fecha de la cabecera, con el formato clínico, y la del récipe sin fecha: la raya.
        $respuesta->assertSee('Emitido el 05/10/2026', false);
        $respuesta->assertSee('Emitido el —', false);
        $respuesta->assertSee('vigencia de 30 días', false);
        // La lista ya no es la puerta de entrada (O-28): desde acá se vuelve al editor.
        $respuesta->assertSee('Volver al editor', false);
    }

    public function test_dos_consultas_del_mismo_paciente_no_mezclan_sus_recipes(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $primera] = $this->pacienteYConsulta($medico, 1001, 1);
        $segunda = Consulta::create([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'nroconsulta' => 2,
            'fecha' => $this->hoy->toDateString(),
        ]);

        $this->fila($medico, 1001, 1, '0001', 1);
        $this->fila($medico, 1001, 2, '0002', 1);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlLista($segunda));

        $respuesta->assertOk();
        $respuesta->assertSee('DIFLUCAN G/Cap. 150 mg');
        $respuesta->assertDontSee('KEFORAL/Cap. 500 mg');
    }

    public function test_el_editor_del_recipe_nuevo_y_el_de_uno_existente_se_abren_y_uno_inexistente_da_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->fila($medico, 1001, 1, '0001', null, ['cantidad' => 3, 'indicaciones' => 'Cada 8 horas']);

        // Nuevo: la fecha por defecto es la de la consulta y no hay ningún medicamento.
        $nuevo = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta, 'nuevo'));
        $nuevo->assertOk();
        $nuevo->assertSee('Récipe nuevo', false);
        $nuevo->assertSee('value="2026-10-07"', false);
        $nuevo->assertSee('Guardar el récipe', false);
        $nuevo->assertSee('Todavía no hay medicamentos en este récipe', false);
        $nuevo->assertSee('data-url-medicamentos', false);

        // Existente: el récipe único del escritorio se abre como el 1, con lo que tiene.
        $uno = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta, 1));
        $uno->assertOk();
        $uno->assertSee('Récipe 1', false);
        $uno->assertSee('KEFORAL/Cap. 500 mg');
        $uno->assertSee('value="3"', false);
        $uno->assertSee('Cada 8 horas');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta, 9))->assertNotFound();
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta, 'abc'))->assertNotFound();
    }

    // ------------------------------------------------------------------------------------- Crear

    public function test_crear_un_recipe_escribe_la_cabecera_y_las_filas_como_el_movil(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlCrear($consulta), [
                'editor' => 1,
                'fecha' => '2026-10-06',
                'items' => [
                    $this->item('0001', 2, 'Una cápsula cada 6 horas'),
                    $this->item('0002', '', ''),
                ],
            ]);

        $respuesta->assertRedirect($this->urlSeccion($consulta, 1));
        $respuesta->assertSessionHas('estado');
        $this->assertStringContainsString('Récipe 1 creado', session('estado'));

        $filas = $this->filasDe($medico, 1001, 1);
        $this->assertCount(2, $filas);
        $this->assertSame(['0001', '0002'], $filas->pluck('codemedicina')->all());
        // `orden` 1..n, el mismo `recipe`, la fecha de emisión y el nombre copiado como el INSERT del escritorio.
        $this->assertSame([1, 2], $filas->pluck('orden')->map(fn ($o) => (int) $o)->all());
        $this->assertSame([1, 1], $filas->pluck('recipe')->map(fn ($r) => (int) $r)->all());
        $this->assertSame([2, 1], $filas->pluck('cantidad')->map(fn ($c) => (int) $c)->all(), 'la cantidad vacía es 1');
        $this->assertSame('Una cápsula cada 6 horas', $filas[0]->indicaciones);
        $this->assertNull($filas[1]->indicaciones);
        $this->assertSame('KEFORAL/Cap. 500 mg', $filas[0]->descripcion);
        $this->assertSame('DIFLUCAN G/Cap. 150 mg', $filas[1]->descripcion);
        foreach ($filas as $fila) {
            $this->assertStringStartsWith('2026-10-06', (string) $fila->fecha);
        }

        // La cabecera: la emisión elegida y la vigencia fija de 30 días.
        $cabecera = DB::table('recipe_detalle')->where('reg_medico', $medico->reg_medico)->where('nrohistoria', 1001)->first();
        $this->assertSame(1, (int) $cabecera->recipe);
        $this->assertSame(1, (int) $cabecera->nroconsulta);
        $this->assertSame('2026-10-06', substr((string) $cabecera->fe_emision, 0, 10));
        $this->assertSame(30, (int) $cabecera->fe_vence);
        $this->assertNull($cabecera->nota, 'la nota queda fuera de WEB-5.1');

        // El rastro: el `created` de la CABECERA bajo `recipes` (la convención del móvil) con source = web, y
        // ninguno por fila (el `record_id` de una fila podría coincidir con el de una cabecera ajena).
        $creados = SyncChange::where('table_name', 'recipes')->where('operation', 'created')
            ->where('reg_medico', $medico->reg_medico)->get();
        $this->assertCount(1, $creados);
        $this->assertSame((int) $cabecera->id, (int) $creados[0]->record_id);
        $this->assertSame('web', $creados[0]->source);
    }

    public function test_la_fecha_de_emision_por_defecto_es_la_de_hoy(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlCrear($consulta), ['editor' => 1, 'items' => [$this->item('0001')]])
            ->assertRedirect();

        $cabecera = DB::table('recipe_detalle')->where('reg_medico', $medico->reg_medico)->first();
        $this->assertSame('2026-10-07', substr((string) $cabecera->fe_emision, 0, 10));
        $this->assertStringStartsWith('2026-10-07', (string) $this->filasDe($medico, 1001, 1)[0]->fecha);
    }

    /**
     * **La fecha de emisión de un récipe nuevo es hoy, no la de la consulta que se está editando** (O-13):
     * la fecha es del documento. Una fecha guardada, en cambio, manda (se prueba más abajo, al editar).
     */
    public function test_la_fecha_de_emision_de_un_recipe_nuevo_no_copia_la_de_una_consulta_vieja(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $consulta->update(['fecha' => '2026-09-01']);

        $editor = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get($this->urlSeccion($consulta, 'nuevo'));

        $editor->assertOk();
        // El reloj de la prueba está en el 7 de octubre: la consulta es del 1.º de septiembre.
        $this->assertMatchesRegularExpression('/name="fecha"[^>]*value="2026-10-07"/s', $editor->getContent());
        $this->assertDoesNotMatchRegularExpression('/name="fecha"[^>]*value="2026-09-01"/s', $editor->getContent());

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlCrear($consulta), ['editor' => 1, 'items' => [$this->item('0001')]])
            ->assertRedirect();

        $cabecera = DB::table('recipe_detalle')->where('reg_medico', $medico->reg_medico)->first();
        $this->assertSame('2026-10-07', substr((string) $cabecera->fe_emision, 0, 10));
    }

    public function test_el_segundo_recipe_es_el_2_y_el_nulo_del_legado_cuenta_como_el_1(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->fila($medico, 1001, 1, '0003', null);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlCrear($consulta), ['editor' => 1, 'items' => [$this->item('0001')]])
            ->assertRedirect();
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlCrear($consulta), ['editor' => 1, 'items' => [$this->item('0002')]])
            ->assertRedirect();

        $this->assertSame(
            [2, 3],
            DB::table('recipe_detalle')->where('reg_medico', $medico->reg_medico)->orderBy('recipe')->pluck('recipe')->map(fn ($r) => (int) $r)->all(),
        );
        $this->assertSame(2, (int) DB::table('recipes')->where('reg_medico', $medico->reg_medico)->where('codemedicina', '0001')->value('recipe'));
        $this->assertSame(3, (int) DB::table('recipes')->where('reg_medico', $medico->reg_medico)->where('codemedicina', '0002')->value('recipe'));
    }

    public function test_la_numeracion_es_la_misma_en_la_web_y_en_el_movil(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        // 1) La web crea el récipe 1.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlCrear($consulta), ['editor' => 1, 'items' => [$this->item('0001')]])->assertRedirect();

        // 2) El móvil, por el sync, crea el siguiente: el 2.
        Sanctum::actingAs($user, ['*'], 'api');
        $respuesta = $this->postJson('/api/app/sync-app-data', ['changes' => [
            ['table' => 'recipes', 'operation' => 'created', 'temp_id' => -30, 'consulta_id' => $consulta->id, 'fecha' => '2026-10-07',
                'items' => [['codemedicina' => '0002', 'cantidad' => 1, 'indicaciones' => 'Una']]],
        ]])->assertOk();
        $creado = collect($respuesta->json('creados'))->first(fn ($c) => $c['table'] === 'recipes');
        $this->assertSame(2, $creado['recipe']);

        // 3) Y la web, otra vez: el 3.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlCrear($consulta), ['editor' => 1, 'items' => [$this->item('0003')]])->assertRedirect();

        $this->assertSame(
            [1, 2, 3],
            DB::table('recipe_detalle')->where('reg_medico', $medico->reg_medico)->orderBy('recipe')->pluck('recipe')->map(fn ($r) => (int) $r)->all(),
        );

        // La historia del paciente (WEB-3.5) agrupa igual: tres documentos, uno por récipe.
        $historia = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.historia', DB::table('medico_pacientes')->where('reg_medico', $medico->reg_medico)->value('paciente_id')));
        $historia->assertOk();
        $this->assertSame(3, substr_count($historia->getContent(), '1 medicamento'));
    }

    public function test_la_historia_del_paciente_agrupa_el_nulo_y_el_1_como_el_mismo_recipe(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [$pacienteId, $consulta] = $this->pacienteYConsulta($medico);
        $this->fila($medico, 1001, 1, '0001', null);

        // La web agrega un medicamento al récipe único del escritorio: su fila nueva lleva `recipe = 1`.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta, 1), ['editor' => 1, 'items' => [$this->item('0001'), $this->item('0002')]])
            ->assertRedirect();

        $historia = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.historia', $pacienteId));

        $historia->assertOk();
        // Un solo récipe de dos medicamentos, no dos documentos (la fila NULL y la fila 1 son el mismo récipe).
        $this->assertSame(1, substr_count($historia->getContent(), '2 medicamentos'));
        $this->assertSame(0, substr_count($historia->getContent(), '1 medicamento'));
    }

    // ------------------------------------------------------------------------------------- Editar

    public function test_editar_cantidad_e_indicaciones_en_el_lugar_deja_el_rastro_por_columna_y_no_toca_el_vademecum(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $fila = $this->fila($medico, 1001, 1, '0001', 1, ['orden' => 1, 'cantidad' => 1, 'indicaciones' => 'Vieja']);
        $otra = $this->fila($medico, 1001, 1, '0002', 1, ['orden' => 2, 'cantidad' => 1, 'indicaciones' => 'Sin cambio']);
        $this->cabecera($medico, 1001, 1, 1, '2026-10-07');
        $usoAntes = DB::table('vademecum')->where('reg_medico', $medico->reg_medico)->where('codemedicina', '0001')->value('uso');

        Carbon::setTestNow($this->hoy->copy()->addMinutes(5));

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta, 1), [
                'editor' => 1,
                'fecha' => '2026-10-07',
                'items' => [$this->item('0001', 4, 'Nueva indicación'), $this->item('0002', 1, 'Sin cambio')],
            ]);

        $respuesta->assertRedirect($this->urlSeccion($consulta, 1));
        $this->assertStringContainsString('Récipe 1 actualizado', session('estado'));
        $this->assertStringContainsString('modificado', session('estado'));

        $editada = DB::table('recipes')->find($fila);
        $this->assertSame(4, (int) $editada->cantidad);
        $this->assertSame('Nueva indicación', $editada->indicaciones);
        // Cambió `updated_at` (el móvil baja por delta) y la fila que no cambió quedó intacta.
        $this->assertGreaterThan('2026-01-01 00:00:00', (string) $editada->updated_at);
        $this->assertSame('2026-01-01 00:00:00', (string) DB::table('recipes')->find($otra)->updated_at);

        // El vademécum NO se tocó: editar las indicaciones de un récipe no cambia el catálogo.
        $this->assertSame(
            $usoAntes,
            DB::table('vademecum')->where('reg_medico', $medico->reg_medico)->where('codemedicina', '0001')->value('uso'),
        );

        // El rastro: una fila por columna que cambió, con source = web; la fila sin cambios no deja nada.
        $trazas = $this->trazas('recipes', [$fila, $otra])->where('operation', 'updated');
        $this->assertSame(['cantidad', 'indicaciones'], $trazas->pluck('column_name')->sort()->values()->all());
        $this->assertSame(['web'], $trazas->pluck('source')->unique()->values()->all());
        $this->assertSame([$fila], $trazas->pluck('record_id')->unique()->map(fn ($id) => (int) $id)->values()->all());
    }

    public function test_agregar_y_quitar_medicamentos_al_editar_deja_los_deleted_y_el_pull_del_movil_los_baja(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $conserva = $this->fila($medico, 1001, 1, '0001', 1, ['orden' => 1]);
        $sale = $this->fila($medico, 1001, 1, '0002', 1, ['orden' => 2]);
        $this->cabecera($medico, 1001, 1, 1);

        Carbon::setTestNow($this->hoy->copy()->addMinutes(5));
        $desde = $this->hoy->copy()->addMinute()->toIso8601String();

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta, 1), [
                'editor' => 1,
                'items' => [$this->item('0001', 1, 'Indicación de 0001'), $this->item('0003', 2, 'Una al día.')],
            ])->assertRedirect();

        $filas = $this->filasDe($medico, 1001, 1);
        $this->assertSame(['0001', '0003'], $filas->pluck('codemedicina')->all());
        $nueva = $filas->firstWhere('codemedicina', '0003');
        // La fila nueva cuelga del mismo récipe, con el `orden` que sigue y el nombre del vademécum.
        $this->assertSame(1, (int) $nueva->recipe);
        $this->assertSame(3, (int) $nueva->orden);
        $this->assertSame('ÁCIDO FÓLICO/Tabletas 5 mg', $nueva->descripcion);
        $this->assertNull(DB::table('recipes')->find($sale));

        $baja = SyncChange::where('table_name', 'recipes')->where('record_id', $sale)->where('operation', 'deleted')->first();
        $this->assertNotNull($baja);
        $this->assertSame('web', $baja->source);
        $this->assertSame($medico->reg_medico, $baja->reg_medico);

        // Lo que pasa en el teléfono: el pull trae la fila editada/nueva por `updated_at` y la baja en `eliminados`.
        Sanctum::actingAs($user, ['*'], 'api');
        $pull = $this->postJson('/api/app/sync-app-data', ['changes' => [], 'since' => $desde])->assertOk();
        $this->assertContains((int) $nueva->id, collect($pull->json('recipes'))->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertNotContains($conserva, collect($pull->json('recipes'))->pluck('id')->map(fn ($id) => (int) $id)->all(), 'la fila sin cambios no baja');
        $this->assertTrue(
            collect($pull->json('eliminados'))->contains(fn ($e) => $e['table_name'] === 'recipes' && (int) $e['record_id'] === $sale),
            'el pull del móvil trae la baja de la fila',
        );
    }

    public function test_cambiar_la_fecha_de_emision_actualiza_la_cabecera_y_las_filas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $a = $this->fila($medico, 1001, 1, '0001', 1, ['orden' => 1, 'fecha' => '2026-10-07']);
        $b = $this->fila($medico, 1001, 1, '0002', 1, ['orden' => 2, 'fecha' => '2026-10-07']);
        $cabecera = $this->cabecera($medico, 1001, 1, 1, '2026-10-07');

        Carbon::setTestNow($this->hoy->copy()->addMinutes(5));

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta, 1), [
                'editor' => 1,
                'fecha' => '2026-10-01',
                'items' => [$this->item('0001', 1, 'Indicación de 0001'), $this->item('0002', 1, 'Indicación de 0002')],
            ]);

        $respuesta->assertRedirect();
        $this->assertStringContainsString('cambiada la fecha de emisión', session('estado'));
        $this->assertSame('2026-10-01', substr((string) DB::table('recipe_detalle')->find($cabecera)->fe_emision, 0, 10));
        $this->assertStringStartsWith('2026-10-01', (string) DB::table('recipes')->find($a)->fecha);
        $this->assertStringStartsWith('2026-10-01', (string) DB::table('recipes')->find($b)->fecha);

        // Rastro por columna: `fe_emision` de la cabecera y `fecha` de cada fila.
        $this->assertSame(['fe_emision'], $this->trazas('recipe_detalle', [$cabecera])->where('operation', 'updated')->pluck('column_name')->all());
        $this->assertSame(['fecha', 'fecha'], $this->trazas('recipes', [$a, $b])->where('operation', 'updated')->pluck('column_name')->all());
    }

    public function test_editar_el_recipe_unico_del_escritorio_no_lo_renumera_y_le_crea_la_cabecera_si_se_le_pone_fecha(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        // Como lo deja el escritorio: sin `recipe`, sin `fecha`, sin cabecera.
        $vieja = $this->fila($medico, 1001, 1, '0001', null, ['orden' => 0, 'cantidad' => 1, 'indicaciones' => 'Vieja']);

        Carbon::setTestNow($this->hoy->copy()->addMinutes(5));

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta, 1), [
                'editor' => 1,
                'fecha' => '2026-10-07',
                'items' => [$this->item('0001', 1, 'Vieja corregida'), $this->item('0002', 1, 'Nueva')],
            ])->assertRedirect();

        $fila = DB::table('recipes')->find($vieja);
        // La fila del escritorio sigue sin número (no se renumera en silencio)…
        $this->assertNull($fila->recipe);
        $this->assertSame('Vieja corregida', $fila->indicaciones);
        $this->assertStringStartsWith('2026-10-07', (string) $fila->fecha);
        // …y la fila nueva del mismo récipe lleva `recipe = 1`, con el `orden` que sigue.
        $nueva = DB::table('recipes')->where('reg_medico', $medico->reg_medico)->where('codemedicina', '0002')->first();
        $this->assertSame(1, (int) $nueva->recipe);
        $this->assertSame(1, (int) $nueva->orden);

        // Se le puso fecha, así que ahora tiene su cabecera (como `ue_fecha` del escritorio), y su `created`
        // la protege del reemplazo de `recipe_detalle`.
        $cabecera = DB::table('recipe_detalle')->where('reg_medico', $medico->reg_medico)->first();
        $this->assertSame(1, (int) $cabecera->recipe);
        $this->assertSame(30, (int) $cabecera->fe_vence);
        $this->assertTrue(SyncChange::where('table_name', 'recipes')->where('record_id', $cabecera->id)->where('operation', 'created')->where('source', 'web')->exists());

        // Y vuelve a numerar bien: el siguiente es el 2.
        $this->assertSame(2, app(\App\Clinica\Consulta\RecipesDeLaConsulta::class)->siguienteNumero($consulta));
    }

    public function test_guardar_un_recipe_sin_cambios_lo_dice_y_no_escribe_rastro(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $fila = $this->fila($medico, 1001, 1, '0001', 1, ['orden' => 1, 'fecha' => '2026-10-07']);
        $this->cabecera($medico, 1001, 1, 1, '2026-10-07');

        Carbon::setTestNow($this->hoy->copy()->addMinutes(5));

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta, 1), [
                'editor' => 1, 'fecha' => '2026-10-07', 'items' => [$this->item('0001', 1, 'Indicación de 0001')],
            ])->assertRedirect()->assertSessionHas('estado', 'El récipe 1 no tenía cambios.');

        $this->assertSame('2026-01-01 00:00:00', (string) DB::table('recipes')->find($fila)->updated_at);
        $this->assertSame(0, SyncChange::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_una_edicion_mas_nueva_del_sync_no_se_pisa(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $fila = $this->fila($medico, 1001, 1, '0001', 1, ['orden' => 1, 'cantidad' => 1, 'indicaciones' => 'Vieja']);
        $this->cabecera($medico, 1001, 1, 1);

        // El teléfono (o el escritorio) editó la cantidad **después** de este momento.
        SyncChange::create([
            'reg_medico' => $medico->reg_medico, 'table_name' => 'recipes', 'record_id' => $fila,
            'operation' => 'updated', 'column_name' => 'cantidad', 'value' => '9',
            'occurred_at' => $this->hoy->copy()->addHour(), 'source' => 'mobile',
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta, 1), [
                'editor' => 1, 'items' => [$this->item('0001', 5, 'Nueva')],
            ]);

        $respuesta->assertRedirect();
        $respuesta->assertSessionHas('error');
        $this->assertStringContainsString('más recientemente', session('error'));

        $guardada = DB::table('recipes')->find($fila);
        // La cantidad quedó como estaba (gana la edición más nueva); la columna sin conflicto sí se escribió.
        $this->assertSame(1, (int) $guardada->cantidad);
        $this->assertSame('Nueva', $guardada->indicaciones);
    }

    // ------------------------------------------------------------------------------------- Eliminar

    public function test_eliminar_un_recipe_entero_borra_las_filas_y_la_cabecera_y_no_toca_los_demas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $a = $this->fila($medico, 1001, 1, '0001', 1, ['orden' => 1]);
        $b = $this->fila($medico, 1001, 1, '0002', 1, ['orden' => 2]);
        $queda = $this->fila($medico, 1001, 1, '0003', 2, ['orden' => 1]);
        $cab1 = $this->cabecera($medico, 1001, 1, 1);
        $cab2 = $this->cabecera($medico, 1001, 1, 2);
        // El mismo número en OTRA consulta del mismo paciente: no se toca.
        $otraConsulta = Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'nroconsulta' => 2, 'fecha' => '2026-10-07']);
        $deOtra = $this->fila($medico, 1001, 2, '0001', 1, ['orden' => 1]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->delete($this->urlEliminar($consulta, 1));

        $respuesta->assertRedirect($this->urlSeccion($consulta));
        $this->assertStringContainsString('Eliminaste el récipe 1', session('estado'));
        $this->assertStringContainsString('2 medicamentos', session('estado'));

        $this->assertNull(DB::table('recipes')->find($a));
        $this->assertNull(DB::table('recipes')->find($b));
        $this->assertNull(DB::table('recipe_detalle')->find($cab1));
        $this->assertNotNull(DB::table('recipes')->find($queda), 'el otro récipe queda');
        $this->assertNotNull(DB::table('recipe_detalle')->find($cab2));
        $this->assertNotNull(DB::table('recipes')->find($deOtra), 'el récipe 1 de otra consulta queda');

        // El rastro: un `deleted` por fila bajo `recipes` (lo que el pull del móvil usa) y uno de la cabecera.
        $bajas = SyncChange::where('table_name', 'recipes')->where('operation', 'deleted')->pluck('record_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame([$a, $b], $bajas);
        $this->assertTrue(SyncChange::where('table_name', 'recipe_detalle')->where('record_id', $cab1)->where('operation', 'deleted')->where('source', 'web')->exists());
    }

    public function test_eliminar_el_recipe_unico_del_escritorio_sin_cabecera(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $a = $this->fila($medico, 1001, 1, '0001', null);
        $b = $this->fila($medico, 1001, 1, '0002', null);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->delete($this->urlEliminar($consulta, 1))->assertRedirect();

        $this->assertSame(0, DB::table('recipes')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(2, SyncChange::where('table_name', 'recipes')->where('operation', 'deleted')->whereIn('record_id', [$a, $b])->count());
    }

    public function test_eliminar_o_guardar_un_recipe_que_no_existe_da_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->delete($this->urlEliminar($consulta, 7))->assertNotFound();
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlGuardar($consulta, 7), ['editor' => 1, 'items' => [$this->item('0001')]])->assertNotFound();
    }

    // ------------------------------------------------------------------------------------- Validación

    public function test_un_medicamento_nuevo_tiene_que_estar_en_el_vademecum_o_en_un_tratamiento(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        // Fuera de todo catálogo: no hay texto libre, el récipe no se escribe y vuelve al editor con lo escrito.
        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->from($this->urlSeccion($consulta, 'nuevo'))
            ->post($this->urlCrear($consulta), ['editor' => 1, 'items' => [$this->item('0001'), $this->item('9999')]]);

        $respuesta->assertRedirect($this->urlSeccion($consulta, 'nuevo'));
        $respuesta->assertSessionHasErrors('items');
        $this->assertStringContainsString('9999', session('errors')->first('items'));
        $this->assertSame(0, DB::table('recipes')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(0, DB::table('recipe_detalle')->where('reg_medico', $medico->reg_medico)->count());

        // Un código que el vademécum ya no tiene pero **es de un tratamiento del médico** sí entra.
        DB::table('recipe_grupo')->insert(['reg_medico' => $medico->reg_medico, 'codigo' => '0001', 'tratamiento' => 'Control',
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('recipe_grupo_detalle')->insert(['reg_medico' => $medico->reg_medico, 'codigo' => '0001', 'codemedicina' => '9999',
            'descripcion' => 'VITAMINA X/gotas', 'cantidad' => 1, 'orden' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlCrear($consulta), ['editor' => 1, 'items' => [$this->item('9999')]])->assertRedirect($this->urlSeccion($consulta, 1));

        $this->assertSame('VITAMINA X/gotas', DB::table('recipes')->where('reg_medico', $medico->reg_medico)->value('descripcion'));
    }

    public function test_el_vademecum_de_otro_medico_no_sirve(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        DB::table('vademecum')->insert(['reg_medico' => 'otro-medico', 'codemedicina' => '7777', 'nombrecomercial' => 'AJENO',
            'created_at' => now(), 'updated_at' => now()]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlCrear($consulta), ['editor' => 1, 'items' => [$this->item('7777')]])
            ->assertSessionHasErrors('items');

        $this->assertSame(0, DB::table('recipes')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_los_errores_del_formulario_estan_en_espanol(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $cliente = fn () => $this->conContexto($user, $medico, $gineco)->actingAs($user)->from($this->urlSeccion($consulta, 'nuevo'));

        // Sin medicamentos.
        $errores = $cliente()->post($this->urlCrear($consulta), ['editor' => 1, 'fecha' => '2026-10-07'])->assertSessionHasErrors()->baseResponse->getSession()->get('errors');
        $this->assertSame('Agrega al menos un medicamento al récipe.', $errores->first('items'));

        // Cantidad inválida, fecha inválida e indicaciones largas.
        $errores = $cliente()->post($this->urlCrear($consulta), [
            'editor' => 1, 'fecha' => 'ayer',
            'items' => [
                $this->item('0001', 'muchas'),
                $this->item('0002', 0),
                $this->item('0003', 1, str_repeat('x', 2001)),
            ],
        ])->assertSessionHasErrors()->baseResponse->getSession()->get('errors');

        $mensajes = collect($errores->all());
        $this->assertTrue($mensajes->contains('La fecha de emisión no es válida.'));
        $this->assertTrue($mensajes->contains('La cantidad de cada medicamento tiene que ser un número entero.'));
        $this->assertTrue($mensajes->contains('La cantidad mínima de un medicamento es 1.'));
        $this->assertTrue($mensajes->contains('Las indicaciones de un medicamento no pueden pasar de 2000 caracteres.'));
        foreach ($mensajes as $mensaje) {
            $this->assertDoesNotMatchRegularExpression('/\b(field|must|required|invalid|The )\b/i', $mensaje, 'el aviso está en inglés: ' . $mensaje);
        }
        $this->assertSame(0, DB::table('recipes')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_un_error_vuelve_al_editor_con_los_medicamentos_y_lo_escrito(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        // Un POST que falla (cantidad inválida) y el editor al que vuelve: trae las filas con sus nombres.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)->from($this->urlSeccion($consulta, 'nuevo'))
            ->post($this->urlCrear($consulta), [
                'editor' => 1, 'fecha' => '2026-10-02',
                'items' => [['codemedicina' => '0001', 'nombre' => 'KEFORAL/Cap. 500 mg', 'cantidad' => 'x', 'indicaciones' => 'Escrito a mano']],
            ])->assertRedirect($this->urlSeccion($consulta, 'nuevo'));

        $editor = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get($this->urlSeccion($consulta, 'nuevo'));
        $editor->assertOk();
        $editor->assertSee('KEFORAL/Cap. 500 mg');
        $editor->assertSee('Escrito a mano');
        $editor->assertSee('value="2026-10-02"', false);
    }

    // ------------------------------------------------------------------------------------- Los buscadores

    public function test_el_buscador_de_medicamentos_no_distingue_tildes_y_busca_por_comercial_y_por_generico(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $url = route('clinica.consulta.recipes.medicamentos', $consulta->id);

        $porComercial = $this->conContexto($user, $medico, $gineco)->actingAs($user)->getJson($url . '?q=keforal');
        $porComercial->assertOk();
        $this->assertSame(['KEFORAL/Cap. 500 mg'], collect($porComercial->json('medicamentos'))->pluck('nombre')->all());
        $this->assertSame('0001', $porComercial->json('medicamentos.0.codigo'));
        $this->assertSame('Tomar una cápsula cada 6 horas.', $porComercial->json('medicamentos.0.uso'));

        // Por el principio activo (genérico).
        $porGenerico = $this->conContexto($user, $medico, $gineco)->actingAs($user)->getJson($url . '?q=cefalexina');
        $this->assertSame(['0001'], collect($porGenerico->json('medicamentos'))->pluck('codigo')->all());

        // Sin tildes, en los dos sentidos: «acido folico» encuentra «ÁCIDO FÓLICO» y «ÁCIDO» encuentra lo mismo.
        $this->assertSame(['0003'], collect($this->conContexto($user, $medico, $gineco)->actingAs($user)->getJson($url . '?q=acido+folico')->json('medicamentos'))->pluck('codigo')->all());
        $this->assertSame(['0003'], collect($this->conContexto($user, $medico, $gineco)->actingAs($user)->getJson($url . '?q=' . urlencode('ÁCIDO'))->json('medicamentos'))->pluck('codigo')->all());

        // Todas las palabras, también la presentación.
        $this->assertSame(['0002'], collect($this->conContexto($user, $medico, $gineco)->actingAs($user)->getJson($url . '?q=' . urlencode('difl 150'))->json('medicamentos'))->pluck('codigo')->all());

        // Sin coincidencias: lista vacía, no un error.
        $this->assertSame([], $this->conContexto($user, $medico, $gineco)->actingAs($user)->getJson($url . '?q=zzzz')->json('medicamentos'));
    }

    public function test_el_buscador_ofrece_un_medicamento_por_nombre_y_presentacion_y_solo_los_del_medico(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        // El vademécum del legado trae repetidos: el mismo nombre/presentación con dos códigos.
        $this->medicamento($medico, '0007', 'ADEVIT', 'VITAMINAS', 'jarabe');
        $this->medicamento($medico, '0005', 'ADEVIT', 'VITAMINAS', 'jarabe');
        $this->medicamento($medico, '0006', 'ADEVIT', 'VITAMINAS', 'gotas');
        DB::table('vademecum')->insert(['reg_medico' => 'otro-medico', 'codemedicina' => '0001', 'nombrecomercial' => 'ADEVIT AJENO',
            'created_at' => now(), 'updated_at' => now()]);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $json = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->getJson(route('clinica.consulta.recipes.medicamentos', $consulta->id) . '?q=adevit')->json('medicamentos');

        $this->assertSame(['ADEVIT/gotas', 'ADEVIT/jarabe'], collect($json)->pluck('nombre')->sort()->values()->all());
        $this->assertSame('0005', collect($json)->firstWhere('nombre', 'ADEVIT/jarabe')['codigo'], 'el de código más bajo');
    }

    public function test_el_buscador_de_tratamientos_trae_sus_medicamentos_con_cantidad_e_indicaciones(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        DB::table('recipe_grupo')->insert([
            ['reg_medico' => $medico->reg_medico, 'codigo' => '0001', 'tratamiento' => 'Infección urinaria', 'created_at' => now(), 'updated_at' => now()],
            ['reg_medico' => $medico->reg_medico, 'codigo' => '0002', 'tratamiento' => 'Control prenatal', 'created_at' => now(), 'updated_at' => now()],
            ['reg_medico' => 'otro-medico', 'codigo' => '0001', 'tratamiento' => 'Infección ajena', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('recipe_grupo_detalle')->insert([
            ['reg_medico' => $medico->reg_medico, 'codigo' => '0001', 'codemedicina' => '0002', 'cantidad' => 2, 'indicaciones' => 'Del tratamiento', 'orden' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['reg_medico' => $medico->reg_medico, 'codigo' => '0001', 'codemedicina' => '0001', 'cantidad' => null, 'indicaciones' => null, 'orden' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $url = route('clinica.consulta.recipes.tratamientos', $consulta->id);

        // Sin tildes: «infeccion» encuentra «Infección urinaria», y solo el del médico.
        $json = $this->conContexto($user, $medico, $gineco)->actingAs($user)->getJson($url . '?q=infeccion');
        $json->assertOk();
        $this->assertSame(['Infección urinaria'], collect($json->json('tratamientos'))->pluck('nombre')->all());

        $items = $json->json('tratamientos.0.items');
        // En el orden del tratamiento, con el nombre del vademécum, la cantidad por defecto 1 y, si el
        // tratamiento no trae indicación, la del vademécum (`uso`).
        $this->assertSame(['0001', '0002'], collect($items)->pluck('codigo')->all());
        $this->assertSame('KEFORAL/Cap. 500 mg', $items[0]['nombre']);
        $this->assertSame(1, $items[0]['cantidad']);
        $this->assertSame('Tomar una cápsula cada 6 horas.', $items[0]['indicaciones']);
        $this->assertSame(2, $items[1]['cantidad']);
        $this->assertSame('Del tratamiento', $items[1]['indicaciones']);

        // Sin texto: todos los del médico.
        $todos = $this->conContexto($user, $medico, $gineco)->actingAs($user)->getJson($url);
        $this->assertCount(2, $todos->json('tratamientos'));
    }

    public function test_un_tratamiento_se_guarda_como_un_recipe_con_todos_sus_medicamentos(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        // Lo que el editor manda después de cargar un tratamiento de tres medicamentos y ajustar uno.
        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlCrear($consulta), ['editor' => 1, 'items' => [
                $this->item('0001', 1, 'Del tratamiento 1'),
                $this->item('0002', 2, 'Del tratamiento 2'),
                $this->item('0003', 1, 'Ajustada a mano'),
            ]])->assertRedirect();

        $filas = $this->filasDe($medico, 1001, 1);
        $this->assertCount(3, $filas);
        $this->assertSame(['0001', '0002', '0003'], $filas->pluck('codemedicina')->all());
        $this->assertSame('Ajustada a mano', $filas[2]->indicaciones);
        // El catálogo no cambió con la indicación ajustada.
        $this->assertSame('Una al día.', DB::table('vademecum')->where('reg_medico', $medico->reg_medico)->where('codemedicina', '0003')->value('uso'));
    }

    // ------------------------------------------------------------------------------------- Alta rápida

    public function test_crear_un_medicamento_lo_da_de_alta_con_el_correlativo_en_mayusculas_y_sin_duplicar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        // Los códigos que no son numéricos no cuentan para el correlativo.
        $this->medicamento($medico, 'MED006', 'OTRO');
        [, $consulta] = $this->pacienteYConsulta($medico);
        $url = route('clinica.consulta.recipes.medicamentos.crear', $consulta->id);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->postJson($url, [
            'nombrecomercial' => '  adevit   plus ', 'nombregenerico' => 'vitaminas', 'presentacion' => 'Jarabe 120 ml', 'uso' => 'Una cucharada al día',
        ]);

        $respuesta->assertStatus(201);
        $respuesta->assertJsonPath('creado', true);
        $respuesta->assertJsonPath('medicamento.codigo', '0004');
        $respuesta->assertJsonPath('medicamento.nombre', 'ADEVIT PLUS/Jarabe 120 ml');

        $fila = DB::table('vademecum')->where('reg_medico', $medico->reg_medico)->where('codemedicina', '0004')->first();
        $this->assertSame('ADEVIT PLUS', $fila->nombrecomercial);
        $this->assertSame('VITAMINAS', $fila->nombregenerico);
        $this->assertSame('Jarabe 120 ml', $fila->presentacion);
        $this->assertSame('Una cucharada al día', $fila->uso);
        // La fila nace con `updated_at`: el móvil la baja en el delta de `vademecum`.
        $this->assertNotNull($fila->updated_at);

        // Lo mismo otra vez (otras mayúsculas): el existente, sin duplicar.
        $otra = $this->conContexto($user, $medico, $gineco)->actingAs($user)->postJson($url, [
            'nombrecomercial' => 'Adevit Plus', 'presentacion' => 'jarabe 120 ML',
        ]);
        $otra->assertOk();
        $otra->assertJsonPath('creado', false);
        $otra->assertJsonPath('medicamento.codigo', '0004');
        $this->assertSame(1, DB::table('vademecum')->where('reg_medico', $medico->reg_medico)->where('nombrecomercial', 'ADEVIT PLUS')->count());

        // Con otra presentación es otro medicamento.
        $distinta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->postJson($url, [
            'nombrecomercial' => 'Adevit Plus', 'presentacion' => 'Gotas',
        ]);
        $distinta->assertStatus(201);
        $distinta->assertJsonPath('medicamento.codigo', '0005');
    }

    public function test_crear_un_medicamento_sin_nombre_o_demasiado_largo_no_escribe_nada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);
        $url = route('clinica.consulta.recipes.medicamentos.crear', $consulta->id);
        $antes = DB::table('vademecum')->where('reg_medico', $medico->reg_medico)->count();

        $sinNombre = $this->conContexto($user, $medico, $gineco)->actingAs($user)->postJson($url, ['nombrecomercial' => '   ']);
        $sinNombre->assertStatus(422);
        $this->assertStringContainsString('medicamento', $sinNombre->json('message'));

        $largo = $this->conContexto($user, $medico, $gineco)->actingAs($user)->postJson($url, ['nombrecomercial' => str_repeat('A', 36)]);
        $largo->assertStatus(422);
        $this->assertStringContainsString('35', $largo->json('message'));

        $presentacion = $this->conContexto($user, $medico, $gineco)->actingAs($user)->postJson($url, ['nombrecomercial' => 'BIEN', 'presentacion' => str_repeat('x', 51)]);
        $presentacion->assertStatus(422);
        $this->assertStringContainsString('50', $presentacion->json('message'));

        $this->assertSame($antes, DB::table('vademecum')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_crear_un_medicamento_sin_javascript_vuelve_a_la_seccion_con_el_aviso(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.consulta.recipes.medicamentos.crear', $consulta->id), ['nombrecomercial' => 'Nuevo'])
            ->assertRedirect($this->urlSeccion($consulta))
            ->assertSessionHas('estado');

        $this->assertSame(1, DB::table('vademecum')->where('reg_medico', $medico->reg_medico)->where('nombrecomercial', 'NUEVO')->count());
    }

    // ------------------------------------------------------------------------------------- Quién entra

    public function test_la_secretaria_no_entra_a_la_seccion_ni_a_ninguna_accion(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->fila($medico, 1001, 1, '0001', 1);
        $secretaria = $this->secretariaConAcceso($medico);
        $cliente = fn () => $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria);

        $cliente()->get($this->urlSeccion($consulta))->assertForbidden();
        $cliente()->post($this->urlCrear($consulta), ['editor' => 1, 'items' => [$this->item('0001')]])->assertForbidden();
        $cliente()->post($this->urlGuardar($consulta, 1), ['editor' => 1, 'items' => [$this->item('0001')]])->assertForbidden();
        $cliente()->delete($this->urlEliminar($consulta, 1))->assertForbidden();
        $cliente()->getJson(route('clinica.consulta.recipes.medicamentos', $consulta->id) . '?q=a')->assertForbidden();
        $cliente()->getJson(route('clinica.consulta.recipes.tratamientos', $consulta->id))->assertForbidden();
        $cliente()->postJson(route('clinica.consulta.recipes.medicamentos.crear', $consulta->id), ['nombrecomercial' => 'X'])->assertForbidden();

        $this->assertSame(1, DB::table('recipes')->where('reg_medico', $medico->reg_medico)->count(), 'no se tocó nada');
        $this->assertSame(0, DB::table('vademecum')->where('reg_medico', $medico->reg_medico)->where('nombrecomercial', 'X')->count());
    }

    public function test_un_invitado_va_al_login(): void
    {
        [, $medico] = $this->medicoConAcceso();
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->get($this->urlSeccion($consulta))->assertRedirect(route('login'));
        $this->post($this->urlCrear($consulta), ['items' => [$this->item('0001')]])->assertRedirect(route('login'));
        $this->delete($this->urlEliminar($consulta, 1))->assertRedirect(route('login'));
    }

    public function test_la_consulta_de_otro_medico_da_404_en_todo_y_no_se_toca(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $propia = $this->fila($medico, 1001, 1, '0001', 1);

        // Otro médico, con su propio contexto, pide la consulta del primero.
        [$otroUser, $otro, $gineco2] = $this->medicoConAcceso();
        $this->vademecum($otro);
        $cliente = fn () => $this->conContexto($otroUser, $otro, $gineco2)->actingAs($otroUser);

        $cliente()->get($this->urlSeccion($consulta))->assertNotFound();
        $cliente()->post($this->urlCrear($consulta), ['editor' => 1, 'items' => [$this->item('0001')]])->assertNotFound();
        $cliente()->post($this->urlGuardar($consulta, 1), ['editor' => 1, 'items' => [$this->item('0001', 9)]])->assertNotFound();
        $cliente()->delete($this->urlEliminar($consulta, 1))->assertNotFound();
        $cliente()->getJson(route('clinica.consulta.recipes.medicamentos', $consulta->id) . '?q=a')->assertNotFound();
        $cliente()->getJson(route('clinica.consulta.recipes.tratamientos', $consulta->id))->assertNotFound();
        $cliente()->postJson(route('clinica.consulta.recipes.medicamentos.crear', $consulta->id), ['nombrecomercial' => 'AJENO'])->assertNotFound();

        $this->assertSame(1, (int) DB::table('recipes')->find($propia)->cantidad, 'la fila del primer médico no se tocó');
        $this->assertSame(1, DB::table('recipes')->where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame(0, DB::table('recipes')->where('reg_medico', $otro->reg_medico)->count());
    }

    public function test_un_recipe_de_otro_registro_con_la_misma_historia_no_se_toca(): void
    {
        // El número de historia es por médico: la historia 1001 de otro `reg_medico` es otro paciente.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);
        $ajena = DB::table('recipes')->insertGetId([
            'reg_medico' => 'otro-registro', 'nrohistoria' => 1001, 'nroconsulta' => 1, 'codemedicina' => '0001',
            'cantidad' => 1, 'recipe' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post($this->urlCrear($consulta), ['editor' => 1, 'items' => [$this->item('0001')]])->assertRedirect();

        // El número que le toca a este médico es el 1: el récipe ajeno de la misma historia no cuenta.
        $this->assertSame(1, (int) DB::table('recipe_detalle')->where('reg_medico', $medico->reg_medico)->value('recipe'));

        $this->conContexto($user, $medico, $gineco)->actingAs($user)->delete($this->urlEliminar($consulta, 1))->assertRedirect();
        $this->assertNotNull(DB::table('recipes')->find($ajena), 'la fila del otro registro sigue ahí');
    }

    public function test_anterior_de_los_recipes_va_en_el_editor_y_no_copia_por_post(): void
    {
        // WEB-11.4 (casilla, 2026-10-20): «Anterior» pone en el récipe que se edita los medicamentos del **último** récipe de la
        // consulta previa, con su cantidad e indicaciones, y se guarda con «Guardar el récipe». El `POST` que copiaba récipes
        // enteros ya no existe (el detalle, en `ConsultaAnteriorWebTest`).
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $primera] = $this->pacienteYConsulta($medico, 1001, 1);
        $segunda = Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => 1001, 'nroconsulta' => 2, 'fecha' => $primera->fecha]);
        $this->fila($medico, 1001, 1, '0001', 1, ['cantidad' => 3, 'indicaciones' => 'Cada 6 horas']);
        $this->fila($medico, 1001, 1, '0003', 2);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.consulta.anterior', [$segunda->id, 'recipes']), ['activar' => '1'])
            ->assertNotFound();
        $this->assertCount(0, $this->filasDe($medico, 1001, 2));

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.consulta', [$segunda->id, 'seccion' => 'recipes']))
            ->assertOk()
            ->assertSee('data-anterior-recipe', false)
            ->assertSee('&quot;codigo&quot;:&quot;0003&quot;', false)
            ->assertDontSee('&quot;codigo&quot;:&quot;0001&quot;', false);
    }
}
