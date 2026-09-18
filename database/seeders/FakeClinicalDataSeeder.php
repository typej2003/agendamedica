<?php

namespace Database\Seeders;

use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoMedicalCenter;
use App\Models\MedicoPaciente;
use App\Models\MotivoCita;
use App\Models\Paciente;
use App\Models\Recipe;
use Faker\Factory as FakerFactory;
use Illuminate\Database\Seeder;

/**
 * Datos clínicos 100% sintéticos (Faker) para el médico de prueba `carlos@gmail.com`
 * (reg_medico=gineco-00001) — para tener volumen realista de pacientes/citas contra el
 * cual desarrollar el sync delta y probar el app sin depender de datos reales de
 * producción ni del dump legado (que además ya no encaja con el esquema actual de
 * `pacientes`/`historias`, ver ROADMAP.md).
 */
class FakeClinicalDataSeeder extends Seeder
{
    private const REG_MEDICO = 'gineco-00001';
    private const NUMHISTORIA_INICIAL = 1000;
    private const TOTAL_PACIENTES = 50;
    private const TOTAL_COLAS = 150;
    private const TOTAL_CONSULTAS = 80;
    private const TOTAL_RECIPES = 40;

    public function run(): void
    {
        $medico = Medico::where('reg_medico', self::REG_MEDICO)->first();
        if (!$medico) {
            return;
        }

        $medicalCenterId = MedicoMedicalCenter::where('medico_id', $medico->id)->value('medical_center_id');
        $faker = FakerFactory::create('es_VE');

        $motivos = $this->crearMotivos();
        $pacientes = $this->crearPacientes($faker, $medico->id, $medicalCenterId);

        $this->crearColas($faker, $pacientes, $motivos);
        $this->crearConsultas($faker, $pacientes);
        $this->crearRecipes($faker, $pacientes, $medico->id);
    }

    /** @return list<array{codigo: string, tipo_atencion: string}> */
    private function crearMotivos(): array
    {
        $motivos = [
            ['codigo' => 'PRIM', 'tipo_atencion' => 'Primera consulta'],
            ['codigo' => 'CTRL', 'tipo_atencion' => 'Control'],
            ['codigo' => 'ECO', 'tipo_atencion' => 'Ecografía'],
            ['codigo' => 'PESO', 'tipo_atencion' => 'Control de peso'],
            ['codigo' => 'PAP', 'tipo_atencion' => 'Papanicolau'],
            ['codigo' => 'COLP', 'tipo_atencion' => 'Colposcopia'],
        ];

        foreach ($motivos as $motivo) {
            MotivoCita::firstOrCreate(
                ['reg_medico' => self::REG_MEDICO, 'codigo' => $motivo['codigo']],
                ['tipo_atencion' => $motivo['tipo_atencion']],
            );
        }

        return $motivos;
    }

    /** @return list<array{paciente: Paciente, numhistoria: string}> */
    private function crearPacientes($faker, int $medicoId, ?int $medicalCenterId): array
    {
        $pacientes = [];

        for ($i = 0; $i < self::TOTAL_PACIENTES; $i++) {
            $sexo = $faker->randomElement(['M', 'F']);
            $nombres = $sexo === 'M' ? $faker->firstNameMale() : $faker->firstNameFemale();
            $apellidos = $faker->lastName() . ' ' . $faker->lastName();

            $paciente = Paciente::create([
                'nac' => 'V',
                'cedula' => (string) $faker->unique()->numberBetween(5000000, 29999999),
                'apellidos' => $apellidos,
                'nombres' => $nombres,
                'sexo' => $sexo,
                'fnacimiento' => $faker->dateTimeBetween('-70 years', '-16 years')->format('Y-m-d'),
                'direccion' => $faker->address(),
                'telefono' => '04' . $faker->randomElement([12, 14, 16, 24, 26]) . $faker->numerify('#######'),
                'email' => $faker->unique()->safeEmail(),
                'medico' => $medicoId,
            ]);

            $numhistoria = (string) (self::NUMHISTORIA_INICIAL + $i);

            Historia::create([
                'numhistoria' => $numhistoria,
                'reg_medico' => self::REG_MEDICO,
                'paciente_id' => $paciente->id,
                'medico_id' => $medicoId,
                'medical_center_id' => $medicalCenterId,
            ]);

            MedicoPaciente::create([
                'medico_id' => $medicoId,
                'paciente_id' => $paciente->id,
                'numhistoria' => $numhistoria,
                'reg_medico' => self::REG_MEDICO,
            ]);

            $pacientes[] = ['paciente' => $paciente, 'numhistoria' => $numhistoria];
        }

        return $pacientes;
    }

