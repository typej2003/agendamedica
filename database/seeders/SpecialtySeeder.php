<?php

// database/seeders/SpecialtySeeder.php
namespace Database\Seeders;

use App\Models\Specialty;
use Illuminate\Database\Seeder;

class SpecialtySeeder extends Seeder
{
    public function run(): void
    {
        $specialties = [
            ['name' => 'Cardiología', 'description' => 'Enfermedades del corazón y del sistema circulatorio.'],
            ['name' => 'Pediatría', 'description' => 'Atención médica de bebés, niños y adolescentes.'],
            ['name' => 'Dermatología', 'description' => 'Diagnóstico y tratamiento de enfermedades de la piel.'],
            ['name' => 'Ginecología y Obstetricia', 'description' => 'Salud del sistema reproductor femenino y embarazos.'],
            ['name' => 'Traumatología y Ortopedia', 'description' => 'Tratamiento de lesiones óseas y musculares.'],
            ['name' => 'Neurología', 'description' => 'Trastornos del sistema nervioso central y periférico.'],
            ['name' => 'Oftalmología', 'description' => 'Cuidado y diagnóstico de enfermedades de la visión.'],
            ['name' => 'Medicina General', 'description' => 'Atención primaria y prevención de enfermedades.'],
            // Las cinco de la lista de Alexander (2026-10-22) que no estaban en el catálogo. Se necesitan para que un médico de
            // esas especialidades pueda registrarse y elegir la suya: sin la fila, el desplegable no la ofrece. Todas entran en
            // **modo medicina general** (agenda, consulta, récipes y documentos) hasta que se construyan sus módulos propios.
            ['name' => 'Medicina Interna', 'description' => 'Diagnóstico y tratamiento del adulto en el hospital y la consulta.'],
            ['name' => 'Otorrinolaringología', 'description' => 'Oído, nariz, garganta, laringe y cuello.'],
            ['name' => 'Urología', 'description' => 'Vías urinarias y aparato reproductor masculino.'],
            ['name' => 'Nefrología', 'description' => 'Enfermedades del riñón y la función renal.'],
            ['name' => 'Gastroenterología', 'description' => 'Aparato digestivo, hígado y vías biliares.'],
        ];

        foreach ($specialties as $specialty) {
            Specialty::firstOrCreate(['name' => $specialty['name']], $specialty);
        }
    }
}