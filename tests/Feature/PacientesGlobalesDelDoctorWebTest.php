<?php

namespace Tests\Feature;

use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * **La lista global de pacientes del doctor y el número de historia del contexto** (Fase 2 del cambio de
 * `reg_medico`, 2026-10-20).
 *
 * Las reglas que fija este test:
 *
 *  - el doctor ve a **todos sus pacientes** —el registro personal y los centros donde trabaja— una sola
 *    vez, y el número que muestra es el del **contexto**;
 *  - un paciente que tiene historia **sólo en el registro personal** aparece en el centro como **sin
 *    historia en este consultorio**, su ficha abre **sin 404** y se le puede **asignar** una historia del
 *    centro (otra historia, otro número);
 *  - la **secretaría** sólo ve los pacientes del **centro**;
 *  - el selector **«ver otra historia»** muestra el registro personal donde el paciente sí tiene historia y
 *    **nunca** un consultorio ajeno (`?reg_medico=` de otro doctor se ignora).
 *
 * `DatabaseTransactions`, como el resto de los tests de la web clínica.
 */
class PacientesGlobalesDelDoctorWebTest extends TestCase
{
    use DatabaseTransactions;

    // --------------------------------------------------------------------------------------- Ayudas

    private function usuario(string $rol): User
    {
        $user = User::create([
            'name'                 => 'Usuario ' . $rol,
            'email'                => 'glob-' . strtolower($rol) . '-' . substr(uniqid(), -6) . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        return $user;
    }

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

    /**
     * Un doctor con su registro personal y un centro (con un doctor más del centro). Devuelve las piezas.
     *
     * @return array{user:User, doctor:Medico, gineco:Specialty, centro:Medico, otro:Medico}
     */
    private function doctorConCentro(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $sufijo = substr(uniqid(), -5);

        $user = $this->usuario('Medico');
        $doctor = $this->ficha('Dra. Global', 'dr-' . $sufijo, $user);
        $doctor->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $doctor->id, 'reg_medico' => $doctor->reg_medico]);

        $centro = $this->ficha('Centro Global', 'centro-' . $sufijo, null, true);
        MedicoRegistro::create(['medico_id' => $doctor->id, 'reg_medico' => $centro->reg_medico]);

        // Otro doctor del mismo centro, para que el centro no sea "el doctor".
        $otroUser = $this->usuario('Medico');
        $otro = $this->ficha('Dr. Del Centro', 'dr2-' . $sufijo, $otroUser);
        $otro->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $otro->id, 'reg_medico' => $centro->reg_medico]);

        return compact('user', 'doctor', 'gineco', 'centro', 'otro');
    }

    private function conContexto(User $user, string $regMedico, ?int $specialtyId): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico'   => $regMedico,
            'specialty_id' => $specialtyId,
            'office_id'    => null,
        ]]);
    }

    /**
     * Un paciente con su vínculo en un `reg_medico`. `$numhistoria` no nulo crea también su `historias`.
     */
    private function paciente(string $nombres, string $apellidos, string $regMedico, int $medicoId, ?string $numhistoria = null): int
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
            'medico_id'   => $medicoId,
            'paciente_id' => $id,
            'numhistoria' => $numhistoria,
            'reg_medico'  => $regMedico,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        if ($numhistoria !== null) {
            Historia::create([
                'numhistoria' => $numhistoria,
                'reg_medico'  => $regMedico,
                'paciente_id' => $id,
                'medico_id'   => $medicoId,
            ]);
        }

        return $id;
    }

    // --------------------------------------------------------------------- La lista global del doctor

    public function test_el_doctor_ve_el_paciente_de_su_registro_y_el_del_centro_en_una_sola_lista(): void
    {
        ['user' => $user, 'doctor' => $doctor, 'gineco' => $gineco, 'centro' => $centro] = $this->doctorConCentro();

        $this->paciente('Personal', 'Del Doctor', $doctor->reg_medico, $doctor->id, '1001');
        $this->paciente('Del', 'Centro', $centro->reg_medico, $centro->id, '2001');

        // Con el contexto en el centro, la lista trae los dos...
        $respuesta = $this->conContexto($user, $centro->reg_medico, $gineco->id)
            ->actingAs($user)->get('/clinica/pacientes');

        $respuesta->assertOk();
        $respuesta->assertSee('Del Doctor, Personal');
        $respuesta->assertSee('Centro, Del');

        // ...y el número que muestra cada uno es el de **ese** consultorio.
        $respuesta->assertSee('Historia 2001');
        $this->assertStringNotContainsString('Historia 1001', $respuesta->getContent());

        // Desde su registro personal, el mismo paciente del centro aparece sin historia en este consultorio.
        $respuesta = $this->conContexto($user, $doctor->reg_medico, $gineco->id)
            ->actingAs($user)->get('/clinica/pacientes');

        $respuesta->assertOk();
        $respuesta->assertSee('Del Doctor, Personal');
        $respuesta->assertSee('Centro, Del');
        $respuesta->assertSee('Historia 1001');
    }

    public function test_el_paciente_que_tiene_historia_solo_en_el_personal_aparece_en_el_centro_sin_historia(): void
    {
        ['user' => $user, 'doctor' => $doctor, 'gineco' => $gineco, 'centro' => $centro] = $this->doctorConCentro();

        $personal = $this->paciente('Solo', 'Personal', $doctor->reg_medico, $doctor->id, '1001');

        // Filtro "sin historia" en el centro: el paciente está, porque en el **contexto** no tiene número.
        $respuesta = $this->conContexto($user, $centro->reg_medico, $gineco->id)
            ->actingAs($user)->get('/clinica/pacientes?historia=sin-historia');

        $respuesta->assertOk();
        $respuesta->assertSee('Personal, Solo');
        $respuesta->assertSee('Sin historia (1)');

        // En su registro personal sí tiene historia, así que ese filtro no lo trae.
        $respuesta = $this->conContexto($user, $doctor->reg_medico, $gineco->id)
            ->actingAs($user)->get('/clinica/pacientes?historia=sin-historia');

        $respuesta->assertOk();
        $respuesta->assertDontSee('Personal, Solo');

        // Y la ficha del paciente en el centro abre sin 404 y lo dice.
        $respuesta = $this->conContexto($user, $centro->reg_medico, $gineco->id)
            ->actingAs($user)->get('/clinica/pacientes/' . $personal);

        $respuesta->assertOk();
        $respuesta->assertSee('Sin historia en este consultorio');
        // Y ofrece ver la historia que sí tiene, en el otro consultorio del doctor.
        $respuesta->assertSee('Ver la historia N.º 1001');
    }

    public function test_al_paciente_del_personal_se_le_puede_asignar_una_historia_del_centro(): void
    {
        ['user' => $user, 'doctor' => $doctor, 'gineco' => $gineco, 'centro' => $centro] = $this->doctorConCentro();

        $paciente = $this->paciente('Solo', 'Personal', $doctor->reg_medico, $doctor->id, '1001');

        $this->conContexto($user, $centro->reg_medico, $gineco->id)
            ->actingAs($user)
            ->post('/clinica/pacientes/' . $paciente . '/historia')
            ->assertRedirect(route('clinica.pacientes.ver', $paciente));

        // La historia nueva es **del centro** y con el correlativo del centro, no la del registro personal.
        $nueva = Historia::where('reg_medico', $centro->reg_medico)->where('paciente_id', $paciente)->first();

        $this->assertNotNull($nueva, 'La historia del centro se tiene que crear.');
        $this->assertNotSame('1001', (string) $nueva->numhistoria);

        // El vínculo del centro quedó creado con ese número y el del registro personal no se tocó.
        $this->assertSame(
            (string) $nueva->numhistoria,
            (string) DB::table('medico_pacientes')->where('reg_medico', $centro->reg_medico)->where('paciente_id', $paciente)->value('numhistoria'),
        );
        $this->assertSame(
            '1001',
            (string) DB::table('medico_pacientes')->where('reg_medico', $doctor->reg_medico)->where('paciente_id', $paciente)->value('numhistoria'),
        );

        // Y la ficha en el centro ya muestra la historia de este consultorio.
        $respuesta = $this->conContexto($user, $centro->reg_medico, $gineco->id)
            ->actingAs($user)->get('/clinica/pacientes/' . $paciente);

        $respuesta->assertOk();
        $respuesta->assertSee('Historia ' . $nueva->numhistoria);
    }

    public function test_la_secretaria_solo_ve_los_pacientes_del_centro(): void
    {
        ['doctor' => $doctor, 'gineco' => $gineco, 'centro' => $centro] = $this->doctorConCentro();

        $this->paciente('Personal', 'Del Doctor', $doctor->reg_medico, $doctor->id, '1001');
        $this->paciente('Del', 'Centro', $centro->reg_medico, $centro->id, '2001');

        $secretaria = $this->usuario('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $centro->reg_medico]);

        $respuesta = $this->conContexto($secretaria, $centro->reg_medico, $gineco->id)
            ->actingAs($secretaria)->get('/clinica/pacientes');

        $respuesta->assertOk();
        $respuesta->assertSee('Centro, Del');
        $respuesta->assertDontSee('Del Doctor, Personal');

        // Y el paciente del registro personal del doctor no se abre desde el centro de la secretaría.
        $personal = DB::table('medico_pacientes')->where('reg_medico', $doctor->reg_medico)->value('paciente_id');
        $this->conContexto($secretaria, $centro->reg_medico, $gineco->id)
            ->actingAs($secretaria)->get('/clinica/pacientes/' . $personal)->assertNotFound();
    }

    // ------------------------------------------------------------------------ «Ver otra historia»

    public function test_la_ficha_ofrece_las_historias_de_los_otros_consultorios_del_doctor(): void
    {
        ['user' => $user, 'doctor' => $doctor, 'gineco' => $gineco, 'centro' => $centro] = $this->doctorConCentro();

        $paciente = $this->paciente('Con', 'Dos Historias', $doctor->reg_medico, $doctor->id, '1001');
        // El mismo paciente, también en el centro, con otra historia.
        DB::table('medico_pacientes')->insert([
            'medico_id'   => $centro->id,
            'paciente_id' => $paciente,
            'numhistoria' => '2001',
            'reg_medico'  => $centro->reg_medico,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        Historia::create([
            'numhistoria' => '2001', 'reg_medico' => $centro->reg_medico,
            'paciente_id' => $paciente, 'medico_id' => $centro->id,
        ]);

        $respuesta = $this->conContexto($user, $centro->reg_medico, $gineco->id)
            ->actingAs($user)->get('/clinica/pacientes/' . $paciente);

        $respuesta->assertOk();
        $respuesta->assertSee('Ver otra historia');
        // La del contexto (el centro) es la que está abierta y no lleva enlace; la personal sí.
        $respuesta->assertSee('Historia 2001');
        $respuesta->assertSee('Ver la historia N.º 1001');
        $respuesta->assertSee(route('clinica.pacientes.historia', ['paciente' => $paciente, 'reg_medico' => $doctor->reg_medico]), false);
    }

    public function test_la_historia_acepta_el_registro_pedido_pero_ignora_uno_ajeno(): void
    {
        ['user' => $user, 'doctor' => $doctor, 'gineco' => $gineco, 'centro' => $centro, 'otro' => $otro] = $this->doctorConCentro();

        $paciente = $this->paciente('Con', 'Dos Historias', $doctor->reg_medico, $doctor->id, '1001');
        DB::table('medico_pacientes')->insert([
            'medico_id' => $centro->id, 'paciente_id' => $paciente, 'numhistoria' => '2001',
            'reg_medico' => $centro->reg_medico, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Historia::create([
            'numhistoria' => '2001', 'reg_medico' => $centro->reg_medico,
            'paciente_id' => $paciente, 'medico_id' => $centro->id,
        ]);

        // La consulta que solo existe en el registro personal del doctor.
        DB::table('consultas')->insert([
            'reg_medico'    => $doctor->reg_medico,
            'numhistoria'   => 1001,
            'nroconsulta'   => 1,
            'fecha'         => '2026-10-01',
            'enfermedadactual' => 'UNICAMENTE EN EL REGISTRO PERSONAL',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        // Con `?reg_medico=` del registro personal, la historia es la de allá.
        $respuesta = $this->conContexto($user, $centro->reg_medico, $gineco->id)
            ->actingAs($user)->get(route('clinica.pacientes.historia', ['paciente' => $paciente, 'reg_medico' => $doctor->reg_medico]));

        $respuesta->assertOk();
        $respuesta->assertSee('Historia N.º 1001');
        $respuesta->assertSee('UNICAMENTE EN EL REGISTRO PERSONAL');

        // Con el `reg_medico` de otro doctor se ignora y se abre la del contexto.
        $respuesta = $this->conContexto($user, $centro->reg_medico, $gineco->id)
            ->actingAs($user)->get(route('clinica.pacientes.historia', ['paciente' => $paciente, 'reg_medico' => $otro->reg_medico]));

        $respuesta->assertOk();
        $respuesta->assertSee('Historia N.º 2001');
        $respuesta->assertDontSee('UNICAMENTE EN EL REGISTRO PERSONAL');
    }
}
