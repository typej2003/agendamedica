<?php

namespace App\Paciente;

use App\Clinica\FechaDelConsultorio;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Segundo paso de la **cuenta del paciente**: comprobar el código y crear la cuenta.
 *
 * En una transacción: revalida que el correo y la cédula sigan libres (dos pestañas pueden llegar juntas),
 * crea el `User` con el rol `Paciente` —la contraseña ya viene hasheada en `RegistroPendiente`— y enlaza
 * o crea la ficha de `pacientes`. Si la ficha existía, **solo completa los campos vacíos**: no se pisa lo
 * que cargó el consultorio. La sesión la inicia el controlador después.
 */
final class ConfirmarRegistroDePaciente
{
    private const INTENTOS_MAXIMOS = 5;

    public function __construct(private RegistroPendiente $pendiente)
    {
    }

    public function ejecutar(string $codigo): User
    {
        $datos = $this->pendiente->datos();

        if ($datos === null) {
            throw ValidationException::withMessages([
                'codigo' => 'El registro venció o la sesión cambió. Vuelve a empezar.',
            ]);
        }

        if (now()->timestamp > (int) ($datos['expira'] ?? 0)) {
            $this->pendiente->olvidar();

            throw ValidationException::withMessages([
                'codigo' => 'El código venció. Vuelve a empezar para recibir uno nuevo.',
            ]);
        }

        if ((int) ($datos['intentos'] ?? 0) >= self::INTENTOS_MAXIMOS) {
            $this->pendiente->olvidar();

            throw ValidationException::withMessages([
                'codigo' => 'Demasiados intentos con ese código. Vuelve a empezar.',
            ]);
        }

        if (! Hash::check($codigo, (string) ($datos['codigo_hash'] ?? ''))) {
            $datos['intentos'] = (int) ($datos['intentos'] ?? 0) + 1;
            $this->pendiente->guardar($datos);

            throw ValidationException::withMessages([
                'codigo' => 'El código no es correcto. Revisa el mensaje y vuelve a intentarlo.',
            ]);
        }

        $user = DB::transaction(function () use ($datos) {
            if (User::whereRaw('LOWER(email) = ?', [$datos['email']])->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'Ese correo acaba de crear una cuenta. Inicia sesión.',
                ]);
            }

            $paciente = Paciente::where('cedula', $datos['cedula'])->lockForUpdate()->first();

            if ($paciente !== null && $paciente->user_id !== null && User::find($paciente->user_id) !== null) {
                throw ValidationException::withMessages([
                    'cedula' => 'Esa cédula acaba de crear una cuenta. Inicia sesión.',
                ]);
            }

            $user = User::create([
                'name' => trim($datos['nombres'] . ' ' . $datos['apellidos']),
                'email' => $datos['email'],
                'password' => $datos['password_hash'],
                'is_active' => true,
                'must_change_password' => false,
            ]);

            $user->assignRole('Paciente');

            // El código que acaba de confirmar prueba que controla este teléfono: es la primera validación (nivel 1).
            app(NivelesDeIdentidad::class)->marcarTelefonoValidado($user, (string) $datos['telefono']);

            if ($paciente === null) {
                Paciente::create([
                    'cedula' => $datos['cedula'],
                    'nombres' => $datos['nombres'],
                    'apellidos' => $datos['apellidos'],
                    'telefono' => $datos['telefono'],
                    'email' => $datos['email'],
                    'fingreso' => FechaDelConsultorio::hoy(),
                    'user_id' => $user->id,
                ]);

                return $user;
            }

            // La ficha ya existía: se enlaza y solo se completa lo que estaba vacío (la misma regla de
            // `CrearPaciente`; el consultorio pudo haber cargado un nombre o un teléfono distinto).
            $completar = [];
            foreach (['nombres', 'apellidos', 'telefono', 'email'] as $columna) {
                if (trim((string) $paciente->{$columna}) === '') {
                    $completar[$columna] = $datos[$columna];
                }
            }

            $paciente->fill($completar);
            $paciente->user_id = $user->id;
            $paciente->save();

            return $user;
        });

        $this->pendiente->olvidar();

        return $user;
    }
}
