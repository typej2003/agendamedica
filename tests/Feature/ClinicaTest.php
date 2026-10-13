<?php

namespace Tests\Feature;

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
 * Web clínica (PLAN-WEB.md, F1): contexto de trabajo, menú por especialidad/rol y el primer módulo
 * (Pacientes, sólo lectura). `DatabaseTransactions` por la razón de siempre (ver ConfiguracionMedicoTest).
 */
class ClinicaTest extends TestCase
{
    use DatabaseTransactions;

    /** Crea un usuario con su ficha de médico, especialidad de ginecología y acceso al registro. */
    private function medicoConAcceso(string $rol = 'Medico', ?string $regMedico = null): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = $regMedico ?: 'clinica-test-' . uniqid();

        $user = $this->usuarioConRol($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dra. de prueba',
            'lastname'   => 'Ginecología',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function usuarioConRol(string $rol): User
    {
        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'clinica-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        return $user;
    }

    /** Deja el contexto ya fijado en la sesión, sin pasar por el formulario. */
    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico'   => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id'    => null,
        ]]);
    }

    public function test_un_invitado_no_entra_a_la_web_clinica(): void
    {
        $this->get('/clinica')->assertRedirect(route('login'));
    }

    public function test_sin_contexto_la_web_manda_a_elegirlo(): void
    {
        [$user] = $this->medicoConAcceso();

        $this->actingAs($user)->get('/clinica')->assertRedirect(route('clinica.contexto'));
        $this->actingAs($user)->get('/clinica/pacientes')->assertRedirect(route('clinica.contexto'));
    }

    public function test_el_contexto_muestra_los_datos_del_medico_y_su_registro(): void
    {
        [$user, $medico] = $this->medicoConAcceso();

        $respuesta = $this->actingAs($user)->get('/clinica/contexto');

        $respuesta->assertOk();
        $respuesta->assertSee($medico->reg_medico);
        $respuesta->assertSee('Ginecología y Obstetricia');
    }

    public function test_el_medico_elige_contexto_y_ve_solo_sus_modulos(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $this->actingAs($user)
            ->post('/clinica/contexto', ['reg_medico' => $medico->reg_medico, 'specialty_id' => $gineco->id])
            ->assertRedirect(route('clinica.inicio'));

        $respuesta = $this->actingAs($user)->get('/clinica');
        $respuesta->assertOk();
        // El único módulo implementado hoy; lo declarado y no construido no aparece en el menú.
        $respuesta->assertSee('Pacientes');
        $respuesta->assertDontSee('Ecografías');
    }

    public function test_no_se_puede_elegir_el_registro_de_otro_medico(): void
    {
        [$user] = $this->medicoConAcceso();

        $this->actingAs($user)
            ->post('/clinica/contexto', ['reg_medico' => 'registro-ajeno-999'])
            ->assertSessionHasErrors('reg_medico');

        $this->assertNull(session('clinica.contexto'));
    }

    public function test_una_empresa_de_otra_especialidad_no_entra(): void
    {
        $user = $this->usuarioConRol('Paciente');

        $this->actingAs($user)->get('/clinica')->assertForbidden();
    }

    public function test_la_secretaria_entra_con_el_acceso_que_se_le_asigno(): void
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();

        // La secretaría no tiene ficha en `medicos`: el acceso va derecho a su cuenta.
        $medico = Medico::create([
            'name' => 'Dr. Dueño', 'lastname' => 'De los datos', 'reg_medico' => 'clinica-secre-' . uniqid(),
        ]);
        $medico->specialties()->attach($gineco->id);

        $secretaria = $this->usuarioConRol('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        $this->actingAs($secretaria)
            ->post('/clinica/contexto', ['reg_medico' => $medico->reg_medico, 'specialty_id' => $gineco->id])
            ->assertRedirect(route('clinica.inicio'));

        $this->actingAs($secretaria)->get('/clinica')->assertOk()->assertSee('Pacientes');
    }

    public function test_el_listado_de_pacientes_solo_trae_los_del_registro_del_contexto(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();

        $propio = $this->paciente('Propio', 'Delcontexto', '11111111', $medico->reg_medico, $medico->id, 1001);
        $ajeno = $this->paciente('Ajeno', 'Deotromedico', '22222222', 'clinica-otro-' . uniqid(), $medico->id, 2002);

        $respuesta = $this->conContexto($user, $medico, $gineco)->actingAs($user)->get('/clinica/pacientes');

        $respuesta->assertOk();
        $respuesta->assertSee('Delcontexto');
        $respuesta->assertDontSee('Deotromedico');
        $this->assertNotSame($propio, $ajeno);
    }

    public function test_la_busqueda_de_pacientes_filtra_por_nombre_y_cedula(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $this->paciente('Rosalía', 'Peñaloza', '12345678', $medico->reg_medico, $medico->id, 1002);
        $this->paciente('Otra', 'Persona', '87654321', $medico->reg_medico, $medico->id, 1003);

        $porNombre = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes?buscar=Peñaloza');
        $porNombre->assertOk()->assertSee('Peñaloza')->assertDontSee('Persona');

        $porCedula = $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes?buscar=87654321');
        $porCedula->assertOk()->assertSee('Persona')->assertDontSee('Peñaloza');
    }

    public function test_la_ficha_de_un_paciente_de_otro_registro_da_404(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $ajeno = $this->paciente('Ajeno', 'Deotromedico', '33333333', 'clinica-otro-' . uniqid(), $medico->id, 3003);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes/' . $ajeno)
            ->assertNotFound();
    }

    public function test_la_ficha_muestra_las_consultas_del_paciente(): void
    {
        [$user, $medico, $gineco] = $this->medicoConAcceso();
        $paciente = $this->paciente('Con', 'Consultas', '44444444', $medico->reg_medico, $medico->id, 1004);

        DB::table('consultas')->insert([
            'reg_medico'      => $medico->reg_medico,
            'numhistoria'     => 1004,
            'nroconsulta'     => 1,
            'fecha'           => '2026-09-30',
            'enfermedadactual'=> 'Control de rutina',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->conContexto($user, $medico, $gineco)->actingAs($user)
            ->get('/clinica/pacientes/' . $paciente)
            ->assertOk()
            ->assertSee('Control de rutina')
            ->assertSee('30/09/2026');
    }

    private function paciente(string $nombres, string $apellidos, string $cedula, string $regMedico, int $medicoId, ?int $numhistoria): int
    {
        $id = DB::table('pacientes')->insertGetId([
            'nombres'    => $nombres,
            'apellidos'  => $apellidos,
            'cedula'     => $cedula,
            'sexo'       => 'F',
            'fnacimiento'=> '1990-05-04',
            'telefono'   => '04121234567',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('medico_pacientes')->insert([
            'medico_id'    => $medicoId,
            'paciente_id'  => $id,
            'numhistoria'  => $numhistoria,
            'reg_medico'   => $regMedico,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return $id;
    }
}
