<?php

namespace App\Support;

/**
 * El logo de una especialidad, para la barra lateral de la web clínica (`clinica/layout`).
 *
 * Los logos son **los del escritorio de cada especialidad** —su carpeta `imagenes`: `PEDIATRIA.jpg` en
 * Ddrpedia, `OTORRINO.jpg` en ORLReport, `TRAUMATOLOGIA.jpg` en OrthoReport…—, copiados al tamaño que entra
 * en la barra (no se recorta ni se reescribe el arte original: se elige la variante que ya existe).
 *
 * **El archivo se llama como el `slug` de la especialidad** (`pediatria.jpg`, `ginecologia-y-obstetricia.jpg`…)
 * y vive en `public/img/especialidades/`: sumar una especialidad es dejar su logo ahí, sin tocar la vista ni
 * este archivo.
 *
 * Una especialidad que todavía no tiene logo **no dibuja nada**: la barra no muestra un hueco ni un texto de
 * relleno. Hoy lo tienen las dos que la web atiende: pediatría (`PEDIATRIA_p_p.jpg` de Ddrpedia) y ginecología
 * y obstetricia (`GINECOLOGIA2_m.jpg` de MICONSULTAGI, la instalación de GinecoReport).
 */
class LogotipoEspecialidad
{
    /** Carpeta de los logos, dentro de `public/`. */
    public const CARPETA = 'img/especialidades';

    /**
     * Las extensiones que se aceptan, en orden de preferencia. El JPEG es lo que trae el escritorio; las demás
     * están para no tener que tocar esto si algún día un logo viene sin fondo.
     */
    private const EXTENSIONES = ['jpg', 'jpeg', 'png', 'webp'];

    /** La URL del logo de esa especialidad, o `null` si no tiene (o si el archivo no está). */
    public static function url(?string $slug): ?string
    {
        // Sólo el nombre del archivo: lo que no sea una letra, un número o un guion se descarta.
        $archivo = preg_replace('/[^a-z0-9-]/', '', mb_strtolower((string) $slug));

        if ($archivo === null || $archivo === '') {
            return null;
        }

        foreach (self::EXTENSIONES as $extension) {
            $ruta = self::CARPETA . '/' . $archivo . '.' . $extension;

            if (is_file(public_path($ruta))) {
                return asset($ruta);
            }
        }

        return null;
    }
}
