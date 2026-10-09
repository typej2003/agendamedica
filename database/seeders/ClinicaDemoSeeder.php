<?php

namespace Database\Seeders;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Deja el entorno local listo para probar la **web clínica** a mano (PLAN-WEB.md, F1):
 *
 *  - el médico de prueba (`gineco-00001`, el de `carlos@gmail.com`) queda con la especialidad
 *    Ginecología y Obstetricia — `MedicalDataSeeder` reparte especialidades al azar, y sin esto el
 *    shell clínico no tendría módulos que mostrar;
 *  - existe una cuenta de secretaría (`secretaria@gmail.com`) con acceso a esos datos, que es el caso
 *    que hasta ahora no tenía forma de representarse (`medico_registros.user_id`).
 *
 * Sólo desarrollo y pruebas: no corre en producción, igual que los demás seeders con clave conocida.
 */
class ClinicaDemoSeeder extends Seeder
{
    public function run(): void
    {
        $gineco = Specialty::where('slug', 'ginecologia-y-obstetricia')->first();
        $medico = Medico::where('reg_medico', 'gineco-00001')->first();

        if (! $medico) {
            return;
        }

        if ($gineco && ! $medico->specialties()->where('specialty_id', $gineco->id)->exists()) {
            $medico->specialties()->attach($gineco->id);
        }

        $secretaria = User::firstOrCreate(
            ['email' => 'secretaria@gmail.com'],
            [
                'name'       => 'Secretaría de prueba',
                'password'   => Hash::make('12345678'),
                'is_active'  => true,
            ]
        );

        if (! $secretaria->hasRole('Secretaria')) {
            $secretaria->assignRole('Secretaria');
        }

        MedicoRegistro::firstOrCreate([
            'user_id'    => $secretaria->id,
            'reg_medico' => $medico->reg_medico,
        ]);
    }
}
