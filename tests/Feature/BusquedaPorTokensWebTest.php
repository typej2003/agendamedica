<?php

namespace Tests\Feature;

use App\Clinica\Pacientes\PacientesDelConsultorio;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Pecle\Search\Laravel\PecleSearchManager;
use Tests\TestCase;

/**
 * La búsqueda de pacientes **por tokens** (`search_tokens`), que es la que corre cuando el índice está
 * construido: cada palabra tiene que ser el comienzo de una palabra indexada del nombre, el apellido o la
 * cédula; el n.º de historia se busca en el pivote del consultorio. La comparación con `entity_id` lleva
 * `CAST` porque el id de `pacientes` es entero y el del índice texto (ver `BusquedaDePacientes`).
 *
 * Con el índice sin construir, la búsqueda cae al respaldo por `LIKE` (lo cubre el último test y toda la
 * suite de pacientes, que no lo construye).
 */
class BusquedaPorTokensWebTest extends TestCase
{
    use DatabaseTransactions;

    // --------------------------------------------------------------------------------------- Ayudas

    private function medicoConAcceso(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'pac-tok-' . substr(uniqid(), -6);

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'pac-tok-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dra. de prueba',
            'lastname'   => 'Tokens',
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

    private function otroMedico(): Medico
    {
        $regMedico = 'pac-tok-otro-' . substr(uniqid(), -6);

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

    /** Construye el índice de tokens con los pacientes que hay ahora. */
    private function construirIndice(): void
    {
        app(PecleSearchManager::class)->rebuild('pacientes');
    }

    /** Las filas que devuelve la lectura del consultorio, sin pasar por el corrector ni por HTTP. */
    private function filas(Medico $medico, string $buscar): array
    {
        return app(PacientesDelConsultorio::class)
            ->listar([$medico->reg_medico], $medico->reg_medico, $buscar, 'todos')
            ->pluck('nombres')
            ->all();
    }

    // ------------------------------------------------------------------------------------- Por tokens

    public function test_busca_por_el_comienzo_de_la_palabra_del_apellido(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 'Ana', 'González', '11111111', '2001');
        $this->paciente($medico, 'Luis', 'Martínez', '22222222', '2002');
        $this->construirIndice();

        $this->conContexto($user, $medico, $gineco)
            ->actingAs($user)
            ->get('/clinica/pacientes?buscar=Gonz')
            ->assertOk()
            ->assertSee('González')
            ->assertDontSee('Martínez');

        $this->assertSame(['Ana'], $this->filas($medico, 'Gonz'));
        $this->assertSame(['Ana'], $this->filas($medico, 'GONZÁLEZ'));
    }

    public function test_ya_no_encuentra_por_el_medio_de_la_palabra(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $this->paciente($medico, 'Ana', 'González', '11111111', '2001');
        $this->construirIndice();

        // «onzale» es el medio de «gonzalez»: el índice es por comienzo de palabra.
        $this->assertSame([], $this->filas($medico, 'onzale'));
        // Y el comienzo sí.
        $this->assertSame(['Ana'], $this->filas($medico, 'gonza'));
    }

    public function test_busca_por_la_cedula_completa(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $this->paciente($medico, 'Ana', 'González', '19920020', '2001');
        $this->paciente($medico, 'Luis', 'Martínez', '22222222', '2002');
        $this->construirIndice();

        $this->assertSame(['Ana'], $this->filas($medico, '19920020'));
        $this->assertSame(['Ana'], $this->filas($medico, '1992'));
    }

    public function test_busca_por_el_numero_de_historia_con_y_sin_los_ceros_a_la_izquierda(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $this->paciente($medico, 'Ana', 'González', '11111111', '01001');
        $this->paciente($medico, 'Luis', 'Martínez', '22222222', '2002');
        $this->construirIndice();

        $this->assertSame(['Ana'], $this->filas($medico, '01001'));
        $this->assertSame(['Ana'], $this->filas($medico, '1001'));
    }

    public function test_no_distingue_acentos_ni_mayusculas(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $this->paciente($medico, 'Rosa', 'Jáquez', '33333333', '2003');
        $this->construirIndice();

        foreach (['Jáquez', 'jáquez', 'JÁQUEZ', 'jaquez', 'JAQUEZ'] as $buscar) {
            $this->assertSame(['Rosa'], $this->filas($medico, $buscar), $buscar);
        }
    }

    public function test_no_devuelve_pacientes_de_otro_consultorio(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $this->paciente($medico, 'Ana', 'González', '11111111', '2001');
        $this->paciente($this->otroMedico(), 'Zulema', 'González', '44444444', '3001');
        $this->construirIndice();

        $this->assertSame(['Ana'], $this->filas($medico, 'Gonz'));
    }

    public function test_el_buscador_del_alta_de_cita_usa_los_mismos_tokens(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 'Ana', 'González', '11111111', '2001');
        $this->construirIndice();

        $this->conContexto($user, $medico, $gineco)
            ->actingAs($user)
            ->getJson('/clinica/pacientes/buscar?q=Gonz')
            ->assertOk()
            ->assertJsonFragment(['nombre' => 'González, Ana']);
    }

    // ---------------------------------------------------------------------------------------- Respaldo

    public function test_sin_indice_construido_la_busqueda_cae_al_respaldo_por_like(): void
    {
        [, $medico] = $this->medicoConAcceso();
        $this->paciente($medico, 'Ana', 'González', '11111111', '2001');
        // Sin construir el índice: `search_indexes` vacío.

        // El respaldo sí encuentra por el medio de la palabra, que es lo que hacía antes.
        $this->assertSame(['Ana'], $this->filas($medico, 'onzale'));
        $this->assertSame(['Ana'], $this->filas($medico, 'Gonz'));
    }
}
