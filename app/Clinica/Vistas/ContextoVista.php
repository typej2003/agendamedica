<?php

namespace App\Clinica\Vistas;

/**
 * La pantalla de **elegir consultorio** (`/clinica/contexto`): con qué médico/registro, especialidad y sede
 * se opera. Una tarjeta por médico al que el usuario tiene acceso.
 */
final class ContextoVista
{
    /** @param  array<string,array{medico:object, especialidades:iterable, sedes:\Illuminate\Support\Collection}>  $disponibles */
    public function __construct(private array $disponibles)
    {
    }

    public function sinAcceso(): bool
    {
        return $this->disponibles === [];
    }

    public function urlGuardar(): string
    {
        return route('clinica.contexto.guardar');
    }

    public function urlSedes(): string
    {
        return route('clinica.sedes');
    }

    public function urlConsultorios(): string
    {
        return route('clinica.consultorios');
    }

    /** @return array<int,array<string,mixed>> */
    public function medicos(): array
    {
        $medicos = [];

        foreach ($this->disponibles as $regMedico => $acceso) {
            $medico = $acceso['medico'];

            $medicos[] = [
                'regMedico'      => (string) $regMedico,
                'nombre'         => trim(($medico->prefix ?? '') . ' ' . $medico->name . ' ' . $medico->lastname),
                'licencia'       => $medico->license_number ?: null,
                'especialidades' => collect($acceso['especialidades'])->map(fn ($e) => ['valor' => $e->id, 'texto' => $e->name])->all(),
                'sedes'          => $acceso['sedes']->map(fn ($sede) => [
                    'valor' => $sede->id,
                    'texto' => ($sede->medicalCenter->name ?? 'Sede') . ($sede->office_number ? ' · ' . $sede->office_number : ''),
                ])->all(),
                // Sin consultorios no hay sede que elegir: la agenda no puede calcular la jornada. El camino
                // es darlos de alta (WEB-2.8b.2), y para eso hay que elegir primero con quién trabajar.
                'sinConsultorios' => $acceso['sedes']->isEmpty(),
            ];
        }

        return $medicos;
    }
}
