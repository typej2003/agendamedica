<?php

namespace Tests\Feature;

use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\PacienteDeLaCita;
use App\Clinica\FechaDelConsultorio;
use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Models\Specialty;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **«Le doy atender y sale el nombre de otro paciente»** (informe del usuario, 2026-10-19, pediatría).
 *
 * El número de historia es único **por registro** (`historias` tiene `(reg_medico, numhistoria)` único),
 * pero el vínculo `medico_pacientes` guarda el número como texto y **no** tiene esa unicidad: el
 * importador de SQL del `/admin` escribe el vínculo **antes** de la fila de `historias`, así que un
 * número que ya era de otro paciente deja el vínculo suelto. Con dos vínculos para el mismo número,
 * la agenda se quedaba con el **último** (su `mapWithKeys`) y «Atender»/el encabezado de la consulta con
 * el **primero**: el médico veía un paciente en la agenda y otro al abrirlo.
 *
 * La regla que fija este test: **el dueño del número es el que está en `historias`**, y todas las
 * pantallas del flujo nombran a ése. Se prueba con pediatría porque es el caso informado: el chamo
 * importado (`paciente_chamo` no tiene apellidos) convive con un paciente cargado desde el `/admin`.
 */
class HistoriaConDuenoUnicoWebTest extends TestCase
{
    use DatabaseTransactions;

