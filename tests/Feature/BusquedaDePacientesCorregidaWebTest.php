<?php

namespace Tests\Feature;

use App\Jobs\ReconstruirIndiceDePacientes;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Paciente;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Pecle\Search\Laravel\PecleSearchManager;
use Tests\TestCase;

/**
 * La **corrección de errores de tecleo** del buscador de pacientes (PecleSearch).
 *
 * Lo que se fija acá: escribir "Gonzlez" encuentra a González y el listado **avisa** que buscó
 * "gonzalez" y ofrece buscar lo escrito tal cual; con `?literal=1` no se corrige nada; la cédula y el
 * n.º de historia pasan intactos; la corrección no se escapa del consultorio; si lo corregido no
 * encuentra a nadie se busca lo escrito (corregir es una mejora, no una condición); y el buscador de
 * "Nueva cita" corrige igual.
 *
 * El índice lo mantiene el paquete y en AppDDR **no se sincroniza solo** (`pecle_search.sync`): los
 * tests lo construyen con `pecle-search:rebuild`, que es lo mismo que corre el despliegue.
 */
class BusquedaDePacientesCorregidaWebTest extends TestCase
{
    use DatabaseTransactions;

    // --------------------------------------------------------------------------------------- Ayudas

    private function medicoConAcceso(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'pac-cor-' . substr(uniqid(), -6);

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'pac-cor-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dra. de prueba',
            'lastname'   => 'Corrección',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico'   => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id'    => null,
        ]]);
    }

    /** Un médico de otro consultorio, para probar que la corrección no mezcla consultorios. */
    private function otroMedico(): Medico
    {
        $regMedico = 'pac-otro-' . substr(uniqid(), -6);

        $medico = Medico::create([
            'name'       => 'Dr. ajeno',
            'lastname'   => 'Otro',
            'email'      => 'ajeno-' . substr(uniqid(), -6) . '@example.com',
            'password'   => Hash::make('secreto123'),
            'reg_medico' => $regMedico,
        ]);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return $medico;
    }

    private function paciente(Medico $medico, string $nombres, string $apellidos, string $cedula, ?string $numhistoria): int
    {
        $id = DB::table('pacientes')->insertGetId([
            'nombres'     => $nombres,
            'apellidos'   => $apellidos,
            'cedula'      => $cedula,
            'sexo'        => 'F',
            'fnacimiento' => '1990-05-04',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        DB::table('medico_pacientes')->insert([
            'medico_id'   => $medico->id,
            'paciente_id' => $id,
            'numhistoria' => $numhistoria,
            'reg_medico'  => $medico->reg_medico,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return $id;
    }

    /**
     * Construye el índice y el vocabulario del corrector con los pacientes que hay en este momento.
     * Es el paso que el despliegue corre una vez: `php artisan pecle-search:rebuild pacientes`.
     */
    private function construirIndice(): void
    {
        app(PecleSearchManager::class)->rebuild('pacientes');
    }

    // ------------------------------------------------------------------------------------ La corrección

    public function test_busca_con_un_error_de_tecleo_y_avisa_la_correccion(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 'Ana', 'González', '11111111', '2001');
        $this->paciente($medico, 'Luis', 'Martínez', '22222222', '2002');
        $this->construirIndice();

        $this->conContexto($user, $medico, $gineco)
            ->actingAs($user)
            ->get('/clinica/pacientes?buscar=Gonzlez')
            ->assertOk()
            ->assertSee('González')
            ->assertDontSee('Martínez')
            ->assertSeeText('Se buscó «Gonzalez»')
            ->assertSeeText('Buscar «Gonzlez» tal cual');
    }

    public function test_la_correccion_se_puede_deshacer_y_busca_lo_escrito(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 'Ana', 'González', '11111111', '2001');
        $this->construirIndice();

        $this->conContexto($user, $medico, $gineco)
            ->actingAs($user)
            ->get('/clinica/pacientes?buscar=Gonzlez&literal=1')
            ->assertOk()
            ->assertDontSee('González')
            ->assertDontSee('data-busqueda-corregida', false)
            ->assertSeeText('Sin resultados para «Gonzlez»');
    }

    public function test_no_corrige_cedulas_ni_numeros_de_historia(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 'Ana', 'González', '11111111', '2001');
        $this->construirIndice();

        foreach (['11111111', '2001'] as $buscar) {
            $this->conContexto($user, $medico, $gineco)
                ->actingAs($user)
                ->get('/clinica/pacientes?buscar=' . $buscar)
                ->assertOk()
                ->assertSee('González')
                ->assertDontSee('data-busqueda-corregida', false);
        }
    }

    public function test_la_correccion_no_se_escapa_del_consultorio(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 'Ana', 'González', '11111111', '2001');
        // El vocabulario del corrector es del índice entero: "gonzalez" también existe en otro
        // consultorio, pero el listado sólo puede devolver lo del contexto.
        $this->paciente($this->otroMedico(), 'Zulema', 'González', '33333333', '3001');
        $this->construirIndice();

        $this->conContexto($user, $medico, $gineco)
            ->actingAs($user)
            ->get('/clinica/pacientes?buscar=Gonzlez')
            ->assertOk()
            ->assertSee('Ana')
            ->assertDontSee('Zulema');
    }

    public function test_si_lo_corregido_no_encuentra_nada_busca_lo_escrito(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 'Luis', 'Martínez', '22222222', '2002');
        // La única palabra que corrige "Gonzlez" está en otro consultorio: con la corrección este
        // listado no encuentra nada, así que se vuelve a lo escrito y **no** se avisa una corrección.
        $this->paciente($this->otroMedico(), 'Zulema', 'González', '33333333', '3001');
        $this->construirIndice();

        $this->conContexto($user, $medico, $gineco)
            ->actingAs($user)
            ->get('/clinica/pacientes?buscar=Gonzlez')
            ->assertOk()
            ->assertDontSee('data-busqueda-corregida', false)
            ->assertSeeText('Sin resultados para «Gonzlez»');
    }

    public function test_el_buscador_de_nueva_cita_corrige_igual_que_el_listado(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 'Ana', 'González', '11111111', '2001');
        $this->construirIndice();

        $this->conContexto($user, $medico, $gineco)
            ->actingAs($user)
            ->getJson('/clinica/pacientes/buscar?q=Gonzlez')
            ->assertOk()
            ->assertJsonFragment(['nombre' => 'González, Ana']);
    }

    public function test_un_paciente_guardado_despues_del_indice_se_corrige_al_instante(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 'Ana', 'González', '11111111', '2001');
        $this->construirIndice();

        // Un guardado suelto —la web, el móvil, la subida incremental del escritorio— pasa por Eloquent y
        // la sincronización del paquete reindexa a ese paciente y refresca el vocabulario en el acto.
        $this->pacienteGuardado('Heriberta', 'Herrera', '44444444', '2003', $medico);

        $this->conContexto($user, $medico, $gineco)
            ->actingAs($user)
            ->get('/clinica/pacientes?buscar=Herera')
            ->assertOk()
            ->assertSee('Herrera')
            ->assertSeeText('Se buscó «Herrera»');
    }

    public function test_el_trabajo_de_reconstruccion_incorpora_lo_que_la_carga_masiva_dejo_afuera(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 'Ana', 'González', '11111111', '2001');
        $this->construirIndice();

        // Así guarda la carga inicial: sin eventos, para no pagar el vocabulario del corrector por fila.
        $this->pacienteGuardado('Heriberta', 'Herrera', '44444444', '2003', $medico, sinEventos: true);

        $buscador = app(PecleSearchManager::class);
        $this->assertSame('Herera', $buscador->resolve('Herera', 'pacientes')->applied, 'la carga no lo indexó');

        // El trabajo que dispara `CargaInicialCompletada` rehace el índice y ya lo corrige.
        (new ReconstruirIndiceDePacientes)->handle($buscador);
        $this->assertSame('Herrera', $buscador->resolve('Herera', 'pacientes')->applied);
    }

    /**
     * Un paciente con su vínculo, guardado como lo hace la app (Eloquent). `$sinEventos` reproduce lo que
     * hace la carga inicial: guardar sin disparar los eventos del modelo (y por lo tanto sin indexar).
     */
    private function pacienteGuardado(
        string $nombres,
        string $apellidos,
        string $cedula,
        string $numhistoria,
        Medico $medico,
        bool $sinEventos = false,
    ): void {
        $crear = fn () => Paciente::create([
            'nombres'     => $nombres,
            'apellidos'   => $apellidos,
            'cedula'      => $cedula,
            'sexo'        => 'F',
            'fnacimiento' => '1990-05-04',
        ]);

        $paciente = $sinEventos ? Paciente::withoutEvents($crear) : $crear();

        DB::table('medico_pacientes')->insert([
            'medico_id'   => $medico->id,
            'paciente_id' => $paciente->id,
            'numhistoria' => $numhistoria,
            'reg_medico'  => $medico->reg_medico,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }
}
