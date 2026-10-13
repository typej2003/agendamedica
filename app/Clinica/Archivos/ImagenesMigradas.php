<?php

namespace App\Clinica\Archivos;

use App\Models\ImagenDeLaPc;
use Illuminate\Support\Facades\Storage;

/**
 * **De una fila legada al archivo que ya está en la nube** (WEB-T.1): cuando una pantalla de la web tenga que mostrar
 * `imagen_pacientes.imagen`, `imagen2`, `imagenes.imagen` o `pacientes.foto_pac`, esta clase dice dónde quedó el archivo
 * que subió `bridge/subir-imagenes`, sin que la columna legada se haya tocado.
 *
 * Sólo lee (`imagenes_de_la_pc` y el disco). **No autoriza**: quien sirve el archivo tiene que haber comprobado antes que
 * la fila es del `reg_medico` del contexto (igual que `ImagenesDeLaConsulta::delContexto` en WEB-4.7); acá el `reg_medico` es
 * parte de la clave para que dos médicos con el mismo `id` de fila no se crucen jamás.
 *
 * `imagen_consulta` no pasa por acá: su columna sí se reescribe y la sirve `ImagenesDeLaConsulta`.
 */
final class ImagenesMigradas
{
    /**
     * El archivo subido para esa celda, o `null` si no se subió (pendiente u omitida) o ya no está en el disco.
     *
     * @return object{disco:string,ruta:string,tipo:?string,tamano:?int,sha256:?string,nombre:?string,descarga:bool}|null
     */
    public function deLaFila(string $regMedico, string $tabla, int $filaId, string $columna): ?object
    {
        $registro = ImagenDeLaPc::where('reg_medico', $regMedico)->where('tabla', $tabla)
            ->where('fila_id', $filaId)->where('columna', $columna)
            ->where('estado', ImagenDeLaPc::SUBIDA)->first();

        if ($registro === null || $registro->ruta === null || ! ArchivosClinicos::esRutaDelDisco($registro->ruta)) {
            return null;
        }

        $disco = $registro->disco ?: ArchivosClinicos::DISCO;
        if (! Storage::disk($disco)->exists($registro->ruta)) {
            return null;
        }

        return (object) [
            'disco' => $disco,
            'ruta' => $registro->ruta,
            'tipo' => $registro->tipo ?: ArchivosClinicos::tipoPorExtension($registro->ruta),
            'tamano' => $registro->tamano === null ? null : (int) $registro->tamano,
            'sha256' => $registro->sha256,
            // El nombre que el consultorio le había dado al archivo; nunca la ruta del sistema ni la de la PC.
            'nombre' => ArchivosClinicos::nombreVisible($registro->ruta_pc),
            'descarga' => ArchivosClinicos::esDeDescarga($registro->ruta),
        ];
    }
}
