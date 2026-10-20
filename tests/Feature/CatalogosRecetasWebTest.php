<?php

namespace Tests\Feature;

use App\Clinica\Catalogos\MedicamentosDelCatalogo;
use App\Clinica\Catalogos\UsoDeUnMedicamento;
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
 * **Los catálogos de medicamentos y tratamientos del médico** (WEB-8.1a): el ABM del vademécum y de los tratamientos
 * (plantillas de récipe), en `/clinica/catalogos/...`.
 *
 * Lo que estos casos fijan:
 *
 *  - **El listado del vademécum**: paginado, con el buscador sin tildes (comercial, genérico, presentación), en orden de
 *    nombre y sólo con lo del médico del contexto.
 *  - **Alta** (la regla de WEB-5.1: correlativo de cuatro dígitos, mayúsculas, topes y sin duplicar nombre + presentación,
 *    más los campos que el ABM suma) y **edición** de sólo lo que cambia, con el rastro `source = web` por columna y el
 *    last-write-wins del sync.
 *  - **Quitar un medicamento = borrar si no tiene uso; si lo tiene, avisar dónde y no borrar** (récipes, récipe de la
 *    pareja y tratamientos), y **quitar un tratamiento** siempre.
 *  - **Un tratamiento** con sus medicamentos: crear, editar cantidad, indicaciones y orden, agregar y quitar, con el rastro
 *    por fila y el `deleted` que entiende el teléfono.
 *  - **La subida del escritorio**: `recipe_grupo` y `recipe_grupo_detalle` (sin clave primaria) no pierden lo que creó la web;
 *    `vademecum` (con clave) tampoco; y lo que ve el móvil.
 *  - El **404** del médico ajeno, el **403** de la secretaría y el invitado al login.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class CatalogosRecetasWebTest extends TestCase
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
        $regMedico = 'catalogos-tes' . substr(uniqid(), -6);

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'catalogos-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Catalogos',
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
            'email'                => 'catalogos-sec' . substr(uniqid(), -6) . '@example.com',
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
        ]])->actingAs($user);
    }

    /** Un medicamento del vademécum del médico (`vademecum`). */
    private function medicamento(
        Medico $medico,
        string $codigo,
        ?string $comercial,
        ?string $generico = null,
        ?string $presentacion = null,
        ?string $uso = null,
        array $extra = [],
    ): int {
        return (int) DB::table('vademecum')->insertGetId(array_merge([
            'reg_medico'      => $medico->reg_medico,
            'codemedicina'    => $codigo,
            'nombrecomercial' => $comercial,
            'nombregenerico'  => $generico,
            'presentacion'    => $presentacion,
            'uso'             => $uso,
            'created_at'      => '2026-01-01 00:00:00',
            'updated_at'      => '2026-01-01 00:00:00',
        ], $extra));
    }

    /** El vademécum chico que usan casi todos los casos. */
    private function vademecum(Medico $medico): void
    {
        $this->medicamento($medico, '0001', 'KEFORAL', 'CEFALEXINA', 'Cap. 500 mg', 'Tomar una cápsula cada 6 horas.');
        $this->medicamento($medico, '0002', 'DIFLUCAN G', 'FLUCONAZOL', 'Cap. 150 mg', 'Tomar 1 cápsula los dos.');
        $this->medicamento($medico, '0003', 'ÁCIDO FÓLICO', 'ACIDO FOLICO', 'Tabletas 5 mg', 'Una al día.');
    }

    /** Un tratamiento del médico con sus medicamentos (`recipe_grupo` + `recipe_grupo_detalle`), como los deja el escritorio. */
    private function tratamiento(Medico $medico, string $codigo, string $nombre, array $items = []): int
    {
        $id = (int) DB::table('recipe_grupo')->insertGetId([
            'reg_medico' => $medico->reg_medico,
            'codigo'     => $codigo,
            'tratamiento' => $nombre,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        foreach (array_values($items) as $orden => $item) {
            DB::table('recipe_grupo_detalle')->insert([
                'reg_medico'   => $medico->reg_medico,
                'codigo'       => $codigo,
                'codemedicina' => $item['codigo'],
                'descripcion'  => $item['descripcion'] ?? ('Nombre de ' . $item['codigo']),
                'indicaciones' => $item['indicaciones'] ?? ('Indicación de ' . $item['codigo']),
                'cantidad'     => $item['cantidad'] ?? 1,
                'orden'        => $orden,
                'created_at'   => '2026-01-01 00:00:00',
                'updated_at'   => '2026-01-01 00:00:00',
            ]);
        }

        return $id;
    }

    /** Una fila de `recipes` (un medicamento en un récipe de un paciente). */
    private function recipe(Medico $medico, int $historia, int $consulta, string $codigo, ?int $recipe = null): int
    {
        return (int) DB::table('recipes')->insertGetId([
            'reg_medico'   => $medico->reg_medico,
            'nrohistoria'  => $historia,
            'nroconsulta'  => $consulta,
            'codemedicina' => $codigo,
            'cantidad'     => 1,
            'indicaciones' => 'Indicación',
            'recipe'       => $recipe,
            'created_at'   => '2026-01-01 00:00:00',
            'updated_at'   => '2026-01-01 00:00:00',
        ]);
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

    /** Los campos del formulario de un medicamento. */
    private function formulario(array $campos = []): array
    {
        return array_merge([
            'id' => null,
            'nombrecomercial' => 'ADEVIT',
            'nombregenerico' => 'MULTIVITAMINICO',
            'presentacion' => 'Jarabe 120 ml',
            'uso' => 'Una cucharada al día.',
        ], $campos);
    }

    /** Un `item` del formulario del editor de tratamientos. */
    private function item(string $codigo, $cantidad = 1, ?string $indicaciones = null): array
    {
        return ['codemedicina' => $codigo, 'cantidad' => $cantidad, 'indicaciones' => $indicaciones];
    }

    private function trazas(string $tabla, array $ids): \Illuminate\Support\Collection
    {
        return SyncChange::where('table_name', $tabla)->whereIn('record_id', $ids ?: [0])->orderBy('id')->get();
    }

    // ------------------------------------------------------------------------ Medicamentos: la lectura

    public function test_el_listado_pagina_el_vademecum_y_solo_trae_lo_del_medico(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();

        for ($i = 1; $i <= 30; $i++) {
            $this->medicamento($medico, sprintf('%04d', $i), sprintf('MEDICINA %02d', $i), null, 'Tabletas');
        }
        $this->medicamento($otro, '0001', 'AJENO UNICO');

        $pagina1 = $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.medicamentos'));
        $pagina1->assertOk()->assertSee('Mostrando 1–25 de 30')->assertSee('MEDICINA 01')->assertDontSee('MEDICINA 26')
            ->assertDontSee('AJENO UNICO');

        $pagina2 = $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.medicamentos', ['page' => 2]));
        $pagina2->assertOk()->assertSee('Mostrando 26–30 de 30')->assertSee('MEDICINA 26')->assertDontSee('MEDICINA 01');
    }

    public function test_el_buscador_no_distingue_tildes_y_busca_por_comercial_generico_y_presentacion(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        $this->medicamento($medico, '0004', 'ADEVIT', 'MULTIVITAMINICO', 'Jarabe');

        $buscar = fn (string $texto) => $this->conContexto($user, $medico, $gineco)
            ->get(route('clinica.catalogos.medicamentos', ['buscar' => $texto]));

        // Por comercial con tilde escrito sin tilde, y por genérico.
        $buscar('acido folico')->assertOk()->assertSee('ÁCIDO FÓLICO')->assertDontSee('KEFORAL');
        $buscar('cefalexina')->assertOk()->assertSee('KEFORAL')->assertDontSee('ADEVIT');
        // Por presentación y con todas las palabras.
        $buscar('tabletas 5')->assertOk()->assertSee('ÁCIDO FÓLICO')->assertDontSee('DIFLUCAN');
        $buscar('multivitaminico jarabe')->assertOk()->assertSee('ADEVIT');

        // Sin coincidencias: el estado vacío distingue "tu búsqueda no encontró nada" de "no hay nada todavía".
        $buscar('zzz')->assertOk()->assertSee('Ningún medicamento coincide')->assertSee('Ver todo el vademécum');
    }

    public function test_el_listado_va_en_orden_de_nombre_sin_distinguir_tildes_y_los_sin_nombre_al_final(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $this->medicamento($medico, '0001', 'ZETA');
        $this->medicamento($medico, '0002', 'ÁBACO');
        $this->medicamento($medico, '0003', 'BETA', null, 'Z');
        $this->medicamento($medico, '0004', 'BETA', null, 'A');
        $this->medicamento($medico, '0005', null);

        $pagina = app(MedicamentosDelCatalogo::class)->pagina($medico->reg_medico, '', 1, '/x');

        $this->assertSame(
            ['0002', '0004', '0003', '0001', '0005'],
            collect($pagina->items())->map(fn ($fila) => $fila->codemedicina)->all(),
            'ÁBACO (la tilde no la manda al final), BETA por presentación, ZETA y el sin nombre al último',
        );
    }

    public function test_un_vademecum_vacio_ofrece_crear_el_primero(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.medicamentos'))
            ->assertOk()->assertSee('Tu vademécum todavía no tiene medicamentos')->assertSee('Crear el primer medicamento');
    }

    // ------------------------------------------------------------------------------ Medicamentos: alta

    public function test_el_alta_da_el_correlativo_pone_mayusculas_guarda_los_campos_del_abm_y_deja_el_rastro(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->medicamento($medico, '0001', 'KEFORAL');
        $this->medicamento($medico, '0003', 'DIFLUCAN G');
        $this->medicamento($medico, 'MED001', 'BASURA DEL LEGADO');

        $respuesta = $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.medicamentos.guardar'), $this->formulario([
            'nombrecomercial' => 'adevit  junior',
            'nombrecomercial1' => 'adevit plus',
            'nombrecomercial3' => 'multi adevit',
            'dosificacion' => '5 ml por día',
            'sico' => '1',
        ]));

        $nuevo = DB::table('vademecum')->where('reg_medico', $medico->reg_medico)->where('nombrecomercial', 'ADEVIT JUNIOR')->first();
        $this->assertNotNull($nuevo);
        $this->assertSame('0004', $nuevo->codemedicina, 'el mayor numérico + 1; el código no numérico no cuenta');
        $this->assertSame('MULTIVITAMINICO', $nuevo->nombregenerico);
        $this->assertSame('Jarabe 120 ml', $nuevo->presentacion);
        $this->assertSame('Una cucharada al día.', $nuevo->uso);
        $this->assertSame('ADEVIT PLUS', $nuevo->nombrecomercial1);
        $this->assertNull($nuevo->nombrecomercial2);
        $this->assertSame('MULTI ADEVIT', $nuevo->nombrecomercial3);
        $this->assertSame('5 ml por día', $nuevo->dosificacion);
        $this->assertSame('S', $nuevo->sico);

        // Vuelve a la lista buscando lo que se creó, y lo dice.
        $respuesta->assertRedirect(route('clinica.catalogos.medicamentos', ['buscar' => 'ADEVIT JUNIOR']));
        $this->conContexto($user, $medico, $gineco)->get($respuesta->headers->get('Location'))
            ->assertSee('Creaste «ADEVIT JUNIOR/Jarabe 120 ml»')->assertSee('0004');

        $creado = $this->trazas('vademecum', [$nuevo->id])->first();
        $this->assertSame('created', $creado->operation);
        $this->assertSame('web', $creado->source);
        $this->assertSame($medico->reg_medico, $creado->reg_medico);
    }

    public function test_un_alta_sin_psicotropico_deja_la_casilla_en_n_como_el_escritorio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.medicamentos.guardar'), $this->formulario());

        $this->assertSame('N', DB::table('vademecum')->where('reg_medico', $medico->reg_medico)->value('sico'));
    }

    public function test_el_alta_no_duplica_nombre_y_presentacion_pero_si_otra_presentacion(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->medicamento($medico, '0001', 'ADEVIT', null, 'Jarabe 120 ml');

        $dup = $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.medicamentos.guardar'), $this->formulario([
            'nombrecomercial' => 'adevit', 'presentacion' => 'JARABE 120 ML',
        ]));
        $dup->assertSessionHasErrors('nombrecomercial');
        $this->assertStringContainsString('Ya tienes «ADEVIT/Jarabe 120 ml»', session('errors')->first('nombrecomercial'));
        $this->assertStringContainsString('0001', session('errors')->first('nombrecomercial'));
        $this->assertSame(1, DB::table('vademecum')->where('reg_medico', $medico->reg_medico)->count());

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.medicamentos.guardar'), $this->formulario([
            'nombrecomercial' => 'ADEVIT', 'presentacion' => 'Gotas',
        ]))->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('vademecum')->where('reg_medico', $medico->reg_medico)->count(), 'otra presentación es otro medicamento');
    }

    public function test_el_alta_respeta_los_topes_y_pide_nombre(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $url = route('clinica.catalogos.medicamentos.guardar');

        $this->conContexto($user, $medico, $gineco)->post($url, $this->formulario(['nombrecomercial' => str_repeat('A', 36)]))
            ->assertSessionHasErrors('nombrecomercial');
        $this->conContexto($user, $medico, $gineco)->post($url, $this->formulario(['nombregenerico' => str_repeat('A', 36)]))
            ->assertSessionHasErrors('nombregenerico');
        $this->conContexto($user, $medico, $gineco)->post($url, $this->formulario(['nombrecomercial2' => str_repeat('A', 41)]))
            ->assertSessionHasErrors('nombrecomercial2');
        $this->conContexto($user, $medico, $gineco)->post($url, $this->formulario(['presentacion' => str_repeat('A', 51)]))
            ->assertSessionHasErrors('presentacion');
        $this->conContexto($user, $medico, $gineco)->post($url, $this->formulario(['uso' => str_repeat('A', 2001)]))
            ->assertSessionHasErrors('uso');
        $this->conContexto($user, $medico, $gineco)->post($url, $this->formulario(['nombrecomercial' => null]))
            ->assertSessionHasErrors('nombrecomercial');

        $this->assertSame(0, DB::table('vademecum')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_los_errores_de_validacion_estan_en_espanol(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.medicamentos.guardar'), $this->formulario([
            'nombrecomercial' => str_repeat('A', 40), 'nombregenerico' => str_repeat('B', 40), 'nombrecomercial1' => str_repeat('C', 50),
            'presentacion' => str_repeat('D', 60), 'uso' => str_repeat('E', 2100), 'dosificacion' => str_repeat('F', 2100), 'page' => 'x',
        ]));

        foreach (session('errors')->all() as $mensaje) {
            $this->assertDoesNotMatchRegularExpression('/\b(The|must|may not|field)\b/', $mensaje, 'el aviso "' . $mensaje . '" está en inglés');
        }
        $this->assertGreaterThanOrEqual(6, count(session('errors')->all()));
    }

    // --------------------------------------------------------------------------- Medicamentos: edición

    public function test_editar_escribe_solo_lo_que_cambia_con_su_rastro_por_columna(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $id = $this->medicamento($medico, '0001', 'KEFORAL', 'CEFALEXINA', 'Cap. 500 mg', 'Tomar una cápsula.');
        Carbon::setTestNow($this->hoy->copy()->addMinutes(5));

        $respuesta = $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.medicamentos.guardar'), [
            'id' => $id,
            'nombrecomercial' => 'KEFORAL',
            'nombregenerico' => 'CEFALEXINA',
            'presentacion' => 'Cap. 1 g',
            'uso' => 'Tomar una cápsula.',
            'buscar' => 'kefo',
            'page' => 1,
        ]);

        $respuesta->assertRedirect(route('clinica.catalogos.medicamentos', ['buscar' => 'kefo']));
        $fila = DB::table('vademecum')->find($id);
        $this->assertSame('Cap. 1 g', $fila->presentacion);
        $this->assertSame('KEFORAL', $fila->nombrecomercial);
        $this->assertSame('0001', $fila->codemedicina, 'el código no se edita');
        $this->assertSame($this->hoy->copy()->addMinutes(5)->format('Y-m-d H:i:s'), $fila->updated_at, 'cambia updated_at: es lo que mira el delta del móvil');

        $trazas = $this->trazas('vademecum', [$id]);
        $this->assertCount(1, $trazas, 'una sola columna cambió y una sola traza');
        $this->assertSame('updated', $trazas[0]->operation);
        $this->assertSame('presentacion', $trazas[0]->column_name);
        $this->assertSame('web', $trazas[0]->source);

        $this->conContexto($user, $medico, $gineco)->get($respuesta->headers->get('Location'))
            ->assertSee('Guardaste «KEFORAL/Cap. 1 g»: cambió la presentación.');
    }

    public function test_editar_sin_cambios_no_escribe_nada_ni_inventa_rastro_con_el_psicotropico_nulo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        // Una fila del escritorio: `sico` NULL y un salto de línea `\n` en las indicaciones.
        $id = $this->medicamento($medico, '0001', 'KEFORAL', 'CEFALEXINA', 'Cap. 500 mg', "Línea uno\nLínea dos");
        Carbon::setTestNow($this->hoy->copy()->addMinutes(5));

        // El navegador manda `\r\n` en el textarea y no manda la casilla sin tildar.
        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.medicamentos.guardar'), [
            'id' => $id, 'nombrecomercial' => 'KEFORAL', 'nombregenerico' => 'CEFALEXINA', 'presentacion' => 'Cap. 500 mg',
            'uso' => "Línea uno\r\nLínea dos",
        ])->assertSessionHasNoErrors();

        $this->assertCount(0, $this->trazas('vademecum', [$id]));
        $fila = DB::table('vademecum')->find($id);
        $this->assertNull($fila->sico);
        $this->assertSame('2026-01-01 00:00:00', $fila->updated_at, 'sin cambios no se toca updated_at');
        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.medicamentos'))
            ->assertOk();
    }

    public function test_editar_el_psicotropico_pasa_de_n_a_s_y_de_s_a_n(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $id = $this->medicamento($medico, '0001', 'KEFORAL', null, null, null, ['sico' => 'N']);
        $base = ['id' => $id, 'nombrecomercial' => 'KEFORAL'];

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.medicamentos.guardar'), $base + ['sico' => '1']);
        $this->assertSame('S', DB::table('vademecum')->find($id)->sico);

        Carbon::setTestNow($this->hoy->copy()->addMinute());
        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.medicamentos.guardar'), $base);
        $this->assertSame('N', DB::table('vademecum')->find($id)->sico, 'destildar escribe N, no NULL');
    }

    public function test_editar_el_nombre_cambia_lo_que_ve_un_recipe_viejo_que_lo_recetaba(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $id = $this->medicamento($medico, '0001', 'KEFORAL', 'CEFALEXINA', 'Cap. 500 mg');
        [, $consulta] = $this->pacienteYConsulta($medico);
        $this->recipe($medico, 1001, 1, '0001');

        $antes = app(\App\Clinica\Consulta\RecipesDeLaConsulta::class)->deLaConsulta($medico->reg_medico, 1001, 1);
        $this->assertSame('KEFORAL/Cap. 500 mg', $antes[0]->items[0]->nombre);

        Carbon::setTestNow($this->hoy->copy()->addMinute());
        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.medicamentos.guardar'), [
            'id' => $id, 'nombrecomercial' => 'KEFORAL FORTE', 'nombregenerico' => 'CEFALEXINA', 'presentacion' => 'Cap. 500 mg',
        ]);

        $despues = app(\App\Clinica\Consulta\RecipesDeLaConsulta::class)->deLaConsulta($medico->reg_medico, 1001, 1);
        $this->assertSame('KEFORAL FORTE/Cap. 500 mg', $despues[0]->items[0]->nombre, 'el récipe resuelve el nombre por código: ve el nombre nuevo');
    }

    public function test_editar_no_deja_repetir_nombre_y_presentacion_de_otro_medicamento(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->medicamento($medico, '0001', 'KEFORAL', null, 'Cap');
        $id = $this->medicamento($medico, '0002', 'DIFLUCAN', null, 'Cap');

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.medicamentos.guardar'), [
            'id' => $id, 'nombrecomercial' => 'keforal', 'presentacion' => 'cap',
        ])->assertSessionHasErrors('nombrecomercial');

        $this->assertSame('DIFLUCAN', DB::table('vademecum')->find($id)->nombrecomercial);
        $this->assertStringContainsString('código 0001', session('errors')->first('nombrecomercial'));
    }

    public function test_editar_sin_ningun_nombre_se_rechaza_pero_con_solo_generico_se_acepta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $id = $this->medicamento($medico, '0001', 'KEFORAL', 'CEFALEXINA');

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.medicamentos.guardar'), ['id' => $id])
            ->assertSessionHasErrors('nombrecomercial');
        $this->assertSame('KEFORAL', DB::table('vademecum')->find($id)->nombrecomercial);

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.medicamentos.guardar'), ['id' => $id, 'nombregenerico' => 'CEFALEXINA'])
            ->assertSessionHasNoErrors();
        $this->assertNull(DB::table('vademecum')->find($id)->nombrecomercial);
    }

    public function test_una_edicion_mas_nueva_de_otro_equipo_no_se_pisa(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $id = $this->medicamento($medico, '0001', 'KEFORAL', 'CEFALEXINA', 'Cap');

        // El teléfono editó el genérico DESPUÉS de lo que la web va a intentar.
        SyncChange::create([
            'reg_medico' => $medico->reg_medico, 'table_name' => 'vademecum', 'record_id' => $id, 'operation' => 'updated',
            'column_name' => 'nombregenerico', 'value' => 'OTRA', 'occurred_at' => $this->hoy->copy()->addHour(), 'source' => 'mobile',
        ]);

        $respuesta = $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.medicamentos.guardar'), [
            'id' => $id, 'nombrecomercial' => 'KEFORAL', 'nombregenerico' => 'NUEVA', 'presentacion' => 'Cap 2',
        ]);

        $fila = DB::table('vademecum')->find($id);
        $this->assertSame('CEFALEXINA', $fila->nombregenerico, 'la columna con una edición más nueva no se pisa');
        $this->assertSame('Cap 2', $fila->presentacion, 'pero eso no impide escribir la otra');
        $this->conContexto($user, $medico, $gineco)->get($respuesta->headers->get('Location'))->assertSee('más recientemente');
    }

    public function test_el_medicamento_de_otro_medico_da_404_al_editar_y_al_quitar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $ajeno = $this->medicamento($otro, '0001', 'AJENO');

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.medicamentos', ['editar' => $ajeno]))->assertNotFound();
        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.medicamentos.guardar'), ['id' => $ajeno, 'nombrecomercial' => 'TOMADO'])
            ->assertNotFound();
        $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.medicamentos.quitar', $ajeno))->assertNotFound();

        $this->assertSame('AJENO', DB::table('vademecum')->find($ajeno)->nombrecomercial);
    }

    /**
     * La fila del catálogo de medicamentos (pauta de Alexander, 2026-10-17): **una línea por medicamento**, con lo
     * secundario —el código y las otras marcas— fuera del listado (en el `title` de la fila y en el diálogo de edición),
     * el psicotrópico como pastilla en la misma línea y **dos acciones directas**: Editar y la papelera, alineadas.
     */
    public function test_el_listado_deja_una_linea_por_medicamento_con_la_papelera_al_lado_de_editar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->medicamento($medico, '0007', 'KEFORAL', 'CEFALEXINA', 'Cap. 500 mg', 'Tomar.', [
            'sico' => 'S', 'nombrecomercial2' => 'CEFA',
        ]);

        $html = $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.medicamentos'))
            ->assertOk()->getContent();

        // Sólo la tabla: el diálogo de edición sigue mostrando el código.
        preg_match('/<tbody>.*?<\/tbody>/s', $html, $cuerpo);
        $this->assertNotSame('', $cuerpo[0] ?? '', 'la tabla tiene que estar');

        // Lo secundario no ocupa una línea en el listado: va en el `title` de la fila y no como texto.
        $this->assertStringNotContainsString('>Código 0007', $cuerpo[0]);
        $this->assertStringNotContainsString('>también:', $cuerpo[0]);
        $this->assertMatchesRegularExpression(
            '/<td class="celda-principal" title="Código 0007[^"]*también: CEFA"/',
            $cuerpo[0],
            'el código y las otras marcas quedan en el title de la fila',
        );

        // La información clínica se queda, en la misma línea.
        $this->assertStringContainsString('Psicotrópico', $cuerpo[0]);

        // Dos acciones: Editar y la papelera, en el mismo bloque alineado y en ese orden. Nada de menú «…».
        $this->assertMatchesRegularExpression(
            '/<div class="acciones-fila"[^>]*>.*?<a class="btn btn-sm btn-outline-secondary"[^>]*>.*?Editar.*?btn btn-sm btn-outline-danger.*?bi-trash.*?<\/div>/s',
            $cuerpo[0],
            'la papelera va al lado de Editar, en el mismo bloque de acciones',
        );
        $this->assertStringNotContainsString('menu-acciones', $cuerpo[0]);

        // La papelera conserva la confirmación que nombra lo que se quita y el DELETE del formulario.
        $this->assertMatchesRegularExpression('/<form method="POST" action="[^"]+"[^>]*data-confirmar="[^"]+"/', $cuerpo[0]);
        $this->assertStringContainsString('name="_method" value="DELETE"', $cuerpo[0]);
        $this->assertMatchesRegularExpression(
            '/<button class="btn btn-sm btn-outline-danger"[^>]*aria-label="Quitar KEFORAL del vademécum"/',
            $cuerpo[0],
        );
    }

    public function test_editar_abre_el_dialogo_con_el_medicamento(): void
    {        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $id = $this->medicamento($medico, '0007', 'KEFORAL', 'CEFALEXINA', 'Cap. 500 mg', 'Tomar.', ['sico' => 'S', 'nombrecomercial2' => 'CEFA']);

        $html = $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.medicamentos', ['editar' => $id]))
            ->assertOk()->assertSee('Editar el medicamento')->assertSee('data-abrir-al-cargar="1"', false)
            ->assertSee('value="' . $id . '"', false)->assertSee('Código 0007')->getContent();

        $this->assertMatchesRegularExpression('/name="sico"[^>]*checked/', $html, 'el psicotrópico viene tildado');
        $this->assertMatchesRegularExpression('/<details\s+open\s*>/', $html, 'hay otras marcas: el bloque arranca abierto');
        $this->assertStringContainsString('value="KEFORAL"', $html);
    }

    // ----------------------------------------------------------------------- Medicamentos: quitar

    public function test_quitar_un_medicamento_sin_uso_lo_borra_y_deja_el_rastro(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $id = $this->medicamento($medico, '0001', 'KEFORAL', 'CEFALEXINA', 'Cap. 500 mg');
        $queda = $this->medicamento($medico, '0002', 'DIFLUCAN');

        $respuesta = $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.medicamentos.quitar', $id), ['buscar' => 'kef', 'page' => 1]);

        $respuesta->assertRedirect(route('clinica.catalogos.medicamentos', ['buscar' => 'kef']));
        $this->assertNull(DB::table('vademecum')->find($id));
        $this->assertNotNull(DB::table('vademecum')->find($queda));

        $baja = $this->trazas('vademecum', [$id])->first();
        $this->assertSame('deleted', $baja->operation);
        $this->assertSame('web', $baja->source);
        $this->assertSame($medico->reg_medico, $baja->reg_medico);

        $this->conContexto($user, $medico, $gineco)->get($respuesta->headers->get('Location'))->assertSee('Quitaste «KEFORAL/Cap. 500 mg» del vademécum.');
    }

    public function test_quitar_un_medicamento_en_uso_no_lo_borra_y_dice_donde_esta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $id = $this->medicamento($medico, '0001', 'KEFORAL', 'CEFALEXINA', 'Cap. 500 mg');

        // En dos récipes de pacientes (el NULL del escritorio es el récipe 1), uno de la pareja y un tratamiento.
        $this->pacienteYConsulta($medico, 1001, 1);
        $this->recipe($medico, 1001, 1, '0001', null);
        $this->recipe($medico, 1001, 1, '0001', 2);
        $this->recipe($medico, 1001, 1, '0001', 2); // la misma fila repetida no cuenta dos veces
        DB::table('recipes_pareja')->insert(['reg_medico' => $medico->reg_medico, 'nrohistoria' => 1001, 'nroconsulta' => 1, 'codemedicina' => '0001', 'cedula' => '1', 'nombre' => 'X']);
        $this->tratamiento($medico, '0001', 'CONTROL PRENATAL', [['codigo' => '0001']]);
        // El mismo código en los récipes de OTRO médico no es un uso de este.
        $this->recipe($otro, 5, 1, '0001', null);

        $respuesta = $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.medicamentos.quitar', $id));

        $this->assertNotNull(DB::table('vademecum')->find($id), 'no se borra');
        $this->assertCount(0, $this->trazas('vademecum', [$id]), 'y no deja rastro de borrado');

        $mensaje = session('error');
        $this->assertStringContainsString('No se quitó «KEFORAL/Cap. 500 mg»', $mensaje);
        $this->assertStringContainsString('2 récipes de pacientes', $mensaje);
        $this->assertStringContainsString('1 récipe de la pareja', $mensaje);
        $this->assertStringContainsString('el tratamiento «CONTROL PRENATAL»', $mensaje);
        $respuesta->assertRedirect(route('clinica.catalogos.medicamentos'));
    }

    public function test_un_medicamento_que_solo_esta_en_un_tratamiento_tampoco_se_borra(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $id = $this->medicamento($medico, '0001', 'KEFORAL');
        $this->tratamiento($medico, '0001', 'UNO', [['codigo' => '0001']]);
        $this->tratamiento($medico, '0002', 'DOS', [['codigo' => '0001']]);

        $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.medicamentos.quitar', $id));

        $this->assertNotNull(DB::table('vademecum')->find($id));
        $this->assertStringContainsString('los tratamientos «DOS» y «UNO»', session('error'));
    }

    public function test_el_uso_de_un_medicamento_se_describe_con_una_frase(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $this->medicamento($medico, '0001', 'KEFORAL');

        $this->assertFalse(UsoDeUnMedicamento::de($medico->reg_medico, '0001')->enUso());
        $this->assertSame('', UsoDeUnMedicamento::de($medico->reg_medico, '0001')->descripcion());

        $this->recipe($medico, 1, 1, '0001', null);
        $this->assertSame('1 récipe de un paciente', UsoDeUnMedicamento::de($medico->reg_medico, '0001')->descripcion());

        $this->tratamiento($medico, '0001', 'A', [['codigo' => '0001']]);
        $uso = UsoDeUnMedicamento::de($medico->reg_medico, '0001');
        $this->assertTrue($uso->enUso());
        $this->assertSame('1 récipe de un paciente y el tratamiento «A»', $uso->descripcion());
    }

    // ------------------------------------------------------------------------- Tratamientos: lectura

    public function test_el_listado_de_tratamientos_busca_por_nombre_y_es_del_medico(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $this->vademecum($medico);
        $this->tratamiento($medico, '0001', 'CONTROL PRENATAL', [['codigo' => '0003', 'descripcion' => 'ÁCIDO FÓLICO/Tabletas 5 mg'], ['codigo' => '0001']]);
        $this->tratamiento($medico, '0002', 'INFECCIÓN URINARIA');
        $this->tratamiento($otro, '0001', 'AJENO');

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.tratamientos'))
            ->assertOk()->assertSee('CONTROL PRENATAL')->assertSee('2 medicamentos')->assertSee('Sin medicamentos')
            ->assertSee('ÁCIDO FÓLICO/Tabletas 5 mg')->assertDontSee('AJENO');

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.tratamientos', ['buscar' => 'infeccion']))
            ->assertOk()->assertSee('INFECCIÓN URINARIA')->assertDontSee('CONTROL PRENATAL');

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.tratamientos', ['buscar' => 'zzz']))
            ->assertOk()->assertSee('Ningún tratamiento coincide');
    }

    public function test_sin_tratamientos_ofrece_crear_el_primero(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.tratamientos'))
            ->assertOk()->assertSee('Todavía no tienes tratamientos')->assertSee('Crear el primer tratamiento');
    }

    public function test_el_editor_de_un_tratamiento_nuevo_y_el_de_uno_existente(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        $id = $this->tratamiento($medico, '0001', 'CONTROL PRENATAL', [['codigo' => '0001', 'descripcion' => 'KEFORAL/Cap. 500 mg', 'cantidad' => 3, 'indicaciones' => 'Mi indicación']]);

        $nuevo = $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.tratamientos.nuevo'))
            ->assertOk()->assertSee('Nuevo tratamiento')->assertSee('id="recipe-form"', false)->assertSee('data-cosa="tratamiento"', false);
        $nuevo->assertSee('Todavía no hay medicamentos en este tratamiento');
        $html = $nuevo->getContent();
        $this->assertStringNotContainsString('data-recipe-modo="tratamientos"', $html, 'un tratamiento no se arma con otros');
        $this->assertStringContainsString('id="recipe-nuevo-medicamento"', $html, 'el medicamento que falta se crea desde el buscador');
        $this->assertStringContainsString('id="dialogo-medicamento"', $html);
        $this->assertStringContainsString('dos-listas', $html, 'el tratamiento a la izquierda y el vademécum a la vista a la derecha');
        $this->assertStringContainsString('data-recipe-lista-en-linea="1"', $html, 'la lista del vademécum se carga sola');

        $editar = $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.tratamientos.editar', $id))
            ->assertOk()->assertSee('Editar tratamiento')->assertSee('Código 0001')->assertSee('CONTROL PRENATAL')
            ->assertSee('KEFORAL/Cap. 500 mg')->assertSee('Mi indicación')->assertSee('data-recipe-subir', false)->getContent();
        $this->assertStringContainsString('value="3"', $editar);
    }

    // -------------------------------------------------------------------------- Tratamientos: crear

    public function test_crear_un_tratamiento_escribe_el_encabezado_y_los_medicamentos_en_orden_con_el_rastro_de_cada_fila(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        $this->tratamiento($medico, '0005', 'EXISTENTE');

        $respuesta = $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.tratamientos.crear'), [
            'editor' => 1,
            'tratamiento' => 'control prenatal',
            'items' => [
                $this->item('0002', 2, 'Una cápsula al día.'),
                $this->item('0001', 1, null),
                $this->item('0003', '', 'Una al día.'),
            ],
        ]);

        $respuesta->assertRedirect(route('clinica.catalogos.tratamientos'));

        $grupo = DB::table('recipe_grupo')->where('reg_medico', $medico->reg_medico)->where('tratamiento', 'CONTROL PRENATAL')->first();
        $this->assertNotNull($grupo, 'el nombre se guarda en mayúsculas');
        $this->assertSame('0006', $grupo->codigo, 'el mayor código + 1, en cuatro dígitos');

        $detalle = DB::table('recipe_grupo_detalle')->where('reg_medico', $medico->reg_medico)->where('codigo', '0006')->orderBy('orden')->get();
        $this->assertSame(['0002', '0001', '0003'], $detalle->pluck('codemedicina')->all(), 'el orden es el de la lista');
        $this->assertSame([0, 1, 2], $detalle->pluck('orden')->map(fn ($o) => (int) $o)->all(), 'desde 0, como el escritorio');
        $this->assertSame([2, 1, 1], $detalle->pluck('cantidad')->map(fn ($c) => (int) $c)->all(), 'la cantidad por defecto es 1');
        $this->assertSame('DIFLUCAN G/Cap. 150 mg', $detalle[0]->descripcion, 'el NOMBRE/presentación del vademécum, como el INSERT del escritorio');
        $this->assertSame('Una cápsula al día.', $detalle[0]->indicaciones);
        $this->assertNull($detalle[1]->indicaciones);

        // Un `created` por fila (encabezado y cada medicamento), bajo el nombre de su tabla, con source web.
        $this->assertSame('created', $this->trazas('recipe_grupo', [$grupo->id])->first()->operation);
        foreach ($detalle as $fila) {
            $traza = $this->trazas('recipe_grupo_detalle', [$fila->id])->first();
            $this->assertSame('created', $traza->operation);
            $this->assertSame('web', $traza->source);
            $this->assertSame($medico->reg_medico, $traza->reg_medico);
        }

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.tratamientos'))
            ->assertSee('Creaste el tratamiento «CONTROL PRENATAL» (código 0006) con 3 medicamentos.');
    }

    public function test_un_tratamiento_sin_medicamentos_se_puede_crear(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.tratamientos.crear'), ['editor' => 1, 'tratamiento' => 'VACÍO'])
            ->assertSessionHasNoErrors();

        $this->assertSame('0001', DB::table('recipe_grupo')->where('reg_medico', $medico->reg_medico)->value('codigo'));
        $this->assertSame(0, DB::table('recipe_grupo_detalle')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_crear_un_tratamiento_valida_el_nombre_el_repetido_y_los_medicamentos_desconocidos(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        $this->tratamiento($medico, '0001', 'CONTROL PRENATAL');
        $url = route('clinica.catalogos.tratamientos.crear');

        $this->conContexto($user, $medico, $gineco)->post($url, ['editor' => 1, 'tratamiento' => ''])->assertSessionHasErrors('tratamiento');
        $this->conContexto($user, $medico, $gineco)->post($url, ['editor' => 1, 'tratamiento' => str_repeat('A', 101)])->assertSessionHasErrors('tratamiento');

        $this->conContexto($user, $medico, $gineco)->post($url, ['editor' => 1, 'tratamiento' => 'control prenatal'])
            ->assertSessionHasErrors('tratamiento');
        $this->assertStringContainsString('Ya tienes un tratamiento llamado «CONTROL PRENATAL»', session('errors')->first('tratamiento'));

        // Un medicamento que no está en el vademécum no entra: no hay texto libre.
        $this->conContexto($user, $medico, $gineco)->post($url, ['editor' => 1, 'tratamiento' => 'OTRO', 'items' => [$this->item('9999')]])
            ->assertSessionHasErrors('items');
        $this->assertStringContainsString('9999', session('errors')->first('items'));

        $this->conContexto($user, $medico, $gineco)->post($url, ['editor' => 1, 'tratamiento' => 'OTRO', 'items' => [$this->item('0001', 0)]])
            ->assertSessionHasErrors('items.0.cantidad');

        $this->assertSame(1, DB::table('recipe_grupo')->where('reg_medico', $medico->reg_medico)->count(), 'nada de lo rechazado se escribió');
    }

    public function test_el_medicamento_de_otro_medico_no_entra_a_un_tratamiento(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $this->medicamento($otro, '7777', 'AJENO');

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.tratamientos.crear'), [
            'editor' => 1, 'tratamiento' => 'X', 'items' => [$this->item('7777')],
        ])->assertSessionHasErrors('items');

        $this->assertSame(0, DB::table('recipe_grupo')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_el_tratamiento_creado_se_ofrece_en_la_seccion_de_recipes_de_una_consulta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        [, $consulta] = $this->pacienteYConsulta($medico);

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.tratamientos.crear'), [
            'editor' => 1, 'tratamiento' => 'Control prenatal',
            'items' => [$this->item('0003', 2, 'Una al día.'), $this->item('0001', 1, 'Mi cefalexina.')],
        ]);

        $json = $this->conContexto($user, $medico, $gineco)
            ->getJson(route('clinica.consulta.recipes.tratamientos', $consulta->id) . '?q=prenatal')->assertOk()->json('tratamientos');

        $this->assertCount(1, $json);
        $this->assertSame('CONTROL PRENATAL', $json[0]['nombre']);
        $this->assertSame(['0003', '0001'], collect($json[0]['items'])->pluck('codigo')->all(), 'en el orden del tratamiento');
        $this->assertSame([2, 1], collect($json[0]['items'])->pluck('cantidad')->all());
        $this->assertSame('Mi cefalexina.', $json[0]['items'][1]['indicaciones']);
        $this->assertSame('ÁCIDO FÓLICO/Tabletas 5 mg', $json[0]['items'][0]['nombre']);
    }

    // ------------------------------------------------------------------------- Tratamientos: editar

    public function test_editar_un_tratamiento_escribe_solo_lo_que_cambia_con_el_rastro_por_columna(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        $id = $this->tratamiento($medico, '0001', 'CONTROL PRENATAL', [
            ['codigo' => '0001', 'cantidad' => 1, 'indicaciones' => 'Uno', 'descripcion' => 'KEFORAL/Cap. 500 mg'],
            ['codigo' => '0002', 'cantidad' => 1, 'indicaciones' => 'Dos', 'descripcion' => 'DIFLUCAN G/Cap. 150 mg'],
        ]);
        $a = (int) DB::table('recipe_grupo_detalle')->where('reg_medico', $medico->reg_medico)->where('codemedicina', '0001')->value('id');
        $b = (int) DB::table('recipe_grupo_detalle')->where('reg_medico', $medico->reg_medico)->where('codemedicina', '0002')->value('id');
        Carbon::setTestNow($this->hoy->copy()->addMinutes(5));

        // B primero y con otra cantidad; A segundo y sin tocar; C nuevo al final.
        $respuesta = $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.tratamientos.guardar', $id), [
            'editor' => 1,
            'tratamiento' => 'CONTROL PRENATAL',
            'items' => [$this->item('0002', 3, 'Dos'), $this->item('0001', 1, 'Uno'), $this->item('0003', 1, 'Tres')],
        ]);

        $respuesta->assertRedirect(route('clinica.catalogos.tratamientos'));

        $filaB = DB::table('recipe_grupo_detalle')->find($b);
        $filaA = DB::table('recipe_grupo_detalle')->find($a);
        $this->assertSame([0, 3], [(int) $filaB->orden, (int) $filaB->cantidad]);
        $this->assertSame(1, (int) $filaA->orden);

        $trazasA = $this->trazas('recipe_grupo_detalle', [$a]);
        $this->assertSame(['orden'], $trazasA->pluck('column_name')->all(), 'A sólo cambió de lugar');
        $trazasB = $this->trazas('recipe_grupo_detalle', [$b]);
        $this->assertEqualsCanonicalizing(['cantidad', 'orden'], $trazasB->pluck('column_name')->all());
        $this->assertSame(['updated', 'updated'], $trazasB->pluck('operation')->all());
        $this->assertSame(['web', 'web'], $trazasB->pluck('source')->all());

        $nueva = DB::table('recipe_grupo_detalle')->where('reg_medico', $medico->reg_medico)->where('codemedicina', '0003')->first();
        $this->assertSame(2, (int) $nueva->orden);
        $this->assertSame('ÁCIDO FÓLICO/Tabletas 5 mg', $nueva->descripcion);
        $this->assertSame('created', $this->trazas('recipe_grupo_detalle', [$nueva->id])->first()->operation);
        $this->assertCount(0, $this->trazas('recipe_grupo', [$id]), 'el encabezado no cambió');

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.tratamientos'))
            ->assertSee('Tratamiento «CONTROL PRENATAL» actualizado: agregado «ÁCIDO FÓLICO/Tabletas 5 mg», modificado «DIFLUCAN G/Cap. 150 mg», cambiado el orden.');
    }

    public function test_quitar_un_medicamento_de_un_tratamiento_borra_la_fila_y_avisa_al_telefono_con_su_nombre(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        $id = $this->tratamiento($medico, '0001', 'T', [['codigo' => '0001'], ['codigo' => '0002'], ['codigo' => '0003']]);
        $sale = (int) DB::table('recipe_grupo_detalle')->where('reg_medico', $medico->reg_medico)->where('codemedicina', '0002')->value('id');
        $queda3 = (int) DB::table('recipe_grupo_detalle')->where('reg_medico', $medico->reg_medico)->where('codemedicina', '0003')->value('id');
        Carbon::setTestNow($this->hoy->copy()->addMinute());

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.tratamientos.guardar', $id), [
            'editor' => 1, 'tratamiento' => 'T',
            'items' => [$this->item('0001', 1, 'Indicación de 0001'), $this->item('0003', 1, 'Indicación de 0003')],
        ]);

        $this->assertNull(DB::table('recipe_grupo_detalle')->find($sale));
        $this->assertSame(1, (int) DB::table('recipe_grupo_detalle')->find($queda3)->orden, 'las que siguen se renumeran');

        $this->assertSame('deleted', $this->trazas('recipe_grupo_detalle', [$sale])->first()->operation);
        $aviso = SyncChange::where('table_name', 'tratamientos_detalle')->where('record_id', $sale)->first();
        $this->assertNotNull($aviso, 'el teléfono suelta la fila de SU tabla (tratamientos_detalle)');
        $this->assertSame(['deleted', 'web', $medico->reg_medico], [$aviso->operation, $aviso->source, $aviso->reg_medico]);

        // Renumerar por una baja no es "cambiar el orden".
        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.tratamientos'))
            ->assertSee('quitado «Nombre de 0002»')->assertDontSee('cambiado el orden');
    }

    public function test_guardar_un_tratamiento_igual_no_escribe_nada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        $id = $this->tratamiento($medico, '0001', 'Control prenatal', [['codigo' => '0001', 'cantidad' => 2], ['codigo' => '0002']]);
        Carbon::setTestNow($this->hoy->copy()->addMinutes(5));

        $respuesta = $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.tratamientos.guardar', $id), [
            'editor' => 1,
            'tratamiento' => 'CONTROL PRENATAL', // el escritorio lo guardó con otras mayúsculas: no es un cambio
            'items' => [$this->item('0001', 2, 'Indicación de 0001'), $this->item('0002', 1, 'Indicación de 0002')],
        ]);

        $this->assertSame(0, SyncChange::where('reg_medico', $medico->reg_medico)->count());
        $this->assertSame('Control prenatal', DB::table('recipe_grupo')->find($id)->tratamiento);
        $this->assertSame(['2026-01-01 00:00:00'], DB::table('recipe_grupo_detalle')->where('reg_medico', $medico->reg_medico)->pluck('updated_at')->unique()->all());
        $this->conContexto($user, $medico, $gineco)->get($respuesta->headers->get('Location'))->assertSee('no tenía cambios');
    }

    public function test_renombrar_un_tratamiento_deja_el_rastro_del_nombre(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $id = $this->tratamiento($medico, '0001', 'VIEJO');
        Carbon::setTestNow($this->hoy->copy()->addMinutes(5));

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.tratamientos.guardar', $id), ['editor' => 1, 'tratamiento' => 'nuevo nombre']);

        $this->assertSame('NUEVO NOMBRE', DB::table('recipe_grupo')->find($id)->tratamiento);
        $traza = $this->trazas('recipe_grupo', [$id])->first();
        $this->assertSame(['updated', 'tratamiento', 'web'], [$traza->operation, $traza->column_name, $traza->source]);
    }

    public function test_el_tratamiento_conserva_un_medicamento_que_el_vademecum_ya_no_tiene(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $id = $this->tratamiento($medico, '0001', 'T', [['codigo' => '0099', 'descripcion' => 'VIEJO/Cap']]);

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.tratamientos.guardar', $id), [
            'editor' => 1, 'tratamiento' => 'T', 'items' => [$this->item('0099', 2, 'Indicación de 0099')],
        ])->assertSessionHasNoErrors();

        $fila = DB::table('recipe_grupo_detalle')->where('reg_medico', $medico->reg_medico)->first();
        $this->assertSame('0099', $fila->codemedicina);
        $this->assertSame(2, (int) $fila->cantidad);
    }

    public function test_el_tratamiento_de_otro_medico_da_404_al_abrir_guardar_y_quitar(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $ajeno = $this->tratamiento($otro, '0001', 'AJENO', [['codigo' => '0001']]);

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.tratamientos.editar', $ajeno))->assertNotFound();
        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.tratamientos.guardar', $ajeno), ['editor' => 1, 'tratamiento' => 'TOMADO'])->assertNotFound();
        $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.tratamientos.quitar', $ajeno))->assertNotFound();

        $this->assertSame('AJENO', DB::table('recipe_grupo')->find($ajeno)->tratamiento);
        $this->assertSame(1, DB::table('recipe_grupo_detalle')->where('reg_medico', $otro->reg_medico)->count());
    }

    // ------------------------------------------------------------------------- Tratamientos: quitar

    public function test_quitar_un_tratamiento_borra_el_encabezado_y_todos_sus_medicamentos_y_no_toca_los_recipes_hechos(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        $id = $this->tratamiento($medico, '0001', 'CONTROL PRENATAL', [['codigo' => '0001'], ['codigo' => '0002']]);
        $otroTratamiento = $this->tratamiento($medico, '0002', 'OTRO', [['codigo' => '0003']]);
        $filas = DB::table('recipe_grupo_detalle')->where('reg_medico', $medico->reg_medico)->where('codigo', '0001')->pluck('id')->map(fn ($i) => (int) $i)->all();

        // Un récipe ya hecho con esos medicamentos (se copió al cargar el tratamiento).
        $this->pacienteYConsulta($medico);
        $recipe = $this->recipe($medico, 1001, 1, '0001');

        $respuesta = $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.tratamientos.quitar', $id));

        $respuesta->assertRedirect(route('clinica.catalogos.tratamientos'));
        $this->assertNull(DB::table('recipe_grupo')->find($id));
        $this->assertSame(0, DB::table('recipe_grupo_detalle')->where('reg_medico', $medico->reg_medico)->where('codigo', '0001')->count());
        $this->assertNotNull(DB::table('recipe_grupo')->find($otroTratamiento), 'el otro tratamiento no se toca');
        $this->assertSame(1, DB::table('recipe_grupo_detalle')->where('reg_medico', $medico->reg_medico)->where('codigo', '0002')->count());
        $this->assertNotNull(DB::table('recipes')->find($recipe), 'los récipes ya hechos no cambian');
        $this->assertSame(2, DB::table('vademecum')->where('reg_medico', $medico->reg_medico)->whereIn('codemedicina', ['0001', '0002'])->count(), 'y el vademécum tampoco');

        // El rastro: un `deleted` por fila con el nombre del servidor y otro con el que entiende el teléfono.
        foreach (['recipe_grupo' => [$id], 'tratamientos' => [$id], 'recipe_grupo_detalle' => $filas, 'tratamientos_detalle' => $filas] as $tabla => $ids) {
            $trazas = $this->trazas($tabla, $ids);
            $this->assertCount(count($ids), $trazas, $tabla);
            $this->assertSame(['deleted'], $trazas->pluck('operation')->unique()->all(), $tabla);
            $this->assertSame(['web'], $trazas->pluck('source')->unique()->all(), $tabla);
        }

        $this->conContexto($user, $medico, $gineco)->get($respuesta->headers->get('Location'))->assertSee('Quitaste el tratamiento «CONTROL PRENATAL» (2 medicamentos).');
    }

    // ------------------------------------------------------------------------------ Quién entra

    public function test_la_secretaria_no_entra_a_los_catalogos_ni_ve_la_entrada(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        $secretaria = $this->secretariaConAcceso($medico);
        $id = $this->tratamiento($medico, '0001', 'T');
        $med = (int) DB::table('vademecum')->where('reg_medico', $medico->reg_medico)->value('id');

        $this->conContexto($secretaria, $medico, $gineco)->get(route('clinica.catalogos'))->assertForbidden();
        $this->conContexto($secretaria, $medico, $gineco)->get(route('clinica.catalogos.medicamentos'))->assertForbidden();
        $this->conContexto($secretaria, $medico, $gineco)->get(route('clinica.catalogos.medicamentos.buscar'))->assertForbidden();
        $this->conContexto($secretaria, $medico, $gineco)->post(route('clinica.catalogos.medicamentos.guardar'), $this->formulario())->assertForbidden();
        $this->conContexto($secretaria, $medico, $gineco)->delete(route('clinica.catalogos.medicamentos.quitar', $med))->assertForbidden();
        $this->conContexto($secretaria, $medico, $gineco)->get(route('clinica.catalogos.tratamientos'))->assertForbidden();
        $this->conContexto($secretaria, $medico, $gineco)->get(route('clinica.catalogos.tratamientos.nuevo'))->assertForbidden();
        $this->conContexto($secretaria, $medico, $gineco)->post(route('clinica.catalogos.tratamientos.crear'), ['tratamiento' => 'X'])->assertForbidden();
        $this->conContexto($secretaria, $medico, $gineco)->post(route('clinica.catalogos.tratamientos.guardar', $id), ['tratamiento' => 'X'])->assertForbidden();
        $this->conContexto($secretaria, $medico, $gineco)->delete(route('clinica.catalogos.tratamientos.quitar', $id))->assertForbidden();

        $this->assertNotNull(DB::table('vademecum')->find($med));
        $this->assertNotNull(DB::table('recipe_grupo')->find($id));

        // Y la barra de la secretaría no ofrece la puerta.
        $this->conContexto($secretaria, $medico, $gineco)->get(route('clinica.agenda'))->assertOk()->assertDontSee(route('clinica.catalogos.medicamentos'));
    }

    public function test_el_medico_ve_la_entrada_de_catalogos_y_lleva_a_los_medicamentos(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.agenda'))->assertOk()
            ->assertSee('title="Catálogos"', false)->assertSee(route('clinica.catalogos.medicamentos'));

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos'))->assertRedirect(route('clinica.catalogos.medicamentos'));

        // El índice ya no es un segmentado arriba de la pantalla: los catálogos son submenús de «Catálogos» en la barra
        // lateral, con el de la pantalla marcado (y el grupo abierto).
        $html = $this->conContexto($user, $medico, $gineco)->get(route('clinica.catalogos.tratamientos'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<a class="nav-link active" href="[^"]*\/catalogos\/tratamientos"[^>]*aria-current="page"/', $html);
        $this->assertMatchesRegularExpression('/<a class="nav-link " href="[^"]*\/catalogos\/medicamentos"/', $html);
        $this->assertStringContainsString('<details class="nav-grupo" data-menu="catalogos" open>', $html);
        $this->assertStringNotContainsString('catalogos-indice', $html);
    }

    public function test_un_invitado_va_al_login(): void
    {
        $this->get(route('clinica.catalogos.medicamentos'))->assertRedirect(route('login'));
        $this->get(route('clinica.catalogos.tratamientos'))->assertRedirect(route('login'));
        $this->post(route('clinica.catalogos.medicamentos.guardar'), $this->formulario())->assertRedirect(route('login'));
        $this->post(route('clinica.catalogos.tratamientos.crear'), ['tratamiento' => 'X'])->assertRedirect(route('login'));
    }

    public function test_el_buscador_json_es_el_del_vademecum_del_medico(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $this->vademecum($medico);
        $this->medicamento($otro, '0001', 'KEFORAL AJENO');

        $json = $this->conContexto($user, $medico, $gineco)->getJson(route('clinica.catalogos.medicamentos.buscar', ['q' => 'acido']))->assertOk()->json();

        $this->assertSame('acido', $json['buscar']);
        $this->assertSame(['0003'], collect($json['medicamentos'])->pluck('codigo')->all());
        $this->assertSame('Una al día.', $json['medicamentos'][0]['uso']);
        $this->assertSame(['0001'], collect($this->conContexto($user, $medico, $gineco)->getJson(route('clinica.catalogos.medicamentos.buscar', ['q' => 'kef']))->json('medicamentos'))->pluck('codigo')->all());
    }

    // ------------------------------------------------------------------- El sync: escritorio y móvil

    public function test_el_movil_baja_lo_editado_por_delta_y_se_entera_de_las_bajas_con_los_nombres_de_su_tabla(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->vademecum($medico);
        $sin = $this->medicamento($medico, '0009', 'SIN USO');
        $trat = $this->tratamiento($medico, '0001', 'T', [['codigo' => '0001']]);
        $fila = (int) DB::table('recipe_grupo_detalle')->where('reg_medico', $medico->reg_medico)->value('id');
        $desde = $this->hoy->copy()->subMinute()->toIso8601String();

        // La web crea un medicamento, edita otro, quita uno, y quita un tratamiento.
        Carbon::setTestNow($this->hoy->copy()->addMinutes(10));
        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.medicamentos.guardar'), $this->formulario());
        $nuevo = (int) DB::table('vademecum')->where('reg_medico', $medico->reg_medico)->where('nombrecomercial', 'ADEVIT')->value('id');
        $keforal = (int) DB::table('vademecum')->where('reg_medico', $medico->reg_medico)->where('codemedicina', '0001')->value('id');
        $this->conContexto($user, $medico, $gineco)->post(route('clinica.catalogos.medicamentos.guardar'), [
            'id' => $keforal, 'nombrecomercial' => 'KEFORAL', 'nombregenerico' => 'CEFALEXINA', 'presentacion' => 'Cap. 1 g',
        ]);
        $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.medicamentos.quitar', $sin));
        $this->conContexto($user, $medico, $gineco)->delete(route('clinica.catalogos.tratamientos.quitar', $trat));

        $desdeElMovil = $this->hoy->copy()->addMinutes(5)->toIso8601String();
        Sanctum::actingAs($user, ['*'], 'api');
        $pull = $this->postJson('/api/app/sync-app-data', ['changes' => [], 'since' => $desdeElMovil])->assertOk();

        $idsVademecum = collect($pull->json('vademecum'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertEqualsCanonicalizing([$nuevo, $keforal], $idsVademecum, 'el delta trae lo creado y lo editado, no lo demás');
        $this->assertSame('Cap. 1 g', collect($pull->json('vademecum'))->firstWhere('id', $keforal)['presentacion']);

        $eliminados = collect($pull->json('eliminados'))->map(fn ($e) => $e['table_name'] . ':' . $e['record_id'])->all();
        $this->assertContains('vademecum:' . $sin, $eliminados, 'el teléfono suelta el medicamento de SU tabla vademecum');
        $this->assertContains('tratamientos:' . $trat, $eliminados, 'y el tratamiento de SU tabla tratamientos, no de recipe_grupo');
        $this->assertContains('tratamientos_detalle:' . $fila, $eliminados);

        // Antes de las ediciones, el primer pull del móvil sigue trayendo el vademécum como siempre.
        $primero = $this->postJson('/api/app/sync-app-data', ['changes' => [], 'since' => $desde])->assertOk();
        $this->assertNotEmpty($primero->json('vademecum'));
    }

    public function test_el_movil_no_cambia_ni_necesita_nada_para_el_vademecum_y_los_tratamientos_siguen_siendo_de_solo_lectura(): void
    {
        [$user, $medico] = $this->medicoConAcceso();

        // El móvil sólo LEE estas tablas: una "creación" suya de vademecum o de tratamientos no existe en la API.
        Sanctum::actingAs($user, ['*'], 'api');
        $respuesta = $this->postJson('/api/app/sync-app-data', ['changes' => [
            ['table' => 'vademecum', 'operation' => 'created', 'temp_id' => -1, 'nombrecomercial' => 'DESDE EL MOVIL'],
        ]]);

        $this->assertSame(0, DB::table('vademecum')->where('reg_medico', $medico->reg_medico)->count());
    }
}
