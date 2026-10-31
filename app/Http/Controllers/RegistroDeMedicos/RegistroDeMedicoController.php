<?php

namespace App\Http\Controllers\RegistroDeMedicos;

use App\Http\Controllers\Controller;
use App\Models\Medico;
use App\Referidos\CodigosDeReferido;
use App\RegistroDeMedicos\CarnetDeLaSolicitud;
use App\RegistroDeMedicos\ConfirmarSolicitudDeMedico;
use App\RegistroDeMedicos\EspecialidadesDelRegistro;
use App\RegistroDeMedicos\SolicitarCodigoDeRegistroDeMedico;
use App\RegistroDeMedicos\SolicitudEnCurso;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * **Registro de médicos con aprobación** (ROADMAP §10.4, fase 2): formulario, código de 6 dígitos al correo y
 * «Solicitud recibida». Público, sólo invitados; la misma forma que el registro del paciente
 * (`RegistroDePacienteController`), pero termina en una solicitud pendiente y no en una sesión.
 */
class RegistroDeMedicoController extends Controller
{
    public function formulario(Request $request, SolicitudEnCurso $enCurso, CodigosDeReferido $codigos)
    {
        // Si vuelve atrás desde el código, el formulario aparece con lo que ya había escrito.
        $previo = $enCurso->datos() ?? [];

        // La foto ya subida se muestra como adjunta aunque el trámite no se haya guardado (el correo falló).
        if (($carnet = $enCurso->carnet()) !== null) {
            $previo = array_merge($carnet, $previo);
        }

        // Atribución: primero el `?ref=` del link, después la cookie de `/r/{codigo}` y al final lo que el
        // médico haya escrito. Un código inválido no bloquea el registro.
        $codigo = null;
        foreach ([
            $request->query('ref'),
            $request->cookie((string) config('latidos.cookie', 'doctorisimo_ref')),
            $previo['codigo_referido'] ?? null,
        ] as $candidato) {
            $codigo = $codigos->resolver($candidato);
            if ($codigo) {
                break;
            }
        }

        $referenteNombre = null;
        if ($codigo) {
            $referente = Medico::where('reg_medico', $codigo->reg_medico)->first();
            $referenteNombre = $referente
                ? trim($referente->name . ' ' . $referente->lastname)
                : null;
        }

        return view('registro-medico.formulario', [
            'previo' => $previo,
            'codigoReferido' => $codigo?->codigo ?? (string) ($previo['codigo_referido'] ?? ''),
            'referenteNombre' => $referenteNombre,
            // Sólo las especialidades que AppDDR ya atiende: el resto del catálogo todavía no se ofrece.
            'especialidades' => EspecialidadesDelRegistro::lista(),
            'carnetAceptado' => CarnetDeLaSolicitud::textosAceptados(),
            'carnetMaximo' => CarnetDeLaSolicitud::textoMaximo(),
            // En bytes: el navegador avisa antes de mandar el formulario si la foto no entra.
            'carnetMaximoBytes' => CarnetDeLaSolicitud::maximoKb() * 1024,
        ]);
    }

    public function enviarCodigo(Request $request, SolicitarCodigoDeRegistroDeMedico $accion)
    {
        // La foto va primero y aparte, y se guarda aunque después falle otro campo: el navegador vacía el campo
        // de archivo cuando el formulario vuelve con errores, así que si se validara junto al resto el médico
        // tendría que elegirla de nuevo (2026-10-24).
        $archivo = $request->file('carnet');

        if ($archivo !== null && $archivo->getError() !== UPLOAD_ERR_NO_FILE) {
            $this->exigirFotoRecibida($archivo);

            $request->validate(['carnet' => CarnetDeLaSolicitud::reglas()], $this->mensajes());

            $accion->recordarCarnet($archivo, (string) $request->input('email'));
        }

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
            'codigo_referido' => ['nullable', 'string', 'max:12'],
            'terminos'     => ['accepted'],
        ], $this->mensajes());

        $resultado = $accion->ejecutar($datos);

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

    /**
     * ¿La foto llegó entera? PHP distingue los motivos y el del formulario no: el tamaño se pasa del
     * `upload_max_filesize`, la carpeta temporal no existe o no deja escribir… Cada uno tiene su mensaje, y así
     * «la foto es muy grande» sólo se dice cuando de verdad lo es (2026-10-24).
     *
     * @throws ValidationException
     */
    private function exigirFotoRecibida(UploadedFile $archivo): void
    {
        if ($archivo->isValid()) {
            return;
        }

        $mensaje = match ($archivo->getError()) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'La foto pesa más de lo que acepta el servidor (máximo '
                . CarnetDeLaSolicitud::textoMaximo() . '). Elige una más liviana o tómala con menos resolución.',
            UPLOAD_ERR_PARTIAL => 'La foto no terminó de subir. Intenta de nuevo.',
            default => 'El servidor no pudo recibir la foto. Vuelve a intentarlo; si sigue igual, envía la '
                . 'solicitud sin la foto y adjúntala después.',
        };

        throw ValidationException::withMessages(['carnet' => $mensaje]);
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
            'terminos.accepted' => 'Marca que leíste y aceptas los términos y condiciones y la política de privacidad.',
            // Respaldo: el caso normal lo explica `exigirFotoRecibida()`, que mira el código de error de PHP.
            'carnet.uploaded' => 'No pudimos recibir la foto. Vuelve a intentarlo o elige otra imagen.',
            'carnet.max' => 'La foto pesa más de ' . CarnetDeLaSolicitud::textoMaximo() . '. Elige una más liviana.',
        ];
    }
}
