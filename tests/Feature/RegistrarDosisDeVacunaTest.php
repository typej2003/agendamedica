<?php

namespace Tests\Feature;

use App\Actions\Vacunas\RegistrarDosisDeVacuna;
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
 * **Registrar una dosis aplicada** (WEB-7.4.4b): la Action `RegistrarDosisDeVacuna` y su formulario en el calendario de vacunas del niño.
 * Se fija que escribe la fila del legado con sus campos y el rastro de la web, no duplica una dosis, completa la planeada que dejó el escritorio,
 * rechaza lo que no se puede (vacuna ajena, fecha futura o anterior al nacimiento) y que el calendario la muestra como aplicada.
 */
class RegistrarDosisDeVacunaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-17 09:00:00'));
        DB::table('vacunas')->whereNull('reg_medico')->delete();
        DB::table('vacunas_dosis')->whereNull('reg_medico')->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $pediatria = Specialty::firstOrCreate(['slug' => 'pediatria'], ['name' => 'Pediatría']);
        $reg = 'dosis-test-' . uniqid();

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'dosis-' . uniqid() . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');
        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dra. de prueba', 'lastname' => 'Dosis', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $reg,
        ]);
        $medico->specialties()->attach($pediatria->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        return [$user, $medico, $pediatria];
    }

    private function conContexto(Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => ['reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null]]);
    }

    private function nino(Medico $medico, int $historia = 9501, ?string $nacimiento = '2026-06-17'): int
    {
        $id = DB::table('pacientes')->insertGetId([
            'nombres' => 'Mateo', 'apellidos' => 'Dosis', 'sexo' => 'M', 'fnacimiento' => $nacimiento, 'created_at' => now(), 'updated_at' => now(),
        ]);
        MedicoPaciente::create(['medico_id' => $medico->id, 'paciente_id' => $id, 'reg_medico' => $medico->reg_medico, 'numhistoria' => $historia === 0 ? null : (string) $historia]);

        return $id;
    }

    private function catalogo(Medico $medico): void
    {
        DB::table('vacunas')->insert([
            'reg_medico' => $medico->reg_medico, 'codi_vacuna' => 'POLI', 'vacuna' => 'Polio', 'edad_inicial_meses' => 2, 'fija' => 'O', 'sexo' => 'A',
            'prioridad' => 'A', 'tipo_vacu' => 'ORAL', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([[1, '1era. Dosis', 2], [2, '2da. Dosis', 4]] as [$n, $r, $m]) {
            DB::table('vacunas_dosis')->insert(['reg_medico' => $medico->reg_medico, 'codi_vacuna' => 'POLI', 'dosis' => $n, 'dosis_descri' => $r, 'meses' => $m,
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function accion(): RegistrarDosisDeVacuna
    {
        return app(RegistrarDosisDeVacuna::class);
    }

    private function filas(Medico $medico, int $historia = 9501)
    {
        return DB::table('vacuna_paciente')->where('reg_medico', $medico->reg_medico)->where('historia', $historia)->orderBy('id')->get();
    }

    // ------------------------------------------------------------------------------------------ La Action

    public function test_crea_la_fila_con_sus_campos_y_el_rastro_de_la_web(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $this->catalogo($medico);

        $resultado = $this->accion()->ejecutar($medico->reg_medico, 9501, 'POLI', 1, '1era. Dosis', [
            'fecha_ejecutada' => '2026-08-20', 'lote' => ' L-77 ', 'via' => 'Oral', 'zona' => '', 'fecha_vence' => '2027-01-01',
            'fecha_proxima_dosis' => '2026-10-20', 'nota' => 'Sin reacción',
        ], '2026-06-17', '2026-08-17');

        $this->assertTrue($resultado->creada);
        $fila = $this->filas($medico)->first();
        $this->assertSame('S', $fila->ejecutada);
        $this->assertSame('POLI', $fila->codi_vacuna);
        $this->assertSame('1era. Dosis', $fila->dosis);
        $this->assertSame(1, (int) $fila->dosis_numero);
        $this->assertSame('Polio', $fila->descripcion);
        $this->assertSame('A', $fila->prioridad);
        $this->assertSame('ORAL', $fila->tipo_vacu);
        $this->assertSame('L-77', $fila->lote);
        $this->assertNull($fila->zona);
        $this->assertSame('S', $fila->aplicada_aca);
        $this->assertSame('2026-08-17', Carbon::parse($fila->fecha_planeada)->toDateString());
        $this->assertSame('2026-08-20', Carbon::parse($fila->fecha_ejecutada)->toDateString());
        $this->assertSame('2026-10-20', Carbon::parse($fila->fecha_proxima_dosis)->toDateString());
        // 64 días de edad ≈ 2,1 meses.
        $this->assertEquals(2.10, $fila->edad_aplicada);

        $rastro = SyncChange::where('table_name', 'vacuna_paciente')->where('record_id', $fila->id)->first();
        $this->assertSame('created', $rastro->operation);
        $this->assertSame('web', $rastro->source);
    }

    public function test_no_duplica_una_dosis_ya_aplicada(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $this->catalogo($medico);
        $this->accion()->ejecutar($medico->reg_medico, 9501, 'POLI', 1, '1era. Dosis', ['fecha_ejecutada' => '2026-08-20']);

        foreach ([[1, '1era. Dosis'], [null, '1ERA. DOSIS']] as [$numero, $rotulo]) {
            try {
                $this->accion()->ejecutar($medico->reg_medico, 9501, 'POLI', $numero, $rotulo, ['fecha_ejecutada' => '2026-09-01']);
                $this->fail('debería rechazar la dosis repetida');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('ya está registrada', $e->getMessage());
            }
        }

        // Otra dosis de la misma vacuna el mismo día sí choca con la clave del escritorio; otro día no.
        try {
            $this->accion()->ejecutar($medico->reg_medico, 9501, 'POLI', 2, '2da. Dosis', ['fecha_ejecutada' => '2026-08-20']);
            $this->fail('misma clave');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('ese día', $e->getMessage());
        }
        $this->accion()->ejecutar($medico->reg_medico, 9501, 'POLI', 2, '2da. Dosis', ['fecha_ejecutada' => '2026-10-16']);

        $this->assertCount(2, $this->filas($medico));
    }

    public function test_completa_la_planeada_que_dejo_el_escritorio_sin_crear_otra_fila(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $this->catalogo($medico);
        $id = DB::table('vacuna_paciente')->insertGetId([
            'reg_medico' => $medico->reg_medico, 'historia' => 9501, 'codi_vacuna' => 'POLI', 'dosis_numero' => 2, 'dosis' => '2da. Dosis',
            'fecha_planeada' => '2026-10-17', 'fecha_ejecutada' => '2026-10-17', 'ejecutada' => 'N', 'lote' => 'VIEJO', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $resultado = $this->accion()->ejecutar($medico->reg_medico, 9501, 'POLI', 2, '2da. Dosis', ['fecha_ejecutada' => '2026-10-16', 'lote' => 'VIEJO'], '2026-06-17');

        $this->assertFalse($resultado->creada);
        $this->assertContains('ejecutada', $resultado->columnas);
        $this->assertNotContains('lote', $resultado->columnas);
        $this->assertCount(1, $this->filas($medico));
        $fila = DB::table('vacuna_paciente')->where('id', $id)->first();
        $this->assertSame('S', $fila->ejecutada);
        $this->assertSame('2026-10-16', Carbon::parse($fila->fecha_ejecutada)->toDateString());
        $this->assertSame('updated', SyncChange::where('table_name', 'vacuna_paciente')->where('record_id', $id)->first()->operation);
    }

    public function test_rechaza_vacuna_ajena_fecha_futura_y_fecha_anterior_al_nacimiento(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $this->catalogo($medico);
        DB::table('vacunas')->insert(['reg_medico' => 'otro', 'codi_vacuna' => 'AJEN', 'vacuna' => 'De otro', 'created_at' => now(), 'updated_at' => now()]);

        $casos = [
            ['AJEN', ['fecha_ejecutada' => '2026-09-01'], 'catálogo'],
            ['POLI', ['fecha_ejecutada' => '2026-10-18'], 'posterior a hoy'],
            ['POLI', ['fecha_ejecutada' => '2026-06-01'], 'anterior al nacimiento'],
            ['POLI', ['fecha_ejecutada' => ''], 'Escribe la fecha'],
            ['POLI', ['fecha_ejecutada' => 'ayer'], 'no es una fecha válida'],
            ['POLI', ['fecha_ejecutada' => '2026-09-01', 'fecha_proxima_dosis' => '2026-08-01'], 'próxima dosis'],
        ];

        foreach ($casos as [$codigo, $datos, $mensaje]) {
            try {
                $this->accion()->ejecutar($medico->reg_medico, 9501, $codigo, 1, '1era. Dosis', $datos, '2026-06-17');
                $this->fail("debería rechazar: $mensaje");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($mensaje, $e->getMessage());
            }
        }

        $this->assertCount(0, $this->filas($medico));
    }

    public function test_no_mezcla_consultorios_ni_historias(): void
    {
        [, $a] = $this->medicoConAcceso();
        [, $b] = $this->medicoConAcceso();
        $this->catalogo($a);
        $this->catalogo($b);

        $this->accion()->ejecutar($a->reg_medico, 9501, 'POLI', 1, '1era. Dosis', ['fecha_ejecutada' => '2026-08-20']);
        // El mismo número de historia en otro consultorio, y otra historia en el mismo, son independientes.
        $this->accion()->ejecutar($b->reg_medico, 9501, 'POLI', 1, '1era. Dosis', ['fecha_ejecutada' => '2026-08-20']);
        $this->accion()->ejecutar($a->reg_medico, 9502, 'POLI', 1, '1era. Dosis', ['fecha_ejecutada' => '2026-08-20']);

        $this->assertCount(1, $this->filas($a, 9501));
        $this->assertCount(1, $this->filas($b, 9501));
        $this->assertCount(1, $this->filas($a, 9502));
    }

    // ------------------------------------------------------------------------------------------ La pantalla

    public function test_el_calendario_ofrece_registrar_y_despues_la_muestra_aplicada(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->catalogo($medico);
        $id = $this->nino($medico);
        $url = route('clinica.pacientes.vacunas', $id);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($url)
            ->assertOk()->assertSee('data-registrar-dosis', false)->assertSee('id="dialogo-registrar-dosis"', false)
            ->assertSee('data-numero="1"', false);

        $this->conContexto($medico, $pediatria)->actingAs($user)->post(route('clinica.pacientes.vacunas.registrar', $id), [
            'codi_vacuna' => 'POLI', 'dosis_numero' => 1, 'rotulo' => '1era. Dosis', 'planeada' => '2026-08-17',
            'fecha_ejecutada' => '2026-08-20', 'lote' => 'L-1',
        ])->assertRedirect($url)->assertSessionHas('estado');

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($url)
            ->assertOk()->assertSeeInOrder(['1era. Dosis', '17/08/2026', '20/08/2026', 'Lote L-1', 'Aplicada']);
    }

    public function test_el_formulario_rechaza_con_aviso_y_la_validacion_es_en_espanol(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->catalogo($medico);
        $id = $this->nino($medico);
        $url = route('clinica.pacientes.vacunas', $id);
        $post = route('clinica.pacientes.vacunas.registrar', $id);

        $this->conContexto($medico, $pediatria)->actingAs($user)->post($post, ['codi_vacuna' => 'POLI', 'dosis_numero' => 1, 'fecha_ejecutada' => '2026-12-01'])
            ->assertRedirect($url)->assertSessionHas('error', 'La fecha de aplicación no puede ser posterior a hoy.');

        $this->conContexto($medico, $pediatria)->actingAs($user)->post($post, ['codi_vacuna' => 'NOEX', 'fecha_ejecutada' => '2026-09-01'])
            ->assertRedirect($url)->assertSessionHas('error');

        $this->conContexto($medico, $pediatria)->actingAs($user)->post($post, ['lote' => str_repeat('x', 21)])
            ->assertSessionHasErrors(['codi_vacuna', 'fecha_ejecutada', 'lote']);

        $this->assertCount(0, $this->filas($medico));
    }

    public function test_sin_historia_no_se_ofrece_ni_se_escribe(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->catalogo($medico);
        $id = $this->nino($medico, 0);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.pacientes.vacunas', $id))
            ->assertOk()->assertDontSee('data-registrar-dosis', false);

        $this->conContexto($medico, $pediatria)->actingAs($user)
            ->post(route('clinica.pacientes.vacunas.registrar', $id), ['codi_vacuna' => 'POLI', 'dosis_numero' => 1, 'fecha_ejecutada' => '2026-09-01'])
            ->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, DB::table('vacuna_paciente')->where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_la_secretaria_no_registra_y_el_paciente_ajeno_da_404(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->catalogo($medico);
        $id = $this->nino($medico);
        $datos = ['codi_vacuna' => 'POLI', 'dosis_numero' => 1, 'fecha_ejecutada' => '2026-09-01'];

        $secretaria = User::create([
            'name' => 'Secretaria', 'email' => 'dosis-secre-' . uniqid() . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);
        $this->conContexto($medico, $pediatria)->actingAs($secretaria)->post(route('clinica.pacientes.vacunas.registrar', $id), $datos)->assertForbidden();

        [$otroUser, $otro, $otraEsp] = $this->medicoConAcceso();
        $this->conContexto($otro, $otraEsp)->actingAs($otroUser)->post(route('clinica.pacientes.vacunas.registrar', $id), $datos)->assertNotFound();

        $this->assertSame(0, DB::table('vacuna_paciente')->count() - DB::table('vacuna_paciente')->whereNotIn('reg_medico', [$medico->reg_medico, $otro->reg_medico])->count());
    }
}
