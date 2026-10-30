<?php

namespace App\Http\Controllers\Paciente;

use App\Http\Controllers\Controller;
use App\Notificaciones\Telefono;
use App\Paciente\ConfirmarRegistroDePaciente;
use App\Paciente\RegistroPendiente;
use App\Paciente\SolicitarCodigoDeRegistro;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * **La cuenta del paciente** (2026-10-19): formulario, código de 6 dígitos y creación de la cuenta.
 *
 * Es público (sólo invitados). El código prueba que controla el teléfono; la ficha de `pacientes` se
 * enlaza o se crea recién al confirmarlo. Después de crear la cuenta se inicia sesión y se va a
 * «Mis citas».
 */
class RegistroDePacienteController extends Controller
{
    public function formulario(Request $request)
    {
        return view('paciente.registro.formulario', [
            'cedula' => (string) $request->query('cedula', ''),
            'telefono' => (string) $request->query('telefono', ''),
        ]);
    }

    public function enviarCodigo(Request $request, SolicitarCodigoDeRegistro $accion)
    {
        $datos = $request->validate([
            'cedula'    => ['required', 'string', 'max:20'],
            'nombres'   => ['required', 'string', 'max:100'],
            'apellidos' => ['required', 'string', 'max:100'],
            'telefono'  => ['required', 'string', 'max:30'],
            'email'     => ['required', 'email', 'max:120'],
            'password'  => ['required', 'string', 'min:8', 'confirmed'],
            'reenviar'  => ['nullable', 'boolean'],
            'terminos'  => ['accepted'],
        ], $this->mensajes());

        $resultado = $accion->ejecutar($datos, $request->boolean('reenviar'));

        return redirect()
            ->route('paciente.registro.codigo')
            ->with('estado', $resultado['reenviado']
                ? 'Te enviamos un código de 6 dígitos. Escríbelo para crear tu cuenta.'
                : 'Ya te habíamos enviado el código. Escríbelo para crear tu cuenta.');
    }

    public function codigo(RegistroPendiente $pendiente)
    {
        $datos = $pendiente->datos();

        if ($datos === null) {
            return redirect()
                ->route('paciente.registro')
                ->with('error', 'El registro venció. Vuelve a empezar.');
        }

        return view('paciente.registro.codigo', [
            'pendiente' => $datos,
            'telefonoVisible' => $this->telefonoVisible((string) $datos['telefono']),
            // Sólo en local: sin WhatsApp/Twilio configurados el código se simula y se muestra para
            // poder probar el flujo. En producción nunca se pinta.
            'codigoDesarrollo' => app()->environment('local') ? ($datos['codigo'] ?? null) : null,
        ]);
    }

    public function confirmar(Request $request, ConfirmarRegistroDePaciente $accion)
    {
        $request->validate([
            'codigo' => ['required', 'digits:6'],
        ], [
            'codigo.required' => 'Escribe el código de 6 dígitos.',
            'codigo.digits' => 'El código tiene que ser de 6 dígitos.',
        ]);

        $user = $accion->ejecutar((string) $request->input('codigo'));

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()
            ->route('paciente.citas')
            ->with('estado', 'Tu cuenta está lista. Estas son tus citas.');
    }

    public function reenviar(RegistroPendiente $pendiente, SolicitarCodigoDeRegistro $accion)
    {
        $datos = $pendiente->datos();

        if ($datos === null) {
            return redirect()
                ->route('paciente.registro')
                ->with('error', 'El registro venció. Vuelve a empezar.');
        }

        $accion->reenviar($datos);

        return redirect()
            ->route('paciente.registro.codigo')
            ->with('estado', 'Te enviamos un código nuevo.');
    }

    /** @return array<string,string> */
    private function mensajes(): array
    {
        return [
            'cedula.required' => 'Escribe tu cédula.',
            'nombres.required' => 'Escribe tu nombre.',
            'apellidos.required' => 'Escribe tu apellido.',
            'telefono.required' => 'Escribe tu teléfono: te enviaremos el código ahí.',
            'email.required' => 'Escribe tu correo: es con el que vas a iniciar sesión.',
            'email.email' => 'Revisa el correo: no tiene forma de correo.',
            'password.required' => 'Elige una contraseña.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'La confirmación no coincide con la contraseña.',
            'terminos.accepted' => 'Marca que leíste y aceptas los términos y condiciones y la política de privacidad.',
        ];
    }

    private function telefonoVisible(string $telefono): string
    {
        $mostrar = Telefono::mostrar($telefono);

        if (strlen($telefono) < 8) {
            return $mostrar;
        }

        return '+' . substr($telefono, 0, 2) . ' ' . substr($telefono, 2, 3) . ' *** ' . substr($telefono, -4);
    }
}
