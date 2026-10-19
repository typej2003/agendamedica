<?php

namespace Tests\Feature;

use App\Actions\Catalogos\GuardarDosisDeVacunaDelCatalogo;
use App\Actions\Catalogos\GuardarVacunaDelCatalogo;
use App\Actions\Catalogos\QuitarVacunaDelCatalogo;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\SyncChange;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **El catálogo de vacunas del médico** (WEB-7.4.4e): las Actions y la pantalla `/clinica/catalogos/vacunas`. Se fija que el alta da el código
 * siguiente y el nombre en mayúsculas sin repetir; que edita sólo lo que cambió; que las dosis no repiten número; que no se quita una vacuna ya
 * aplicada; que «Cargar el catálogo inicial» copia las novedades sin pisar; que no toca el catálogo inicial ni otro consultorio; y que es del médico.
 */
class VacunasCatalogoWebTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('vacunas')->whereNull('reg_medico')->delete();
        DB::table('vacunas_dosis')->whereNull('reg_medico')->delete();
    }

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $especialidad = 'pediatria'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $esp = Specialty::firstOrCreate(['slug' => $especialidad], ['name' => ucfirst($especialidad)]);
        $reg = 'vac-cat-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'vac-cat-' . substr(uniqid(), -6) . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');
        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dra. de prueba', 'lastname' => 'Catalogo', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $reg,
        ]);
        $medico->specialties()->attach($esp->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        return [$user, $medico, $esp];
    }

    private function conContexto(Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => ['reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null]]);
    }

    private function datos(array $extra = []): array
    {
        return array_merge(['vacuna' => 'rotavirus', 'edad_inicial_meses' => '2', 'fija' => 'O', 'sexo' => 'A', 'cobertura' => 'Diarrea'], $extra);
    }

    private function vacuna(?string $reg, string $codigo, string $nombre, array $extra = []): int
    {
        return DB::table('vacunas')->insertGetId(array_merge([
            'reg_medico' => $reg, 'codi_vacuna' => $codigo, 'vacuna' => $nombre, 'fija' => 'O', 'sexo' => 'A', 'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    // ------------------------------------------------------------------------------------------ Las Actions

    public function test_el_alta_da_el_codigo_que_sigue_y_el_nombre_en_mayusculas_con_su_rastro(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $this->vacuna($medico->reg_medico, '05', 'BCG');
        $this->vacuna($medico->reg_medico, 'X1', 'CODIGO RARO');
        $accion = app(GuardarVacunaDelCatalogo::class);

        $r = $accion->ejecutar($medico->reg_medico, $this->datos());

        $this->assertTrue($r->creado);
        $fila = DB::table('vacunas')->where('reg_medico', $medico->reg_medico)->where('vacuna', 'ROTAVIRUS')->first();
        $this->assertSame('06', $fila->codi_vacuna);
        $this->assertSame('Diarrea', $fila->cobertura);
        $this->assertSame(2, (int) $fila->edad_inicial_meses);
        $this->assertSame('created', SyncChange::where('table_name', 'vacunas')->where('record_id', $fila->id)->first()->operation);

        // Una segunda alta toma el 07, y la primera del catálogo vacío es la 01.
        $accion->ejecutar($medico->reg_medico, $this->datos(['vacuna' => 'Polio']));
        $this->assertSame('07', DB::table('vacunas')->where('reg_medico', $medico->reg_medico)->where('vacuna', 'POLIO')->value('codi_vacuna'));
        [, $otro] = $this->medicoConAcceso();
        $accion->ejecutar($otro->reg_medico, $this->datos());
        $this->assertSame('01', DB::table('vacunas')->where('reg_medico', $otro->reg_medico)->value('codi_vacuna'));
    }

    public function test_no_repite_el_nombre_aunque_cambien_tildes_o_mayusculas_y_valida(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $accion = app(GuardarVacunaDelCatalogo::class);
        $accion->ejecutar($medico->reg_medico, $this->datos(['vacuna' => 'Sarampión']));

        $casos = [
            [['vacuna' => 'SARAMPION'], 'Ya tienes una vacuna'],
            [['vacuna' => ''], 'Escribe el nombre'],
            [['vacuna' => str_repeat('x', 101)], 'no puede pasar de 100'],
            [['fija' => 'X'], 'obligatoria o recomendada'],
            [['sexo' => 'Q'], 'sexo'],
            [['edad_inicial_meses' => 'dos'], 'edad inicial'],
            [['edad_inicial_meses' => '9999'], 'edad inicial'],
            [['cobertura' => str_repeat('c', 201)], 'cobertura'],
        ];

        foreach ($casos as [$extra, $mensaje]) {
            try {
                $accion->ejecutar($medico->reg_medico, $this->datos(array_merge(['vacuna' => 'Otra'], $extra)));
                $this->fail("debería rechazar: $mensaje");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($mensaje, $e->getMessage());
            }
        }

        $this->assertSame(1, DB::table('vacunas')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_editar_cambia_solo_lo_que_cambio_y_no_toca_el_codigo(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $id = $this->vacuna($medico->reg_medico, '05', 'BCG', ['edad_inicial_meses' => 0]);
        $accion = app(GuardarVacunaDelCatalogo::class);

        $mismo = $accion->ejecutar($medico->reg_medico, $this->datos(['vacuna' => 'BCG', 'edad_inicial_meses' => '0', 'cobertura' => '']), $id);
        $this->assertContains('fija', $mismo->columnas === [] ? ['fija'] : []);

        $r = $accion->ejecutar($medico->reg_medico, $this->datos(['vacuna' => 'BCG', 'edad_inicial_meses' => '1', 'fija' => 'R', 'cobertura' => '']), $id);
        $fila = DB::table('vacunas')->where('id', $id)->first();
        $this->assertSame('05', $fila->codi_vacuna);
        $this->assertSame(1, (int) $fila->edad_inicial_meses);
        $this->assertSame('R', $fila->fija);
        $this->assertContains('edad_inicial_meses', $r->columnas);
        $this->assertNotContains('vacuna', $r->columnas);
    }

    public function test_no_edita_el_catalogo_inicial_ni_el_de_otro_consultorio(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $inicial = $this->vacuna(null, '05', 'BCG INICIAL');
        $ajena = $this->vacuna('otro-consultorio', '06', 'AJENA');

        foreach ([$inicial, $ajena] as $id) {
            try {
                app(GuardarVacunaDelCatalogo::class)->ejecutar($medico->reg_medico, $this->datos(['vacuna' => 'Cambiada']), $id);
                $this->fail('debería rechazar');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('no está en tu catálogo', $e->getMessage());
            }
            try {
                app(QuitarVacunaDelCatalogo::class)->ejecutar($medico->reg_medico, $id);
                $this->fail('debería rechazar');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('no está en tu catálogo', $e->getMessage());
            }
        }

        $this->assertSame('BCG INICIAL', DB::table('vacunas')->where('id', $inicial)->value('vacuna'));
        $this->assertSame('AJENA', DB::table('vacunas')->where('id', $ajena)->value('vacuna'));
    }

    public function test_las_dosis_no_repiten_numero_y_se_editan_y_se_quitan(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $id = $this->vacuna($medico->reg_medico, '16', 'POLIO');
        $accion = app(GuardarDosisDeVacunaDelCatalogo::class);

        $accion->ejecutar($medico->reg_medico, $id, ['dosis' => '1', 'dosis_descri' => '1era. Dosis', 'meses' => '2']);
        $accion->ejecutar($medico->reg_medico, $id, ['dosis' => '2', 'dosis_descri' => 'Refuerzo', 'meses' => '15,5']);
        $filas = DB::table('vacunas_dosis')->where('reg_medico', $medico->reg_medico)->where('codi_vacuna', '16')->orderBy('dosis')->get();
        $this->assertCount(2, $filas);
        $this->assertEquals(15.5, $filas[1]->meses);

        foreach ([[['dosis' => '1'], 'ya tiene una dosis'], [['dosis' => '0'], 'entero de 1 a 99'], [['dosis' => '3', 'meses' => '-1'], 'meses'], [['dosis' => '3', 'dosis_descri' => str_repeat('x', 51)], '50 caracteres']] as [$datos, $mensaje]) {
            try {
                $accion->ejecutar($medico->reg_medico, $id, $datos);
                $this->fail("debería rechazar: $mensaje");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($mensaje, $e->getMessage());
            }
        }

        $r = $accion->ejecutar($medico->reg_medico, $id, ['dosis' => '1', 'dosis_descri' => 'Primera', 'meses' => '2'], (int) $filas[0]->id);
        $this->assertSame(['dosis_descri'], $r->columnas);

        $accion->quitar($medico->reg_medico, (int) $filas[1]->id);
        $this->assertSame(1, DB::table('vacunas_dosis')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_no_quita_una_vacuna_ya_aplicada_y_si_la_quita_se_lleva_sus_dosis(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $aplicada = $this->vacuna($medico->reg_medico, '05', 'BCG');
        $libre = $this->vacuna($medico->reg_medico, '06', 'ROTAVIRUS');
        foreach (['05', '06'] as $c) {
            DB::table('vacunas_dosis')->insert(['reg_medico' => $medico->reg_medico, 'codi_vacuna' => $c, 'dosis' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('vacuna_paciente')->insert(['reg_medico' => $medico->reg_medico, 'historia' => 1, 'codi_vacuna' => '05', 'ejecutada' => 'S',
            'fecha_ejecutada' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()]);
        $accion = app(QuitarVacunaDelCatalogo::class);

        try {
            $accion->ejecutar($medico->reg_medico, $aplicada);
            $this->fail('debería rechazar');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('ya se aplicó 1 vez', $e->getMessage());
        }
        $this->assertSame(1, DB::table('vacunas_dosis')->where('reg_medico', $medico->reg_medico)->where('codi_vacuna', '05')->count());

        $this->assertSame('ROTAVIRUS', $accion->ejecutar($medico->reg_medico, $libre));
        $this->assertSame(0, DB::table('vacunas')->where('id', $libre)->count());
        $this->assertSame(0, DB::table('vacunas_dosis')->where('reg_medico', $medico->reg_medico)->where('codi_vacuna', '06')->count());
    }

    // ------------------------------------------------------------------------------------------ La pantalla

    public function test_la_pantalla_lista_las_del_medico_con_sus_dosis_y_oculta_el_resto(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $id = $this->vacuna($medico->reg_medico, '16', 'POLIO', ['edad_inicial_meses' => 2, 'cobertura' => 'POLIOMIELITIS', 'fija' => 'R']);
        DB::table('vacunas_dosis')->insert(['reg_medico' => $medico->reg_medico, 'codi_vacuna' => '16', 'dosis' => 1, 'dosis_descri' => '1era. Dosis', 'meses' => 2,
            'created_at' => now(), 'updated_at' => now()]);
        $this->vacuna('otro-consultorio', '99', 'AJENA');

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.catalogos.vacunas'))
            ->assertOk()->assertSee('POLIO')->assertSee('POLIOMIELITIS')->assertSee('Recomendada')->assertSee('1era. Dosis · 2 m')
            ->assertDontSee('AJENA')->assertDontSee('id="vacunas-novedades"', false);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.catalogos.vacunas', ['buscar' => 'poliomielitis']))
            ->assertOk()->assertSee('POLIO');
        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.catalogos.vacunas', ['buscar' => 'zzz']))
            ->assertOk()->assertSee('Sin resultados');
        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.catalogos.vacunas', ['editar' => $id]))
            ->assertOk()->assertSee('Editar la vacuna')->assertSee('data-abrir-al-cargar="1"', false);
        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.catalogos.vacunas', ['dosis_de' => $id]))
            ->assertOk()->assertSee('id="dialogo-dosis"', false)->assertSee('Dosis de POLIO');
    }

    public function test_crear_editar_y_quitar_desde_la_pantalla(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $sesion = fn () => $this->conContexto($medico, $pediatria)->actingAs($user);

        $sesion()->post(route('clinica.catalogos.vacunas.guardar'), $this->datos())
            ->assertRedirect(route('clinica.catalogos.vacunas'))->assertSessionHas('estado');
        $id = DB::table('vacunas')->where('reg_medico', $medico->reg_medico)->value('id');

        $sesion()->post(route('clinica.catalogos.vacunas.guardar'), $this->datos(['id' => $id, 'edad_inicial_meses' => '4']))->assertSessionHas('estado');
        $this->assertSame(4, (int) DB::table('vacunas')->where('id', $id)->value('edad_inicial_meses'));

        $sesion()->post(route('clinica.catalogos.vacunas.guardar'), $this->datos(['vacuna' => '']))->assertSessionHasErrors('vacuna');
        $sesion()->post(route('clinica.catalogos.vacunas.guardar'), $this->datos(['fija' => 'Z']))->assertSessionHasErrors('fija');

        $sesion()->post(route('clinica.catalogos.vacunas.dosis.guardar', $id), ['dosis' => '1', 'dosis_descri' => 'Única', 'meses' => '4'])
            ->assertRedirect(route('clinica.catalogos.vacunas', ['dosis_de' => $id]));
        $sesion()->post(route('clinica.catalogos.vacunas.dosis.guardar', $id), ['dosis' => '1'])
            ->assertSessionHas('error', 'Esta vacuna ya tiene una dosis con ese número.');
        $dosis = DB::table('vacunas_dosis')->where('reg_medico', $medico->reg_medico)->value('id');
        $sesion()->delete(route('clinica.catalogos.vacunas.dosis.quitar', $dosis))->assertSessionHas('estado');
        $this->assertSame(0, DB::table('vacunas_dosis')->where('reg_medico', $medico->reg_medico)->count());

        $sesion()->delete(route('clinica.catalogos.vacunas.quitar', $id))->assertSessionHas('estado');
        $this->assertSame(0, DB::table('vacunas')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_cargar_el_catalogo_inicial_ofrece_las_novedades_y_no_pisa_lo_del_medico(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->vacuna(null, '05', 'BCG INICIAL');
        $this->vacuna(null, '06', 'ROTA INICIAL');
        DB::table('vacunas_dosis')->insert(['reg_medico' => null, 'codi_vacuna' => '06', 'dosis' => 1, 'dosis_descri' => 'Única', 'meses' => 2, 'created_at' => now(), 'updated_at' => now()]);
        $this->vacuna($medico->reg_medico, '05', 'MI BCG');

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.catalogos.vacunas'))
            ->assertOk()->assertSee('id="vacunas-novedades"', false)->assertSee('Cargar el catálogo inicial')->assertSee('ROTA INICIAL');

        $this->conContexto($medico, $pediatria)->actingAs($user)->post(route('clinica.catalogos.vacunas.cargar-inicial'))
            ->assertRedirect(route('clinica.catalogos.vacunas'))->assertSessionHas('estado');

        $this->assertSame('MI BCG', DB::table('vacunas')->where('reg_medico', $medico->reg_medico)->where('codi_vacuna', '05')->value('vacuna'));
        $this->assertSame('ROTA INICIAL', DB::table('vacunas')->where('reg_medico', $medico->reg_medico)->where('codi_vacuna', '06')->value('vacuna'));
        $this->assertSame(1, DB::table('vacunas_dosis')->where('reg_medico', $medico->reg_medico)->count());

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.catalogos.vacunas'))
            ->assertOk()->assertDontSee('id="vacunas-novedades"', false);
        $this->conContexto($medico, $pediatria)->actingAs($user)->post(route('clinica.catalogos.vacunas.cargar-inicial'))
            ->assertSessionHas('estado', 'Ya tienes todo lo que trae el catálogo inicial: no se agregó nada.');
    }

    public function test_el_submenu_es_de_pediatria_y_la_secretaria_no_entra(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.catalogos.vacunas'))
            ->assertOk()->assertSee(route('clinica.catalogos.vacunas'), false);

        [$userG, $medicoG, $gineco] = $this->medicoConAcceso('ginecologia-y-obstetricia');
        $this->conContexto($medicoG, $gineco)->actingAs($userG)->get(route('clinica.catalogos.medicamentos'))
            ->assertOk()->assertDontSee(route('clinica.catalogos.vacunas'), false);

        $secretaria = User::create([
            'name' => 'Secretaria', 'email' => 'vac-cat-secre' . substr(uniqid(), -6) . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);
        $this->conContexto($medico, $pediatria)->actingAs($secretaria)->get(route('clinica.catalogos.vacunas'))->assertForbidden();
        $this->conContexto($medico, $pediatria)->actingAs($secretaria)->post(route('clinica.catalogos.vacunas.cargar-inicial'))->assertForbidden();
    }

    public function test_la_entrada_del_modulo_vacunas_manda_a_elegir_al_nino(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.vacunas'))
            ->assertRedirect(route('clinica.pacientes'))->assertSessionHas('estado');
    }
}
