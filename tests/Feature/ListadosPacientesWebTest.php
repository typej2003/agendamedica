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
 * **El listado de pacientes por rango** (WEB-6.8) en `/clinica/listados?listado=pacientes-por-rango`, contra la
 * base: la pantalla abre y lista lo que corresponde, el rango de **ingreso**, la edad, el sexo, el **estado civil**
 * (por `codeestado`, no por `codesegemp` como dos de los DataWindows del legado) y la procedencia acotan, el orden
 * es el del número de historia, todo se acota al `reg_medico` del contexto y la secretaría recibe **403**.
 *
 * Son los *Indicadores pacientes* del escritorio (`w_reprotes_pacientes.srw`).
 */
class ListadosPacientesWebTest extends TestCase
{
    use DatabaseTransactions;

    /** El día del consultorio con el que se arman los datos: de él salen la fecha final y las edades. */
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
        $regMedico = 'lpac-' . uniqid();

        $user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'listados-pac-' . uniqid() . '@example.com',
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

    /**
     * Un paciente del consultorio. El `numhistoria` va en el pivote (`medico_pacientes`), que es donde el esquema
     * de AppDDR lo guarda y de dónde lo lee el listado.
     */
    private function paciente(Medico $medico, ?int $historia, string $apellido, array $datos = []): int
    {
        $ahora = self::HOY . ' 00:00:00';

        $id = DB::table('pacientes')->insertGetId(array_merge([
            'nombres'     => 'Ana',
            'apellidos'   => $apellido,
            'nac'         => 'V',
            'cedula'      => '1' . str_pad((string) ($historia ?? 0), 7, '0', STR_PAD_LEFT),
            'fnacimiento' => '1992-10-14',
            'fingreso'    => '2020-03-12',
            'sexo'        => 'F',
            'codeestado'  => 'C',
            'codesegemp'  => '02',
            'created_at'  => $ahora,
            'updated_at'  => $ahora,
        ], $datos));

        DB::table('medico_pacientes')->insert([
            'medico_id'   => $medico->id,
            'paciente_id' => $id,
            'numhistoria' => $historia === null ? null : (string) $historia,
            'reg_medico'  => $medico->reg_medico,
            'created_at'  => $ahora,
            'updated_at'  => $ahora,
        ]);

        return $id;
    }

    private function seguro(Medico $medico, string $codigo, string $nombre): void
    {
        DB::table('seg_emp')->insert([
            'reg_medico' => $medico->reg_medico, 'codesegemp' => $codigo, 'nombre' => $nombre,
            'created_at' => self::HOY . ' 00:00:00', 'updated_at' => self::HOY . ' 00:00:00',
        ]);
    }

    private function listado(array $filtros = []): string
    {
        return route('clinica.listados', array_merge(['listado' => 'pacientes-por-rango'], $filtros));
    }

    // ------------------------------------------------------------------------------------ La pantalla

    public function test_la_pantalla_lista_los_pacientes_con_las_ocho_columnas_del_legado(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->seguro($medico, '02', 'PDVSA');
        $this->paciente($medico, 12, 'Pérez');

        $respuesta = $this->conContexto($user, $medico, $gineco)->get($this->listado())->assertOk();

        $respuesta->assertSee('Pacientes por rango');
        $respuesta->assertSee('# Hist.', false)->assertSee('Paciente')->assertSee('Cédula')->assertSee('Edad')
            ->assertSee('E. civil')->assertSee('Sexo')->assertSee('F. ingreso')->assertSee('Empresa');

        $respuesta->assertSee('00012');          // # Hist., con los ceros del legado
        $respuesta->assertSee('Pérez, Ana');
        $respuesta->assertSee('V-10000012');     // nac + cédula
        $respuesta->assertSee('34 años');
        $respuesta->assertSee('Casado(a)');
        $respuesta->assertSee('Femenino');
        $respuesta->assertSee('12/03/2020');
        $respuesta->assertSee('PDVSA');

        // La clave del listado viaja en el formulario y el selector ofrece los dos listados.
        $respuesta->assertSee('name="listado" value="pacientes-por-rango"', false);
        $respuesta->assertSee('Embarazos y partos');
    }

    public function test_el_rango_de_ingreso_acota(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 1, 'Dentro', ['fingreso' => '2024-05-10']);
        $this->paciente($medico, 2, 'Antes', ['fingreso' => '2023-12-31']);
        $this->paciente($medico, 3, 'Despues', ['fingreso' => '2025-01-01']);

