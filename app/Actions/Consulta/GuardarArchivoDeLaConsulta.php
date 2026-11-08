<?php

namespace App\Actions\Consulta;

use App\Models\ArchivoDeHistoria;
use App\Models\Consulta;
use App\Sync\RegistroDeCambios;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * **Guardar un PDF en la consulta** (CAR-5, 2026-11-04): el ítem «Archivos-PDF» del escritorio del cardiólogo.
 *
 * El archivo va al **disco privado** `clinica` (el mismo de las imágenes de la consulta), en una carpeta por médico y
 * consulta, con un nombre que arma el servidor. La fila de `archivos_historia` guarda la ruta relativa; el nombre
 * original se conserva como sufijo del archivo para poder mostrarlo.
 *
 * Deja su rastro `created` en `sync_changes`, como el resto de las escrituras de la web.
 */
final class GuardarArchivoDeLaConsulta
{
    public function ejecutar(Consulta $consulta, UploadedFile $archivo, ?string $origen = 'web'): ArchivoDeHistoria
    {
        $carpeta = 'archivos/' . $consulta->reg_medico . '/consulta-' . $consulta->id;

        $original = Str::slug(pathinfo($archivo->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'documento';
        $nombre = Str::limit($original, 60, '') . '-' . Str::lower(Str::random(8)) . '.pdf';

        Storage::disk('clinica')->putFileAs($carpeta, $archivo, $nombre);

        $fila = ArchivoDeHistoria::create([
            'reg_medico' => $consulta->reg_medico,
            'nrohistoria' => (int) $consulta->numhistoria,
            'nroconsulta' => (int) $consulta->nroconsulta,
            'archivo' => $carpeta . '/' . $nombre,
        ]);

        if ($origen !== null) {
            RegistroDeCambios::registrarOperacion('archivos_historia', (int) $fila->getKey(), $consulta->reg_medico, 'created', null, null, now(), $origen);
        }

        return $fila;
    }
}
