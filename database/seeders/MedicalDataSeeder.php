<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Medico;
use App\Models\MedicalCenter;
use App\Models\Office;
use App\Models\Specialty;
use App\Models\MedicoRegistro;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class MedicalDataSeeder extends Seeder
{
    public function run(): void
    {
        $city = City::with(['state', 'state.country'])->first();

        if (!$city) {
            return;
        }

        $countryId = $city->state->country_id;
        $stateId = $city->state_id;
        $cityId = $city->id;

        $specialties = Specialty::all();

        if ($specialties->isEmpty()) {
            return;
        }

        $centers = [
            [
                'name' => 'Centro Médico San José',
                'address' => 'Av. Principal Norte, Edificio Médico, Nivel 1',
                'phone' => '+58 212-5550101',
                'offices' => ['Consultorio 101-A', 'Consultorio 102-B', 'Consultorio 103-C'],
            ],
            [
                'name' => 'Clínica Especializada Metropolitana',
                'address' => 'Calle Los Almendros con Av. Bolívar, Torre B',
                'phone' => '+58 212-5550202',
                'offices' => ['Oficina 201', 'Oficina 202', 'Oficina 203'],
            ],
            [
                'name' => 'Hospital Privado Santa María',
                'address' => 'Sector Las Flores, Transversal 3, Local 12',
                'phone' => '+58 212-5550303',
                'offices' => ['Modulo A - Cons. 1', 'Modulo A - Cons. 2', 'Modulo B - Cons. 1'],
            ],
        ];

        $doctorsData = [
            ['first_name' => 'Carlos', 'last_name' => 'Mendoza', 'email' => 'carlos@gmail.com', 'phone' => '04141112233', 'reg_medico' => 'gineco-00001'],
            ['first_name' => 'Ana', 'last_name' => 'Pérez', 'email' => 'ana.perez@example.com', 'phone' => '04121112233', 'reg_medico' => 'MPPS-54321'],
            ['first_name' => 'María', 'last_name' => 'Delgado', 'email' => 'maria.delgado@example.com', 'phone' => '04122223344', 'reg_medico' => 'MPPS-98765'],
            ['first_name' => 'Alejandro', 'last_name' => 'Rojas', 'email' => 'alejandro.rojas@example.com', 'phone' => '04243334455', 'reg_medico' => 'MPPS-56789'],
            ['first_name' => 'Elena', 'last_name' => 'Benítez', 'email' => 'elena.benitez@example.com', 'phone' => '04164445566', 'reg_medico' => 'MPPS-13578'],
            ['first_name' => 'Roberto', 'last_name' => 'Gómez', 'email' => 'roberto.gomez@example.com', 'phone' => '04145556677', 'reg_medico' => 'MPPS-24680'],
            ['first_name' => 'Sofía', 'last_name' => 'López', 'email' => 'sofia.lopez@example.com', 'phone' => '04126667788', 'reg_medico' => 'MPPS-13579'],
        ];

        $doctorIndex = 0;
        $hashedPassword = Hash::make('12345678');

        foreach ($centers as $centerData) {
            $center = MedicalCenter::create([
                'country_id' => $countryId,
                'state_id' => $stateId,
                'city_id' => $cityId,
                'name' => $centerData['name'],
                'address' => $centerData['address'],
                'phone' => $centerData['phone'],
            ]);

            foreach ($centerData['offices'] as $officeNumber) {
                $office = Office::create([
                    'medical_center_id' => $center->id,
                    'office_number' => $officeNumber,
                    'phone' => $center->phone,
                    'schedule' => 'Lunes a Viernes de 8:00 AM a 4:00 PM',
                ]);

                if (isset($doctorsData[$doctorIndex])) {
                    $docData = $doctorsData[$doctorIndex];

                    // 1. Crear el usuario asociado al médico
                    $user = User::create([
                        'name' => $docData['first_name'] . ' ' . $docData['last_name'],
                        'email' => $docData['email'],
                        'password' => $hashedPassword,
                        'reg-medico' => $docData['reg_medico'],
                    ]);

                    // Asignar rol de médico si utilizas Spatie Permissions (Opcional)
                    if (method_exists($user, 'assignRole')) {
                        $user->assignRole('medico');
                    }

                    // 2. Crear el médico vinculando el user_id registrado
                    $medico = Medico::create([
                        'user_id' => $user->id,
                        'name' => $docData['first_name'],
                        'lastname' => $docData['last_name'],
                        'license_number' => 'MPPS-' . rand(10000, 99999),
                        'email' => $docData['email'],
                        'phone' => $docData['phone'],
                        'biography' => 'Médico especialista enfocado en la atención integral del paciente.',
                        'photo_path' => null,
                        'office_id' => $office->id,
                        'consultation_fee' => 50.00,
                        'password' => $hashedPassword,
                        'reg-medico' => $docData['reg_medico'],
                        'is_active' => true,
                    ]);

                    // 3. Registrar en la tabla MedicoRegistro
                    MedicoRegistro::create([
                        'medico_id' => $medico->id,
                        'reg-medico' => $docData['reg_medico'],
                    ]);

                    // 4. Asociar al centro médico a través de la tabla pivote
                    $medico->medicalCenters()->attach($center->id);

                    // 5. Asignar especialidades aleatorias
                    $assignedSpecialties = $specialties->random(rand(1, 2))->pluck('id');
                    $medico->specialties()->attach($assignedSpecialties);

                    $doctorIndex++;
                }
            }
        }
    }
}