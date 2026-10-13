<?php

namespace Tests\Feature\Concerns;

use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Evolucion;
use App\Models\Historia;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Office;
use App\Models\RecipeFormato;
use App\Models\Specialty;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Lo que comparten las pruebas de **imprimir el récipe** (WEB-5.2): un médico con su consultorio, una
 * secretaría, un paciente con historia y consulta, récipes con medicamentos del vademécum, el récipe de la pareja,
 * el membrete del médico y las citas (la que se atendió y la próxima).
 *
 * "Hoy" es `2026-10-07 09:00` (UTC) = las 05:00 en Caracas: el mismo día en el consultorio.
 */
trait PreparaRecipesParaImprimir
{
    private Carbon $hoy;

    protected function fijarHoy(): void
    {
        $this->hoy = Carbon::parse('2026-10-07 09:00:00');
        Carbon::setTestNow($this->hoy->copy());
    }

    protected function soltarHoy(): void
    {
        Carbon::setTestNow();
    }

    /** @return array{0:User, 1:Medico, 2:Specialty} */
    private function medicoConAcceso(string $rol = 'Medico'): array
    {
        $this->seed(EspecialidadesYModulosSeeder::class);

        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->firstOrFail();
        $regMedico = 'imprimir-test-' . uniqid();

        $user = User::create([
            'name'                 => 'Usuario de prueba',
            'email'                => 'imprimir-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $user->assignRole($rol);

        $medico = Medico::create([
            'user_id'    => $user->id,
            'name'       => 'Ana',
            'lastname'   => 'Imprime',
            'prefix'     => 'Dra',
            'email'      => $user->email,
            'password'   => $user->password,
            'reg_medico' => $regMedico,
        ]);
        $medico->specialties()->attach($gineco->id);
        MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

        return [$user, $medico, $gineco];
    }

    private function secretariaConAcceso(Medico $medico): User
    {
        $secretaria = User::create([
            'name'                 => 'Secretaria',
            'email'                => 'imprimir-secre-' . uniqid() . '@example.com',
            'password'             => Hash::make('secreto123'),
            'is_active'            => true,
            'must_change_password' => false,
        ]);
        $secretaria->assignRole('Secretaria');
        MedicoRegistro::create(['user_id' => $secretaria->id, 'reg_medico' => $medico->reg_medico]);

        return $secretaria;
    }

    private function conContexto(User $user, Medico $medico, Specialty $specialty): self
    {
        return $this->withSession(['clinica.contexto' => [
            'reg_medico'   => $medico->reg_medico,
            'specialty_id' => $specialty->id,
            'office_id'    => null,
        ]]);
    }

    private function medicamento(Medico $medico, string $codigo, string $comercial, ?string $generico = null, ?string $presentacion = null, ?string $uso = null): void
    {
        DB::table('vademecum')->insert([
            'reg_medico'      => $medico->reg_medico,
            'codemedicina'    => $codigo,
            'nombrecomercial' => $comercial,
            'nombregenerico'  => $generico,
            'presentacion'    => $presentacion,
            'uso'             => $uso,
            'created_at'      => '2026-01-01 00:00:00',
            'updated_at'      => '2026-01-01 00:00:00',
        ]);
    }

    private function vademecum(Medico $medico): void
    {
        $this->medicamento($medico, '0001', 'KEFORAL', 'CEFALEXINA', 'Cap. 500 mg', 'Tomar una cápsula cada 6 horas.');
        $this->medicamento($medico, '0002', 'DIFLUCAN G', 'FLUCONAZOL', 'Cap. 150 mg', 'Tomar 1 cápsula los dos.');
        $this->medicamento($medico, '0003', 'ÁCIDO FÓLICO', 'ACIDO FOLICO', 'Tabletas 5 mg', 'Una al día.');
        $this->medicamento($medico, '0004', 'LOSARTAN', null, 'Tabletas 50 mg', 'Una en la mañana.');
    }

    /** @return array{0:int, 1:Consulta} el id del paciente y su consulta de hoy */
    private function pacienteYConsulta(Medico $medico, int $numhistoria = 1001, int $nroconsulta = 1, array $extra = []): array
    {
        $pacienteId = DB::table('pacientes')->insertGetId(array_merge([
            'nac'         => 'V',
            'nombres'     => 'Ana',
            'apellidos'   => 'Alvarez',
            'cedula'      => '14555666',
            'sexo'        => 'F',
            'fnacimiento' => '1990-10-08',
            'direccion'   => 'Av. Bolívar, edificio Sol, Barquisimeto',
            'created_at'  => now(),
            'updated_at'  => now(),
        ], $extra));

        MedicoPaciente::create([
            'medico_id'   => $medico->id,
            'paciente_id' => $pacienteId,
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => (string) $numhistoria,
        ]);

        Historia::create([
            'numhistoria' => (string) $numhistoria,
            'reg_medico'  => $medico->reg_medico,
            'paciente_id' => $pacienteId,
            'medico_id'   => $medico->id,
        ]);

        $consulta = Consulta::create([
            'reg_medico'  => $medico->reg_medico,
            'numhistoria' => $numhistoria,
            'nroconsulta' => $nroconsulta,
            'fecha'       => $this->hoy->toDateString(),
        ]);

        return [$pacienteId, $consulta];
    }

    /**
     * Un récipe del paciente: una fila de `recipes` por código (con su cabecera en `recipe_detalle`).
     *
     * @param  array<int,string|array{0:string,1?:int,2?:?string}>  $codigos  `'0001'` o `['0001', cantidad, indicaciones]`
     */
    private function recipe(Medico $medico, int $historia, int $consulta, ?int $numero, array $codigos, ?string $fecha = '2026-10-06', bool $cabecera = true): void
    {
        foreach (array_values($codigos) as $orden => $codigo) {
            [$codigo, $cantidad, $indicaciones] = array_pad((array) $codigo, 3, null);

            DB::table('recipes')->insert([
                'reg_medico'   => $medico->reg_medico,
                'nrohistoria'  => $historia,
                'nroconsulta'  => $consulta,
                'codemedicina' => $codigo,
                'cantidad'     => $cantidad ?? 1,
                'indicaciones' => $indicaciones ?? 'Indicación de ' . $codigo,
                'orden'        => $orden + 1,
                'recipe'       => $numero,
                'fecha'        => $fecha,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        if ($cabecera && $numero !== null) {
            DB::table('recipe_detalle')->insert([
                'reg_medico'  => $medico->reg_medico,
                'nrohistoria' => $historia,
                'nroconsulta' => $consulta,
                'recipe'      => $numero,
                'fe_emision'  => $fecha,
                'fe_vence'    => 30,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    /** @param  array<int,string>  $codigos */
    private function pareja(Medico $medico, int $historia, int $consulta, array $codigos, string $cedula = '9876543', string $nombre = 'Mariana Rojas'): void
    {
        foreach (array_values($codigos) as $orden => $codigo) {
            DB::table('recipes_pareja')->insert([
                'reg_medico'   => $medico->reg_medico,
                'nrohistoria'  => $historia,
                'nroconsulta'  => $consulta,
                'codemedicina' => $codigo,
                'cantidad'     => 1,
                'indicaciones' => 'Pareja ' . $codigo,
                'orden'        => $orden + 1,
                'cedula'       => $cedula,
                'nombre'       => $nombre,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    /** El membrete del médico: sus datos de `evolucion` y, si se pide, su fila de `recipe_formatos`. */
    private function membrete(Medico $medico, array $evolucion = [], ?array $formato = null): void
    {
        Evolucion::updateOrCreate(['reg_medico' => $medico->reg_medico], array_merge([
            'especialidad' => 'Ginecología y Obstetricia',
            'rif'          => 'J-12345678-9',
            'linea_1'      => 'Av. Principal, Torre Salud, piso 3',
            'linea_2'      => '0251-5551234',
            'linea_3'      => 'consultorio@example.com',
            'clave'        => 0,
        ], $evolucion));

        if ($formato !== null) {
            DB::table('recipe_formatos')->insert(array_merge([
                'reg_medico' => $medico->reg_medico,
                'created_at' => '2026-09-01 00:00:00',
                'updated_at' => '2026-09-01 00:00:00',
            ], $formato));
        }
    }

    private function centro(string $nombre = 'Centro de prueba'): MedicalCenter
    {
        return MedicalCenter::create([
            'country_id' => DB::table('countries')->value('id'),
            'state_id'   => DB::table('estados')->value('id'),
            'city_id'    => DB::table('cities')->value('id'),
            'name'       => $nombre,
            'address'    => 'Dirección de prueba',
        ]);
    }

    private function sede(Medico $medico, string $modalidad): Office
    {
        return Office::create([
            'medical_center_id' => $this->centro()->id,
            'medico_id'         => $medico->id,
            'reg_medico'        => $medico->reg_medico,
            'office_number'     => 'C1',
            'modalidad'         => $modalidad,
            'duracion_cita'     => 30,
            'activo'            => true,
        ]);
    }

    /** Una cita (de la agenda) del paciente: la atendida de hoy o una futura. */
    private function cita(Medico $medico, int $historia, string $fecha, array $extra = []): Cola
    {
        return Cola::create(array_merge([
            'reg_medico'  => $medico->reg_medico,
            'fecha'       => $fecha,
            'hora_ini'    => '10:30:00',
            'numhistoria' => $historia,
            'numorden'    => 1,
            'atendido'    => 0,
            'estado'      => 0,
        ], $extra));
    }

    private function urlRecipe(Consulta $consulta, int $numero = 1): string
    {
        return route('clinica.consulta.recipes.imprimir', [$consulta->id, $numero]);
    }

    private function urlPareja(Consulta $consulta): string
    {
        return route('clinica.consulta.recipe-pareja.imprimir', $consulta->id);
    }

    /** El número de hojas (páginas A4) que trae la página de impresión. */
    private function hojasDe(string $html): int
    {
        return substr_count($html, 'class="recipe-hoja ');
    }
}
