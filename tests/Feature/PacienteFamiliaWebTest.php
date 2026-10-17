<?php

namespace Tests\Feature;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Database\Seeders\ParentescoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * La **familia del niño** en la ficha (WEB-7.4.2a): los padres, los teléfonos y los hermanos que el escritorio de pediatría guarda en
 * `paciente_chamo` + `familia_bebe`. Sólo lectura.
 *
 * Se fija que: un niño con familia la muestra con sus hermanos enlazados; un niño sin `familia_bebe` dice que no hay datos y aun así
 * lista a los hermanos; un paciente que no es un `paciente_chamo` (cualquiera de ginecología) no ve el bloque; y la familia de otro
 * consultorio nunca se mezcla.
 */
class PacienteFamiliaWebTest extends TestCase
{
    use DatabaseTransactions;

    private function medicoConAcceso(): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);
        $this->seed(ParentescoSeeder::class);

        $pediatria = Specialty::firstOrCreate(['slug' => 'pediatria'], ['name' => 'Pediatría']);
        $regMedico = 'fam-web-' . uniqid();

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'fam-web-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole('Medico');

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Dra. de prueba',
            'lastname'   => 'Familia',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($pediatria->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $pediatria];
    }

    private function conContexto(Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico'   => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id'    => null,
        ]]);
    }

    /** Un niño con su ficha web (`pacientes` + vínculo), como lo deja el mapeo del sync. */
    private function nino(Medico $medico, string $nombres, int $historia): int
    {
        $id = DB::table('pacientes')->insertGetId([
            'nombres'     => $nombres,
            'apellidos'   => 'Pérez',
            'sexo'        => 'M',
            'fnacimiento' => now()->subYears(4)->toDateString(),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
        DB::table('medico_pacientes')->insert([
            'medico_id'   => $medico->id,
            'paciente_id' => $id,
            'numhistoria' => (string) $historia,
            'reg_medico'  => $medico->reg_medico,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return $id;
    }

    /** La fila de `paciente_chamo`, tal como la sube el escritorio de pediatría. */
    private function chamo(string $reg, int $historia, int $familia, string $nombre, string $nacimiento = '2022-03-01'): void
    {
        DB::table('paciente_chamo')->insert([
            'reg_medico'   => $reg,
            'familia'      => $familia,
            'historia'     => $historia,
            'nombre'       => $nombre,
            'sexo'         => 'M',
            'f_nacimiento' => $nacimiento,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    private function familia(string $reg, int $familia, array $extra = []): void
    {
        DB::table('familia_bebe')->insert(array_merge([
            'reg_medico'        => $reg,
            'familia'           => $familia,
            'nom_madre'         => 'ROSA GÓMEZ',
            'fecn_madre'        => '1990-05-10',
            'ocupa_madre'       => 'DOCENTE',
            'nom_padre'         => 'LUIS PÉREZ',
            'ocupa_padre'       => 'INGENIERO',
            'celu_madre'        => '04141234567',
            'cel_padre'         => '04127654321',
            'telefono'          => '02121112233',
            'ced_representante' => '12345678',
            'direccion'         => 'AV. PRINCIPAL, CASA 4',
            'localidad'         => 'CARACAS',
            'dinamica_familiar' => 'VIVEN CON LOS ABUELOS',
            'created_at'        => now(),
            'updated_at'        => now(),
        ], $extra));
    }

    public function test_el_nino_con_familia_la_muestra_con_los_padres_los_telefonos_y_los_hermanos_enlazados(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $reg = $medico->reg_medico;
        $id = $this->nino($medico, 'Mateo', 501);
        $idHermana = $this->nino($medico, 'Sofía', 502);
        $this->chamo($reg, 501, 77, 'PEREZ MATEO');
        $this->chamo($reg, 502, 77, 'PEREZ SOFIA', '2019-08-20');
        $this->familia($reg, 77);

        $respuesta = $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.pacientes.ver', $id));

        $respuesta->assertOk()
            ->assertSee('id="ficha-familia"', false)
            ->assertSee('ROSA GÓMEZ')->assertSee('DOCENTE')->assertSee('LUIS PÉREZ')->assertSee('INGENIERO')
            ->assertSee('04141234567')->assertSee('04127654321')->assertSee('02121112233')
            ->assertSee('12345678')->assertSee('AV. PRINCIPAL, CASA 4')->assertSee('VIVEN CON LOS ABUELOS')
            ->assertSee('Hermanos')->assertSee('PEREZ SOFIA')
            ->assertSee(route('clinica.pacientes.ver', $idHermana), false);
    }

    public function test_sin_familia_bebe_dice_que_no_hay_datos_y_aun_asi_lista_a_los_hermanos(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $reg = $medico->reg_medico;
        $id = $this->nino($medico, 'Mateo', 511);
        $this->chamo($reg, 511, 88, 'PEREZ MATEO');
        $this->chamo($reg, 512, 88, 'PEREZ HERMANO'); // sin ficha en la web: se lista, sin enlace

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.pacientes.ver', $id))
            ->assertOk()
            ->assertSee('Sin datos de la familia')
            ->assertSee('PEREZ HERMANO');
    }

    public function test_un_paciente_que_no_es_un_chamo_no_ve_el_bloque(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $id = $this->nino($medico, 'Adulto', 521);

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.pacientes.ver', $id))
            ->assertOk()
            ->assertDontSee('id="ficha-familia"', false);
    }

    public function test_la_familia_de_otro_consultorio_no_se_mezcla(): void
    {
        [$user, $medico, $pediatria] = $this->medicoConAcceso();
        $id = $this->nino($medico, 'Mateo', 531);
        $this->chamo($medico->reg_medico, 531, 99, 'PEREZ MATEO');
        // Otra consultorio, el mismo número de familia: no debe verse.
        $this->familia('otro-consultorio', 99, ['nom_madre' => 'MADRE AJENA']);
        $this->chamo('otro-consultorio', 900, 99, 'NIÑO AJENO');

        $this->conContexto($medico, $pediatria)->actingAs($user)->get(route('clinica.pacientes.ver', $id))
            ->assertOk()
            ->assertSee('Sin datos de la familia')
            ->assertDontSee('MADRE AJENA')->assertDontSee('NIÑO AJENO');
    }
}