    private function crearColas($faker, array $pacientes, array $motivos): void
    {
        for ($i = 0; $i < self::TOTAL_COLAS; $i++) {
            $paciente = $faker->randomElement($pacientes);
            $motivo = $faker->randomElement($motivos);
            $fecha = $faker->dateTimeBetween('-45 days', '+15 days');
            $horaIni = sprintf('%02d:%02d:00', $faker->numberBetween(8, 16), $faker->randomElement([0, 15, 30, 45]));

            Cola::create([
                'reg_medico' => self::REG_MEDICO,
                'fecha' => $fecha->format('Y-m-d'),
                'numhistoria' => $paciente['numhistoria'],
                'numorden' => $i + 1,
                'atendido' => $faker->numberBetween(0, 1),
                'estado' => $faker->numberBetween(0, 1),
                'turno' => $faker->randomElement(['M', 'T']),
                'motivo' => $motivo['tipo_atencion'],
                'monto' => $faker->randomElement([0, 30, 50, 80]),
                'hora_ini' => $horaIni,
                'tiempo' => 30,
                'tipo' => $motivo['codigo'],
            ]);
        }
    }

    private function crearConsultas($faker, array $pacientes): void
    {
        for ($i = 0; $i < self::TOTAL_CONSULTAS; $i++) {
            $paciente = $faker->randomElement($pacientes);
            $fecha = $faker->dateTimeBetween('-45 days', 'now');

            Consulta::create([
                'reg_medico' => self::REG_MEDICO,
                'numhistoria' => (int) $paciente['numhistoria'],
                'nroconsulta' => $i + 1,
                'fecha' => $fecha->format('Y-m-d'),
                'enfermedadactual' => $faker->sentence(10),
                'peso' => $faker->randomFloat(1, 45, 95),
                'talla' => $faker->randomFloat(2, 1.5, 1.9),
                'eliminado' => '0',
            ]);
        }
    }

    private function crearRecipes($faker, array $pacientes, int $medicoId): void
    {
        $medicamentos = [
            ['codigo' => 'MED001', 'descripcion' => 'Ácido fólico 5mg', 'indicaciones' => 'Tomar 1 tableta vía oral cada 24 horas'],
            ['codigo' => 'MED002', 'descripcion' => 'Amoxicilina 500mg', 'indicaciones' => 'Tomar 1 cápsula vía oral cada 8 horas por 7 días'],
            ['codigo' => 'MED003', 'descripcion' => 'Ibuprofeno 400mg', 'indicaciones' => 'Tomar 1 tableta vía oral cada 8 horas si hay dolor'],
            ['codigo' => 'MED004', 'descripcion' => 'Complejo B', 'indicaciones' => 'Tomar 1 tableta vía oral cada 24 horas por 30 días'],
            ['codigo' => 'MED005', 'descripcion' => 'Metronidazol 500mg', 'indicaciones' => 'Tomar 1 tableta vía oral cada 12 horas por 7 días'],
            ['codigo' => 'MED006', 'descripcion' => 'Sulfato ferroso', 'indicaciones' => 'Tomar 1 tableta vía oral cada 24 horas con las comidas'],
        ];

        for ($i = 0; $i < self::TOTAL_RECIPES; $i++) {
            $paciente = $faker->randomElement($pacientes);
            $medicamento = $faker->randomElement($medicamentos);
            $fecha = $faker->dateTimeBetween('-45 days', 'now');

            Recipe::create([
                'reg_medico' => self::REG_MEDICO,
                'numhistoria' => (int) $paciente['numhistoria'],
                'nroconsulta' => $i + 1,
                'fecha' => $fecha->format('Y-m-d'),
                'codemedicina' => $medicamento['codigo'],
                'descripcion' => $medicamento['descripcion'],
                'indicaciones' => $medicamento['indicaciones'],
                'cantidad' => $faker->numberBetween(1, 3),
                'medico' => $medicoId,
            ]);
        }
    }
}
