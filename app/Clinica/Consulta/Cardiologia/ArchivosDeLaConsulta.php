<?php

namespace App\Clinica\Consulta\Cardiologia;

use App\Models\ArchivoDeHistoria;
use App\Models\Consulta;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * **Los PDF de una consulta** (CAR-5): las filas de `archivos_historia` de la historia y la consulta, con el nombre visible
 * y si el archivo está en el disco privado de la web (los del escritorio son rutas de Windows y sólo se listan).
 */
final class ArchivosDeLaConsulta
{
    /**
     * @return Collection<int,ArchivoDeHistoria>
     */
    public function de(Consulta $consulta): Collection
    {
        return ArchivoDeHistoria::where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta)
            ->orderBy('id')
            ->get();
    }

    /** El nombre visible del archivo: el del escritorio tal cual o el que armó la web. */
    public static function nombreVisible(?string $ruta): string
    {
        $base = basename((string) $ruta);

        // La web guarda `<nombre-original>-<aleatorio>.pdf`: se muestra el nombre original sin el sufijo.
        if (preg_match('/^(.*)-[a-z0-9]{8}\.pdf$/', $base, $partes) === 1) {
            return str_replace('-', ' ', $partes[1]) . '.pdf';
        }

        return $base !== '' ? $base : 'Archivo';
    }

    /** ¿El archivo vive en el disco privado de la web? (Los del escritorio son rutas de la PC.) */
    public static function esDeLaWeb(?string $ruta): bool
    {
        return is_string($ruta) && str_starts_with($ruta, 'archivos/') && Storage::disk('clinica')->exists($ruta);
    }
}
