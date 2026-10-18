<?php

namespace Tests\Feature;

use App\Actions\Catalogos\GuardarDietaDelCatalogo;
use App\Actions\Catalogos\QuitarDietaDelCatalogo;
use App\Clinica\Consulta\DietasDelCatalogo;
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
 * **El catálogo de dietas del médico** (WEB-12.4b): las Actions y la pantalla `/clinica/catalogos/dietas`. Se fija que el alta da el código siguiente
 * (dos dígitos) con el título en mayúsculas sin repetir; que edita sólo lo que cambió; que siempre se puede quitar; que no toca otro consultorio; que lo
 * que se guarda es lo que la pestaña *Alimentación* ofrece; y que es del médico y de pediatría.
 */
class DietasCatalogoWebTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $especialidad = 'pediatria'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $esp = Specialty::firstOrCreate(['slug' => $especialidad], ['name' => ucfirst($especialidad)]);
        $reg = 'die-cat-' . uniqid();

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'die-cat-' . uniqid() . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');
        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dra. de prueba', 'lastname' => 'Dietas', 'email' => $user->email,
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
        return array_merge(['dietas' => 'lactancia materna', 'descripcion' => 'Pecho a libre demanda.', 'calorias' => '650'], $extra);
    }

    private function dieta(?string $reg, string $codigo, string $titulo, string $texto = 'Texto', $calorias = null): int
    {
        return DB::table('dietas')->insertGetId([
            'reg_medico' => $reg, 'cod_dieta' => $codigo, 'dietas' => $titulo, 'descripcion' => $texto, 'calorias' => $calorias,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------------------------------ Las Actions

    public function test_el_alta_da_el_codigo_que_sigue_el_titulo_en_mayusculas_y_su_rastro(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $this->dieta($medico->reg_medico, '05', 'ALGUNA');
        $this->dieta($medico->reg_medico, 'X1', 'CODIGO RARO');
        $accion = app(GuardarDietaDelCatalogo::class);

        $r = $accion->ejecutar($medico->reg_medico, $this->datos());

        $this->assertTrue($r->creado);
        $fila = DB::table('dietas')->where('reg_medico', $medico->reg_medico)->where('dietas', 'LACTANCIA MATERNA')->first();
        $this->assertSame('06', $fila->cod_dieta);
        $this->assertSame('Pecho a libre demanda.', $fila->descripcion);
        $this->assertEquals(650, $fila->calorias);
        $this->assertSame('created', SyncChange::where('table_name', 'dietas')->where('record_id', $fila->id)->first()->operation);

        [, $otro] = $this->medicoConAcceso();
        $accion->ejecutar($otro->reg_medico, $this->datos(['calorias' => '']));
        $nueva = DB::table('dietas')->where('reg_medico', $otro->reg_medico)->first();
        $this->assertSame('01', $nueva->cod_dieta);
        $this->assertNull($nueva->calorias);
    }

    public function test_no_repite_el_titulo_y_valida(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $accion = app(GuardarDietaDelCatalogo::class);
        $accion->ejecutar($medico->reg_medico, $this->datos(['dietas' => 'Alimentación complementaria']));

        $casos = [
            [['dietas' => 'ALIMENTACION COMPLEMENTARIA'], 'Ya tienes una dieta'],
            [['dietas' => ''], 'Escribe el título'],
            [['dietas' => str_repeat('x', 201)], 'no puede pasar de 200'],
            [['descripcion' => '   '], 'Escribe el texto'],
            [['descripcion' => str_repeat('y', GuardarDietaDelCatalogo::LARGO_TEXTO + 1)], 'no puede pasar de'],
            [['calorias' => 'mucha'], 'calorías'],
            [['calorias' => '100000'], 'calorías'],
        ];

        foreach ($casos as [$extra, $mensaje]) {
            try {
                $accion->ejecutar($medico->reg_medico, $this->datos(array_merge(['dietas' => 'Otra'], $extra)));
                $this->fail("debería rechazar: $mensaje");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($mensaje, $e->getMessage());
            }
        }

        $this->assertSame(1, DB::table('dietas')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_editar_cambia_solo_lo_que_cambio_y_no_toca_el_codigo(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $id = $this->dieta($medico->reg_medico, '05', 'LACTANCIA', "Linea 1\nLinea 2", 500);
        $accion = app(GuardarDietaDelCatalogo::class);

        $mismo = $accion->ejecutar($medico->reg_medico, ['dietas' => 'Lactancia', 'descripcion' => "Linea 1\r\nLinea 2", 'calorias' => '500'], $id);
        $this->assertTrue($mismo->sinCambios());

        $r = $accion->ejecutar($medico->reg_medico, ['dietas' => 'Lactancia', 'descripcion' => 'Nuevo texto', 'calorias' => '600,5'], $id);
        $fila = DB::table('dietas')->where('id', $id)->first();
        $this->assertSame('05', $fila->cod_dieta);
        $this->assertSame('Nuevo texto', $fila->descripcion);
        $this->assertEquals(600.5, $fila->calorias);
        $this->assertContains('descripcion', $r->columnas);
        $this->assertNotContains('dietas', $r->columnas);
    }

    public function test_no_edita_ni_quita_la_dieta_de_otro_consultorio(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $ajena = $this->dieta('otro-consultorio', '06', 'AJENA');

        try {
            app(GuardarDietaDelCatalogo::class)->ejecutar($medico->reg_medico, $this->datos(['dietas' => 'Cambiada']), $ajena);
            $this->fail('debería rechazar');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('no está en tu catálogo', $e->getMessage());
        }
        try {
            app(QuitarDietaDelCatalogo::class)->ejecutar($medico->reg_medico, $ajena);
            $this->fail('debería rechazar');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('no está en tu catálogo', $e->getMessage());
        }
        $this->assertSame('AJENA', DB::table('dietas')->where('id', $ajena)->value('dietas'));
    }

    public function test_siempre_se_puede_quitar_y_deja_su_rastro(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $id = $this->dieta($medico->reg_medico, '01', 'DIETA BLANDA');

        $this->assertSame('DIETA BLANDA', app(QuitarDietaDelCatalogo::class)->ejecutar($medico->reg_medico, $id));
        $this->assertSame(0, DB::table('dietas')->where('id', $id)->count());
        $this->assertSame('deleted', SyncChange::where('table_name', 'dietas')->where('record_id', $id)->orderByDesc('id')->first()->operation);
    }

    public function test_lo_que_se_guarda_es_lo_que_ofrece_la_pestana_alimentacion(): void
    {
        [, $medico] = $this->medicoConAcceso();
        app(GuardarDietaDelCatalogo::class)->ejecutar($medico->reg_medico, $this->datos());

        $ofrecidas = app(DietasDelCatalogo::class)->de($medico->reg_medico);
        $this->assertCount(1, $ofrecidas);
        $this->assertSame('LACTANCIA MATERNA', $ofrecidas->first()->titulo);
        $this->assertSame('Pecho a libre demanda.', $ofrecidas->first()->texto);
    }

    // ------------------------------------------------------------------------------------------ La pantalla

    public function test_la_pantalla_lista_las_del_medico_y_oculta_el_resto(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $id = $this->dieta($medico->reg_medico, '01', 'LACTANCIA', 'Pecho exclusivo', 650);
        $this->dieta($medico->reg_medico, '02', 'SIN TEXTO', 'none');
        $this->dieta('otro-consultorio', '03', 'AJENA');

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.catalogos.dietas'))
            ->assertOk()->assertSee('LACTANCIA')->assertSee('650 kcal')->assertSee('Sin texto')->assertDontSee('AJENA');

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.catalogos.dietas', ['buscar' => 'pecho']))
            ->assertOk()->assertSee('LACTANCIA')->assertDontSee('SIN TEXTO');
        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.catalogos.dietas', ['buscar' => 'zzz']))
            ->assertOk()->assertSee('Sin resultados');
        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.catalogos.dietas', ['editar' => $id]))
            ->assertOk()->assertSee('Editar la dieta')->assertSee('data-abrir-al-cargar="1"', false);
        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.catalogos.dietas', ['nuevo' => 1]))
            ->assertOk()->assertSee('Nueva dieta')->assertSee('data-abrir-al-cargar="1"', false);
    }

    public function test_crear_editar_y_quitar_desde_la_pantalla(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $sesion = fn () => $this->conContexto($medico, $pediatria)->actingAs($user);

        $sesion()->post(route('clinica.catalogos.dietas.guardar'), $this->datos())
            ->assertRedirect(route('clinica.catalogos.dietas'))->assertSessionHas('estado');
        $id = DB::table('dietas')->where('reg_medico', $medico->reg_medico)->value('id');

        $sesion()->post(route('clinica.catalogos.dietas.guardar'), $this->datos(['id' => $id, 'calorias' => '700']))->assertSessionHas('estado');
        $this->assertEquals(700, DB::table('dietas')->where('id', $id)->value('calorias'));

        $sesion()->post(route('clinica.catalogos.dietas.guardar'), $this->datos(['dietas' => '']))->assertSessionHasErrors('dietas');
        $sesion()->post(route('clinica.catalogos.dietas.guardar'), $this->datos(['dietas' => 'Otra', 'descripcion' => '']))->assertSessionHasErrors('descripcion');
        $sesion()->post(route('clinica.catalogos.dietas.guardar'), $this->datos(['dietas' => 'Otra', 'calorias' => 'x']))->assertSessionHasErrors('calorias');

        $sesion()->delete(route('clinica.catalogos.dietas.quitar', $id))->assertSessionHas('estado');
        $this->assertSame(0, DB::table('dietas')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_el_submenu_es_de_pediatria_y_la_secretaria_no_entra(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.catalogos.dietas'))
            ->assertOk()->assertSee(route('clinica.catalogos.dietas'), false);

        [$userG, $medicoG, $gineco] = $this->medicoConAcceso('ginecologia-y-obstetricia');
        $this->conContexto($medicoG, $gineco)->actingAs($userG)->get(route('clinica.catalogos.medicamentos'))
            ->assertOk()->assertDontSee(route('clinica.catalogos.dietas'), false);

        $secretaria = User::create([
            'name' => 'Secretaria', 'email' => 'die-cat-secre-' . uniqid() . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);
        $this->conContexto($medico, $pediatria)->actingAs($secretaria)->get(route('clinica.catalogos.dietas'))->assertForbidden();
        $this->conContexto($medico, $pediatria)->actingAs($secretaria)->post(route('clinica.catalogos.dietas.guardar'), $this->datos())->assertForbidden();
    }

    public function test_editar_una_dieta_ajena_da_404(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $ajena = $this->dieta('otro-consultorio', '03', 'AJENA');

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.catalogos.dietas', ['editar' => $ajena]))->assertNotFound();
    }
}
