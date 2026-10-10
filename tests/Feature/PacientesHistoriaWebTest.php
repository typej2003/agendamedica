<?php

namespace Tests\Feature;

use App\Clinica\Pacientes\FiltroDePacientes;
use App\Models\Cola;
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
 * **Asignar el número de historia desde la ficha del paciente** (`POST /clinica/pacientes/{id}/historia`,
 * WEB-3.3) y el filtro **"Sin historia"** del listado (`/clinica/pacientes?historia=sin-historia`).
 *
 * Lo que se prueba es el criterio:
 *
 *  - la ficha del paciente **sin** historia ofrece la acción y la del que **ya tiene** no la ofrece;
 *  - asignarla le da el **correlativo siguiente** del médico y deja **la misma ficha** mostrando el
 *    número;
 *  - es **idempotente**: llamarla dos veces no numera dos veces (y lo dice);
 *  - un paciente de otro `reg_medico` da **404** y no se numera nada;
 *  - el filtro "Sin historia" del listado muestra **sólo** a ésos, con su conteo, y no se sale del
 *    `reg_medico` del contexto.
 *
 * La regla del número en sí (el correlativo, el reintento y el enlace de las citas) vive en
 * `App\Actions\Pacientes\AsignarHistoria` y se prueba en `AsignarHistoriaTest` (WEB-2.11), **sin
 * tocarse**: acá se prueba lo que agrega la web.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class PacientesHistoriaWebTest extends TestCase
{
    use DatabaseTransactions;

    // --------------------------------------------------------------------------------------- Ayudas

    private function medicoConAcceso(string $rol = 'Medico'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'pac-hist-' . uniqid();

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'pac-hist-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dra. de prueba',
            'lastname'   => 'Historia',
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

    /**
     * Un paciente con su vínculo. `$numhistoria` nulo = el alta rápida, todavía sin número; cuando no es
     * nulo también se crea la fila de `historias`, que es como la leen el sync y la Action.
     */
    private function paciente(Medico $medico, string $nombres, string $apellidos = 'De Prueba', ?string $numhistoria = null): int
    {
        $id = DB::table('pacientes')->insertGetId([
            'nombres'     => $nombres,
            'apellidos'   => $apellidos,
            'cedula'      => (string) random_int(10000000, 30000000),
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

        if ($numhistoria !== null) {
            Historia::create([
                'numhistoria' => $numhistoria,
                'reg_medico'  => $medico->reg_medico,
                'paciente_id' => $id,
                'medico_id'   => $medico->id,
            ]);
        }

        return $id;
    }

    // ------------------------------------------------------------------ La ficha (la acción a la vista)

    public function test_la_ficha_sin_historia_ofrece_la_accion_y_explica_el_efecto(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Ana', 'Sin Historia');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.ver', $paciente));

        $respuesta->assertOk();
        $respuesta->assertSee('Sin historia');
        $respuesta->assertSee('Falta el número de historia');
        $respuesta->assertSee('Asignar el número de historia');
        // El POST va a la ruta de ese paciente y la confirmación dice el efecto (el número no se elige).
        $respuesta->assertSee(route('clinica.pacientes.asignar-historia', $paciente), false);
        $respuesta->assertSee('no se puede deshacer');
    }

    public function test_la_ficha_del_que_ya_tiene_historia_no_ofrece_asignarla(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Beto', 'Con Historia', '1001');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.ver', $paciente));

        $respuesta->assertOk();
        $respuesta->assertSee('Historia N.º 1001');
        $respuesta->assertDontSee('Falta el número de historia');
        $respuesta->assertDontSee('Asignar el número de historia');
        $respuesta->assertDontSee(route('clinica.pacientes.asignar-historia', $paciente), false);
    }

    // ------------------------------------------------------------------------------ Asignar la historia

    public function test_asignar_la_historia_da_el_correlativo_siguiente_y_la_ficha_lo_muestra(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        // El correlativo del médico ya va por la 1001: la próxima es la 1002 (el número es **del
        // médico**, no global: lo dice la wiki `04-historia-clinica.md`).
        $this->paciente($medico, 'Ana', 'Anterior', '1001');
        $nuevo = $this->paciente($medico, 'Beto', 'Nuevo');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.pacientes.asignar-historia', $nuevo));

        $respuesta->assertRedirect(route('clinica.pacientes.ver', $nuevo));
        $respuesta->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'H. 1002'));

        // La historia y el vínculo quedaron escritos, y es la misma ficha (no un paciente nuevo).
        $historia = Historia::where('paciente_id', $nuevo)->firstOrFail();
        $this->assertSame('1002', $historia->numhistoria);
        $this->assertSame($medico->reg_medico, $historia->reg_medico);
        $this->assertSame('1002', MedicoPaciente::where('paciente_id', $nuevo)->value('numhistoria'));

        // Y la ficha —la misma URL— ahora lo muestra y ya no ofrece la acción.
        $ficha = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes.ver', $nuevo));

        $ficha->assertOk();
        $ficha->assertSee('Historia N.º 1002');
        $ficha->assertDontSee('Falta el número de historia');
    }

    public function test_asignar_la_historia_dos_veces_no_numera_dos_veces(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Beto', 'Nuevo');

        $peticion = $this->conContexto($user, $medico, $gineco)->actingAs($user);
        $peticion->post(route('clinica.pacientes.asignar-historia', $paciente))
            ->assertRedirect()
            ->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'H. 1'));

        $segunda = $peticion->post(route('clinica.pacientes.asignar-historia', $paciente));
        $segunda->assertRedirect();
        // La segunda vez lo dice: devolvió la que ya tenía, no asignó otra.
        $segunda->assertSessionHas('estado', fn ($estado) => str_contains($estado, 'ya tenía historia'));

        $this->assertSame(1, Historia::where('paciente_id', $paciente)->count());
        $this->assertSame(1, Historia::where('reg_medico', $medico->reg_medico)->count());
    }

    public function test_un_paciente_de_otro_medico_da_404_y_no_se_numera(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        // Otro médico, con un paciente suyo sin historia.
        [, $otro] = $this->medicoConAcceso();
        $ajeno = $this->paciente($otro, 'Ajena', 'Persona');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.pacientes.asignar-historia', $ajeno))
            ->assertNotFound();

        $this->assertSame(0, Historia::where('paciente_id', $ajeno)->count());
        $this->assertNull(MedicoPaciente::where('paciente_id', $ajeno)->value('numhistoria'));
    }

    public function test_la_historia_sale_del_medico_del_contexto_y_no_del_formulario(): void
    {
        // `reg_medico` es el tenant: no viaja en el POST y lo que se mande a mano se ignora. El número
        // sale del vínculo del paciente.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Beto', 'Nuevo');

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.pacientes.asignar-historia', $paciente), ['reg_medico' => 'otro-registro', 'numhistoria' => '9999'])
            ->assertRedirect();

        $this->assertSame($medico->reg_medico, Historia::where('paciente_id', $paciente)->value('reg_medico'));
        $this->assertSame('1', Historia::where('paciente_id', $paciente)->value('numhistoria'));
    }

    public function test_la_secretaria_tambien_puede_asignar_la_historia(): void
    {
        // Es su trabajo: el legado lo hacía la secretaría al recibir al paciente (wiki
        // `04-historia-clinica.md`), y el médico si llegaba a atenderlo sin historia.
        $this->seed(EspecialidadesYModulosSeeder::class);
        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();

        $medico = Medico::create(['name' => 'Dr. Dueño', 'lastname' => 'Datos', 'reg_medico' => 'pac-hist-secre-' . uniqid()]);
        $medico->specialties()->attach($gineco->id);

        $secretaria = User::create([
            'name'                 => 'Secretaria',
            'email'                => 'pac-hist-secre-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        $paciente = $this->paciente($medico, 'Beto', 'Nuevo');

        $this->conContexto($secretaria, $medico, $gineco)->actingAs($secretaria)
            ->post(route('clinica.pacientes.asignar-historia', $paciente))
            ->assertRedirect()
            ->assertSessionHas('estado');

        $this->assertSame(1, Historia::where('paciente_id', $paciente)->count());
    }

    public function test_un_invitado_no_puede_asignar_la_historia(): void
    {
        [$user, $medico] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Beto', 'Nuevo');

        $this->post(route('clinica.pacientes.asignar-historia', $paciente))
            ->assertRedirect(route('login'));

        $this->assertSame(0, Historia::where('paciente_id', $paciente)->count());
    }

    public function test_asignar_la_historia_enlaza_la_cita_que_estaba_sin_historia(): void
    {
        // El paciente dado de alta por el alta de la cita (WEB-2.2) tiene la cita anclada por
        // `cola.paciente_sinhistoria_id`: al asignarle el número desde la ficha, la Action enlaza **esa**
        // misma cita. Es el caso real del mostrador: la secretaría llena la historia desde el paciente y
        // la cita del día deja de estar "sin historia" sin volver a la agenda.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente($medico, 'Beto', 'Nuevo');
        $cita = Cola::create([
            'reg_medico'              => $medico->reg_medico,
            'fecha'                   => '2026-10-05',
            'hora_ini'                => '09:00:00',
            'paciente_sinhistoria_id' => $paciente,
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->post(route('clinica.pacientes.asignar-historia', $paciente))
            ->assertRedirect();

        $this->assertSame(1, (int) $cita->fresh()->numhistoria);
        $this->assertSame(1, Cola::where('id', $cita->id)->count());
    }

    // --------------------------------------------------------------------- El filtro "Sin historia"

    public function test_el_filtro_sin_historia_muestra_solo_a_los_que_no_la_tienen(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 'Ana', 'Con Historia', '1001');
        $this->paciente($medico, 'Beto', 'Sin Historia');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes', ['historia' => FiltroDePacientes::SIN_HISTORIA]));

        $respuesta->assertOk();
        $respuesta->assertSee('Sin Historia, Beto');
        $respuesta->assertDontSee('Con Historia, Ana');
        // El conteo de cada segmento va en su etiqueta, calculado por el controlador.
        $respuesta->assertSee('Todos (2)');
        $respuesta->assertSee('Sin historia (1)');
        $respuesta->assertSee('Con historia (1)');
    }

    public function test_el_filtro_con_historia_muestra_los_contrarios(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 'Ana', 'Con Historia', '1001');
        $this->paciente($medico, 'Beto', 'Sin Historia');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes', ['historia' => FiltroDePacientes::CON_HISTORIA]));

        $respuesta->assertOk();
        $respuesta->assertSee('Con Historia, Ana');
        $respuesta->assertDontSee('Sin Historia, Beto');
    }

    public function test_el_filtro_no_se_sale_del_medico_del_contexto(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 'Beto', 'Sin Historia');

        // Otro médico, con un paciente suyo sin historia y el mismo apellido.
        [, $otro] = $this->medicoConAcceso();
        $this->paciente($otro, 'Zoe', 'Sin Historia');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes', ['historia' => FiltroDePacientes::SIN_HISTORIA]));

        $respuesta->assertOk();
        $respuesta->assertSee('Sin Historia, Beto');
        $respuesta->assertDontSee('Sin Historia, Zoe');
        $respuesta->assertSee('Sin historia (1)');
    }

    public function test_el_filtro_tolera_la_historia_vacia_o_con_espacios_del_legado(): void
    {
        // El legado guarda "sin número" de dos formas (`null` y `''`) y a veces con espacios: la fila
        // muestra "Sin historia" en los tres casos, así que el filtro tiene que traerlos a los tres o la
        // pantalla diría una cosa y el filtro haría otra.
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $nulo = $this->paciente($medico, 'Nulo', 'Sin Historia');
        $vacio = $this->paciente($medico, 'Vacio', 'Sin Historia');
        $espacios = $this->paciente($medico, 'Espacios', 'Sin Historia');
        MedicoPaciente::where('paciente_id', $vacio)->update(['numhistoria' => '']);
        MedicoPaciente::where('paciente_id', $espacios)->update(['numhistoria' => '   ']);
        $this->paciente($medico, 'Ana', 'Con Historia', '1001');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes', ['historia' => FiltroDePacientes::SIN_HISTORIA]));

        $respuesta->assertOk();
        $respuesta->assertSee('Sin Historia, Nulo');
        $respuesta->assertSee('Sin Historia, Vacio');
        $respuesta->assertSee('Sin Historia, Espacios');
        $respuesta->assertDontSee('Con Historia, Ana');
        $respuesta->assertSee('Sin historia (3)');
        $this->assertNotNull($nulo);
    }

    public function test_el_filtro_desconocido_se_comporta_como_todos(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 'Ana', 'Con Historia', '1001');
        $this->paciente($medico, 'Beto', 'Sin Historia');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes', ['historia' => 'lo-que-sea']));

        $respuesta->assertOk();
        $respuesta->assertSee('Con Historia, Ana');
        $respuesta->assertSee('Sin Historia, Beto');
    }

    public function test_el_filtro_sin_historia_sin_pacientes_lo_dice(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 'Ana', 'Con Historia', '1001');

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes', ['historia' => FiltroDePacientes::SIN_HISTORIA]));

        $respuesta->assertOk();
        $respuesta->assertSee('No hay pacientes sin historia');
        $respuesta->assertSee('Sin historia (0)');
    }

    public function test_el_filtro_conserva_la_busqueda_y_limpiar_la_quita_sin_cambiar_de_segmento(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente($medico, 'Beto', 'Sin Historia');
        $this->paciente($medico, 'Carla', 'Sin Historia');

        // Buscar dentro del segmento: el filtro no se pierde al escribir en el buscador.
        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get(route('clinica.pacientes', ['historia' => FiltroDePacientes::SIN_HISTORIA, 'buscar' => 'Beto']));

        $respuesta->assertOk();
        $respuesta->assertSee('Sin Historia, Beto');
        $respuesta->assertDontSee('Sin Historia, Carla');
        // La búsqueda viaja en las opciones del segmentado y en "Limpiar" (que deja el filtro puesto).
        $respuesta->assertSee('buscar=Beto', false);
        $respuesta->assertSee('historia=sin-historia', false);
    }
}
