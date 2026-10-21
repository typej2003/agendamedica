<?php

namespace App\Pagos;

/**
 * El catálogo de pasarelas y variantes que el motor sabe manejar. Agregar una pasarela es agregar su
 * driver y una línea acá; el panel y los formularios leen de esta clase.
 */
final class CatalogoDePasarelas
{
    public const SYPAGO = 'sypago';
    public const MANUAL = 'manual';

    public const VARIANTES = [
        self::SYPAGO => [
            'debito_inmediato' => 'Débito inmediato (OTP)',
            'cuenta_o_movil' => 'Cuenta o pago móvil',
            'pago_movil' => 'Pago móvil',
        ],
        self::MANUAL => [
            'transferencia' => 'Transferencia o pago móvil',
        ],
    ];

    public const PASARELAS = [
        self::SYPAGO => 'SyPago',
        self::MANUAL => 'Manual',
    ];

    /** Para qué se puede ofrecer un método. */
    public const USOS = [
        'planes' => 'Planes de médicos',
        'cobros' => 'Cobros a pacientes',
        'ambos' => 'Planes y cobros',
    ];

    /** @return array<string,string> */
    public static function variantesDe(string $pasarela): array
    {
        return self::VARIANTES[$pasarela] ?? [];
    }

    public static function etiqueta(string $pasarela, string $variante): string
    {
        $nombre = self::PASARELAS[$pasarela] ?? ucfirst($pasarela);
        $detalle = self::VARIANTES[$pasarela][$variante] ?? $variante;

        return $nombre . ' · ' . $detalle;
    }
}
