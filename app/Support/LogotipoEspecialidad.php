<?php

namespace App\Support;

/**
 * El logo de una especialidad, para la barra lateral de la web clínica (`clinica/layout`).
 *
 * Los logos son **los del manual de identidad** (`IDENTIDAD_BÁSICA_DOCTORISIMO.pdf`, 2026-11-13): la marca de
 * cada especialidad —gineco report, pedia report…— con el azul de marca pasado a blanco para que se lea sobre
 * la barra oscura, y el acento de la especialidad tal cual. Salen del manual a 600 dpi, con el fondo
 * transparente.
 *
 * **El archivo se llama como el `slug` de la especialidad** (`pediatria.png`, `ginecologia-y-obstetricia.png`…)
 * y vive en `public/img/especialidades/`: sumar una especialidad es dejar su logo ahí, sin tocar la vista ni
 * este archivo.
 *
 * Una especialidad que todavía no tiene logo **no dibuja nada**: la barra no muestra un hueco ni un texto de
 * relleno. Hoy lo tienen pediatría y ginecología y obstetricia.
 */
class LogotipoEspecialidad
{
    /** Carpeta de los logos, dentro de `public/`. */
    public const CARPETA = 'img/especialidades';

    /**
     * Las extensiones que se aceptan, en orden de preferencia. Los logos del manual son PNG con transparencia;
     * el JPEG quedó del escritorio, para no tener que tocar esto si algún día vuelve uno sin fondo.
     */
    private const EXTENSIONES = ['png', 'webp', 'jpg', 'jpeg'];

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
