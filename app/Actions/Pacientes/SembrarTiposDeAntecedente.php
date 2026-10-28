<?php

namespace App\Actions\Pacientes;

use App\Clinica\Consulta\TiposDeAntecedente;
use Illuminate\Support\Facades\DB;

/**
 * **Sembrar los siete tipos de antecedente del médico** (`tipo_antecedente`, con su `reg_medico`) según la
 * especialidad de la consulta (WEB-12.3; ginecología, 2026-10-28).
 *
 * Antes esta Action era sólo de pediatría (su mapa estaba en `SembrarTiposDeAntecedentePediatricos`). Los códigos
 * `01`–`07` son los mismos en todas las especialidades y lo que cambia es el nombre, que vive en
 * `TiposDeAntecedente`: acá se elige el mapa de la especialidad del contexto y no se siembra nada para una
 * especialidad sin nombres propios.
 *
 * Es **idempotente y no pisa**: sólo agrega el código que el médico no tiene (aunque lo haya llamado distinto:
 * lo suyo manda). No toca `antecedentes` (los antecedentes concretos de cada médico) ni nada del paciente.
 */
final class SembrarTiposDeAntecedente
{
    /** @return int cuántos tipos agregó */
    public function ejecutar(string $regMedico, ?string $especialidad): int
    {
        $tipos = TiposDeAntecedente::para($especialidad);

        if ($tipos === null) {
            // La fuente no trae los nombres de esta especialidad: no se le prestan los de otra.
            return 0;
        }

        $letras = TiposDeAntecedente::letras($especialidad);

        return DB::transaction(function () use ($regMedico, $tipos, $letras) {
            $tiene = DB::table('tipo_antecedente')->where('reg_medico', $regMedico)->pluck('codetipo')->map(fn ($c) => trim((string) $c))->all();
            $agregados = 0;

            foreach ($tipos as $codigo => $nombre) {
                if (in_array($codigo, $tiene, true)) {
                    continue;
                }

                DB::table('tipo_antecedente')->insert([
                    'reg_medico' => $regMedico, 'codetipo' => $codigo, 'descripcion' => $nombre,
                    'tipoantecedente' => $letras[$codigo] ?? null, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $agregados++;
            }

            return $agregados;
        });
    }
}
