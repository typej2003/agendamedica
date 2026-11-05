<?php

namespace Tests\Feature;

use App\Clinica\FechaDelConsultorio;
use App\Models\Cola;
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
 * **«Compartir la cita» nombraba a otro paciente** (informe del usuario, 2026-11-05, pediatría): la cita era de
 * Alejo Dura y la pantalla mostró a Norbis Soto. En la agenda, en la ficha y en la consulta el nombre era el
 * correcto: sólo esa pantalla lo mostraba mal.
 *
 * La causa es la trampa 145. La pantalla resolvía «de quién es esta cita» al revés que la agenda: prefería
 * `cola.paciente_sinhistoria_id` —el ancla que queda de cuando se agendó sin número y que nadie limpia al
 * numerarla— y, si no, buscaba `historias` por el número **sin acotar al `reg_medico`** y casteándolo a entero.
 * El número de historia es único **por registro**, así que con el mismo número en otro consultorio del médico
 * `first()` devolvía el paciente de ese otro consultorio.
 *
 * Lo que fija este test:
 *  - el nombre sale del **registro de la cita**, aunque el número exista en dos consultorios del médico;
 *  - con número y un `paciente_sinhistoria_id` viejo, manda el **dueño del número**, igual que en la agenda;
 *  - en pediatría, un niño que sólo está en `paciente_chamo` igual se nombra;
 *  - la pantalla y la fila de la agenda dicen **el mismo texto** (si divergen, el test falla).
 *
 * `DatabaseTransactions` como el resto de la web clínica (ver MedicAPI/AGENTS.md).
 */
