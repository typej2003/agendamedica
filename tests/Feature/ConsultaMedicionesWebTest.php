<?php

namespace Tests\Feature;

use App\Actions\Consulta\GuardarMedicionesDeLaConsulta;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\PestanasDelExamen;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\PercentilesPaciente;
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
 * **Las mediciones de la consulta** (WEB-7.4.3a): la solapa *Mediciones* de pediatría y su Action `GuardarMedicionesDeLaConsulta`.
 *
 * Se fija que: la solapa sólo se ofrece en pediatría y es con la que abre; la Action escribe **una fila por consulta** en
 * `percentiles_paciente` con el IMC calculado, **sólo lo que cambió** y con el rastro `source = web`; no crea filas en blanco; la coma
 * decimal se acepta; el guard del sync no se pisa; y la pantalla compara con las curvas **si hay referencia** y lo dice si no. La referencia
 * de la prueba es **inventada** (números redondos): no sale de ningún dato real.
 */
class ConsultaMedicionesWebTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hoy = Carbon::parse('2026-10-13 09:00:00');
        Carbon::setTestNow($this->hoy->copy());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $especialidad = 'pediatria', string $rol = 'Medico'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $specialty = Specialty::firstOrCreate(['slug' => $especialidad], ['name' => ucfirst($especialidad)]);
        $regMedico = 'medi-test-' . substr(uniqid(), -6);

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'medi-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dra. de prueba',
            'lastname'   => 'Mediciones',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($specialty->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $specialty];
    }

    private function conContexto(Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico'   => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id'    => null,
        ]]);
    }

    /** Un niño (sexo `M`, nacido hace 100 días) con su consulta de hoy. */
    private function ninoYConsulta(Medico $medico, int $numhistoria = 3001, int $nroconsulta = 1, string $sexo = 'M', ?string $nacimiento = '2026-07-05'): Consulta
    {
        $pacienteId = DB::table('pacientes')->insertGetId([
            'nombres' => 'Mateo', 'apellidos' => 'Pérez', 'sexo' => $sexo, 'fnacimiento' => $nacimiento,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        MedicoPaciente::firstOrCreate(
            ['medico_id' => $medico->id, 'reg_medico' => $medico->reg_medico, 'numhistoria' => (string) $numhistoria],
            ['paciente_id' => $pacienteId],
        );
        Historia::firstOrCreate(
            ['numhistoria' => (string) $numhistoria, 'reg_medico' => $medico->reg_medico],
            ['paciente_id' => $pacienteId, 'medico_id' => $medico->id],
        );

        return Consulta::create([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => $numhistoria, 'nroconsulta' => $nroconsulta,
            'fecha' => $this->hoy->toDateString(),
        ]);
    }

    private function url(Consulta $consulta, ?string $pestana = PestanasDelExamen::MEDICIONES): string
    {
        $parametros = [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_EVALUACION];
        if ($pestana !== null) {
            $parametros['pestana'] = $pestana;
        }

        return route('clinica.consulta', $parametros);
    }

    private function fila(Consulta $consulta): ?PercentilesPaciente
    {
        return PercentilesPaciente::where('reg_medico', $consulta->reg_medico)
            ->where('hhistoria', $consulta->numhistoria)->where('hconsulta', $consulta->nroconsulta)->first();
    }

    /** Una referencia de la prueba para los niños: a los 100 días el peso es 5 / 6 / 7 y la talla 55 / 60 / 65. */
    private function sembrarReferencia(Medico $medico): void
    {
        DB::table('percentil_nino')->insert([
            'reg_medico' => $medico->reg_medico, 'edad_ini_dia' => 91, 'edad_fin_dia' => 365, 'anos' => 0.25,
            'peso_b' => 5, 'peso_m' => 6, 'peso_a' => 7,
            'talla_b' => 55, 'talla_m' => 60, 'talla_a' => 65,
            'imc_b' => 15, 'imc_m' => 16, 'imc_a' => 18,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------------------------------ La Action

    public function test_guardar_crea_la_fila_de_la_consulta_con_el_imc_y_el_rastro_de_la_web(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $consulta = $this->ninoYConsulta($medico);

        $resultado = app(GuardarMedicionesDeLaConsulta::class)->ejecutar($consulta, [
            'peso' => '16', 'talla' => '100', 'c_cefalica' => '50,5', 'c_brazo' => '', 'pulso' => '90',
            'p_a_sistolica' => '100', 'p_a_diastolica' => '60',
        ]);

        $this->assertTrue($resultado->creado);
        $fila = $this->fila($consulta);
        $this->assertNotNull($fila);
        $this->assertSame(16.0, $fila->peso);
        $this->assertSame(50.5, $fila->c_cefalica);
        $this->assertNull($fila->c_brazo);
        $this->assertSame(16.0, $fila->imc);
        $this->assertSame('2026-10-13', $fila->fecha->toDateString());
        $this->assertSame(1, PercentilesPaciente::where('hhistoria', 3001)->where('reg_medico', $medico->reg_medico)->count());

        $rastro = SyncChange::where('table_name', 'percentiles_paciente')->where('record_id', $fila->id)->first();
        $this->assertSame('created', $rastro->operation);
        $this->assertSame('web', $rastro->source);
    }

    public function test_editar_escribe_solo_lo_que_cambio_y_recalcula_el_imc(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $consulta = $this->ninoYConsulta($medico);
        $accion = app(GuardarMedicionesDeLaConsulta::class);
        $accion->ejecutar($consulta, ['peso' => '16', 'talla' => '100', 'pulso' => '90']);

        $mismo = $accion->ejecutar($consulta, ['peso' => '16', 'talla' => '100', 'pulso' => '90']);
        $this->assertTrue($mismo->sinCambios());

        $cambio = $accion->ejecutar($consulta, ['peso' => '20', 'talla' => '100']);
        $this->assertEqualsCanonicalizing(['peso', 'imc'], $cambio->columnas);
        $fila = $this->fila($consulta);
        $this->assertSame(20.0, $fila->imc);
        // El pulso no viajó: no se toca.
        $this->assertSame(90.0, $fila->pulso);

        // Una columna que viaja vacía se borra y el IMC queda sin valor (un minuto después: el reloj de la prueba está quieto).
        Carbon::setTestNow($this->hoy->copy()->addMinute());
        $accion->ejecutar($consulta, ['talla' => '']);
        $fila = $this->fila($consulta);
        $this->assertNull($fila->talla);
        $this->assertNull($fila->imc);
        $this->assertSame(1, PercentilesPaciente::where('hhistoria', 3001)->count());
    }

    public function test_un_formulario_en_blanco_no_crea_filas_ni_acepta_valores_invalidos(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $consulta = $this->ninoYConsulta($medico);
        $accion = app(GuardarMedicionesDeLaConsulta::class);

        $this->assertTrue($accion->ejecutar($consulta, ['peso' => '', 'talla' => null])->sinCambios());
        $this->assertNull($this->fila($consulta));

        $this->expectException(\InvalidArgumentException::class);
        $accion->ejecutar($consulta, ['peso' => 'abc']);
    }

    public function test_el_guard_del_sync_no_se_pisa(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $consulta = $this->ninoYConsulta($medico);
        $accion = app(GuardarMedicionesDeLaConsulta::class);
        $accion->ejecutar($consulta, ['peso' => '10']);
        $fila = $this->fila($consulta);

        SyncChange::create([
            'reg_medico' => $medico->reg_medico, 'table_name' => 'percentiles_paciente', 'record_id' => (int) $fila->id,
            'operation' => 'updated', 'column_name' => 'peso', 'value' => '11',
            'occurred_at' => $this->hoy->copy()->addSecond(), 'source' => 'escritorio',
        ]);

        $resultado = $accion->ejecutar($consulta, ['peso' => '12']);

        $this->assertSame(['peso'], $resultado->bloqueadas);
        $this->assertSame(10.0, $this->fila($consulta)->peso);
    }

    // ------------------------------------------------------------------------------------------ La pantalla

    public function test_en_pediatria_la_evaluacion_abre_en_las_mediciones_y_ofrece_solo_dos_solapas(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $consulta = $this->ninoYConsulta($medico);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($consulta, null))
            ->assertOk()
            ->assertSee('id="mediciones-form"', false)
            ->assertSee('Examen físico')
            ->assertSee('Edad en esta consulta: 3 meses 8 días')
            ->assertDontSee('Imágenes de mamas')
            ->assertDontSee('Examen ginecológico');
    }

    /** Alexander, 2026-10-26: la PA diastólica aceptaba «170/90» y el perímetro cefálico cualquier cosa; faltaban las máscaras. */
    public function test_cada_medicion_lleva_su_mascara_numerica(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $consulta = $this->ninoYConsulta($medico);

        $html = $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($consulta, null))->assertOk()->getContent();

        foreach (['peso' => ['decimal', 3, 3], 'talla' => ['decimal', 3, 1], 'c_cefalica' => ['decimal', 3, 1], 'c_brazo' => ['decimal', 3, 1],
            'pulso' => ['entero', 3, 0], 'p_a_sistolica' => ['entero', 3, 0], 'p_a_diastolica' => ['entero', 3, 0]] as $columna => [$tipo, $enteros, $decimales]) {
            $this->assertMatchesRegularExpression(
                '/name="' . $columna . '"[^>]*data-mascara="' . $tipo . '" data-enteros="' . $enteros . '" data-decimales="' . $decimales . '"/',
                $html,
                "«{$columna}» tiene que llevar su máscara ({$tipo}, {$enteros} enteros, {$decimales} decimales).",
            );
        }
    }

    public function test_en_ginecologia_no_se_ofrece_la_solapa(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso('ginecologia-y-obstetricia');
        $consulta = $this->ninoYConsulta($medico);

        $this->conContexto($medico, $gineco)->actingAs($user)->get($this->url($consulta, PestanasDelExamen::FISICO))
            ->assertOk()->assertDontSee('data-chips-mediciones', false)->assertDontSee('>Mediciones<', false);
    }

    public function test_sin_referencia_la_pantalla_lo_dice_y_no_falla(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $consulta = $this->ninoYConsulta($medico);
        app(GuardarMedicionesDeLaConsulta::class)->ejecutar($consulta, ['peso' => '6', 'talla' => '60']);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($consulta))
            ->assertOk()
            ->assertSee('todavía no tiene cargadas las curvas de referencia')
            ->assertSee('data-medicion-imc', false)
            ->assertSee('16,67');
    }

    public function test_con_referencia_compara_cada_medida_con_las_curvas(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->sembrarReferencia($medico);
        $consulta = $this->ninoYConsulta($medico);
        app(GuardarMedicionesDeLaConsulta::class)->ejecutar($consulta, ['peso' => '8', 'talla' => '58']);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($consulta))
            ->assertOk()
            ->assertSee('Por encima de la curva alta')
            ->assertSee('Entre la curva baja y la media')
            ->assertDontSee('todavía no tiene cargadas');
    }

    public function test_sin_sexo_la_pantalla_pide_el_dato_en_vez_de_comparar(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->sembrarReferencia($medico);
        $consulta = $this->ninoYConsulta($medico, 3002, 1, '');
        DB::table('pacientes')->where('nombres', 'Mateo')->update(['sexo' => null]);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($consulta))
            ->assertOk()->assertSee('Falta el sexo del niño');
    }

    public function test_el_post_guarda_y_vuelve_a_la_solapa(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $consulta = $this->ninoYConsulta($medico);

        $this->conContexto($medico, $pediatria)->actingAs($user)
            ->post(route('clinica.consulta.mediciones.guardar', $consulta->id), ['peso' => '12,5', 'talla' => '90'])
            ->assertRedirect($this->url($consulta))->assertSessionHas('estado');

        $this->assertSame(12.5, $this->fila($consulta)->peso);

        $this->conContexto($medico, $pediatria)->actingAs($user)
            ->post(route('clinica.consulta.mediciones.guardar', $consulta->id), ['peso' => '12,5', 'talla' => '90'])
            ->assertSessionHas('estado', 'Las mediciones no tenían cambios.');
    }

    public function test_los_datos_invalidos_se_rechazan_con_aviso_en_espanol(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $consulta = $this->ninoYConsulta($medico);

        $respuesta = $this->conContexto($medico, $pediatria)->actingAs($user)
            ->post(route('clinica.consulta.mediciones.guardar', $consulta->id), ['peso' => 'mucho', 'talla' => '0', 'pulso' => '9999']);

        $respuesta->assertSessionHasErrors(['peso', 'talla', 'pulso']);
        $this->assertSame('El peso tiene que ser un número (hasta 3 decimales).', session('errors')->first('peso'));
        $this->assertNull($this->fila($consulta));
    }

    public function test_la_secretaria_y_el_consultorio_ajeno_no_escriben(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $consulta = $this->ninoYConsulta($medico);

        $secretaria = User::create([
            'name' => 'Secretaria', 'email' => 'medi-secre-' . substr(uniqid(), -6) . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        $this->conContexto($medico, $pediatria)->actingAs($secretaria)
            ->post(route('clinica.consulta.mediciones.guardar', $consulta->id), ['peso' => '5'])->assertForbidden();

        [$otroUser, $otro, $otraEsp] = $this->medicoConAcceso();
        $this->conContexto($otro, $otraEsp)->actingAs($otroUser)
            ->post(route('clinica.consulta.mediciones.guardar', $consulta->id), ['peso' => '5'])->assertNotFound();

        $this->assertNull($this->fila($consulta));
    }
}
