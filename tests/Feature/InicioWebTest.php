<?php

namespace Tests\Feature;

use App\Clinica\FechaDelConsultorio;
use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\MedicalCenter;
use App\Models\Medico;
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
 * **El inicio del médico: las consultas de hoy** (A-12).
 *
 * `/clinica` dejó de ser la pantalla «Consultorio» con las tarjetas de módulos y pasó a ser la lista de los
 * pacientes de hoy del médico del contexto, en la sede del contexto y en orden de atención (las atendidas
 * primero). No cambia de día, no tiene navegación, buscador ni filtros, y ofrece las mismas acciones de la fila
 * de la agenda. La secretaría (y Root, que es administración) entran directo a la agenda.
 *
 * Desde O-34 (2026-10-18) el encabezado tiene la acción primaria **«Agregar paciente»**: el alta del que llega
 * sin cita, que termina en la consulta del día (alta → historia → «¿Atender ahora?»). Está siempre, no sólo en
 * el día vacío.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class InicioWebTest extends TestCase
{
    use DatabaseTransactions;

    /** El "hoy" del consultorio: la fecha de las citas y la del inicio. Es un sábado. */
    private Carbon $hoy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hoy = FechaDelConsultorio::dia('2026-10-17');
        Carbon::setTestNow($this->hoy->copy()->setTime(9, 0));
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
        $regMedico = 'inicio-test-' . uniqid();

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'inicio-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Carlos',
            'lastname'   => 'Mendoza',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    /** La secretaría: sin ficha de médico, con el registro asignado (como en la web real). */
    private function secretariaConAcceso(Medico $medico): User
    {
        $secretaria = User::create([
            'name'                 => 'Secretaria',
            'email'                => 'inicio-secre-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        return $secretaria;
    }

    /** Root es la cuenta de administración: acceso al registro, pero no atiende. */
    private function rootConAcceso(Medico $medico): User
    {
        $root = User::create([
            'name'                 => 'Root',
            'email'                => 'inicio-root-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $root->assignRole('Root');
        MedicoRegistro::create(['user_id' => $root->id, 'reg_medico' => $medico->reg_medico]);

        return $root;
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty, ?Office $sede = null): self
    {
        return $this->actingAs($user)->withSession(['clinica.contexto' => [
            'reg_medico'   => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id'    => $sede?->id,
        ]]);
    }

    private function sede(Medico $medico, string $numero = 'C1'): Office
    {
        $centro = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id'   => DB::table('estados')->value('id'),
            'city_id'    => DB::table('cities')->value('id'),
            'name'       => 'Sede ' . $numero,
            'address'    => 'Dirección de prueba',
        ]);

        $sede = Office::create([
            'medical_center_id' => $centro->id,
            'medico_id'         => $medico->id,
            'reg_medico'        => $medico->reg_medico,
            'office_number'     => $numero,
            'modalidad'         => Office::MODALIDAD_HORA,
            'duracion_cita'     => 30,
            'activo'            => true,
        ]);

        OfficeSchedule::create([
            'office_id'   => $sede->id,
            'reg_medico'  => $medico->reg_medico,
            'dia_semana'  => 6,
            'hora_inicio' => '08:00',
            'hora_fin'    => '12:00',
            'cupo'        => null,
        ]);

        return $sede;
    }

    private function paciente(Medico $medico, string $nombres, string $apellidos, int $numhistoria): int
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

        Historia::create([
            'numhistoria' => (string) $numhistoria,
            'reg_medico'  => $medico->reg_medico,
            'paciente_id' => $id,
            'medico_id'   => $medico->id,
        ]);

        return $id;
    }

    private function cita(Medico $medico, Office $sede, int $numhistoria, string $hora, array $extra = []): Cola
    {
        return Cola::create(array_merge([
            'reg_medico'        => $medico->reg_medico,
            'numhistoria'       => $numhistoria,
            'fecha'             => $this->hoy->toDateString(),
            'hora_ini'          => $hora,
            'medical_center_id' => $sede->medical_center_id,
            'numorden'          => 1,
            'atendido'          => 0,
            'estado'            => Cola::ESTADO_NO_CONFIRMADA,
        ], $extra));
    }

    // ----------------------------------------------------------------------- La lista del día

    public function test_el_inicio_muestra_los_pacientes_de_hoy_de_la_sede_del_contexto(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico, 'C1');
        $otra = $this->sede($medico, 'C2');

        $this->paciente($medico, 'Ana', 'Alvarez', 1001);
        $this->paciente($medico, 'Beto', 'Bravo', 1002);
        $this->paciente($medico, 'Cira', 'Cedeño', 1003);
        $this->paciente($medico, 'Dora', 'Díaz', 1004);

        $this->cita($medico, $sede, 1001, '09:00:00');                                     // hoy, la sede del contexto
        $this->cita($medico, $sede, 1002, '10:00:00', ['atendido' => 1, 'estado' => Cola::ESTADO_CONFIRMADA]);
        $this->cita($medico, $otra, 1003, '11:00:00');                                     // hoy, otra sede
        $this->cita($medico, $sede, 1004, '09:00:00', ['fecha' => '2026-10-18']);          // mañana

        // Y una cita de otro médico a la misma hora: el inicio es del médico del contexto.
        [, $otro] = $this->medicoConAcceso();
        $sedeAjena = $this->sede($otro, 'C9');
        $this->paciente($otro, 'Eva', 'Extraña', 7001);
        $this->cita($otro, $sedeAjena, 7001, '09:30:00');

        $respuesta = $this->conContexto($user, $medico, $gineco, $sede)->get('/clinica');

        $respuesta->assertOk()
            ->assertSee('Pacientes de hoy')
            ->assertSee('Alvarez, Ana')
            ->assertSee('Bravo, Beto')
            ->assertDontSee('Cedeño, Cira')
            ->assertDontSee('Díaz, Dora')
            ->assertDontSee('Extraña, Eva');
    }

    public function test_las_atendidas_van_al_inicio_y_marcadas_y_se_mantiene_la_hora_dentro_de_cada_grupo(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);

        $this->paciente($medico, 'Temprano', 'Pendiente', 1001);
        $this->paciente($medico, 'Tarde', 'Pendiente', 1002);
        $this->paciente($medico, 'Primera', 'Atendida', 1003);
        $this->paciente($medico, 'Segunda', 'Atendida', 1004);

        // Las pendientes, por hora; las atendidas, también por hora, aunque su hora sea más tarde.
        $this->cita($medico, $sede, 1001, '08:00:00');
        $this->cita($medico, $sede, 1002, '09:00:00');
        $this->cita($medico, $sede, 1003, '10:00:00', ['atendido' => 1, 'estado' => Cola::ESTADO_CONFIRMADA]);
        $this->cita($medico, $sede, 1004, '11:00:00', ['atendido' => 1, 'estado' => Cola::ESTADO_CONFIRMADA]);

        $html = $this->conContexto($user, $medico, $gineco, $sede)->get('/clinica')->assertOk()->getContent();

        // Orden: las atendidas primero (Primera 10:00 y Segunda 11:00) y después las pendientes (Temprano 08:00
        // y Tarde 09:00), y dentro de cada grupo por hora. `assertLessThan($mayor, $menor)` = "menor < mayor".
        $this->assertLessThan(strpos($html, 'Pendiente, Temprano'), strpos($html, 'Atendida, Primera'));
        $this->assertLessThan(strpos($html, 'Atendida, Segunda'), strpos($html, 'Atendida, Primera'));
        $this->assertLessThan(strpos($html, 'Pendiente, Temprano'), strpos($html, 'Atendida, Segunda'));
        $this->assertLessThan(strpos($html, 'Pendiente, Tarde'), strpos($html, 'Pendiente, Temprano'));

        // Marcadas como en la agenda (el icono de «Atendida»), y sin inventar un estado nuevo.
        $this->assertSame(2, substr_count($html, 'bi-check2-all'));
        $this->assertStringContainsString('2 de 4 atendidos', $html);
    }

    public function test_la_fila_ofrece_las_mismas_acciones_que_la_agenda(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $pacienteId = $this->paciente($medico, 'Ana', 'Alvarez', 1001);
        $cita = $this->cita($medico, $sede, 1001, '09:00:00');

        $respuesta = $this->conContexto($user, $medico, $gineco, $sede)->get('/clinica');

        $respuesta->assertOk()
            ->assertSee(route('clinica.agenda.confirmar', $cita->id), false)
            ->assertSee(route('clinica.agenda.atender', $cita->id), false)
            ->assertSee('data-atender', false)
            ->assertSee(route('clinica.agenda.cobrar', $cita->id), false)
            ->assertSee(route('clinica.pacientes.citas', $pacienteId), false)
            ->assertSee('id="dialogo-atender"', false)
            ->assertSee('id="dialogo-cobro"', false)
            ->assertSee('id="dialogo-recordatorio"', false);
    }

    /** Con la cita ya atendida, la acción dice «Editar consulta» (como en la agenda) y no se vuelve a marcar. */
    public function test_una_cita_atendida_ofrece_editar_consulta(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $this->paciente($medico, 'Ana', 'Alvarez', 1001);
        $this->cita($medico, $sede, 1001, '09:00:00', ['atendido' => 1, 'estado' => Cola::ESTADO_CONFIRMADA]);

        $this->conContexto($user, $medico, $gineco, $sede)->get('/clinica')
            ->assertOk()
            ->assertSee('Editar consulta')
            ->assertSee('data-editar="1"', false);
    }

    public function test_si_el_dia_no_tiene_citas_muestra_el_estado_vacio_con_las_dos_salidas(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);

        $this->conContexto($user, $medico, $gineco, $sede)->get('/clinica')
            ->assertOk()
            ->assertSee('Hoy no hay pacientes')
            ->assertSee(route('clinica.agenda', ['vista' => 'dia', 'fecha' => $this->hoy->toDateString()]))
            // «Agregar paciente» es del encabezado y se ve también en el día vacío; la tarjeta del vacío
            // ofrece la otra salida —la agenda— y no repite el botón.
            ->assertSee(route('clinica.pacientes.nuevo'), false)
            ->assertSee('Agregar paciente');
    }

    // --------------------------------------------------------- «Agregar paciente» en el inicio (O-34)

    /** El botón está **siempre**, no sólo en el día vacío: es el camino del paciente que llega sin cita. */
    public function test_el_encabezado_ofrece_agregar_paciente_aunque_el_dia_tenga_pacientes(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $this->paciente($medico, 'Ana', 'Alvarez', 1001);
        $this->cita($medico, $sede, 1001, '09:00:00');

        $this->conContexto($user, $medico, $gineco, $sede)->get('/clinica')
            ->assertOk()
            ->assertSee('Agregar paciente')
            ->assertSee(route('clinica.pacientes.nuevo'), false)
            ->assertSee('data-tour="inicio-agregar"', false);
    }

    /**
     * **El camino completo de «se sobreentiende para atender el mismo día»**: el botón del inicio lleva al alta,
     * el alta numera la historia (O-04), la ficha ofrece «¿Atender ahora?» (O-05) y ese «sí» le crea la cita de hoy
     * y abre la consulta. La prueba es de punta a punta a propósito: el botón no tiene reglas propias y lo que hay
     * que cuidar es que el camino siga cerrado.
     */
    public function test_agregar_paciente_desde_el_inicio_termina_en_la_consulta_del_dia(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);

        // 1) El inicio ofrece el alta y el alta abre.
        $this->conContexto($user, $medico, $gineco, $sede)->get('/clinica')
            ->assertOk()
            ->assertSee(route('clinica.pacientes.nuevo'), false);
        $this->conContexto($user, $medico, $gineco, $sede)->get(route('clinica.pacientes.nuevo'))->assertOk();

        // 2) El alta del recién llegado: queda con historia y con el ofrecimiento de atenderlo.
        $alta = $this->conContexto($user, $medico, $gineco, $sede)->post('/clinica/pacientes', [
            'nac'       => 'V',
            'cedula'    => '24680135',
            'apellidos' => 'Mendoza',
            'nombres'   => 'Luisa',
            'sexo'      => 'F',
        ]);

        $paciente = DB::table('pacientes')->where('cedula', '24680135')->first();
        $this->assertNotNull($paciente);
        $alta->assertRedirect(route('clinica.pacientes.ver', $paciente->id));
        $alta->assertSessionHas('ofrecer_atender', $paciente->id);

        // 3) La ficha ofrece «¿Atender ahora?».
        $this->conContexto($user, $medico, $gineco, $sede)
            ->get(route('clinica.pacientes.ver', $paciente->id))
            ->assertOk()
            ->assertSee('¿Atender ahora?', false);

        // 4) El «sí»: la cita del día y la consulta abierta.
        $atender = $this->conContexto($user, $medico, $gineco, $sede)
            ->post(route('clinica.pacientes.atender', $paciente->id));

        $cita = Cola::where('reg_medico', $medico->reg_medico)->firstOrFail();
        $consulta = Consulta::where('numhistoria', (int) $cita->numhistoria)->firstOrFail();

        $atender->assertRedirect(route('clinica.consulta', $consulta->id));
        $this->assertSame($this->hoy->toDateString(), Carbon::parse($cita->fecha)->toDateString());
        $this->assertSame($sede->medical_center_id, (int) $cita->medical_center_id);
        $this->assertSame(1, (int) $cita->atendido);
        // El alta web numeró la historia en el mismo paso (O-04): sin número, «Atender ahora» no existiría.
        $this->assertNotNull(DB::table('medico_pacientes')->where('paciente_id', $paciente->id)->value('numhistoria'));
    }

    public function test_el_inicio_ya_no_son_las_tarjetas_de_modulos(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->sede($medico);

        $this->conContexto($user, $medico, $gineco)->get('/clinica')
            ->assertOk()
            ->assertDontSee('modulo-acceso')
            ->assertDontSee('modulo-nombre')
            ->assertDontSee('Pantalla en construcción');
    }

    // -------------------------------------------------------------------- La secretaría y Root

    public function test_la_secretaria_entra_directo_a_la_agenda(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $secretaria = $this->secretariaConAcceso($medico);

        $this->conContexto($secretaria, $medico, $gineco, $sede)
            ->get('/clinica')
            ->assertRedirect(route('clinica.agenda'));

        // Y la agenda (su pantalla) responde bien: el redirect no puede entrar en bucle.
        $this->conContexto($secretaria, $medico, $gineco, $sede)
            ->get(route('clinica.agenda'))
            ->assertOk()
            ->assertSee('Agenda');
    }

    public function test_root_entra_directo_a_la_agenda(): void
    {
        [, $medico, $gineco] = $this->medicoConAcceso();
        $sede = $this->sede($medico);
        $root = $this->rootConAcceso($medico);

        $this->conContexto($root, $medico, $gineco, $sede)
            ->get('/clinica')
            ->assertRedirect(route('clinica.agenda'));

        $this->conContexto($root, $medico, $gineco, $sede)
            ->get(route('clinica.agenda'))
            ->assertOk();
    }
}