class CompartirCitaPacienteWebTest extends TestCase
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
        $regMedico = 'compartir-' . substr(uniqid(), -6);

        $user = User::create([
            'name'                 => 'Pediatra de prueba',
            'email'                => $regMedico . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Compartir',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($pediatria->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $pediatria];
    }

    /** Un segundo consultorio del mismo médico: otro `reg_medico`, con su propio número de historia. */
    private function otroRegistro(Medico $medico): string
    {
        $registro = $medico->reg_medico . '-b';

        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $registro]);

        return $registro;
    }

    private function sede(Medico $medico, ?string $registro = null): Office
    {
        $regMedico = $registro ?? $medico->reg_medico;

        $centro = MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id'   => DB::table('estados')->value('id'),
            'city_id'    => DB::table('cities')->value('id'),
            'name'       => 'Sede de compartir',
            'address'    => 'Dirección de prueba',
        ]);

        $sede = Office::create([
            'medical_center_id' => $centro->id,
            'medico_id'         => $medico->id,
            'reg_medico'        => $regMedico,
            'office_number'     => 'C1',
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
            'cupo'        => null,
        ]);

        return $sede;
    }

    /**
     * Un paciente inventado. Con `$numhistoria` queda en el vínculo del consultorio y, si `$conHistoria`, ése es
     * el número **registrado** en `historias` (el dueño). Sin número es una ficha que todavía no tiene historia.
     */
    private function paciente(
        Medico $medico,
        string $nombres,
        ?string $apellidos,
        ?int $numhistoria = null,
        bool $conHistoria = false,
        ?string $registro = null,
    ): int {
        $regMedico = $registro ?? $medico->reg_medico;

        $id = DB::table('pacientes')->insertGetId([
            'nombres'     => $nombres,
            'apellidos'   => $apellidos,
            'cedula'      => (string) random_int(10000000, 30000000),
            'sexo'        => 'M',
            'fnacimiento' => '2018-05-04',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        if ($numhistoria !== null) {
            MedicoPaciente::create([
                'medico_id'   => $medico->id,
                'paciente_id' => $id,
                'numhistoria' => (string) $numhistoria,
                'reg_medico'  => $regMedico,
            ]);
        }

        if ($numhistoria !== null && $conHistoria) {
            Historia::create([
                'numhistoria' => (string) $numhistoria,
                'reg_medico'  => $regMedico,
                'paciente_id' => $id,
                'medico_id'   => $medico->id,
            ]);
        }

        return $id;
    }

    /** El niño del ABM de pediatría: vive en `paciente_chamo` y **no** en `pacientes`. */
    private function chamo(Medico $medico, string $nombre, int $historia): void
    {
        DB::table('paciente_chamo')->insert([
            'reg_medico' => $medico->reg_medico,
            'familia'    => $historia,
            'historia'   => $historia,
            'nombre'     => $nombre,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function cita(Medico $medico, Office $sede, ?int $numhistoria, ?int $sinHistoria = null, ?string $registro = null): Cola
    {
        return Cola::create([
            'reg_medico'              => $registro ?? $medico->reg_medico,
            'fecha'                   => $this->hoy->toDateString(),
            'hora_ini'                => '08:00:00',
            'medical_center_id'       => $sede->medical_center_id,
            'medico_id'               => $medico->id,
            'numorden'                => 1,
            'numhistoria'             => $numhistoria,
            'paciente_sinhistoria_id' => $sinHistoria,
            'atendido'                => 0,
            'estado'                  => Cola::ESTADO_NO_CONFIRMADA,
        ]);
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty, ?string $registro = null): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico'   => $registro ?? $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id'    => null,
        ]])->actingAs($user);
    }

    /** La agenda del día: la pantalla que el usuario dice que nombra bien. */
    private function agenda(User $user, Medico $medico, Specialty $specialty, ?string $registro = null)
    {
        return $this->conContexto($user, $medico, $specialty, $registro)->get(route('clinica.agenda', [
            'vista' => 'dia',
            'fecha' => $this->hoy->toDateString(),
            'sede'  => 'todas',
        ]));
    }

    /** «Compartir la cita» de una cita concreta. */
    private function compartir(User $user, Medico $medico, Specialty $specialty, Cola $cita, ?string $registro = null)
    {
        return $this->conContexto($user, $medico, $specialty, $registro)
            ->get(route('clinica.agenda.link-de-cita', $cita->id))
            ->assertOk();
    }

    /** El nombre que la pantalla de compartir le pone al paciente (el `<dd>` de la tarjeta). */
    private function nombreEnCompartir(User $user, Medico $medico, Specialty $specialty, Cola $cita, ?string $registro = null): string
    {
        $html = $this->compartir($user, $medico, $specialty, $cita, $registro)->getContent();

        $this->assertSame(
            1,
            preg_match('/<dt class="col-sm-3">Paciente<\/dt>\s*<dd class="col-sm-9">([^<]*)<\/dd>/u', $html, $coincidencia),
            'La pantalla de compartir tenía que decir el nombre del paciente.',
        );

        return trim(html_entity_decode($coincidencia[1], ENT_QUOTES, 'UTF-8'));
    }

    // ------------------------------------------------------------------------------------- Pruebas

    /**
     * El caso informado: el número 12 existe en los **dos consultorios** del médico y la cita es del segundo.
     * La pantalla buscaba la historia sin acotar al registro, así que nombraba al niño del primero.
     */
    public function test_el_nombre_sale_del_registro_de_la_cita_aunque_el_numero_exista_en_dos(): void
    {
        [$user, $medico, $pediatria] = $this->medicoDePediatria();
        $registroB = $this->otroRegistro($medico);
        $sede = $this->sede($medico, $registroB);

        // El registro A tiene su historia 12, de otra niña (la que la pantalla mostraba de más).
        $this->paciente($medico, 'NORBIS', 'SOTO', 12, true);
        // El registro B tiene SU historia 12: el niño de la cita.
        $this->paciente($medico, 'ALEJO', 'DURA', 12, true, $registroB);

        $cita = $this->cita($medico, $sede, 12, null, $registroB);

        // La agenda nombra al dueño del 12 en el registro de la cita.
        $this->agenda($user, $medico, $pediatria, $registroB)
            ->assertOk()
            ->assertSee('DURA, ALEJO', false)
            ->assertDontSee('SOTO', false);

        // Y compartir la cita tiene que decir lo mismo, no el paciente del otro consultorio.
        $this->compartir($user, $medico, $pediatria, $cita, $registroB)
            ->assertSee('DURA, ALEJO', false)
            ->assertDontSee('SOTO', false);

        $this->assertSame('DURA, ALEJO', $this->nombreEnCompartir($user, $medico, $pediatria, $cita, $registroB));
    }

    /**
     * Una cita **numerada** que arrastra un `paciente_sinhistoria_id` viejo (el ancla de cuando se agendó sin
     * número, que nadie limpia al numerarla): manda el dueño del número, como en la agenda. Antes esta pantalla
     * prefería el ancla y nombraba al paciente viejo.
     */
    public function test_la_cita_numerada_nombra_al_dueno_del_numero_y_no_al_paciente_sin_historia_viejo(): void
    {
        [$user, $medico, $pediatria] = $this->medicoDePediatria();
        $sede = $this->sede($medico);

        $this->paciente($medico, 'ALEJO', 'DURA', 30, true);
        $viejo = $this->paciente($medico, 'NORBIS', 'SOTO');

        $cita = $this->cita($medico, $sede, 30, $viejo);

        $this->compartir($user, $medico, $pediatria, $cita)
            ->assertSee('DURA, ALEJO', false)
            ->assertDontSee('SOTO', false);

        $nombre = $this->nombreEnCompartir($user, $medico, $pediatria, $cita);
        $this->assertSame('DURA, ALEJO', $nombre);

        // La comparación que importa: la fila de la agenda y la pantalla dicen **el mismo texto**.
        $this->agenda($user, $medico, $pediatria)
            ->assertOk()
            ->assertSee($nombre, false);
    }

    /**
     * Pediatría: el ABM real de pacientes es `paciente_chamo` (una fila por `historia`) y `pacientes` puede estar
     * vacía. Un niño que sólo vive ahí igual tiene que salir con su nombre.
     */
    public function test_una_cita_de_pediatria_cuyo_nino_solo_esta_en_paciente_chamo_lleva_su_nombre(): void
    {
        [$user, $medico, $pediatria] = $this->medicoDePediatria();
        $sede = $this->sede($medico);

        $this->chamo($medico, 'SOFIA PEREZ', 45);

        $cita = $this->cita($medico, $sede, 45);

        $this->compartir($user, $medico, $pediatria, $cita)
            ->assertSee('SOFIA PEREZ', false);

        $this->assertSame('SOFIA PEREZ', $this->nombreEnCompartir($user, $medico, $pediatria, $cita));
    }
}
