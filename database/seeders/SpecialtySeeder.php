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
        ];

        foreach ($specialties as $specialty) {
            Specialty::firstOrCreate(['name' => $specialty['name']], $specialty);
        }
    }
}