<?php

namespace Tests\Feature;

use App\Clinica\ContextoAutomatico;
use App\Clinica\ContextoTrabajo;
use App\Clinica\Vistas\ContextoVista;
use App\Models\City;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Office;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **El contexto de trabajo con el doctor real y el centro médico** (Fase 2 del cambio de `reg_medico`,
 * 2026-10-20).
 *
 * Lo que fija este test es la decisión del usuario:
 *
 *  - el **doctor** ve **todos** sus `reg_medico` (el suyo y los centros donde trabaja), y en cada uno el
 *    `medico` del contexto es **él**, con **sus** especialidades; los consultorios son los **del tenant**;
 *  - la **secretaría** (sin ficha de médico) elige **un centro** y en su contexto el `medico` es la fila
 *    del **centro**, con las especialidades de **sus doctores** (el centro no tiene propias);
 *  - los **consultorios** son los del tenant, no los que creó el doctor: el del centro también se ve;
 *  - un **centro** no atiende: si algún día tuviera login, no se le ofrece la consulta.
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class ContextoDoctorYCentroWebTest extends TestCase
{
    use DatabaseTransactions;

    // --------------------------------------------------------------------------------------- Ayudas

    private function usuario(string $rol): User
    {
        $user = User::create([
            'name'                 => 'Usuario ' . $rol,
            'email'                => 'ctx-' . strtolower($rol) . '-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        return $user;
    }

    /**
     * Una ficha de `medicos`: un **doctor** (con su registro y su especialidad) o un **centro**
     * (`es_centro`, sin cuenta).
     */
    private function ficha(string $nombre, string $regMedico, ?User $user = null, bool $esCentro = false): Medico
    {
        return Medico::create([
            'user_id'    => $user?->id,
            'name'       => $nombre,
            'lastname'   => 'Ficha',
            'email'      => $user?->email,
            'password'   => $user?->password,
            'reg_medico' => $regMedico,
            'es_centro'  => $esCentro,
        ]);
    }

    private function especialidad(string $slug): Specialty
    {
        return Specialty::where('slug', $slug)->firstOrFail();
    }

    private function sede(): MedicalCenter
    {
        return MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id'   => City::firstOrFail()->state_id,
            'city_id'    => City::firstOrFail()->id,
            'name'       => 'Sede ' . substr(uniqid(), -6),
            'address'    => 'Av. Principal',
            'activo'     => true,
        ]);
    }

    private function consultorio(string $regMedico, int $medicoId, string $numero): Office
    {
        return Office::create([
            'medical_center_id' => $this->sede()->id,
            'medico_id'         => $medicoId,
            'reg_medico'        => $regMedico,
            'office_number'     => $numero,
            'modalidad'         => Office::MODALIDAD_ORDEN,
            'duracion_cita'     => 30,
            'activo'            => true,
        ]);
    }

    /**
     * El escenario completo: un doctor con su registro personal y dos centros, cada centro con su
     * consultorio. Devuelve las piezas que necesitan los tests.
     *
     * @return array{user:User, doctor:Medico, gineco:Specialty, pediatria:Specialty, centros:array<int,Medico>}
     */
    private function doctorConDosCentros(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = $this->especialidad('ginecologia-y-obstetricia');
        $pediatria = $this->especialidad('pediatria');

        $user = $this->usuario('Medico');
        $sufijo = substr(uniqid(), -5);

        $doctor = $this->ficha('Dra. Real', 'dr-' . $sufijo, $user);
        $doctor->specialties()->attach([$gineco->id, $pediatria->id]);
        MedicoRegistro::create(['medico_id' => $doctor->id, 'reg_medico' => $doctor->reg_medico]);

        $centros = [];
        foreach (['centro-uno-' . $sufijo, 'centro-dos-' . $sufijo] as $i => $reg) {
            $centro = $this->ficha('Centro ' . ($i + 1), $reg, null, true);
            MedicoRegistro::create(['medico_id' => $doctor->id, 'reg_medico' => $reg]);
            $centros[] = $centro;
        }

        return compact('user', 'doctor', 'gineco', 'pediatria', 'centros');
    }

    // ------------------------------------------------------------------------ El doctor en sus centros

    public function test_el_doctor_ve_su_registro_y_sus_dos_centros_con_el_como_medico(): void
    {
        ['user' => $user, 'doctor' => $doctor, 'centros' => $centros] = $this->doctorConDosCentros();

        $accesos = app(ContextoTrabajo::class)->disponibles($user);

        $this->assertCount(3, $accesos, 'El doctor tiene que ver su registro y los dos centros.');
        $this->assertArrayHasKey($doctor->reg_medico, $accesos);
        $this->assertArrayHasKey($centros[0]->reg_medico, $accesos);
        $this->assertArrayHasKey($centros[1]->reg_medico, $accesos);

        foreach ($accesos as $registro => $acceso) {
            // **El médico del contexto es el doctor logueado**, no el dueño del `reg_medico` (el centro).
            $this->assertSame($doctor->id, $acceso['medico']->id, "En {$registro} el médico del contexto tiene que ser el doctor.");
            $this->assertFalse($acceso['es_centro']);
            // Y las especialidades son **las suyas**, no las del centro (que no tiene).
            $this->assertEqualsCanonicalizing(
                ['ginecologia-y-obstetricia', 'pediatria'],
                $acceso['especialidades']->pluck('slug')->all(),
            );
        }
    }

    public function test_al_fijar_un_centro_la_especialidad_y_los_consultorios_salen_de_ese_tenant(): void
    {
        ['user' => $user, 'doctor' => $doctor, 'centros' => $centros, 'gineco' => $gineco] = $this->doctorConDosCentros();

        // El consultorio lo creó **el centro** (su `medico_id`), no el doctor: el doctor lo tiene que ver igual.
        $consultorio = $this->consultorio($centros[0]->reg_medico, $centros[0]->id, 'C1');

        $contexto = app(ContextoTrabajo::class)->fijar($user, $centros[0]->reg_medico, $gineco->id, $consultorio->id);

        $this->assertSame($centros[0]->reg_medico, $contexto['reg_medico']);
        $this->assertSame($doctor->id, $contexto['medico']->id);
        $this->assertSame($gineco->id, $contexto['specialty']->id);
        $this->assertSame($consultorio->id, $contexto['office']->id);
    }

    /**
     * Los consultorios del contexto son **los del tenant** (Fase 2): `Office::vigentesDe` ya no se filtra
     * por el `medico_id` del doctor, así que el doctor ve el consultorio que creó el centro y los que creó
     * otro doctor del mismo centro.
     */
    public function test_los_consultorios_del_contexto_son_todos_los_del_tenant(): void
    {
        ['user' => $user, 'doctor' => $doctor, 'centros' => $centros] = $this->doctorConDosCentros();

        $delCentro = $this->consultorio($centros[0]->reg_medico, $centros[0]->id, 'C1');
        $delOtroDoctor = $this->consultorio($centros[0]->reg_medico, $doctor->id + 999, 'C2');
        // Y uno del registro personal del doctor, para que no se mezclen.
        $personal = $this->consultorio($doctor->reg_medico, $doctor->id, 'P1');

        $accesos = app(ContextoTrabajo::class)->disponibles($user);

        $this->assertEqualsCanonicalizing(
            [$delCentro->id, $delOtroDoctor->id],
            $accesos[$centros[0]->reg_medico]['sedes']->pluck('id')->all(),
        );
        $this->assertSame([$personal->id], $accesos[$doctor->reg_medico]['sedes']->pluck('id')->all());
    }

    /**
     * **El doctor es una sola tarjeta**, no una por registro (corrección del usuario, 2026-10-20): sus
     * especialidades son las suyas y el menú de consultorios combina los suyos con los del centro, con el
     * `reg_medico` de cada uno para que elegir el consultorio fije el contexto correcto. Como hay dos
     * consultorios, la pantalla se muestra (no se salta).
     */
    public function test_el_doctor_es_una_sola_tarjeta_y_el_menu_combina_sus_consultorios_con_los_del_centro(): void
    {
        ['user' => $user, 'doctor' => $doctor, 'centros' => $centros] = $this->doctorConDosCentros();

        $personal = $this->consultorio($doctor->reg_medico, $doctor->id, 'P1');
        $delCentro = $this->consultorio($centros[0]->reg_medico, $centros[0]->id, 'C1');

        $vista = new ContextoVista(app(ContextoTrabajo::class)->disponibles($user));
        $tarjetas = $vista->medicos();

        $this->assertCount(1, $tarjetas, 'El doctor es una sola tarjeta, no una por registro.');
        $this->assertSame($doctor->reg_medico, $tarjetas[0]['regMedico']);
        // Sus especialidades (las dos), no las del centro.
        $this->assertCount(2, $tarjetas[0]['especialidades']);

        // El menú de consultorios combina los suyos con los del centro.
        $sedes = collect($tarjetas[0]['sedes'])->keyBy('valor');
        $this->assertCount(2, $sedes);
        $this->assertSame($doctor->reg_medico, $sedes[$personal->id]['regMedico']);
        $this->assertNull($sedes[$personal->id]['centro'], 'El consultorio personal va en «Mis consultorios».');
        $this->assertSame($centros[0]->reg_medico, $sedes[$delCentro->id]['regMedico']);
        $this->assertNotNull($sedes[$delCentro->id]['centro'], 'El consultorio del centro va agrupado con el centro.');

        $this->assertTrue($tarjetas[0]['elegirConsultorio']);
        $this->assertTrue($vista->hayAlgoQueElegir());
        $this->assertNull(app(ContextoAutomatico::class)->de($user), 'Con dos consultorios hay algo que elegir.');
    }

    // ------------------------------------------------------------------------------------ La secretaría

    /**
     * La secretaría no tiene ficha de médico: su contexto es **el centro**, y las especialidades que ofrece
     * son la **unión de las de sus doctores** (el centro no tiene propias).
     */
    public function test_la_secretaria_ve_el_centro_como_medico_y_las_especialidades_de_sus_doctores(): void
    {
        ['user' => $user, 'doctor' => $doctor, 'centros' => $centros, 'gineco' => $gineco, 'pediatria' => $pediatria] = $this->doctorConDosCentros();

        // Un segundo doctor del mismo centro, con la otra especialidad.
        $otroUser = $this->usuario('Medico');
        $otro = $this->ficha('Dr. Segundo', 'dr2-' . substr(uniqid(), -5), $otroUser);
        $otro->specialties()->attach($pediatria->id);
        MedicoRegistro::create(['medico_id' => $otro->id, 'reg_medico' => $centros[0]->reg_medico]);

        $secretaria = $this->usuario('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $centros[0]->reg_medico]);

        $accesos = app(ContextoTrabajo::class)->disponibles($secretaria);

        $this->assertCount(1, $accesos);
        $acceso = $accesos[$centros[0]->reg_medico];

        // El médico del contexto es la **fila del centro**, no un doctor.
        $this->assertSame($centros[0]->id, $acceso['medico']->id);
        $this->assertTrue($acceso['es_centro']);
        // Y las especialidades son la unión de las de los dos doctores (sin repetir y ordenadas).
        $this->assertEqualsCanonicalizing(
            [$gineco->id, $pediatria->id],
            $acceso['especialidades']->pluck('id')->all(),
        );
    }

    public function test_una_secretaria_con_dos_centros_elige_con_cual_trabaja(): void
    {
        ['centros' => $centros, 'gineco' => $gineco] = $this->doctorConDosCentros();

        $secretaria = $this->usuario('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $centros[0]->reg_medico]);
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $centros[1]->reg_medico]);

        $accesos = app(ContextoTrabajo::class)->disponibles($secretaria);
        $this->assertCount(2, $accesos);

        // La pantalla lo ofrece: hay dos tarjetas, con el nombre del centro.
        $vista = new ContextoVista($accesos);
        $this->assertTrue($vista->hayAlgoQueElegir());
        $this->assertCount(2, $vista->medicos());
        $this->assertTrue(collect($vista->medicos())->every(fn (array $m) => $m['esCentro']));

        // Y elige uno: el contexto queda en ese centro.
        $contexto = app(ContextoTrabajo::class)->fijar($secretaria, $centros[1]->reg_medico, $gineco->id);

        $this->assertSame($centros[1]->reg_medico, $contexto['reg_medico']);
        $this->assertSame($centros[1]->id, $contexto['medico']->id);
        $this->assertTrue($contexto['es_centro']);
    }

    public function test_la_agenda_de_la_secretaria_es_la_del_centro(): void
    {
        ['centros' => $centros, 'gineco' => $gineco] = $this->doctorConDosCentros();

        $secretaria = $this->usuario('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $centros[0]->reg_medico]);

        $this->withSession(['clinica.contexto' => [
            'reg_medico'   => $centros[0]->reg_medico,
            'specialty_id' => $gineco->id,
            'office_id'    => null,
        ]]);

        $respuesta = $this->actingAs($secretaria)->get('/clinica/agenda');

        $respuesta->assertOk();
        // La agenda del centro es la del centro: la pantalla no muestra la especialidad de un doctor suelto.
        $respuesta->assertDontSee($centros[1]->name);
    }

    // ------------------------------------------------------------------------------ El centro no atiende

    /**
     * Un centro **no atiende**. Hoy lo cubre el rol de la secretaría, pero si un centro llegara a tener
     * login (`medicos.user_id`) la web tampoco le ofrece la consulta: el contexto lo dice con `es_centro`.
     */
    public function test_un_centro_no_ofrece_atender_ni_abrir_la_consulta(): void
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $gineco = $this->especialidad('ginecologia-y-obstetricia');

        // El caso raro: el centro con cuenta de acceso y rol de médico.
        $user = $this->usuario('Medico');
        $centro = $this->ficha('Centro con Login', 'centro-log-' . substr(uniqid(), -5), $user, true);
        $centro->specialties()->attach($gineco->id);

        $paciente = DB::table('pacientes')->insertGetId([
            'nombres' => 'Ana', 'apellidos' => 'Del Centro', 'cedula' => (string) random_int(10000000, 30000000),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('medico_pacientes')->insert([
            'medico_id'   => $centro->id,
            'paciente_id' => $paciente,
            'numhistoria' => '7001',
            'reg_medico'  => $centro->reg_medico,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // El contexto lo marca.
        $contexto = app(ContextoTrabajo::class)->fijar($user, $centro->reg_medico, $gineco->id);
        $this->assertTrue($contexto['es_centro']);

        // Y atender responde 403 aunque la cuenta tenga rol de médico y el paciente tenga historia.
        $this->actingAs($user)->post('/clinica/pacientes/' . $paciente . '/atender')->assertForbidden();
    }
}
