<?php

namespace Tests\Feature;

use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Evolucion;
use App\Models\Historia;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Office;
use App\Models\OfficeSchedule;
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
 * **La cita con su doctor** (Fase 3 del cambio de `reg_medico`, 2026-10-20).
 *
 * Las decisiones del usuario que fija este test:
 *
 *  - la cita del **centro** nace a nombre del **centro**, no de un doctor: `cola.medico_id` = `null` y en
 *    la columna legacy `cola.medico` va el `medicos.id` del centro (lo que el escritorio espera leer);
 *  - la cita del **consultorio personal** es del doctor, con su clave de `evolucion` en `cola.medico`;
 *  - el doctor **no se elige al agendar**: lo asigna la secretaría desde la agenda —sólo un doctor del
 *    consultorio, nunca uno ajeno ni el centro— o lo toma el médico al atender;
 *  - una cita **ya atendida** no cambia de doctor, y reagendarla no le pisa el doctor;
 *  - Inicio y Agenda muestran el médico de cada cita **cuando el consultorio tiene más de un doctor**, con
 *    «Sin asignar» mientras no lo tenga; con uno solo no se repite el nombre.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class MedicoDeLaCitaWebTest extends TestCase
{
    use DatabaseTransactions;

    /** Un lunes cualquiera: los bloques se siembran con `dia_semana = 1`. */
    private Carbon $lunes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lunes = Carbon::parse('2026-10-05')->startOfWeek(Carbon::MONDAY);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // --------------------------------------------------------------------------------------- Ayudas

    private function usuario(string $rol): User
    {
        $user = User::create([
            'name'                 => 'Usuario ' . $rol,
            'email'                => 'medico-cita-' . strtolower($rol) . '-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        return $user;
    }

    /** Una ficha de `medicos`: un doctor (con su registro personal) o un centro (`es_centro`, sin cuenta). */
    private function ficha(string $nombre, string $regMedico, ?User $user = null, bool $esCentro = false): Medico
    {
        return Medico::create([
            'user_id'    => $user?->id,
            'name'       => $nombre,
            'lastname'   => 'Fernández',
            'prefix'     => 'Dr.',
            'email'      => $user?->email,
            'password'   => $user?->password,
            'reg_medico' => $regMedico,
            'es_centro'  => $esCentro,
        ]);
    }

    /** Un doctor con cuenta, su especialidad, su registro personal y su clave de `evolucion`. */
    private function doctor(string $nombre, Specialty $especialidad, int $clave): Medico
    {
        $user = $this->usuario('Medico');
        $doctor = $this->ficha($nombre, 'dr-' . substr(uniqid(), -8), $user);
        $doctor->specialties()->attach($especialidad->id);
        MedicoRegistro::create(['medico_id' => $doctor->id, 'reg_medico' => $doctor->reg_medico]);

        // La clave de `evolucion` del doctor, en **su** registro: es lo que va a `cola.medico` cuando la
        // cita queda a su nombre.
        Evolucion::create([
            'reg_medico'     => $doctor->reg_medico,
            'clave'          => $clave,
            'correo_med'     => $doctor->email,
            'plantilla_cita' => 'Hola {paciente}: cita el {fecha} a las {hora} con ' . $nombre . '.',
        ]);

        return $doctor;
    }

    private function sede(string $regMedico, int $medicoId, string $numero = 'C1'): Office
    {
        $centro = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id'   => DB::table('estados')->value('id'),
            'city_id'    => DB::table('cities')->value('id'),
            'name'       => 'Sede ' . $numero . ' ' . substr(uniqid(), -4),
            'address'    => 'Dirección de prueba',
            'activo'     => true,
        ]);

        $sede = Office::create([
            'medical_center_id' => $centro->id,
            'medico_id'         => $medicoId,
            'reg_medico'        => $regMedico,
            'office_number'     => $numero,
            'modalidad'         => Office::MODALIDAD_HORA,
            'duracion_cita'     => 30,
            'activo'            => true,
        ]);

        OfficeSchedule::create([
            'office_id'   => $sede->id,
            'reg_medico'  => $regMedico,
            'dia_semana'  => 1,
            'hora_inicio' => '08:00',
            'hora_fin'    => '12:00',
        ]);

        return $sede;
    }

    /**
     * El escenario del **centro**: el centro (sin cuenta), `$cuantos` doctores vinculados a él (cada uno con
     * su registro personal y su clave de `evolucion`) y la secretaría del centro.
     *
     * @return array{centro:Medico, doctores:array<int,Medico>, secretaria:User, especialidad:Specialty, sede:Office}
     */
    private function escenarioDelCentro(int $cuantos = 2): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $especialidad = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();

        $centro = $this->ficha('Centro Médico', 'centro-' . substr(uniqid(), -8), null, true);

        $doctores = [];
        foreach (array_slice(['Uno', 'Dos'], 0, $cuantos) as $indice => $nombre) {
            $doctor = $this->doctor($nombre, $especialidad, 30 + $indice);
            // El doctor trabaja en el centro: es lo que lo hace asignable.
            MedicoRegistro::create(['medico_id' => $doctor->id, 'reg_medico' => $centro->reg_medico]);
            $doctores[] = $doctor;
        }

        $secretaria = $this->usuario('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $centro->reg_medico]);

        return [
            'centro'       => $centro,
            'doctores'     => $doctores,
            'secretaria'   => $secretaria,
            'especialidad' => $especialidad,
            'sede'         => $this->sede($centro->reg_medico, $centro->id),
        ];
    }

    /** El escenario del **consultorio personal**: el doctor en su propio registro. */
    private function escenarioPersonal(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $especialidad = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $doctor = $this->doctor('Personal', $especialidad, 12);

        return [
            'doctor'       => $doctor,
            'user'         => $doctor->user,
            'especialidad' => $especialidad,
            'sede'         => $this->sede($doctor->reg_medico, $doctor->id),
        ];
    }

    private function conContexto(User $user, string $regMedico, Specialty $especialidad, ?int $officeId = null): self
    {
        Carbon::setTestNow($this->lunes->copy()->setTime(9, 0));

        return $this->actingAs($user)->withSession(['clinica.contexto' => [
            'reg_medico'   => $regMedico,
            'specialty_id' => $especialidad->id,
            'office_id'    => $officeId,
        ]]);
    }

    /**
     * Un paciente del consultorio, con su número de historia y su historia clínica. Devuelve el `pacientes.id`
     * (lo que el alta de la cita pide como `paciente_id`); las citas se enlazan por el **número**.
     */
    private function pacienteConHistoria(string $regMedico, int $medicoId, int $numhistoria): int
    {
        $id = DB::table('pacientes')->insertGetId([
            'nombres'     => 'Ana',
            'apellidos'   => 'Alvarez',
            'cedula'      => (string) random_int(10000000, 30000000),
            'sexo'        => 'F',
            'fnacimiento' => '1990-05-04',
            'telefono'    => '0414-1234567',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        DB::table('medico_pacientes')->insert([
            'medico_id'   => $medicoId,
            'paciente_id' => $id,
            'numhistoria' => (string) $numhistoria,
            'reg_medico'  => $regMedico,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        Historia::create([
            'numhistoria' => (string) $numhistoria,
            'reg_medico'  => $regMedico,
            'paciente_id' => $id,
            'medico_id'   => $medicoId,
        ]);

        return $id;
    }

    private function cita(string $regMedico, Office $sede, int $numhistoria, string $hora, array $extra = []): Cola
    {
        return Cola::create(array_merge([
            'reg_medico'        => $regMedico,
            'numhistoria'       => $numhistoria,
            'fecha'             => $this->lunes->toDateString(),
            'hora_ini'          => $hora,
            'medical_center_id' => $sede->medical_center_id,
            'numorden'          => 1,
            'atendido'          => 0,
            'estado'            => Cola::ESTADO_NO_CONFIRMADA,
        ], $extra));
    }

    private function urlDeLaAgenda(): string
    {
        return '/clinica/agenda?vista=dia&fecha=' . $this->lunes->toDateString() . '&sede=todas';
    }

    // -------------------------------------------------------- Cómo nace la cita (centro vs. personal)

    public function test_la_cita_del_centro_nace_a_nombre_del_centro_y_sin_doctor(): void
    {
        ['centro' => $centro, 'secretaria' => $secretaria, 'especialidad' => $gineco, 'sede' => $sede] = $this->escenarioDelCentro();
        $pacienteId = $this->pacienteConHistoria($centro->reg_medico, $centro->id, 2001);

        $this->conContexto($secretaria, $centro->reg_medico, $gineco, $sede->id)
            ->post('/clinica/agenda', [
                'sede'        => $sede->id,
                'fecha'       => $this->lunes->toDateString(),
                'hora'        => '09:00',
                'paciente_id' => $pacienteId,
            ])
            ->assertRedirect();

        $cita = Cola::where('reg_medico', $centro->reg_medico)->firstOrFail();

        // La cita es **del centro**: sin doctor asignado y con el id del centro en la columna legacy (lo que
        // el escritorio lee en `cola.medico`).
        $this->assertNull($cita->medico_id, 'La cita del centro no nace con doctor.');
        $this->assertSame((int) $centro->id, (int) $cita->medico, 'En la columna legacy va el medicos.id del centro.');
    }

    public function test_la_cita_del_consultorio_personal_nace_con_el_doctor_y_su_clave(): void
    {
        ['doctor' => $doctor, 'user' => $user, 'especialidad' => $gineco, 'sede' => $sede] = $this->escenarioPersonal();
        $pacienteId = $this->pacienteConHistoria($doctor->reg_medico, $doctor->id, 3001);

        $this->conContexto($user, $doctor->reg_medico, $gineco, $sede->id)
            ->post('/clinica/agenda', [
                'sede'        => $sede->id,
                'fecha'       => $this->lunes->toDateString(),
                'hora'        => '09:00',
                'paciente_id' => $pacienteId,
            ])
            ->assertRedirect();

        $cita = Cola::where('reg_medico', $doctor->reg_medico)->firstOrFail();

        $this->assertSame((int) $doctor->id, (int) $cita->medico_id, 'La cita personal es del doctor.');
        $this->assertSame(12, (int) $cita->medico, 'En la columna legacy va la clave de evolucion del doctor.');
    }

    // ------------------------------------------------------------------ Asignar el doctor desde la agenda

    public function test_la_secretaria_asigna_un_doctor_del_centro(): void
    {
        ['centro' => $centro, 'doctores' => $doctores, 'secretaria' => $secretaria, 'especialidad' => $gineco, 'sede' => $sede] = $this->escenarioDelCentro();
        $this->pacienteConHistoria($centro->reg_medico, $centro->id, 2002);
        $cita = $this->cita($centro->reg_medico, $sede, 2002, '09:00:00', ['medico' => $centro->id]);

        $this->conContexto($secretaria, $centro->reg_medico, $gineco, $sede->id)
            ->post(route('clinica.agenda.medico', $cita->id), ['medico_id' => $doctores[1]->id])
            ->assertRedirect()
            ->assertSessionHas('estado');

        $fresca = $cita->fresh();

        $this->assertSame((int) $doctores[1]->id, (int) $fresca->medico_id);
        $this->assertSame(31, (int) $fresca->medico, 'La clave legacy pasa a ser la del doctor asignado.');

        // Con rastro para el sync, columna por columna, como cualquier escritura de la web.
        $this->assertSame(1, SyncChange::where('table_name', 'cola')
            ->where('record_id', $cita->id)
            ->where('column_name', 'medico_id')
            ->where('source', 'web')
            ->count());
        $this->assertSame(1, SyncChange::where('table_name', 'cola')
            ->where('record_id', $cita->id)
            ->where('column_name', 'medico')
            ->where('source', 'web')
            ->count());
    }

    public function test_no_se_asigna_un_doctor_de_otro_consultorio(): void
    {
        ['centro' => $centro, 'secretaria' => $secretaria, 'especialidad' => $gineco, 'sede' => $sede] = $this->escenarioDelCentro();
        $this->pacienteConHistoria($centro->reg_medico, $centro->id, 2003);
        $cita = $this->cita($centro->reg_medico, $sede, 2003, '09:00:00', ['medico' => $centro->id]);

        // Un doctor que no trabaja en este centro (su propio consultorio).
        $ajeno = $this->doctor('Ajeno', $gineco, 50);

        $this->conContexto($secretaria, $centro->reg_medico, $gineco, $sede->id)
            ->post(route('clinica.agenda.medico', $cita->id), ['medico_id' => $ajeno->id])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($cita->fresh()->medico_id, 'Un doctor ajeno no se asigna.');
    }

    public function test_no_se_asigna_el_centro_como_medico_de_la_cita(): void
    {
        ['centro' => $centro, 'secretaria' => $secretaria, 'especialidad' => $gineco, 'sede' => $sede] = $this->escenarioDelCentro();
        $this->pacienteConHistoria($centro->reg_medico, $centro->id, 2004);
        $cita = $this->cita($centro->reg_medico, $sede, 2004, '09:00:00', ['medico' => $centro->id]);

        $this->conContexto($secretaria, $centro->reg_medico, $gineco, $sede->id)
            ->post(route('clinica.agenda.medico', $cita->id), ['medico_id' => $centro->id])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($cita->fresh()->medico_id, 'El centro no atiende: no es el médico de la cita.');
    }

    public function test_una_cita_atendida_no_cambia_de_doctor(): void
    {
        ['centro' => $centro, 'doctores' => $doctores, 'secretaria' => $secretaria, 'especialidad' => $gineco, 'sede' => $sede] = $this->escenarioDelCentro();
        $this->pacienteConHistoria($centro->reg_medico, $centro->id, 2005);
        $cita = $this->cita($centro->reg_medico, $sede, 2005, '09:00:00', [
            'medico'    => 30,
            'medico_id' => $doctores[0]->id,
            'atendido'  => 1,
            'estado'    => Cola::ESTADO_CONFIRMADA,
        ]);

        $this->conContexto($secretaria, $centro->reg_medico, $gineco, $sede->id)
            ->post(route('clinica.agenda.medico', $cita->id), ['medico_id' => $doctores[1]->id])
            ->assertRedirect()
            ->assertSessionHas('error');

        $fresca = $cita->fresh();
        $this->assertSame((int) $doctores[0]->id, (int) $fresca->medico_id);
        $this->assertSame(30, (int) $fresca->medico);
    }

    /** Reagendar mueve la misma fila: el doctor que ya tenía la cita no se pisa. */
    public function test_reagendar_no_pisa_el_doctor_de_la_cita(): void
    {
        ['centro' => $centro, 'doctores' => $doctores, 'secretaria' => $secretaria, 'especialidad' => $gineco, 'sede' => $sede] = $this->escenarioDelCentro();
        $pacienteId = $this->pacienteConHistoria($centro->reg_medico, $centro->id, 2006);
        $cita = $this->cita($centro->reg_medico, $sede, 2006, '09:00:00', [
            'medico'    => 31,
            'medico_id' => $doctores[1]->id,
        ]);

        $this->conContexto($secretaria, $centro->reg_medico, $gineco, $sede->id)
            ->post(route('clinica.agenda.actualizar', $cita->id), [
                'sede'        => $sede->id,
                'fecha'       => $this->lunes->toDateString(),
                'hora'        => '10:30',
                'paciente_id' => $pacienteId,
            ])
            ->assertRedirect();

        $fresca = $cita->fresh();
        $this->assertSame('10:30', substr((string) $fresca->hora_ini, 0, 5));
        $this->assertSame((int) $doctores[1]->id, (int) $fresca->medico_id, 'Editar la cita no le cambia el doctor.');
        $this->assertSame(31, (int) $fresca->medico);
    }

    // --------------------------------------------------------------- El médico la toma al atender

    public function test_el_medico_toma_la_cita_sin_doctor_al_atender(): void
    {
        ['centro' => $centro, 'doctores' => $doctores, 'especialidad' => $gineco, 'sede' => $sede] = $this->escenarioDelCentro();
        $this->pacienteConHistoria($centro->reg_medico, $centro->id, 2007);
        $cita = $this->cita($centro->reg_medico, $sede, 2007, '09:00:00', ['medico' => $centro->id]);

        // El doctor atiende en el centro: su contexto es el `reg_medico` del centro y él es el médico.
        $this->conContexto($doctores[1]->user, $centro->reg_medico, $gineco, $sede->id)
            ->post(route('clinica.agenda.atender', $cita->id))
            ->assertRedirect();

        $fresca = $cita->fresh();

        $this->assertSame(1, (int) $fresca->atendido);
        $this->assertSame((int) $doctores[1]->id, (int) $fresca->medico_id, 'La cita queda del médico que la atiende.');
        $this->assertSame(31, (int) $fresca->medico, 'Y con su clave en la columna legacy.');

        // La consulta abierta es la del paciente.
        $this->assertSame(1, Consulta::where('reg_medico', $centro->reg_medico)->where('numhistoria', 2007)->count());
    }

    /** La cita **ya asignada** no cambia de médico porque otro médico la abra. */
    public function test_el_doctor_que_atiende_no_le_quita_la_cita_a_otro(): void
    {
        ['centro' => $centro, 'doctores' => $doctores, 'especialidad' => $gineco, 'sede' => $sede] = $this->escenarioDelCentro();
        $this->pacienteConHistoria($centro->reg_medico, $centro->id, 2008);
        $cita = $this->cita($centro->reg_medico, $sede, 2008, '09:00:00', [
            'medico'    => 30,
            'medico_id' => $doctores[0]->id,
        ]);

        $this->conContexto($doctores[1]->user, $centro->reg_medico, $gineco, $sede->id)
            ->post(route('clinica.agenda.atender', $cita->id))
            ->assertRedirect();

        $fresca = $cita->fresh();
        $this->assertSame(1, (int) $fresca->atendido);
        $this->assertSame((int) $doctores[0]->id, (int) $fresca->medico_id, 'El doctor asignado no cambia al atender.');
    }

    // ------------------------------------------------------------------ La agenda y el inicio muestran el médico

    public function test_la_agenda_muestra_el_medico_y_sin_asignar_con_dos_doctores(): void
    {
        ['centro' => $centro, 'doctores' => $doctores, 'secretaria' => $secretaria, 'especialidad' => $gineco, 'sede' => $sede] = $this->escenarioDelCentro();

        $this->pacienteConHistoria($centro->reg_medico, $centro->id, 2101);
        $conDoctor = $this->cita($centro->reg_medico, $sede, 2101, '09:00:00', [
            'medico'    => 31,
            'medico_id' => $doctores[1]->id,
        ]);

        $this->pacienteConHistoria($centro->reg_medico, $centro->id, 2102);
        $sinDoctor = $this->cita($centro->reg_medico, $sede, 2102, '09:30:00', [
            'medico'   => $centro->id,
            'numorden' => 2,
        ]);

        $html = $this->conContexto($secretaria, $centro->reg_medico, $gineco, $sede->id)
            ->get($this->urlDeLaAgenda())
            ->assertOk()
            ->getContent();

        // El nombre del doctor asignado, la pastilla de «Sin asignar» y el menú para asignarlo.
        $this->assertStringContainsString($doctores[1]->nombreMostrar, $html);
        $this->assertStringContainsString('Sin asignar', $html);
        $this->assertStringContainsString(e(route('clinica.agenda.medico', $sinDoctor->id)), $html);
        // La cita que ya tiene doctor también se puede reasignar (mientras no esté atendida).
        $this->assertStringContainsString(e(route('clinica.agenda.medico', $conDoctor->id)), $html);
    }

    public function test_con_un_solo_doctor_la_agenda_no_repite_el_medico(): void
    {
        ['centro' => $centro, 'doctores' => $doctores, 'secretaria' => $secretaria, 'especialidad' => $gineco, 'sede' => $sede] = $this->escenarioDelCentro(1);
        $this->pacienteConHistoria($centro->reg_medico, $centro->id, 2103);
        $cita = $this->cita($centro->reg_medico, $sede, 2103, '09:00:00', ['medico' => $centro->id]);

        $html = $this->conContexto($secretaria, $centro->reg_medico, $gineco, $sede->id)
            ->get($this->urlDeLaAgenda())
            ->assertOk()
            ->getContent();

        // Con un solo doctor no se repite el nombre en la fila ni se ofrece elegir (no hay nada que elegir).
        $this->assertStringNotContainsString('Sin asignar', $html);
        $this->assertStringNotContainsString(e(route('clinica.agenda.medico', $cita->id)), $html);
        $this->assertStringNotContainsString($doctores[0]->nombreMostrar, $html);
    }

    public function test_el_inicio_muestra_el_medico_de_cada_cita_con_dos_doctores(): void
    {
        ['centro' => $centro, 'doctores' => $doctores, 'especialidad' => $gineco, 'sede' => $sede] = $this->escenarioDelCentro();

        $this->pacienteConHistoria($centro->reg_medico, $centro->id, 2104);
        $this->cita($centro->reg_medico, $sede, 2104, '09:00:00', [
            'medico'    => 31,
            'medico_id' => $doctores[1]->id,
        ]);

        $this->pacienteConHistoria($centro->reg_medico, $centro->id, 2105);
        $this->cita($centro->reg_medico, $sede, 2105, '09:30:00', ['medico' => $centro->id, 'numorden' => 2]);

        $this->conContexto($doctores[0]->user, $centro->reg_medico, $gineco, $sede->id)
            ->get('/clinica')
            ->assertOk()
            ->assertSee($doctores[1]->nombreMostrar)
            ->assertSee('Sin asignar');
    }

    // ------------------------------------------------------------------------ El recordatorio

    /**
     * El `{doctor}` de la plantilla sale del **doctor asignado** (`medico_id`), no de la `clave` legacy: en
     * una cita del centro `cola.medico` lleva el `medicos.id` del centro, y usarlo como clave mostraría el
     * nombre de otro médico.
     */
    public function test_el_recordatorio_usa_el_doctor_asignado_y_no_la_clave_del_centro(): void
    {
        ['centro' => $centro, 'doctores' => $doctores, 'secretaria' => $secretaria, 'especialidad' => $gineco, 'sede' => $sede] = $this->escenarioDelCentro();
        $this->pacienteConHistoria($centro->reg_medico, $centro->id, 2106);

        // La trampa: una `evolucion` del centro con `clave` = el id del centro, de otro doctor. Si el
        // recordatorio resolviera por `cola.medico` (que lleva ese id), diría el nombre equivocado.
        Evolucion::create([
            'reg_medico'     => $centro->reg_medico,
            'clave'          => (int) $centro->id,
            'correo_med'     => $doctores[0]->email,
            'plantilla_cita' => 'Texto del centro para {paciente}.',
        ]);
        Evolucion::create([
            'reg_medico'     => $centro->reg_medico,
            'clave'          => 90,
            'correo_med'     => $doctores[1]->email,
            'plantilla_cita' => 'Hola {paciente}: te espera el {doctor}.',
        ]);

        $this->cita($centro->reg_medico, $sede, 2106, '09:00:00', [
            'medico'    => $centro->id,
            'medico_id' => $doctores[1]->id,
        ]);

        $this->conContexto($secretaria, $centro->reg_medico, $gineco, $sede->id)
            ->get($this->urlDeLaAgenda())
            ->assertOk()
            ->assertSee('Hola Alvarez, Ana: te espera el ' . $doctores[1]->nombreMostrar . '.', false)
            ->assertDontSee('Texto del centro para Alvarez, Ana.', false);
    }
}