        $this->conContexto($user, $medico, $gineco)
            ->get($this->listado(['desde' => '2024-01-01', 'hasta' => '2024-12-31']))
            ->assertOk()->assertSee('Dentro, Ana')->assertDontSee('Antes, Ana')->assertDontSee('Despues, Ana');

        // Los dos extremos están incluidos.
        $this->conContexto($user, $medico, $gineco)
            ->get($this->listado(['desde' => '2023-12-31', 'hasta' => '2024-05-10']))
            ->assertSee('Dentro, Ana')->assertSee('Antes, Ana');
    }

    public function test_el_filtro_de_edad_acota(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 1, 'Treintaycuatro', ['fnacimiento' => '1992-10-14']);   // 34 años justos
        $this->paciente($medico, 2, 'Veinte', ['fnacimiento' => '2006-06-01']);           // 20 años
        $this->paciente($medico, 3, 'Setenta', ['fnacimiento' => '1956-01-01']);          // 70 años

        $this->conContexto($user, $medico, $gineco)
            ->get($this->listado(['edad_inicial' => '30', 'edad_final' => '40']))
            ->assertOk()->assertSee('Treintaycuatro')->assertDontSee('Veinte, Ana')->assertDontSee('Setenta, Ana');

        // Las edades al revés se dan vuelta y la pantalla lo dice.
        $this->conContexto($user, $medico, $gineco)
            ->get($this->listado(['edad_inicial' => '40', 'edad_final' => '30']))
            ->assertSee('Treintaycuatro')->assertSee('Las edades venían al revés');
    }

    public function test_el_sexo_el_estado_civil_y_la_procedencia_filtran(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->seguro($medico, '02', 'PDVSA');
        $this->paciente($medico, 1, 'Ella', ['sexo' => 'F', 'codeestado' => 'S', 'codesegemp' => '00']);
        $this->paciente($medico, 2, 'El', ['sexo' => 'M', 'codeestado' => 'C', 'codesegemp' => '02']);

        $pedir = fn (array $filtros) => $this->conContexto($user, $medico, $gineco)->get($this->listado($filtros))->assertOk();

        $pedir(['sexo' => 'M'])->assertSee('El, Ana')->assertDontSee('Ella, Ana');
        $pedir(['sexo' => 'F'])->assertSee('Ella, Ana')->assertDontSee('El, Ana');
        $pedir(['estado_civil' => 'S'])->assertSee('Ella, Ana')->assertDontSee('El, Ana');
        $pedir(['procedencia' => 'personal'])->assertSee('Ella, Ana')->assertDontSee('El, Ana');
        $pedir(['procedencia' => '02'])->assertSee('El, Ana')->assertDontSee('Ella, Ana');
        $pedir(['procedencia' => 'todos'])->assertSee('Ella, Ana')->assertSee('El, Ana');
    }

    public function test_el_estado_civil_se_compara_con_codeestado_y_no_con_codesegemp(): void
    {
        // La desviación a conciencia: dos DataWindows del legado comparan el estado civil contra `codesegemp`.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 1, 'Soltera', ['codeestado' => 'S', 'codesegemp' => '02']);
        $this->paciente($medico, 2, 'Empresa', ['codeestado' => 'C', 'codesegemp' => 'S']);

        $this->conContexto($user, $medico, $gineco)
            ->get($this->listado(['estado_civil' => 'S']))
            ->assertOk()->assertSee('Soltera, Ana')->assertDontSee('Empresa, Ana');
    }

    public function test_el_orden_es_el_del_numero_de_historia_como_numero(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 10, 'Diez');
        $this->paciente($medico, 9, 'Nueve');
        $this->paciente($medico, null, 'SinHistoria');
        $this->paciente($medico, 100, 'Cien');

        $this->conContexto($user, $medico, $gineco)->get($this->listado())->assertOk()
            // «9» antes que «10» (como número) y los que no tienen historia, al final.
            ->assertSeeInOrder(['Nueve, Ana', 'Diez, Ana', 'Cien, Ana', 'SinHistoria, Ana']);
    }

    public function test_un_paciente_sin_cedula_ni_sexo_no_inventa_datos(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 1, 'Anonima', ['cedula' => 'S.C.', 'sexo' => null, 'codeestado' => null, 'codesegemp' => '00']);

        $this->conContexto($user, $medico, $gineco)->get($this->listado())
            ->assertOk()->assertSee('Anonima, Ana')->assertDontSee('S.C.')->assertDontSee('V-S.C.');
    }

    public function test_solo_se_ven_los_pacientes_del_medico_del_contexto(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        [, $otro] = $this->medicoConAcceso();
        $this->paciente($medico, 1, 'Mia');
        $this->paciente($otro, 1, 'Ajena');

        $this->conContexto($user, $medico, $gineco)->get($this->listado())
            ->assertOk()->assertSee('Mia, Ana')->assertDontSee('Ajena, Ana');
    }

    public function test_una_clave_de_listado_inexistente_vuelve_al_de_por_defecto(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 1, 'Mia');

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.listados', ['listado' => 'no-existe']))
            ->assertOk()->assertSee('name="listado" value="embarazos"', false)->assertDontSee('Mia, Ana');
    }

    public function test_el_consultorio_sin_pacientes_dice_que_todavia_no_hay(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)->get($this->listado())->assertOk()->assertSee('Todavía no hay pacientes');
        $this->conContexto($user, $medico, $gineco)->get($this->listado(['sexo' => 'M']))->assertSee('Ningún paciente con esos filtros');
    }

    public function test_la_hoja_imprimible_sale_con_los_mismos_filtros(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->seguro($medico, '02', 'PDVSA');
        $this->paciente($medico, 12, 'Pérez');
        $this->paciente($medico, 13, 'Varon', ['sexo' => 'M']);

        $respuesta = $this->conContexto($user, $medico, $gineco)
            ->get(route('clinica.listados.imprimir', ['listado' => 'pacientes-por-rango', 'sexo' => 'F', 'desde' => '2020-01-01', 'hasta' => '2020-12-31']))
            ->assertOk()
            ->assertSee('Pérez, Ana')->assertDontSee('Varon, Ana')
            ->assertSee('00012')->assertSee('V-10000012')->assertSee('PDVSA')
            // La cabecera lleva los filtros: un listado sin ellos no dice nada en papel.
            ->assertSee('Ingreso desde')->assertSee('01/01/2020')->assertSee('Ingreso hasta')->assertSee('31/12/2020')
            ->assertSee('Femenino')
            ->assertSee('1 paciente.');

        // Es una hoja aparte: sin el armazón de la web.
        $respuesta->assertDontSee('app-sidebar', false);
    }

    // ----------------------------------------------------------------------------- Edad abierta (O-14)

    public function test_sin_rango_de_edad_aparece_el_paciente_sin_fecha_de_nacimiento(): void
    {
        // O-14 (2026-10-17): el rango **abierto** (el de la pantalla) no filtra por `fnacimiento`, así que un
        // paciente sin fecha de nacimiento —el que carga el alta rápida de «Nueva cita»— aparece igual.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 1, 'SinFecha', ['fnacimiento' => null, 'fingreso' => '2024-05-10']);

        $this->conContexto($user, $medico, $gineco)->get($this->listado())
            ->assertOk()
            ->assertSee('SinFecha, Ana')
            // Y el subtítulo no anuncia una edad que no se filtró.
            ->assertDontSee('de 1 a 110 años');
    }

    public function test_con_rango_de_edad_se_sigue_filtrando_como_hoy(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 1, 'SinFecha', ['fnacimiento' => null, 'fingreso' => '2024-05-10']);
        $this->paciente($medico, 2, 'Treintaycuatro', ['fnacimiento' => '1992-10-14']);

        // Con la edad puesta, el que no tiene fecha de nacimiento no puede cumplir el rango: no aparece.
        $this->conContexto($user, $medico, $gineco)
            ->get($this->listado(['edad_inicial' => '30', 'edad_final' => '40']))
            ->assertOk()
            ->assertSee('Treintaycuatro, Ana')
            ->assertDontSee('SinFecha, Ana');
    }

    public function test_el_subtitulo_dice_la_edad_solo_cuando_filtra(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 1, 'Uno');

        $this->conContexto($user, $medico, $gineco)->get($this->listado())
            ->assertSee('ingresados entre el')
            ->assertDontSee('de 1 a 110 años');

        $this->conContexto($user, $medico, $gineco)
            ->get($this->listado(['edad_inicial' => '30', 'edad_final' => '40']))
            ->assertSee('de 30 a 40 años');
    }

    // ------------------------------------------------------------------------------------- Permisos

    public function test_la_secretaria_recibe_403(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        [$secretaria] = $this->medicoConAcceso('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        $this->conContexto($secretaria, $medico, $gineco)->get($this->listado())->assertForbidden();
        $this->conContexto($secretaria, $medico, $gineco)
            ->get(route('clinica.listados.imprimir', ['listado' => 'pacientes-por-rango']))->assertForbidden();
    }
}
