<?php

namespace App\Support;

use JsonSerializable;

/**
 * Características comerciales de un plan (`planes.caracteristicas` y su copia en
 * `reg_medico_servicio.caracteristicas`).
 *
 * Van aparte de `RestriccionesPlan` a propósito: las restricciones son **límites**
 * (cuántos médicos, cuántas secretarías, cuánto histórico) y las características son
 * **capacidades y forma de cobro** (si imprime, si cobra por asiento y cuánto incluye).
 *
 * Igual que las restricciones, viven en un JSON que se completa con [DEFAULTS] al leer:
 * una fila vieja sin la clave nueva se comporta como el valor por defecto.
 *
 * Para sumar una característica: agregarla a [DEFAULTS] y, si conviene, un método con nombre.
 */
class CaracteristicasPlan implements JsonSerializable
{
    /** `precio_asiento_usd = null` significa cobro fijo (el `precio_usd` del plan). */
    public const DEFAULTS = [
        // ¿El plan permite imprimir reportes y generar PDF?
        'impresion_reportes' => true,
        // Si no es null, el plan se cobra por asiento a este precio mensual.
        'precio_asiento_usd' => null,
        // Cuántas secretarías entran sin cobrar asiento (la "1 secretaría gratis").
        'secretarias_incluidas' => 0,
        // Piso de asientos que se cobran aunque el equipo sea más chico.
        'minimo_asientos' => 0,
    ];

    /** @var array<string, mixed> */
    private array $valores;

    /** @param array<string, mixed>|null $guardadas lo que había en el JSON (puede estar incompleto) */
    public function __construct(?array $guardadas = null)
    {
        $this->valores = array_merge(self::DEFAULTS, $guardadas ?? []);
    }

    public static function desde($valor): self
    {
        if ($valor instanceof self) {
            return $valor;
        }
        if (is_string($valor)) {
            $valor = json_decode($valor, true);
        }

        return new self(is_array($valor) ? $valor : null);
    }

    /** @param mixed $default lo que se devuelve si la característica no existe ni en los defaults */
    public function get(string $clave, $default = null)
    {
        return $this->valores[$clave] ?? $default;
    }

    public function impresionReportes(): bool
    {
        return (bool) ($this->valores['impresion_reportes'] ?? true);
    }

    /** El precio por asiento mensual, o null si el plan tiene un precio fijo. */
    public function precioAsientoUsd(): ?float
    {
        $valor = $this->valores['precio_asiento_usd'] ?? null;

        return is_numeric($valor) && (float) $valor > 0 ? (float) $valor : null;
    }

    public function cobraPorAsiento(): bool
    {
        return $this->precioAsientoUsd() !== null;
    }

    public function secretariasIncluidas(): int
    {
        return max(0, (int) ($this->valores['secretarias_incluidas'] ?? 0));
    }

    public function minimoAsientos(): int
    {
        return max(0, (int) ($this->valores['minimo_asientos'] ?? 0));
    }

    /** Una copia con algunas características cambiadas. */
    public function con(array $cambios): self
    {
        return new self(array_merge($this->valores, $cambios));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->valores;
    }

    public function jsonSerialize(): array
    {
        return $this->valores;
    }
}
