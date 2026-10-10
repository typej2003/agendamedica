<?php

namespace App\Support;

/**
 * Iniciales y color de un avatar, a partir del nombre de una persona.
 *
 * Aguanta los dos formatos con los que llegan los nombres en la web clínica: "Apellido, Nombre"
 * (el de los listados) y "Nombre Apellido" (el de las fichas y los médicos). El color sale de una
 * suma del nombre, así que una misma persona se ve siempre igual sin guardar nada.
 */
class Iniciales
{
    /** Clases de `doctorisimo-ui.css` (`.avatar-tono-*`), todas de la paleta suave del logo. */
    private const TONOS = ['avatar-tono-violeta', 'avatar-tono-celeste', 'avatar-tono-exito', 'avatar-tono-aviso', 'avatar-tono-tinta'];

    /** Hasta dos letras: la primera del nombre y la primera del apellido. */
    public static function de(?string $nombre): string
    {
        $nombre = trim((string) $nombre);

        if ($nombre === '') {
            return '?';
        }

        // "Pérez, Ana María" → apellido y nombre; el orden de lectura es nombre-apellido.
        if (str_contains($nombre, ',')) {
            [$apellido, $nombres] = array_map('trim', explode(',', $nombre, 2));
            $nombre = trim($nombres . ' ' . $apellido);
        }

        $partes = preg_split('/\s+/u', $nombre, -1, PREG_SPLIT_NO_EMPTY);
        $primera = mb_substr($partes[0], 0, 1);
        $ultima = count($partes) > 1 ? mb_substr(end($partes), 0, 1) : '';

        return mb_strtoupper($primera . $ultima);
    }

    /** La clase de color del avatar, estable para un mismo nombre. */
    public static function tono(?string $nombre): string
    {
        return self::TONOS[abs(crc32(mb_strtolower(trim((string) $nombre)))) % count(self::TONOS)];
    }
}
