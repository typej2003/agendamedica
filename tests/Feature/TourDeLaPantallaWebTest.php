<?php

namespace Tests\Feature;

use App\Clinica\Tours\ToursDisponibles;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **El tour guiado de cada pantalla** (A-15).
 *
 * Lo que estos casos fijan: la marca vive en el servidor (`tours_vistos`), el tour se abre solo la primera
 * vez y no la segunda, la marca entra por `POST` (nunca por `GET`), reabrir y volver a terminar no duplica
 * ni borra la marca, las cinco pantallas lo reciben para el médico y la secretaría no recibe los de la
 * consulta ni los catálogos (que además le responden 403 en sus rutas).
 *
 * Lo que se ve en el navegador (el globo, el resalte, `Esc`, el botón de reabrir, las anchuras) lo mide
 * `.agent-tools/verificar-tours-web.js`.
 */
class TourDeLaPantallaWebTest extends TestCase
{
    use DatabaseTransactions;

    // --------------------------------------------------------------------------------------- Ayudas

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'tour-test-' . substr(uniqid(), -6);

        $user = User::create([
            'name' => 'Doctora de prueba', 'email' => 'tour-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id' => $user->id, 'name' => 'Doctora', 'lastname' => 'De Prueba', 'email' => $user->email,
            'password' => $user->password, 'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function secretariaConAcceso(Medico $medico): User
    {
        $secretaria = User::create([
            'name' => 'Secretaria', 'email' => 'tour-secre-' . substr(uniqid(), -6) . '@example.com',
            'password' => Hash::make('secreto123'), 'is_active' => true, 'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        return $secretaria;
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico' => $medico->reg_medico, 'specialty_id' => $specialty->id, 'office_id' => null,
        ]])->actingAs($user);
    }

    /** Un paciente con historia y su consulta, para que la pantalla de la consulta exista de verdad. */
    private function pacienteYConsulta(Medico $medico, int $numhistoria = 4321, int $nroconsulta = 1): Consulta
    {
        $pacienteId = DB::table('pacientes')->insertGetId([
            'nombres' => 'Ana', 'apellidos' => 'Alvarez', 'cedula' => (string) random_int(10000000, 30000000),
            'sexo' => 'F', 'fnacimiento' => '1990-05-04', 'created_at' => now(), 'updated_at' => now(),
        ]);

        MedicoPaciente::create([
            'medico_id' => $medico->id, 'paciente_id' => $pacienteId,
            'reg_medico' => $medico->reg_medico, 'numhistoria' => (string) $numhistoria,
        ]);

        Historia::create([
            'numhistoria' => (string) $numhistoria, 'reg_medico' => $medico->reg_medico,
            'paciente_id' => $pacienteId, 'medico_id' => $medico->id,
        ]);

        return Consulta::create([
            'reg_medico' => $medico->reg_medico, 'numhistoria' => $numhistoria,
            'nroconsulta' => $nroconsulta, 'fecha' => now()->toDateString(),
        ]);
    }

    private function pasosEnElHtml(string $html): int
    {
        return substr_count($html, 'data-tour-paso');
    }

    // ------------------------------------------------------------------- Primera visita y la marca

    /** La primera visita muestra el tour y deja el botón de reabrir; todavía no hay ninguna marca. */
    public function test_la_primera_visita_muestra_el_tour_y_ofrece_reabrirlo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $html = $this->conContexto($user, $medico, $gineco)->get(route('clinica.inicio'))->assertOk()->getContent();

        $this->assertStringContainsString('data-tour-raiz', $html);
        $this->assertStringContainsString('data-pantalla="inicio"', $html);
        $this->assertStringContainsString('data-mostrar="1"', $html, 'La primera vez se abre solo.');
        $this->assertStringContainsString('data-selector="[data-tour=inicio-lista] tbody tr"', $html);
        $this->assertStringContainsString('Los pacientes de hoy', $html);
        $this->assertStringContainsString('¿Cómo funciona esta pantalla?', $html, 'El botón de reabrir está desde el principio.');
        $this->assertGreaterThan(0, $this->pasosEnElHtml($html));
        $this->assertStringContainsString('role="dialog" aria-modal="true"', $html, 'El globo es modal: el velo bloquea la pantalla.');

        $this->assertDatabaseCount('tours_vistos', 0);
    }

