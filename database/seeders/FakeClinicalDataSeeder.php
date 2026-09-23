<?php

namespace Database\Seeders;

use App\Models\Cola;
use App\Models\Evolucion;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoMedicalCenter;
use App\Models\Office;
use App\Models\OfficeSchedule;
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

        $this->crearConfiguracion();
        $motivos = $this->crearMotivos();
        $sedes = $this->crearSedes($medico->id);
        $this->crearMotivosPorSede($sedes);
        $pacientes = $this->crearPacientes($faker, $medico->id, $medicalCenterId);

        $this->crearColas($faker, $pacientes, $motivos, $sedes);
        $this->crearConsultas($faker, $pacientes);
        $this->crearRecipes($faker, $pacientes);
    }

    /**
     * La configuración del médico vive en `evolucion` (sí, ese nombre — ver ROADMAP.md).
     * En el dump legado real la fila existe pero está **toda en NULL**, así que acá se siembra
     * con valores reales para poder desarrollar contra algo representativo de un consultorio
     * ya configurado.
     */
    private function crearConfiguracion(): void
    {
        Evolucion::firstOrCreate(
            ['reg_medico' => self::REG_MEDICO],
            [
                // `clave` es un entero NOT NULL y **no es una credencial**: en los datos reales
                // vale 1 y 2, es el número de profesional dentro del `reg_medico` (que es la
                // instancia de PowerBuilder, compartida por varios doctores).
                'clave' => 1,
                'especialidad' => 'Ginecología y Obstetricia',
                'ciudad' => 'Barquisimeto',
                'cita_previa' => 'S',
                'tiempo_paci' => 30,
                'pais' => 'Venezuela',
                'prefi_1' => '0414',
                'prefi_2' => '0424',
                'prefi_3' => '0412',
                'correo_med' => 'carlos@gmail.com',
                'telefono' => '02515551234',
                'lunes_i' => '08:00',
                'lunes_f' => '16:00',
                'martes_i' => '08:00',
                'martes_f' => '16:00',
                'miercoles_i' => '08:00',
                'miercoles_f' => '16:00',
                'jueves_i' => '08:00',
                'jueves_f' => '16:00',
                'vienes_i' => '08:00',
                'viernes_f' => '13:00',
            ],
        );
    }

    /**
     * Las sedes donde atiende el médico, con sus bloques de trabajo. Está calcado del caso real
     * que planteó el usuario (el "Dr. Parra"), porque es el que rompe todos los supuestos viejos:
     * **dos sedes el mismo día** con modalidades distintas, y una tercera con doble jornada.
     *
     * Sin datos así no se puede probar lo que importa — que el número de paciente reinicie en
     * cada jornada y que el cupo se mire contra el bloque, no contra el día.
     *
     * @return array<int, array{office: Office, centro_id: int}>
     */
    private function crearSedes(int $medicoId): array
    {
        $definiciones = [
            [
                'centro_id' => 1, // Centro Médico San José
                'numero' => 'Consultorio 701',
                'modalidad' => Office::MODALIDAD_ORDEN,
                'duracion' => 20,
                // Mañanas de lunes a jueves: es la sede "de hospital", con cupo.
                'bloques' => [
                    [1, '08:00', '12:00', 20],
                    [2, '08:00', '12:00', 20],
                    [3, '08:00', '12:00', 20],
                    [4, '08:00', '12:00', 20],
                ],
            ],
            [
                'centro_id' => 2, // Clínica Especializada Metropolitana
                'numero' => 'Consultorio 3-B',
                'modalidad' => Office::MODALIDAD_HORA,
                'duracion' => 30,
                // Tardes de lunes a viernes: con hora de cita, sin cupo.
                'bloques' => [
                    [1, '14:00', '18:00', null],
                    [2, '14:00', '18:00', null],
                    [3, '14:00', '18:00', null],
                    [4, '14:00', '18:00', null],
                    [5, '14:00', '18:00', null],
                ],
            ],
            [
                'centro_id' => 3, // Hospital Privado Santa María
                'numero' => 'Consultorio 12',
                'modalidad' => Office::MODALIDAD_ORDEN,
                'duracion' => 15,
                // Sábados, doble jornada en la misma sede: es el caso donde el correlativo tiene
                // que reiniciar aunque no se cambie de lugar.
                'bloques' => [
                    [6, '08:00', '12:00', 25],
                    [6, '14:00', '17:00', 15],
                ],
            ],
        ];

        $sedes = [];
        foreach ($definiciones as $definicion) {
            $office = Office::updateOrCreate(
                ['medico_id' => $medicoId, 'medical_center_id' => $definicion['centro_id']],
                [
                    'reg_medico' => self::REG_MEDICO,
                    'office_number' => $definicion['numero'],
                    'modalidad' => $definicion['modalidad'],
                    'duracion_cita' => $definicion['duracion'],
                    'activo' => true,
                ],
            );

            $office->schedules()->delete();
            foreach ($definicion['bloques'] as [$dia, $inicio, $fin, $cupo]) {
                OfficeSchedule::create([
                    'office_id' => $office->id,
                    'reg_medico' => self::REG_MEDICO,
                    'dia_semana' => $dia,
                    'hora_inicio' => $inicio,
                    'hora_fin' => $fin,
                    'cupo' => $cupo,
                ]);
            }

            $sedes[] = ['office' => $office, 'centro_id' => $definicion['centro_id']];
        }

        return $sedes;
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

    /**
     * Motivos **de una sede en particular**, con precio propio — a diferencia de los de
     * `crearMotivos()`, que son globales del médico y sin precio (así era antes de que el usuario
     * aclarara que un motivo puede no estar disponible, o costar distinto, según la sede).
     *
     * Calcado del ejemplo real de `Manual agenda.pdf` (pág. 7): mismo tipo de motivo, precio
     * distinto según la "Agenda" (sede) elegida. Solo se siembra en dos de las tres sedes de
     * prueba, a propósito: la tercera queda sin motivos propios para poder probar el fallback a
     * los motivos sin sede (`office_id` nulo) desde el picker de Nueva Cita.
     *
     * @param array<int, array{office: Office, centro_id: int}> $sedes
     */
    private function crearMotivosPorSede(array $sedes): void
    {
        if (count($sedes) < 2) {
            return;
        }

        // Los `tipo_atencion` repiten el nombre de la sede a propósito, y hay un
        // código exclusivo de San José (`ECO4D`): así se ve a simple vista, al
        // cambiar de sede en Nueva Cita, tanto el precio (antes invisible) como
        // qué motivos aparecen o desaparecen — no solo cambia un número.
        $porSede = [
            $sedes[0]['office']->id => [
                ['codigo' => 'PRIM', 'tipo_atencion' => 'Primera cita (San José)', 'precio' => 100.0],
                ['codigo' => 'CTRL', 'tipo_atencion' => 'Control (San José)', 'precio' => 150.0],
                // Exclusivo de esta sede: el ecógrafo 4D no está en las otras dos.
                ['codigo' => 'ECO4D', 'tipo_atencion' => 'Ecografía 4D (San José)', 'precio' => 200.0],
            ],
            $sedes[1]['office']->id => [
                ['codigo' => 'PRIM', 'tipo_atencion' => 'Primera cita (Metropolitana)', 'precio' => 80.0],
            ],
        ];

        foreach ($porSede as $officeId => $motivos) {
            foreach ($motivos as $motivo) {
                MotivoCita::updateOrCreate(
                    ['reg_medico' => self::REG_MEDICO, 'office_id' => $officeId, 'codigo' => $motivo['codigo']],
                    ['tipo_atencion' => $motivo['tipo_atencion'], 'precio' => $motivo['precio']],
                );
            }
        }
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

    /**
     * Las citas se reparten **en los bloques de las sedes**, no en horas al azar: una cita que no
     * cae en ninguna jornada configurada no sirve para probar ni el correlativo ni el cupo.
     *
     * El `numorden` se lleva por jornada (sede + bloque + fecha), igual que lo hace el legado
     * dentro de su día: arranca en 1 y sube. Lo que el app muestra es la **posición**, pero
     * sembrar datos coherentes evita perseguir fantasmas después.
     */
    private function crearColas($faker, array $pacientes, array $motivos, array $sedes): void
    {
        // Todos los bloques posibles, aplanados: [centro, día de la semana, inicio, fin, turno].
        $bloques = [];
        foreach ($sedes as $sede) {
            foreach ($sede['office']->schedules as $indice => $bloque) {
                $bloques[] = [
                    'centro_id' => $sede['centro_id'],
                    'dia_semana' => $bloque->dia_semana,
                    'inicio' => OfficeSchedule::aMinutos($bloque->hora_inicio),
                    'fin' => OfficeSchedule::aMinutos($bloque->hora_fin),
                    // El legado usa 'D' para la jornada de la mañana y 'T' para la tarde
                    // (17.043 y 2.863 filas del dump real), no 'M'.
                    'turno' => OfficeSchedule::aMinutos($bloque->hora_inicio) < 12 * 60 ? 'D' : 'T',
                ];
            }
        }

        $ordenPorJornada = [];

        for ($i = 0; $i < self::TOTAL_COLAS; $i++) {
            $paciente = $faker->randomElement($pacientes);
            $motivo = $faker->randomElement($motivos);
            $bloque = $faker->randomElement($bloques);

            // Una fecha del rango que caiga en el día de la semana de ese bloque.
            $fecha = $faker->dateTimeBetween('-45 days', '+15 days');
            $corrimiento = ($bloque['dia_semana'] - (int) $fecha->format('N') + 7) % 7;
            $fecha = (clone $fecha)->modify("+{$corrimiento} days");

            $minutos = $faker->numberBetween($bloque['inicio'], max($bloque['inicio'], $bloque['fin'] - 15));
            $horaIni = sprintf('%02d:%02d:00', intdiv($minutos, 60), $minutos % 60 - ($minutos % 15));

            $clave = $fecha->format('Y-m-d') . '#' . $bloque['centro_id'] . '#' . $bloque['turno'];
            $ordenPorJornada[$clave] = ($ordenPorJornada[$clave] ?? 0) + 1;

            Cola::create([
                'reg_medico' => self::REG_MEDICO,
                'fecha' => $fecha->format('Y-m-d'),
                'numhistoria' => $paciente['numhistoria'],
                'medical_center_id' => $bloque['centro_id'],
                'numorden' => $ordenPorJornada[$clave],
                'atendido' => $faker->numberBetween(0, 1),
                'estado' => $faker->numberBetween(0, 1),
                'turno' => $bloque['turno'],
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

    private function crearRecipes($faker, array $pacientes): void
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
                'nrohistoria' => (int) $paciente['numhistoria'],
                'nroconsulta' => $i + 1,
                'fecha' => $fecha->format('Y-m-d'),
                'codemedicina' => $medicamento['codigo'],
                'descripcion' => $medicamento['descripcion'],
                'indicaciones' => $medicamento['indicaciones'],
                'cantidad' => $faker->numberBetween(1, 3),
            ]);
        }
    }
}
