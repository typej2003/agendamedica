<?php

namespace App\Support;

use JsonSerializable;

/**
 * Restricciones de un plan (`planes.restricciones` y su copia en `reg_medico_servicio.restricciones`).
 *
 * Viven en un JSON porque se van a ir agregando. Para que agregar una no obligue a migrar filas, todo
 * valor que falte en lo guardado se completa con [DEFAULTS] al leer: una fila vieja sin la clave nueva
 * se comporta como el valor por defecto (aquí, `null` = sin límite).
 *
 * Para sumar una restricción: agregarla a [DEFAULTS] y, si conviene, un método con nombre.
 */
class RestriccionesPlan implements JsonSerializable
{
    /** `null` = sin límite. */
    public const DEFAULTS = [
        // Cuentas de médico enlazadas al `reg_medico` que el plan permite.
        'max_medicos' => null,
        // Cuántos meses hacia atrás se ven citas, consultas y fichas en el app y la web.
        'max_historico_meses' => null,
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

    /** @param mixed $default lo que se devuelve si la restricción no existe ni en los defaults */
    public function get(string $clave, $default = null)
    {
        return $this->valores[$clave] ?? $default;
    }

    public function maxMedicos(): ?int
    {
        return $this->entero('max_medicos');
    }

    public function maxHistoricoMeses(): ?int
    {
        return $this->entero('max_historico_meses');
    }

    /** Una copia con algunas restricciones cambiadas. */
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

    private function entero(string $clave): ?int
    {
        $valor = $this->valores[$clave] ?? null;

        return is_numeric($valor) ? (int) $valor : null;
    }
}