    /** Terminar o saltar avisa por POST: la fila queda y la visita siguiente ya no lo abre solo. */
    public function test_marcar_como_visto_se_hace_por_post_y_no_se_repite(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)
            ->post(route('clinica.tour.visto', ['pantalla' => 'inicio']))
            ->assertOk()
            ->assertJson(['pantalla' => 'inicio']);

        $this->assertDatabaseHas('tours_vistos', ['user_id' => $user->id, 'pantalla' => 'inicio']);

        // La visita siguiente ya no lo abre solo, pero el botón de reabrir sigue estando.
        $html = $this->conContexto($user, $medico, $gineco)->get(route('clinica.inicio'))->assertOk()->getContent();
        $this->assertStringContainsString('data-mostrar="0"', $html);
        $this->assertStringContainsString('¿Cómo funciona esta pantalla?', $html);

        // Marcar de nuevo (reabrir y volver a terminar) no duplica la fila.
        $this->conContexto($user, $medico, $gineco)
            ->post(route('clinica.tour.visto', ['pantalla' => 'inicio']))->assertOk();

        $this->assertDatabaseCount('tours_vistos', 1);
    }

    /** Reabrir es del navegador, pero la marca no se borra: la pantalla sigue ofreciendo el tour sin abrirlo. */
    public function test_reabrir_no_borra_la_marca(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)->post(route('clinica.tour.visto', ['pantalla' => 'agenda']));

        $this->conContexto($user, $medico, $gineco)->get(route('clinica.agenda'))->assertOk();

        $this->assertDatabaseHas('tours_vistos', ['user_id' => $user->id, 'pantalla' => 'agenda']);
        $this->assertDatabaseCount('tours_vistos', 1);
    }

    /** La marca es de cada usuario: que otra cuenta haya visto el tour no cambia el mío. */
    public function test_la_marca_es_por_usuario(): void
    {
        [$medicoUser, $medico, $gineco] = $this->medicoConAcceso();
        [$otroUser, $otroMedico, $otraEspecialidad] = $this->medicoConAcceso();

        $this->conContexto($medicoUser, $medico, $gineco)
            ->post(route('clinica.tour.visto', ['pantalla' => 'agenda']))->assertOk();

        $html = $this->conContexto($otroUser, $otroMedico, $otraEspecialidad)
            ->get(route('clinica.agenda'))->assertOk()->getContent();

        $this->assertStringContainsString('data-mostrar="1"', $html);
    }

    // ------------------------------------------------------------------- El endpoint

    public function test_el_endpoint_no_acepta_get(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)
            ->get(route('clinica.tour.visto', ['pantalla' => 'agenda']))
            ->assertStatus(405);
    }

    public function test_una_pantalla_que_no_existe_da_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->conContexto($user, $medico, $gineco)
            ->post(route('clinica.tour.visto', ['pantalla' => 'inventada']))
            ->assertNotFound();

        $this->assertDatabaseCount('tours_vistos', 0);
    }

    // ------------------------------------------------------------------- Las cinco pantallas

    /** El médico recibe los cinco tours, cada uno con su pantalla y sus pasos. */
    public function test_el_medico_recibe_el_tour_de_las_cinco_pantallas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico);

        $pantallas = [
            'inicio'       => [route('clinica.inicio'), '[data-tour=inicio-lista] tbody tr'],
            'agenda'       => [route('clinica.agenda'), '[data-tour=agenda-nueva]'],
            'consulta'     => [route('clinica.consulta', $consulta->id), '[data-tour=consulta-guardar]'],
            'catalogos'    => [route('clinica.catalogos.plantillas-texto'), '[data-tour=menu-plantillas-de-texto]'],
            'consultorios' => [route('clinica.consultorios'), '[data-tour=consultorios-alta]'],
        ];

        foreach ($pantallas as $pantalla => [$url, $selector]) {
            $html = $this->conContexto($user, $medico, $gineco)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('data-pantalla="' . $pantalla . '"', $html, "Falta el tour de {$pantalla}.");
            $this->assertStringContainsString('data-mostrar="1"', $html, "El tour de {$pantalla} no se abre la primera vez.");
            $this->assertStringContainsString('data-selector="' . $selector . '"', $html, "Falta el paso {$selector} en {$pantalla}.");
            $this->assertGreaterThan(0, $this->pasosEnElHtml($html), "El tour de {$pantalla} no tiene pasos.");
        }
    }

    /** Los pasos de la consulta y de la agenda que el enunciado pide, uno por uno. */
    public function test_los_pasos_de_la_consulta_apuntan_a_lo_que_hay_en_la_pantalla(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico);

        // Con una sección abierta están el botón de plantillas y el de guardar.
        $url = route('clinica.consulta', [$consulta->id, 'seccion' => 'evolucion']);
        $html = $this->conContexto($user, $medico, $gineco)->get($url)->assertOk()->getContent();

        foreach (['menu-consulta', 'consulta-encabezado', 'consulta-navegador', 'consulta-plantillas', 'consulta-guardar'] as $selector) {
            $this->assertStringContainsString('data-selector="[data-tour=' . $selector . ']"', $html);
        }

        // Y los elementos señalados existen en el HTML (el paso no inventa nada).
        $this->assertStringContainsString('data-tour="menu-consulta"', $html);
        $this->assertStringContainsString('data-tour="consulta-encabezado"', $html);
        $this->assertStringContainsString('data-tour="consulta-plantillas"', $html);
        $this->assertStringContainsString('data-tour="consulta-guardar"', $html);
    }

    public function test_los_pasos_de_la_agenda_y_de_los_consultorios_tienen_su_elemento(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $agenda = $this->conContexto($user, $medico, $gineco)->get(route('clinica.agenda'))->assertOk()->getContent();
        foreach (['agenda-barra', 'agenda-nueva'] as $selector) {
            $this->assertStringContainsString('data-selector="[data-tour=' . $selector . ']"', $agenda);
        }
        $this->assertStringContainsString('data-tour="agenda-barra"', $agenda);
        $this->assertStringContainsString('data-tour="agenda-nueva"', $agenda);

        $consultorios = $this->conContexto($user, $medico, $gineco)->get(route('clinica.consultorios'))->assertOk()->getContent();
        foreach (['[data-tour=consultorios-lista] tbody tr td:first-child', '[data-tour=consultorios-bloques]', '[data-tour=consultorios-alta]'] as $selector) {
            $this->assertStringContainsString('data-selector="' . $selector . '"', $consultorios);
        }
        $this->assertStringContainsString('data-tour="consultorios-lista"', $consultorios);
    }

    // ------------------------------------------------------------------- La secretaría

    /**
     * La secretaría ve el tour de la agenda (su inicio, A-12) y el de consultorios, pero **no** el de la
     * consulta ni el de los catálogos: ésas son pantallas del médico y para ella quedan vacías.
     */
    public function test_la_secretaria_solo_recibe_los_tours_que_puede_ver(): void
    {
        [$medicoUser, $medico, $gineco] = $this->medicoConAcceso();
        $secretaria = $this->secretariaConAcceso($medico);

        // `/clinica` la manda a la agenda (A-12) y ahí sí tiene su tour.
        $this->conContexto($secretaria, $medico, $gineco)->get('/clinica')->assertRedirect(route('clinica.agenda'));

        $agenda = $this->conContexto($secretaria, $medico, $gineco)->get(route('clinica.agenda'))->assertOk()->getContent();
        $this->assertStringContainsString('data-pantalla="agenda"', $agenda);

        $consultorios = $this->conContexto($secretaria, $medico, $gineco)->get(route('clinica.consultorios'))->assertOk()->getContent();
        $this->assertStringContainsString('data-pantalla="consultorios"', $consultorios);

        // Los tours que no son suyos no se le pintan ni se pueden marcar.
        $this->assertSame([], ToursDisponibles::pasos(ToursDisponibles::CONSULTA, $secretaria));
        $this->assertSame([], ToursDisponibles::pasos(ToursDisponibles::CATALOGOS, $secretaria));
        $this->assertSame([], ToursDisponibles::pasos(ToursDisponibles::INICIO, $secretaria));

        $this->conContexto($secretaria, $medico, $gineco)
            ->post(route('clinica.tour.visto', ['pantalla' => 'consulta']))->assertForbidden();
        $this->conContexto($secretaria, $medico, $gineco)
            ->post(route('clinica.tour.visto', ['pantalla' => 'catalogos']))->assertForbidden();

        // Y sus pantallas siguen cerradas (la regla es la de la ruta, no la del tour).
        $this->conContexto($secretaria, $medico, $gineco)->get(route('clinica.catalogos.plantillas-texto'))->assertForbidden();

        // El médico sí los tiene.
        $this->assertNotEmpty(ToursDisponibles::pasos(ToursDisponibles::CONSULTA, $medicoUser));
    }

    /**
     * **La ayuda no ocupa una fila en la consulta** (O-31): su encabezado es una sola fila de ~49 px y el
     * botón de reabrir va dentro, al final, junto a Guardar y Terminar. En las demás pantallas el botón sigue
     * arriba, en su propia fila.
     */
    public function test_en_la_consulta_el_boton_de_reabrir_va_dentro_del_encabezado(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $consulta = $this->pacienteYConsulta($medico);

        $url = route('clinica.consulta', [$consulta->id, 'seccion' => 'evolucion']);
        $html = $this->conContexto($user, $medico, $gineco)->get($url)->assertOk()->getContent();

        // La ayuda está, como botón redondo, con su nombre accesible y el tour abriéndose solo la primera vez.
        $this->assertStringContainsString('class="tour-reabrir-redondo"', $html);
        $this->assertStringContainsString('aria-label="¿Cómo funciona esta pantalla?"', $html);
        $this->assertStringContainsString('title="¿Cómo funciona esta pantalla?"', $html);
        $this->assertStringContainsString('data-mostrar="1"', $html);

        // Dentro de la fila del encabezado y después de Guardar y Terminar: no hay renglón propio.
        $this->assertStringNotContainsString('tour-reabrir-fila', $html, 'La consulta no debe gastar una fila en la ayuda.');
        $this->assertGreaterThan(strpos($html, 'data-tour="consulta-encabezado"'), strpos($html, 'data-tour-reabrir'));
        $this->assertGreaterThan(strpos($html, 'data-barra-guardar'), strpos($html, 'data-tour-reabrir'));

        // En otra pantalla se queda como estaba: arriba del encabezado, en su fila.
        $inicio = $this->conContexto($user, $medico, $gineco)->get(route('clinica.inicio'))->assertOk()->getContent();
        $this->assertStringContainsString('tour-reabrir-fila', $inicio);
        $this->assertStringNotContainsString('tour-reabrir-redondo', $inicio);
    }

    /** Un catálogo que no está en la lista de pantallas con tour no lo recibe. */
    public function test_una_pantalla_sin_tour_no_pinta_el_boton_de_reabrir(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $html = $this->conContexto($user, $medico, $gineco)->get(route('clinica.pacientes'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-tour-raiz', $html);
        $this->assertStringNotContainsString('¿Cómo funciona esta pantalla?', $html);
    }
}
