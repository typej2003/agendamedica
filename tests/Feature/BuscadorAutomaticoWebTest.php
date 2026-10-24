<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
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
 * **Los buscadores de lista buscan solos** (2026-10-20, `public/js/clinica/buscar-auto.js`): sin Enter y con debounce, y con
 * «Agregar «texto»» cuando lo escrito no coincide exacto con ninguna fila.
 *
 * Sin navegador: lo que se fija es el contrato entre las vistas y el script —el formulario `data-buscar-auto`, la región
 * `data-buscar-resultados` que el script reemplaza (y que tiene que venir también en la respuesta de `?buscar=`), el botón
 * `data-buscar-agregar` con el diálogo o campo que existe de verdad— y que el alta recibe lo buscado ya escrito.
 */
class BuscadorAutomaticoWebTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private Medico $medico;

    private Specialty $gineco;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00:00'));
        $this->seed(EspecialidadesYModulosSeeder::class);

        $this->gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $reg = 'buscar-auto-' . substr(uniqid(), -6);

        $this->user = User::create([
            'name' => 'Usuario de prueba', 'email' => 'buscar-auto-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $this->user->assignRole('Medico');

        $this->medico = Medico::create([
            'user_id' => $this->user->id, 'name' => 'Dr. de prueba', 'lastname' => 'Buscador', 'email' => $this->user->email,
            'password' => $this->user->password, 'reg_medico' => $reg,
        ]);
        $this->medico->specialties()->attach($this->gineco->id);
        MedicoRegistro::create(['medico_id' => $this->medico->id, 'reg_medico' => $reg]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function entrando(): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $this->medico->reg_medico, 'specialty_id' => $this->gineco->id, 'office_id' => null,
        ]])->actingAs($this->user);
    }

    protected function consulta(): Consulta
    {
        $pacienteId = DB::table('pacientes')->insertGetId([
            'nombres' => 'Ana', 'apellidos' => 'Alvarez', 'cedula' => (string) random_int(10000000, 30000000),
            'sexo' => 'F', 'fnacimiento' => '1990-05-04', 'created_at' => now(), 'updated_at' => now(),
        ]);
        MedicoPaciente::create([
            'medico_id' => $this->medico->id, 'paciente_id' => $pacienteId, 'reg_medico' => $this->medico->reg_medico, 'numhistoria' => '1001',
        ]);
        Historia::create([
            'numhistoria' => '1001', 'reg_medico' => $this->medico->reg_medico, 'paciente_id' => $pacienteId, 'medico_id' => $this->medico->id,
        ]);

        return Consulta::create([
            'reg_medico' => $this->medico->reg_medico, 'numhistoria' => 1001, 'nroconsulta' => 1, 'fecha' => now()->toDateString(),
        ]);
    }

    // ------------------------------------------------------------------------------------ Catálogos

    /** @return array<string,array{0:string,1:string,2:string}> ruta, diálogo y campo del «Agregar» */
    public static function catalogos(): array
    {
        return [
            'medicamentos' => ['clinica.catalogos.medicamentos', 'dialogo-medicamento', 'medicamento-comercial'],
            'diagnósticos' => ['clinica.catalogos.diagnosticos', 'dialogo-catalogo', 'catalogo-descripcion'],
            'motivos de consulta' => ['clinica.catalogos.motivos-consulta', 'dialogo-catalogo', 'catalogo-descripcion'],
            'tipos de examen' => ['clinica.catalogos.tipos-examen', 'dialogo-catalogo', 'catalogo-descripcion'],
            'exámenes' => ['clinica.catalogos.examenes', 'dialogo-catalogo', 'examen-nombre'],
            'médicos referidos' => ['clinica.catalogos.medicos-referidos', 'dialogo-catalogo', 'doctor-apellidos'],
            'motivos de cita' => ['clinica.catalogos.motivos-cita', 'dialogo-catalogo', 'motivo-razon'],
        ];
    }

    #[DataProvider('catalogos')]
    public function test_los_catalogos_buscan_solos_y_ofrecen_agregar(string $ruta, string $dialogo, string $campo): void
    {
        foreach ([route($ruta), route($ruta, ['buscar' => 'zzz-no-existe'])] as $url) {
            $respuesta = $this->entrando()->get($url)->assertOk()
                ->assertSee('data-buscar-auto', false)
                ->assertSee('data-buscar-resultados', false)
                ->assertSee('data-buscar-agregar', false)
                ->assertSee('data-agregar-dialogo="' . $dialogo . '"', false)
                ->assertSee('data-prefill-campo="' . $campo . '"', false);

            // El diálogo y el campo que el botón nombra existen en la misma página.
            $respuesta->assertSee('id="' . $dialogo . '"', false)->assertSee('id="' . $campo . '"', false);
        }
    }

    public function test_el_buscador_de_pacientes_busca_solo_y_agregar_lleva_al_alta(): void
    {
        $this->entrando()->get(route('clinica.pacientes', ['buscar' => 'zzz']))->assertOk()
            ->assertSee('data-buscar-auto', false)
            ->assertSee('data-buscar-resultados', false)
            ->assertSee('data-buscar-filas=".identidad-nombre"', false)
            ->assertSee('data-agregar-url="' . route('clinica.pacientes.nuevo') . '"', false);
    }

    public function test_el_alta_de_paciente_recibe_lo_buscado_en_el_primer_campo_del_nombre(): void
    {
        $this->entrando()->get(route('clinica.pacientes.nuevo', ['nombre' => 'Perez Gomez']))->assertOk()
            ->assertSee('value="Perez Gomez"', false);

        // Una búsqueda sin letras (cédula, n.º de historia) no se escribe en un campo de nombre.
        $this->entrando()->get(route('clinica.pacientes.nuevo', ['nombre' => '12345678']))->assertOk()
            ->assertDontSee('value="12345678"', false);
    }

    public function test_los_tratamientos_buscan_solos_y_el_editor_nuevo_recibe_el_nombre(): void
    {
        $this->entrando()->get(route('clinica.catalogos.tratamientos', ['buscar' => 'zzz']))->assertOk()
            ->assertSee('data-buscar-auto', false)
            ->assertSee('data-buscar-resultados', false)
            ->assertSee('data-agregar-url="' . route('clinica.catalogos.tratamientos.nuevo') . '"', false);

        $this->entrando()->get(route('clinica.catalogos.tratamientos.nuevo', ['nombre' => 'Infeccion urinaria']))->assertOk()
            ->assertSee('id="tratamiento-nombre"', false)
            ->assertSee('value="Infeccion urinaria"', false);

        $this->entrando()->get(route('clinica.catalogos.tratamientos.nuevo'))->assertOk()
            ->assertDontSee('value="Infeccion urinaria"', false);
    }

    // ------------------------------------------------------------------------------------ La consulta

    public function test_los_motivos_de_la_consulta_buscan_solos_y_ofrecen_crear_lo_que_no_existe(): void
    {
        $consulta = $this->consulta();
        DB::table('motivos_consulta')->insert([
            'reg_medico' => $this->medico->reg_medico, 'codemotivo' => '0001', 'descripcion' => 'CONTROL', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $url = fn (string $buscar) => route('clinica.consulta', [$consulta->id, 'seccion' => 'motivos', 'buscar' => $buscar]);

        $this->entrando()->get($url('dolor nuevo'))->assertOk()
            ->assertSee('data-buscar-auto', false)
            ->assertSee('data-buscar-resultados', false)
            ->assertSee('data-motivo-crear="dolor nuevo"', false);

        // Lo escrito coincide exacto (sin mayúsculas): no se ofrece crear.
        $this->entrando()->get($url('control'))->assertOk()
            ->assertSee('data-buscar-resultados', false)
            ->assertDontSee('data-motivo-crear', false);
    }

    public function test_diagnosticos_y_antecedentes_de_la_consulta_buscan_solos_y_agregan_en_su_formulario(): void
    {
        $consulta = $this->consulta();

        $this->entrando()->get(route('clinica.consulta', [$consulta->id, 'seccion' => 'diagnostico', 'buscar' => 'xyz']))->assertOk()
            ->assertSee('data-buscar-auto', false)
            ->assertSee('data-buscar-resultados', false)
            ->assertSee('data-agregar-campo="diagnostico-descripcion"', false)
            ->assertSee('id="diagnostico-descripcion"', false);

        $this->entrando()->get(route('clinica.consulta', [$consulta->id, 'seccion' => 'antecedentes', 'buscar' => 'xyz']))->assertOk()
            ->assertSee('data-buscar-auto', false)
            ->assertSee('data-buscar-resultados', false)
            ->assertSee('data-agregar-campo="antecedente-descripcion"', false)
            ->assertSee('id="antecedente-descripcion"', false);
    }

    public function test_las_referencias_de_la_consulta_buscan_solas_y_agregan_un_medico_en_el_dialogo(): void
    {
        $consulta = $this->consulta();
        DB::table('especial')->insert([
            'reg_medico' => $this->medico->reg_medico, 'codeespecial' => '002', 'especialidad' => 'CARDIOLOGIA', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->entrando()->get(route('clinica.consulta', [$consulta->id, 'seccion' => 'solicitudes', 'solicitud' => 'referencias', 'buscar' => 'xyz']))
            ->assertOk()
            ->assertSee('data-buscar-auto', false)
            ->assertSee('data-buscar-resultados', false)
            ->assertSee('data-agregar-dialogo="dialogo-medico"', false)
            ->assertSee('id="dialogo-medico"', false)
            ->assertSee('id="medico-apellidos"', false);
    }

    // ------------------------------------------------------------------------------------ El script

    public function test_el_script_trae_los_ganchos_que_las_vistas_declaran_y_el_armazon_lo_carga(): void
    {
        $js = file_get_contents(public_path('js/clinica/buscar-auto.js'));

        foreach ([
            'form[data-buscar-auto]', 'data-buscar-resultados', 'buscarRegion', 'buscarFilas', 'data-buscar-agregar', 'agregarDialogo',
            'agregarCampo', 'agregarUrl', 'agregarParametro', 'prefillCampo', 'data-buscar-enviar', 'data-buscar-limpiar', 'buscar-auto:cargado', 'replaceState',
        ] as $gancho) {
            $this->assertStringContainsString($gancho, $js, "Falta «{$gancho}» en buscar-auto.js");
        }

        $this->assertStringContainsString('const ESPERA = 350;', $js);

        $this->entrando()->get(route('clinica.pacientes'))->assertSee('js/clinica/buscar-auto.js', false);
    }
}
