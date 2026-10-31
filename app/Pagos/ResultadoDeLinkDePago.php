<?php

namespace App\Pagos;

use App\Models\Pago;

/**
 * Lo que devuelve `App\Actions\Pagos\GenerarLinkDePagoDeCita`: el pago pendiente que se comparte con el
 * paciente o el motivo por el que no se generó nada.
 *
 * Los motivos son **estados distintos**, no un booleano: la pantalla tiene que decir qué falta y llevar a
 * donde se arregla (el precio de la cita o el método de pago del administrador). `ERROR` es el cajón de
 * los avisos del motor (`Cobros`), como la falta de tasa.
 */
final class ResultadoDeLinkDePago
{
    public const LISTO = 'listo';
    public const SIN_PRECIO = 'sin_precio';
    public const SIN_METODO = 'sin_metodo';
    public const YA_PAGADA = 'ya_pagada';
    public const ERROR = 'error';

    private function __construct(
        public readonly string $estado,
        public readonly ?Pago $pago = null,
        public readonly string $mensaje = '',
    ) {
    }

    public static function listo(Pago $pago): self
    {
        return new self(self::LISTO, $pago);
    }

    public static function sinPrecio(): self
    {
        return new self(
            self::SIN_PRECIO,
            null,
            'Esta cita no tiene precio. Ponlo en la cita antes de generar el link.',
        );
    }

    public static function sinMetodo(): self
    {
        return new self(
            self::SIN_METODO,
            null,
            'No hay un método de pago configurado para cobrar citas. Pídele al administrador que configure SyPago.',
        );
    }

    public static function yaPagada(): self
    {
        return new self(self::YA_PAGADA, null, 'Esta cita ya está pagada: no hay nada que cobrar.');
    }

    public static function error(string $mensaje): self
    {
        return new self(self::ERROR, null, $mensaje);
    }

    public function hayLink(): bool
    {
        return $this->estado === self::LISTO && $this->pago !== null;
    }
}
