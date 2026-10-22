<?php

namespace App\Paciente;

use App\Models\Paciente;
use App\Models\PacienteIdentidad;
use App\Models\User;
use App\Notificaciones\Telefono;
use Carbon\Carbon;

/**
 * **Los niveles de identidad de un paciente-cuenta**, en un solo lugar:
 *
 *  - **Nivel 0**: cuenta propia sin validar. Sólo se puede bloquear la cuenta.
 *  - **Nivel 1**: teléfono validado por código y con la validación vigente.
 *  - **Nivel 2**: cédula confirmada por personal del consultorio (cédula laminada).
 *
 * Los plazos (`identidad.telefono_validacion_meses`, `identidad.bloqueo_telefono_meses`) salen de la config.
 */
final class NivelesDeIdentidad
{
    public static function mesesDeValidacionDelTelefono(): int
    {
        return (int) config('identidad.telefono_validacion_meses', 12);
    }

    public static function mesesDeBloqueoPorTelefono(): int
    {
        return (int) config('identidad.bloqueo_telefono_meses', 6);
    }

    /** Cuándo vence la validación hecha en `$cuando`. */
    public static function venceLaValidacion(Carbon $cuando): Carbon
    {
        return $cuando->copy()->addMonthsNoOverflow(self::mesesDeValidacionDelTelefono());
    }

    /** ¿La cuenta tiene un teléfono validado cuya validación sigue vigente? */
    public function telefonoVigente(User $user): bool
    {
        return $this->telefonoValidadoVigente($user) !== null;
    }

    /** El teléfono validado de la cuenta (normalizado) si la validación no venció; si no, `null`. */
    public function telefonoValidadoVigente(User $user): ?string
    {
        if (blank($user->telefono_validado) || $user->telefono_validado_en === null) {
            return null;
        }

        if (self::venceLaValidacion($user->telefono_validado_en)->lte(now())) {
            return null;
        }

        return Telefono::internacional($user->telefono_validado);
    }

    /** Anota que la cuenta acaba de probar con un código que controla `$telefono`. */
    public function marcarTelefonoValidado(User $user, string $telefono): void
    {
        $user->forceFill([
            'telefono_validado' => Telefono::internacional($telefono) ?? $telefono,
            'telefono_validado_en' => now(),
        ])->save();
    }

    /** Sólo los dígitos de una cédula (`V-12.345.678` → `12345678`); `''` si no hay. */
    public static function digitos(?string $cedula): string
    {
        return preg_replace('/\D/', '', (string) $cedula);
    }

    /** ¿Esa cédula está confirmada por el personal en alguna ficha que hoy la tiene? */
    public function cedulaConfirmada(?string $cedula): bool
    {
        $digitos = self::digitos($cedula);

        if ($digitos === '') {
            return false;
        }

        return PacienteIdentidad::where('cedula', $digitos)
            ->whereNotNull('cedula_confirmada_en')
            ->with('paciente:id,cedula')
            ->get()
            ->contains(fn (PacienteIdentidad $identidad) => $identidad->paciente !== null
                && self::digitos($identidad->paciente->cedula) === $digitos);
    }

    /** ¿La ficha tiene su cédula confirmada (y es la misma que se confirmó)? */
    public function fichaConfirmada(?Paciente $paciente): bool
    {
        return $paciente !== null && $this->cedulaConfirmada($paciente->cedula)
            && PacienteIdentidad::where('paciente_id', $paciente->id)->exists();
    }

    /** 0, 1 o 2 (ver la descripción de la clase). */
    public function nivel(User $user): int
    {
        if ($this->fichaConfirmada($user->paciente)) {
            return 2;
        }

        return $this->telefonoVigente($user) ? 1 : 0;
    }
}
