<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * **La migración que une el nombre y el apellido de los pacientes de pediatría** (2026-11-03): PediaReport
 * guarda el nombre completo en `nombres` y no tiene apellidos, así que la ficha quedaba con el apellido
 * vacío y el médico tenía que cortarlo a mano.
 *
 * Se prueba el criterio: el nombre y el apellido se unen **con un espacio en blanco en medio** y sólo para
 * los pacientes vinculados a un consultorio de pediatría; los de las demás especialidades no se tocan.
 */
class MigracionNombresPediatriaTest extends TestCase
{
    use DatabaseTransactions;

    private function medico(string $slug): Medico
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $specialty = Specialty::where('slug', $slug)->firstOrFail();
        $regMedico = 'migra-' . substr(uniqid(), -8);

        $user = User::create([
            'name'     => 'Usuario de prueba',
            'email'    => $regMedico . '@example.com',
            'password' => bcrypt('secreto123'),
        ]);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dr. de prueba',
            'lastname'   => 'Migración',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($specialty->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return $medico;
    }

    private function paciente(Medico $medico, string $nombres, ?string $apellidos): int
    {
        $id = DB::table('pacientes')->insertGetId([
            'nombres'    => $nombres,
            'apellidos'  => $apellidos,
            'cedula'     => (string) random_int(10000000, 29999999),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('medico_pacientes')->insert([
            'medico_id'   => $medico->id,
            'paciente_id' => $id,
            'reg_medico'  => $medico->reg_medico,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return $id;
    }

    public function test_la_migracion_une_el_apellido_al_nombre_solo_en_pediatria(): void
    {
        $pediatria = $this->medico('pediatria');
        $ginecologia = $this->medico('ginecologia-y-obstetricia');

        $nino = $this->paciente($pediatria, 'PEDRO ADRIAN', 'PEREZ');
        $sinApellido = $this->paciente($pediatria, 'SIN APELLIDO', null);
        $adulto = $this->paciente($ginecologia, 'ANA', 'ALVAREZ');

        /** @var \Illuminate\Database\Migrations\Migration $migracion */
        $migracion = require database_path('migrations/2026_11_03_130000_unir_apellidos_en_nombres_de_pacientes_de_pediatria.php');
        $migracion->up();

        $this->assertSame('PEDRO ADRIAN PEREZ', DB::table('pacientes')->where('id', $nino)->value('nombres'));
        $this->assertNull(DB::table('pacientes')->where('id', $nino)->value('apellidos'));

        // El que ya estaba sin apellido no se toca y tampoco se le agrega un espacio de más.
        $this->assertSame('SIN APELLIDO', DB::table('pacientes')->where('id', $sinApellido)->value('nombres'));

        // El de ginecología conserva sus dos columnas.
        $this->assertSame('ANA', DB::table('pacientes')->where('id', $adulto)->value('nombres'));
        $this->assertSame('ALVAREZ', DB::table('pacientes')->where('id', $adulto)->value('apellidos'));
    }
}
