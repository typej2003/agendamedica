<?php

namespace App\Paciente;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Segundo paso de confirmar o cambiar el teléfono: comprobar el código y anotar la validación (nivel 1).
 *
 * Si el número es otro, también se actualiza el teléfono de la ficha de la cuenta (`pacientes.telefono`): es el
 * número al que el consultorio le manda los recordatorios, y la reserva pública lo compara con el que escribe.
 */
final class ConfirmarTelefonoDelPaciente
{
    private const INTENTOS_MAXIMOS = 5;

    public function __construct(private TelefonoPendiente $pendiente, private NivelesDeIdentidad $niveles)
    {
    }

    /**
     * @return string el teléfono validado (normalizado)
     *
     * @throws ValidationException
     */
    public function ejecutar(User $user, string $codigo): string
    {
        $datos = $this->pendiente->datos();

        if ($datos === null || (int) ($datos['user_id'] ?? 0) !== (int) $user->id) {
            throw ValidationException::withMessages(['codigo' => 'El trámite venció. Vuelve a pedir el código.']);
        }

        if (now()->timestamp > (int) ($datos['expira'] ?? 0)) {
            $this->pendiente->olvidar();

            throw ValidationException::withMessages(['codigo' => 'El código venció. Pide uno nuevo.']);
        }

        if ((int) ($datos['intentos'] ?? 0) >= self::INTENTOS_MAXIMOS) {
            $this->pendiente->olvidar();

            throw ValidationException::withMessages(['codigo' => 'Demasiados intentos con ese código. Pide uno nuevo.']);
        }

        if (! Hash::check($codigo, (string) ($datos['codigo_hash'] ?? ''))) {
            $datos['intentos'] = (int) ($datos['intentos'] ?? 0) + 1;
            $this->pendiente->guardar($datos);

            throw ValidationException::withMessages(['codigo' => 'El código no es correcto. Revisa el mensaje y vuelve a intentarlo.']);
        }

        $telefono = (string) $datos['telefono'];

        DB::transaction(function () use ($user, $telefono) {
            $this->niveles->marcarTelefonoValidado($user, $telefono);

            $ficha = $user->paciente;

            if ($ficha !== null && \App\Notificaciones\Telefono::internacional($ficha->telefono) !== $telefono) {
                $ficha->telefono = $telefono;
                $ficha->save();
            }
        });

        $this->pendiente->olvidar();

        return $telefono;
    }
}
