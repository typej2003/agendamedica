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
    /** Pago móvil o transferencia a la cuenta del médico (o de la plataforma). */
    public const PAGO_MOVIL = 'pago_movil';

    public const VARIANTES = [
        // SyPago tiene un solo producto de cobro inmediato: el débito con OTP. Si es cuenta o teléfono
        // (pago móvil) lo decide el número que escribe el pagador, no el método.
        self::SYPAGO => [
            'debito_inmediato' => 'Débito inmediato',
        ],
        // El paciente **envía** el pago desde su banco y el sistema lo confirma: consultando la API del
        // banco que recibe (si la cuenta la tiene configurada) o con el consultorio.
        self::PAGO_MOVIL => [
            'pago_movil' => 'Pago móvil o transferencia',
        ],
        self::MANUAL => [
            'transferencia' => 'Transferencia o pago móvil',
        ],
    ];

    public const PASARELAS = [
        self::SYPAGO => 'SyPago',
        self::PAGO_MOVIL => 'Pago móvil',
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