    private Carbon $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hoy = FechaDelConsultorio::dia('2026-10-19');
        Carbon::setTestNow($this->hoy->copy());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // --------------------------------------------------------------------------------------- Ayudas

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoDePediatria(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $pediatria = Specialty::where('slug', 'pediatria')->firstOrFail();
        $regMedico = 'hist-dueno-' . substr(uniqid(), -6);

        $user = User::create([
            'name'                 => 'Pediatra de prueba',
            'email'                => 'hist-dueno-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Historia',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($pediatria->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $pediatria];
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico'   => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id'    => null,
        ]]);
    }

    private function sede(Medico $medico): Office
    {
        $centro = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id'   => DB::table('estados')->value('id'),
            'city_id'    => DB::table('cities')->value('id'),
            'name'       => 'San José',
            'address'    => 'Dirección de prueba',
        ]);

        $sede = Office::create([
            'medical_center_id' => $centro->id,
            'medico_id'         => $medico->id,
            'reg_medico'        => $medico->reg_medico,
            'office_number'     => 'C1',
            'modalidad'         => Office::MODALIDAD_HORA,
            'duracion_cita'     => 30,
            'activo'            => true,
        ]);

        OfficeSchedule::create([
            'office_id'   => $sede->id,
            'reg_medico'  => $medico->reg_medico,
            'dia_semana'  => 1,
            'hora_inicio' => '08:00',
            'hora_fin'    => '12:00',
            'cupo'        => null,
        ]);

        return $sede;
    }

    /**
     * Un paciente del médico con un número de historia en el vínculo.
     *
     * `$conHistoria` decide si el número **se registra** en `historias` (el dueño) o queda sólo en el
     * vínculo (el vínculo suelto que deja el importador de SQL del `/admin`).
     */
    private function paciente(
        Medico $medico,
        string $nombres,
        ?string $apellidos,
        int $numhistoria,
        bool $conHistoria,
    ): int {
        $id = DB::table('pacientes')->insertGetId([
            'nombres'     => $nombres,
            'apellidos'   => $apellidos,
            'cedula'      => (string) random_int(10000000, 30000000),
            'sexo'        => 'M',
            'fnacimiento' => '2018-05-04',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        MedicoPaciente::create([
            'medico_id'   => $medico->id,
            'paciente_id' => $id,
            'numhistoria' => (string) $numhistoria,
            'reg_medico'  => $medico->reg_medico,
        ]);

        if ($conHistoria) {
            Historia::create([
                'numhistoria' => (string) $numhistoria,
                'reg_medico'  => $medico->reg_medico,
                'paciente_id' => $id,
                'medico_id'   => $medico->id,
            ]);
        }

        return $id;
    }

    private function cita(Medico $medico, Office $sede, int $numhistoria): Cola
    {
        return Cola::create([
            'reg_medico'        => $medico->reg_medico,
            'fecha'             => $this->hoy->toDateString(),
            'hora_ini'          => '08:00:00',
            'medical_center_id' => $sede->medical_center_id,
            'numorden'          => 1,
            'numhistoria'       => $numhistoria,
            'atendido'          => 0,
            'estado'            => Cola::ESTADO_NO_CONFIRMADA,
        ]);
    }

    // ------------------------------------------------------------------------------------- Pruebas

    /**
     * El caso informado: el chamo importado es el dueño de la historia 21 y, en el mismo número, quedó el
     * vínculo suelto de un paciente cargado desde el `/admin`. La agenda nombraba al suelto (el último
     * vínculo) y la consulta al chamo (el primero).
     */
    public function test_la_agenda_y_la_consulta_nombran_al_dueno_de_la_historia(): void
    {
        [$user, $medico, $pediatria] = $this->medicoDePediatria();
        $sede = $this->sede($medico);

        // El dueño: el chamo importado de PediaReport (`paciente_chamo` no tiene apellidos).
        $this->paciente($medico, 'KARLA RONDON', null, 21, true);

        // El vínculo suelto: el paciente que dejó el importador de SQL con el mismo número, sin historia.
        $this->paciente($medico, 'ALEXANDER', 'DIAZ', 21, false);

        $cita = $this->cita($medico, $sede, 21);

        // 1) La agenda nombra al dueño del número.
        $agenda = $this->conContexto($user, $medico, $pediatria)->actingAs($user)->get(
            route('clinica.agenda', ['vista' => 'dia', 'fecha' => $this->hoy->toDateString(), 'sede' => 'todas'])
        );

        $agenda->assertOk();
        $agenda->assertSee('KARLA RONDON');
        $agenda->assertDontSee('DIAZ');

        // 2) «Atender» abre la consulta de la historia 21, y el encabezado nombra al mismo dueño.
        $respuesta = $this->conContexto($user, $medico, $pediatria)->actingAs($user)
            ->post(route('clinica.agenda.atender', $cita->id));

        $consulta = Consulta::where('reg_medico', $medico->reg_medico)->firstOrFail();
        $respuesta->assertRedirect(route('clinica.consulta', $consulta->id));

        $pantalla = $this->get(route('clinica.consulta', $consulta->id));
        $pantalla->assertOk();
        $pantalla->assertSee('KARLA RONDON');
        $pantalla->assertDontSee('DIAZ');
    }

    /**
     * El vínculo suelto **no** es la historia de ese paciente: la ficha y «Atender» tienen que decir que
     * no la tiene (y no que tiene la del otro). Es lo que destrababa la salida: con el número ocupado, la
     * ficha no ofrecía asignar y el paciente se quedaba sin historia propia para siempre.
     */
    public function test_el_paciente_del_vinculo_suelto_no_hereda_la_historia_del_dueno(): void
    {
        [$user, $medico] = $this->medicoDePediatria();

        $this->paciente($medico, 'KARLA RONDON', null, 21, true);
        $suelto = $this->paciente($medico, 'ALEXANDER', 'DIAZ', 21, false);

        $registros = [$medico->reg_medico];

        $this->assertNull(
            app(PacienteDeLaCita::class)->historia($suelto, $registros),
            'el número 21 es de otro paciente: el vínculo suelto no tiene historia'
        );

        // Y el dueño sí la tiene, con su número.
        $duenio = MedicoPaciente::where('reg_medico', $medico->reg_medico)
            ->where('paciente_id', '!=', $suelto)
            ->value('paciente_id');

        $this->assertSame('21', app(PacienteDeLaCita::class)->historia((int) $duenio, $registros)?->numhistoria);
    }

    /**
     * La consulta ya creada tampoco cambia de paciente: el encabezado resuelve por el registro de la
     * consulta y por el dueño del número, no por el primer vínculo que aparezca.
     */
    public function test_el_encabezado_de_la_consulta_resuelve_por_el_registro_y_por_el_dueno(): void
    {
        [$user, $medico] = $this->medicoDePediatria();

        $duenio = $this->paciente($medico, 'KARLA RONDON', null, 21, true);
        $this->paciente($medico, 'ALEXANDER', 'DIAZ', 21, false);

        $consulta = Consulta::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => 21,
            'nroconsulta' => 1,
            'fecha'       => $this->hoy->toDateString(),
        ]);

        $paciente = app(ConsultasDelContexto::class)->paciente($consulta, [$medico->reg_medico]);

        $this->assertNotNull($paciente);
        $this->assertSame((int) $duenio, $paciente->id);
        $this->assertSame('KARLA RONDON', trim($paciente->apellidos . ' ' . $paciente->nombres));
        $this->assertSame('21', (string) $paciente->numhistoria);
    }
}
