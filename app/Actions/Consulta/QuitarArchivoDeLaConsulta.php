<?php

namespace App\Actions\Consulta;

use App\Models\ArchivoDeHistoria;
use App\Sync\RegistroDeCambios;
use Illuminate\Support\Facades\Storage;

/**
 * **Quitar un PDF de la consulta** (CAR-5): borra la fila y **sólo** el archivo del disco privado. Una ruta del escritorio
 * (una ruta de Windows) no se toca: no existe en el servidor.
 */
final class QuitarArchivoDeLaConsulta
{
    public function ejecutar(ArchivoDeHistoria $archivo, ?string $origen = 'web'): void
    {
        $ruta = (string) $archivo->archivo;

        if (str_starts_with($ruta, 'archivos/') && Storage::disk('clinica')->exists($ruta)) {
            Storage::disk('clinica')->delete($ruta);
        }

        $id = (int) $archivo->getKey();
        $reg = $archivo->reg_medico;

        $archivo->delete();

        if ($origen !== null) {
            RegistroDeCambios::registrarOperacion('archivos_historia', $id, $reg, 'deleted', null, null, now(), $origen);
        }
    }
}
