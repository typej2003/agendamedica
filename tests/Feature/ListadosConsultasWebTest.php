<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **El listado de consultas por rango** (WEB-6.8) en `/clinica/listados?listado=consultas-por-rango`, contra la
 * base: una fila por consulta, el rango por **fecha de la consulta** (no por el ingreso del paciente), la edad, los
 * cuatro filtros escritos —motivo, diagnóstico, medicamento y antecedente—, las listas de cada consulta juntas en su
 * fila, todo acotado al `reg_medico` del contexto y la secretaría con **403**.
 *
 * Son los *Indicadores de Consultas* del escritorio (`w_reporte_consulta_barbaro.srw`).
 */
class ListadosConsultasWebTest extends TestCase
{
    use DatabaseTransactions;

    private const HOY = '2026-10-14';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::HOY . ' 12:00:00'));
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
        $regMedico = 'lcon-' . uniqid();

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'listados-con-' . uniqid() . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Dr. de prueba', 'lastname' => 'Listados', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null,
        ]])->actingAs($user);
    }

    /** Un paciente con su historia en el pivote del médico. */
    private function paciente(Medico $medico, int $historia, string $apellido, array $datos = []): int
    {
        $ahora = self::HOY . ' 00:00:00';

        $id = DB::table('pacientes')->insertGetId(array_merge([
            'nombres' => 'Ana', 'apellidos' => $apellido, 'fnacimiento' => '1992-10-14', 'fingreso' => '2020-03-12',
            'created_at' => $ahora, 'updated_at' => $ahora,
        ], $datos));

        DB::table('medico_pacientes')->insert([
            'medico_id' => $medico->id, 'paciente_id' => $id, 'numhistoria' => (string) $historia,
            'reg_medico' => $medico->reg_medico, 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);

        return $id;
    }

    private function consulta(Medico $medico, int $historia, int $numero, string $fecha): void
    {
        DB::table('consultas')->insert([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => $historia, 'nroconsulta' => $numero,
            'fecha' => $fecha, 'created_at' => $fecha . ' 00:00:00', 'updated_at' => $fecha . ' 00:00:00',
        ]);
    }

    private function motivo(Medico $medico, int $historia, int $consulta, string $codigo, string $descripcion): void
    {
        DB::table('motivos_consulta')->insert(['reg_medico' => $medico->reg_medico, 'codemotivo' => $codigo, 'descripcion' => $descripcion, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('motivo_consulta_paciente')->insert(['reg_medico' => $medico->reg_medico, 'codemotivo' => $codigo, 'nrohistoria' => $historia, 'nroconsulta' => $consulta, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function diagnostico(Medico $medico, int $historia, int $consulta, string $codigo, string $descripcion, int $orden = 1): void
    {
        DB::table('diagnosticos')->insert(['reg_medico' => $medico->reg_medico, 'codediagnostico' => $codigo, 'descripcion' => $descripcion, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('diagnostico_paciente')->insert(['reg_medico' => $medico->reg_medico, 'nrohistoria' => $historia, 'nroconsulta' => $consulta, 'codediagnostico' => $codigo, 'orden' => $orden, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function medicamento(Medico $medico, int $historia, int $consulta, string $codigo, string $nombre): void
    {
        DB::table('recipes')->insert(['reg_medico' => $medico->reg_medico, 'nrohistoria' => $historia, 'nroconsulta' => $consulta, 'codemedicina' => $codigo, 'descripcion' => $nombre, 'recipe' => 1, 'orden' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function antecedente(Medico $medico, int $historia, string $codigo, string $descripcion): void
    {
        DB::table('antecedentes')->insert(['reg_medico' => $medico->reg_medico, 'codantecedente' => $codigo, 'descripcion' => $descripcion, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('antece_paciente')->insert(['reg_medico' => $medico->reg_medico, 'numhistoria' => $historia, 'codeantecedente' => $codigo, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function listado(array $filtros = []): string
    {
        return route('clinica.listados', array_merge(['listado' => 'consultas-por-rango'], $filtros));
    }

    // ------------------------------------------------------------------------------------ La pantalla

    public function test_la_pantalla_lista_la_consulta_con_sus_listas_en_una_sola_fila(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 12, 'Pérez');
        $this->consulta($medico, 12, 1, '2026-09-20');
        $this->motivo($medico, 12, 1, '0001', 'DOLOR PÉLVICO');
        $this->diagnostico($medico, 12, 1, '0001', 'QUISTE DE OVARIO', 1);
        $this->diagnostico($medico, 12, 1, '0002', 'ANEMIA LEVE', 2);
        $this->medicamento($medico, 12, 1, '0001', 'IBUPROFENO 400 MG');
        $this->medicamento($medico, 12, 1, '0002', 'HIERRO 100 MG');

        $respuesta = $this->conContexto($user, $medico, $gineco)->get($this->listado())->assertOk();

        $respuesta->assertSee('Consultas por rango');
        $respuesta->assertSee('# Hist.', false)->assertSee('Paciente')->assertSee('Edad')->assertSee('Fecha')
            ->assertSee('Motivo de consulta')->assertSee('Diagnóstico')->assertSee('Medicamento');
        $respuesta->assertDontSee('Antecedente</th>', false);    // sólo si se filtra por uno

        $respuesta->assertSee('00012')->assertSee('Pérez, Ana')->assertSee('34 años')->assertSee('20/09/2026');
        $respuesta->assertSee('DOLOR PÉLVICO')->assertSee('QUISTE DE OVARIO')->assertSee('ANEMIA LEVE')
            ->assertSee('IBUPROFENO 400 MG')->assertSee('HIERRO 100 MG');

        // **Una fila por consulta**: 2 diagnósticos × 2 medicamentos no repite al paciente.
        $this->assertSame(1, substr_count($respuesta->getContent(), 'Pérez, Ana'));
        $respuesta->assertSee('1 consulta ·');

        $respuesta->assertSee('name="listado" value="consultas-por-rango"', false);
        $respuesta->assertSee('Consultas por rango');
    }

    public function test_el_rango_es_el_de_la_fecha_de_la_consulta_y_no_el_del_ingreso_del_paciente(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 1, 'Dentro', ['fingreso' => '2000-02-02']);   // ingresó mucho antes del rango
        $this->consulta($medico, 1, 1, '2024-05-10');
        $this->paciente($medico, 2, 'Fuera', ['fingreso' => '2024-05-10']);    // ingresó dentro, pero consultó fuera
        $this->consulta($medico, 2, 1, '2025-02-01');

        $this->conContexto($user, $medico, $gineco)
            ->get($this->listado(['desde' => '2024-01-01', 'hasta' => '2024-12-31']))
            ->assertOk()->assertSee('Dentro, Ana')->assertDontSee('Fuera, Ana');

        // Los dos extremos están incluidos.
        $this->conContexto($user, $medico, $gineco)
            ->get($this->listado(['desde' => '2024-05-10', 'hasta' => '2025-02-01']))
            ->assertSee('Dentro, Ana')->assertSee('Fuera, Ana');
    }

    public function test_el_filtro_de_edad_acota_y_las_edades_al_reves_se_dan_vuelta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 1, 'Treintaycuatro', ['fnacimiento' => '1992-10-14']);
        $this->consulta($medico, 1, 1, '2026-01-01');
        $this->paciente($medico, 2, 'Setenta', ['fnacimiento' => '1956-01-01']);
        $this->consulta($medico, 2, 1, '2026-01-01');

        $this->conContexto($user, $medico, $gineco)
            ->get($this->listado(['edad_inicial' => '30', 'edad_final' => '40']))
            ->assertOk()->assertSee('Treintaycuatro')->assertDontSee('Setenta, Ana');

        $this->conContexto($user, $medico, $gineco)
            ->get($this->listado(['edad_inicial' => '40', 'edad_final' => '30']))
            ->assertSee('Treintaycuatro')->assertSee('Las edades venían al revés');
    }

    public function test_el_motivo_el_diagnostico_y_el_medicamento_se_buscan_por_su_nombre(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 1, 'Quiste');
        $this->consulta($medico, 1, 1, '2026-01-01');
        $this->motivo($medico, 1, 1, '0001', 'DOLOR PÉLVICO');
        $this->diagnostico($medico, 1, 1, '0001', 'QUISTE DE OVARIO');
        $this->medicamento($medico, 1, 1, '0001', 'IBUPROFENO 400 MG');
        $this->paciente($medico, 2, 'Anemia');
        $this->consulta($medico, 2, 1, '2026-01-01');
        $this->motivo($medico, 2, 1, '0002', 'CONTROL');
        $this->diagnostico($medico, 2, 1, '0002', 'ANEMIA LEVE');
        $this->medicamento($medico, 2, 1, '0002', 'HIERRO 100 MG');

        $pedir = fn (array $filtros) => $this->conContexto($user, $medico, $gineco)->get($this->listado($filtros))->assertOk();

        // Sin acentos ni mayúsculas, y con una parte del nombre.
        $pedir(['motivo' => 'dolor pelvico'])->assertSee('Quiste, Ana')->assertDontSee('Anemia, Ana');
        $pedir(['diagnostico' => 'QUISTE'])->assertSee('Quiste, Ana')->assertDontSee('Anemia, Ana');
        $pedir(['diagnostico' => 'ovario quiste'])->assertSee('Quiste, Ana');     // todas las palabras, en cualquier orden
        $pedir(['diagnostico' => 'quiste hierro'])->assertDontSee('Quiste, Ana'); // una palabra que no está en ninguno
        $pedir(['medicamento' => 'hierro'])->assertSee('Anemia, Ana')->assertDontSee('Quiste, Ana');
        $pedir(['motivo' => 'control', 'diagnostico' => 'anemia', 'medicamento' => 'hierro'])->assertSee('Anemia, Ana');
        $pedir(['motivo' => 'control', 'diagnostico' => 'quiste'])->assertDontSee('Anemia, Ana')->assertDontSee('Quiste, Ana');

        // Los comodines de LIKE se buscan como texto.
        $pedir(['diagnostico' => '%'])->assertDontSee('Quiste, Ana')->assertDontSee('Anemia, Ana');
    }

    public function test_el_medicamento_tambien_se_busca_en_el_vademecum(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 1, 'Vademecum');
        $this->consulta($medico, 1, 1, '2026-01-01');
        DB::table('vademecum')->insert(['reg_medico' => $medico->reg_medico, 'codemedicina' => '0009', 'nombrecomercial' => 'AMOXIDAL', 'nombregenerico' => 'AMOXICILINA', 'created_at' => now(), 'updated_at' => now()]);
        // El récipe no copió el nombre: sólo tiene el código.
        DB::table('recipes')->insert(['reg_medico' => $medico->reg_medico, 'nrohistoria' => 1, 'nroconsulta' => 1, 'codemedicina' => '0009', 'recipe' => 1, 'orden' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $this->conContexto($user, $medico, $gineco)->get($this->listado(['medicamento' => 'amoxicilina']))
            ->assertOk()->assertSee('Vademecum, Ana')->assertSee('AMOXIDAL');
    }

    public function test_el_antecedente_filtra_por_el_paciente_y_agrega_su_columna(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 1, 'Diabetica');
        $this->consulta($medico, 1, 1, '2026-01-01');
        $this->consulta($medico, 1, 2, '2026-02-01');
        $this->antecedente($medico, 1, '0001', 'DIABETES MELLITUS');
        $this->paciente($medico, 2, 'Sana');
        $this->consulta($medico, 2, 1, '2026-01-01');

        $respuesta = $this->conContexto($user, $medico, $gineco)->get($this->listado(['antecedente' => 'diabetes']))->assertOk();

        // El antecedente es del paciente: entran **las dos** consultas suyas, y no la de la otra paciente.
        $respuesta->assertSee('Antecedente</th>', false)->assertSee('DIABETES MELLITUS');
        $this->assertSame(2, substr_count($respuesta->getContent(), 'Diabetica, Ana'));
        $respuesta->assertDontSee('Sana, Ana');
    }

    public function test_el_orden_es_el_mas_reciente_primero(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 1, 'Vieja');
        $this->consulta($medico, 1, 1, '2026-01-10');
        $this->paciente($medico, 2, 'Nueva');
        $this->consulta($medico, 2, 1, '2026-09-10');

        $this->conContexto($user, $medico, $gineco)->get($this->listado())->assertOk()
            ->assertSeeInOrder(['Nueva, Ana', 'Vieja, Ana']);
    }

    public function test_solo_se_ven_las_consultas_del_medico_del_contexto(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $this->paciente($medico, 1, 'Mia');
        $this->consulta($medico, 1, 1, '2026-01-01');
        $this->diagnostico($medico, 1, 1, '0001', 'MIO');
        $this->paciente($otro, 1, 'Ajena');                  // la misma historia 1 en otro consultorio
        $this->consulta($otro, 1, 1, '2026-01-01');
        $this->diagnostico($otro, 1, 1, '0001', 'AJENO');

        $this->conContexto($user, $medico, $gineco)->get($this->listado())
            ->assertOk()->assertSee('Mia, Ana')->assertSee('MIO')->assertDontSee('Ajena, Ana')->assertDontSee('AJENO');
    }

    public function test_el_estado_vacio_distingue_la_busqueda_sin_resultados_del_consultorio_sin_consultas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)->get($this->listado())->assertOk()->assertSee('Todavía no hay consultas');
        $this->conContexto($user, $medico, $gineco)->get($this->listado(['diagnostico' => 'nada']))->assertSee('Ninguna consulta con esos filtros');
    }

    public function test_la_hoja_imprimible_sale_con_los_mismos_filtros_y_acostada(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 12, 'Pérez');
        $this->consulta($medico, 12, 1, '2026-09-20');
        $this->diagnostico($medico, 12, 1, '0001', 'QUISTE DE OVARIO');
        $this->paciente($medico, 13, 'Otra');
        $this->consulta($medico, 13, 1, '2026-09-20');
        $this->diagnostico($medico, 13, 1, '0002', 'ANEMIA LEVE');

        $respuesta = $this->conContexto($user, $medico, $gineco)
            ->get(route('clinica.listados.imprimir', ['listado' => 'consultas-por-rango', 'diagnostico' => 'quiste', 'desde' => '2026-09-01', 'hasta' => '2026-09-30']))
            ->assertOk()
            ->assertSee('Pérez, Ana')->assertSee('QUISTE DE OVARIO')->assertDontSee('Otra, Ana')
            ->assertSee('00012')->assertSee('20/09/2026')
            // La cabecera lleva los filtros: un listado sin ellos no dice nada en papel.
            ->assertSee('Desde')->assertSee('01/09/2026')->assertSee('Hasta')->assertSee('30/09/2026')
            ->assertSee('Diagnóstico')->assertSee('quiste')
            ->assertSee('1 consulta.')
            ->assertSee('size: landscape', false);

        $respuesta->assertDontSee('app-sidebar', false);
    }

    public function test_el_selector_ofrece_los_tres_listados(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)->get($this->listado())->assertOk()
            ->assertSeeInOrder(['Embarazos y partos', 'Pacientes por rango', 'Consultas por rango']);
    }

    // ------------------------------------------------------------------------------------- Permisos

    public function test_la_secretaria_recibe_403(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        [$secretaria] = $this->medicoConAcceso('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        $this->conContexto($secretaria, $medico, $gineco)->get($this->listado())->assertForbidden();
        $this->conContexto($secretaria, $medico, $gineco)
            ->get(route('clinica.listados.imprimir', ['listado' => 'consultas-por-rango']))->assertForbidden();
    }
}
