<?php

namespace App\Http\Controllers\RegistroDeMedicos;

use App\Http\Controllers\Controller;
use App\Models\Specialty;
use App\RegistroDeMedicos\CarnetDeLaSolicitud;
use App\RegistroDeMedicos\ConfirmarSolicitudDeMedico;
use App\RegistroDeMedicos\SolicitarCodigoDeRegistroDeMedico;
use App\RegistroDeMedicos\SolicitudEnCurso;
use Illuminate\Http\Request;

/**
 * **Registro de médicos con aprobación** (ROADMAP §10.4, fase 2): formulario, código de 6 dígitos al correo y
 * «Solicitud recibida». Público, sólo invitados; la misma forma que el registro del paciente
 * (`RegistroDePacienteController`), pero termina en una solicitud pendiente y no en una sesión.
 */
class RegistroDeMedicoController extends Controller
{
    public function formulario(SolicitudEnCurso $enCurso)
    {
        return view('registro-medico.formulario', [
            // Si vuelve atrás desde el código, el formulario aparece con lo que ya había escrito.
            'previo' => $enCurso->datos() ?? [],
            'especialidades' => Specialty::where('activo', true)->orderBy('name')->get(['id', 'name']),
            'carnetAceptado' => CarnetDeLaSolicitud::textosAceptados(),
            'carnetMaximo' => CarnetDeLaSolicitud::textoMaximo(),
        ]);
    }

    public function enviarCodigo(Request $request, SolicitarCodigoDeRegistroDeMedico $accion)
    {
        $datos = $request->validate([
            'prefijo'      => ['nullable', 'string', 'max:20'],
            'nombres'      => ['required', 'string', 'max:100'],
            'apellidos'    => ['required', 'string', 'max:100'],
            'cedula'       => ['required', 'string', 'max:20'],
            'mpps'         => ['required', 'string', 'max:30'],
            'colegio'      => ['nullable', 'string', 'max:30'],
            'specialty_id' => ['nullable', 'integer'],
            'telefono'     => ['required', 'string', 'max:30'],
            'email'        => ['required', 'email', 'max:120'],
            'ciudad'       => ['nullable', 'string', 'max:100'],
            'mensaje'      => ['nullable', 'string', 'max:1000'],
            'carnet'       => array_merge(['nullable'], CarnetDeLaSolicitud::reglas()),
        ], $this->mensajes());

        $resultado = $accion->ejecutar($datos, $request->file('carnet'));

        return redirect()
            ->route('medico.registro.codigo')
            ->with('estado', $resultado['reenviado']
                ? 'Te enviamos un código de 6 dígitos al correo. Escríbelo para enviar tu solicitud.'
                : 'Ya te habíamos enviado el código. Escríbelo para enviar tu solicitud.');
    }

    public function codigo(SolicitudEnCurso $enCurso)
    {
        $datos = $enCurso->datos();

        if ($datos === null) {
            return redirect()
                ->route('medico.registro')
                ->with('error', 'La solicitud venció. Vuelve a empezar.');
        }

        return view('registro-medico.codigo', [
            'email' => (string) $datos['email'],
            // Sólo en local: sin correo configurado el código se muestra para poder probar el flujo.
            'codigoDesarrollo' => app()->environment('local') ? ($datos['codigo'] ?? null) : null,
        ]);
    }

    public function confirmar(Request $request, ConfirmarSolicitudDeMedico $accion)
    {
        $request->validate([
            'codigo' => ['required', 'digits:6'],
        ], [
            'codigo.required' => 'Escribe el código de 6 dígitos.',
            'codigo.digits' => 'El código tiene que ser de 6 dígitos.',
        ]);

        $solicitud = $accion->ejecutar((string) $request->input('codigo'));

        return redirect()
            ->route('medico.registro.recibida')
            ->with('solicitud_email', $solicitud->email);
    }

    public function reenviar(SolicitudEnCurso $enCurso, SolicitarCodigoDeRegistroDeMedico $accion)
    {
        $datos = $enCurso->datos();

        if ($datos === null) {
            return redirect()
                ->route('medico.registro')
                ->with('error', 'La solicitud venció. Vuelve a empezar.');
        }

        $accion->reenviar($datos);

        return redirect()
            ->route('medico.registro.codigo')
            ->with('estado', 'Te enviamos un código nuevo.');
    }

    public function recibida(Request $request)
    {
        $email = $request->session()->get('solicitud_email');

        if ($email === null) {
            return redirect()->route('medico.registro');
        }

        return view('registro-medico.recibida', ['email' => $email]);
    }

    /** @return array<string,string> */
    private function mensajes(): array
    {
        return [
            'nombres.required' => 'Escribe tu nombre.',
            'apellidos.required' => 'Escribe tu apellido.',
            'cedula.required' => 'Escribe tu cédula.',
            'mpps.required' => 'Escribe tu número de registro del MPPS.',
            'telefono.required' => 'Escribe tu teléfono.',
            'email.required' => 'Escribe tu correo: ahí te llegan el código y la respuesta.',
            'email.email' => 'Revisa el correo: no tiene forma de correo.',
            'mensaje.max' => 'El mensaje es muy largo (hasta 1000 caracteres).',
        ];
    }
}
