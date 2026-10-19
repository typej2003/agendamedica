<?php

namespace Tests\Feature;

use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\PercentilesPaciente;
use App\Models\Specialty;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **Las gráficas de crecimiento** (WEB-7.4.3c): `/clinica/pacientes/{id}/crecimiento` y su hoja impresa. Se fija que: sin mediciones dice
 * por qué; con mediciones y sin referencia dibuja los puntos y avisa que faltan las curvas; con la referencia dibuja las tres curvas y los
 * puntos en SVG; el juego de edades sigue a la edad del niño y se puede cambiar; cada gráfica tiene su dato; es del médico (la secretaría 403) y
 * de su consultorio (otro 404). La referencia de la prueba es **inventada**: ninguna fila sale de datos reales.
 */
class CrecimientoWebTest extends TestCase
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
    private function medicoConAcceso(string $rol = 'Medico'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $pediatria = Specialty::firstOrCreate(['slug' => 'pediatria'], ['name' => 'Pediatría']);
        $reg = 'crec-test-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'crec-' . substr(uniqid(), -6) . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dra. de prueba', 'lastname' => 'Crecimiento', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $reg,
        ]);
        $medico->specialties()->attach($pediatria->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $reg]);

        return [$user, $medico, $pediatria];
    }

    private function conContexto(Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null,
        ]]);
    }

    /** Un niño nacido el 2024-10-13 (2 años hoy) con su historia; devuelve el id del paciente. */
    private function nino(Medico $medico, int $historia = 5001, string $sexo = 'M', string $nacimiento = '2024-10-13'): int
    {
        $id = DB::table('pacientes')->insertGetId([
            'nombres' => 'Mateo', 'apellidos' => 'Pérez', 'sexo' => $sexo, 'fnacimiento' => $nacimiento,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        MedicoPaciente::create(['medico_id' => $medico->id, 'paciente_id' => $id, 'reg_medico' => $medico->reg_medico, 'numhistoria' => (string) $historia]);
        Historia::create(['numhistoria' => (string) $historia, 'reg_medico' => $medico->reg_medico, 'paciente_id' => $id, 'medico_id' => $medico->id]);

        return $id;
    }

    /** Una consulta con su medición (kg, cm). */
    private function medir(Medico $medico, int $historia, int $consulta, string $fecha, array $medidas): void
    {
        Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => $historia, 'nroconsulta' => $consulta, 'fecha' => $fecha]);
        PercentilesPaciente::create(array_merge([
            'reg_medico' => $medico->reg_medico, 'hhistoria' => $historia, 'hconsulta' => $consulta, 'fecha' => $fecha,
        ], $medidas));
    }

    /** Dos rangos de referencia para los niños (0–1 y 1–5 años), con números redondos. */
    private function sembrarReferencia(Medico $medico): void
    {
        foreach ([[0, 365, 0.0, 3.0], [366, 1825, 1.0, 10.0]] as [$ini, $fin, $anos, $peso]) {
            DB::table('percentil_nino')->insert([
                'reg_medico' => $medico->reg_medico, 'edad_ini_dia' => $ini, 'edad_fin_dia' => $fin, 'anos' => $anos,
                'peso_b' => $peso, 'peso_m' => $peso + 1, 'peso_a' => $peso + 2,
                'talla_b' => 50 + $peso, 'talla_m' => 55 + $peso, 'talla_a' => 60 + $peso,
                'cefalica_b' => 33, 'cefalica_m' => 35, 'cefalica_a' => 37,
                'imc_b' => 14, 'imc_m' => 16, 'imc_a' => 18,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function url(int $paciente, array $query = []): string
    {
        return route('clinica.pacientes.crecimiento', array_merge([$paciente], $query));
    }

    public function test_sin_mediciones_dice_por_que_y_no_ofrece_imprimir(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $id = $this->nino($medico);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($id))
            ->assertOk()
            ->assertSee('id="crecimiento-vacio"', false)
            ->assertSee('Todavía no hay mediciones')
            ->assertSee('Evaluación física → Mediciones')
            ->assertDontSee('<svg', false);
    }

    public function test_con_mediciones_y_sin_referencia_dibuja_los_puntos_y_avisa(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $id = $this->nino($medico);
        $this->medir($medico, 5001, 1, '2025-10-13', ['peso' => 10, 'talla' => 75]);
        $this->medir($medico, 5001, 2, '2026-10-13', ['peso' => 12.5, 'talla' => 88]);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($id))
            ->assertOk()
            ->assertSee('<svg', false)
            ->assertSee('class="grafica-nino-punto"', false)
            ->assertDontSee('grafica-curva-media', false)
            ->assertSee('id="crecimiento-sin-referencia"', false)
            ->assertSee('Sin curvas de referencia')
            ->assertSee('12,5')
            // Más nueva primero, con la edad a esa fecha.
            ->assertSeeInOrder(['13/10/2026', '2 años', '13/10/2025', '1 año']);
    }

    public function test_con_referencia_dibuja_las_tres_curvas_y_dice_donde_cae_cada_medicion(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->sembrarReferencia($medico);
        $id = $this->nino($medico);
        // A los 2 años (731 días): referencia 10 / 11 / 12 kg; 15 kg queda por encima de la alta.
        $this->medir($medico, 5001, 1, '2026-10-13', ['peso' => 15, 'talla' => 62]);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($id))
            ->assertOk()
            ->assertSee('grafica-curva-baja', false)
            ->assertSee('grafica-curva-media', false)
            ->assertSee('grafica-curva-alta', false)
            ->assertSee('grafica-franja', false)
            ->assertDontSee('id="crecimiento-sin-referencia"', false)
            ->assertSee('Sobre la alta');
    }

    public function test_el_juego_de_edades_sigue_al_nino_y_se_puede_cambiar(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $chico = $this->nino($medico, 5001, 'M', '2024-10-13');
        $grande = $this->nino($medico, 5002, 'F', '2016-10-13');
        $this->medir($medico, 5001, 1, '2026-10-13', ['peso' => 12]);
        $this->medir($medico, 5002, 1, '2026-10-13', ['peso' => 30]);

        $respuesta = $this->conContexto($medico, $pediatria)->actingAs($user);
        $respuesta->get($this->url($chico))->assertSee('Peso · 0 a 5 años');
        $respuesta->get($this->url($grande))->assertSee('Peso · 5 a 19 años');
        // Cambiar el juego: el niño de 2 años no tiene mediciones entre los 5 y 19.
        $respuesta->get($this->url($chico, ['juego' => '5-19']))->assertSee('Peso · 5 a 19 años')
            ->assertSee('no tiene mediciones de esta gráfica entre los 5 a 19 años');
        // Un juego o una gráfica que no existen vuelven a los de siempre.
        $respuesta->get($this->url($chico, ['juego' => 'x', 'grafica' => 'y']))->assertOk()->assertSee('Peso · 0 a 5 años');
    }

    public function test_cada_grafica_tiene_su_dato_y_el_peso_para_la_talla_usa_la_talla(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $id = $this->nino($medico);
        $this->medir($medico, 5001, 1, '2026-10-13', ['peso' => 12, 'talla' => 88, 'c_cefalica' => 48]);

        $sesion = $this->conContexto($medico, $pediatria)->actingAs($user);

        $sesion->get($this->url($id, ['grafica' => 'talla']))->assertOk()->assertSee('Talla · 0 a 5 años');
        $sesion->get($this->url($id, ['grafica' => 'cefalica']))->assertOk()->assertSee('Perímetro cefálico · 0 a 5 años')->assertSee('grafica-nino-punto', false);
        $sesion->get($this->url($id, ['grafica' => 'imc']))->assertOk()->assertSee('IMC · 0 a 5 años')->assertSee('grafica-nino-punto', false);
        $sesion->get($this->url($id, ['grafica' => 'peso-talla']))->assertOk()->assertSee('Peso para la talla')->assertSee('talla 88 cm', false);
    }

    public function test_la_hoja_impresa_trae_todas_las_graficas_y_la_tabla(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $this->sembrarReferencia($medico);
        $id = $this->nino($medico);
        $this->medir($medico, 5001, 1, '2026-10-13', ['peso' => 12, 'talla' => 88, 'c_cefalica' => 48]);

        $this->conContexto($medico, $pediatria)->actingAs($user)
            ->get(route('clinica.pacientes.crecimiento.imprimir', [$id, 'juego' => '0-5']))
            ->assertOk()
            ->assertSee('Curvas de crecimiento')
            ->assertSee('Peso · 0 a 5 años')->assertSee('Talla · 0 a 5 años')->assertSee('Perímetro cefálico · 0 a 5 años')
            ->assertSee('IMC · 0 a 5 años')->assertSee('Peso para la talla')
            ->assertSee('Mateo')
            ->assertSee('data-imprimir', false);
    }

    public function test_la_secretaria_no_entra_y_el_paciente_de_otro_consultorio_da_404(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $id = $this->nino($medico);

        $secretaria = User::create([
            'name' => 'Secretaria', 'email' => 'crec-secre-' . substr(uniqid(), -6) . '@example.com', 'password' => Hash::make('secreto123'),
            'is_active' => true, 'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        $this->conContexto($medico, $pediatria)->actingAs($secretaria)->get($this->url($id))->assertForbidden();
        $this->conContexto($medico, $pediatria)->actingAs($secretaria)
            ->get(route('clinica.pacientes.crecimiento.imprimir', $id))->assertForbidden();

        [$otroUser, $otro, $otraEsp] = $this->medicoConAcceso();
        $this->conContexto($otro, $otraEsp)->actingAs($otroUser)->get($this->url($id))->assertNotFound();
    }

    public function test_un_nino_sin_historia_o_sin_sexo_no_falla(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $sinHistoria = DB::table('pacientes')->insertGetId(['nombres' => 'Sin', 'apellidos' => 'Historia', 'created_at' => now(), 'updated_at' => now()]);
        MedicoPaciente::create(['medico_id' => $medico->id, 'paciente_id' => $sinHistoria, 'reg_medico' => $medico->reg_medico, 'numhistoria' => null]);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($sinHistoria))->assertOk()->assertSee('Todavía no hay mediciones');

        $sinSexo = $this->nino($medico, 5003, '', '2024-10-13');
        DB::table('pacientes')->where('id', $sinSexo)->update(['sexo' => null]);
        $this->medir($medico, 5003, 1, '2026-10-13', ['peso' => 12]);
        $this->conContexto($medico, $pediatria)->actingAs($user)->get($this->url($sinSexo))->assertOk()->assertSee('Falta el sexo del niño');
    }

    public function test_el_enlace_aparece_en_la_ficha_y_en_las_mediciones_y_la_entrada_del_menu_redirige(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $id = $this->nino($medico);
        $consulta = Consulta::create(['reg_medico' => $medico->reg_medico, 'numhistoria' => 5001, 'nroconsulta' => 1, 'fecha' => '2026-10-13']);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.pacientes.ver', $id))
            ->assertOk()->assertSee($this->url($id), false);
        $this->conContexto($medico, $pediatria)->actingAs($user)
            ->get(route('clinica.consulta', [$consulta->id, 'seccion' => 'evaluacion', 'pestana' => 'mediciones']))
            ->assertOk()->assertSee($this->url($id), false);
        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.crecimiento'))
            ->assertRedirect(route('clinica.pacientes'))->assertSessionHas('estado');
    }
}
