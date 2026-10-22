<?php

namespace App\Http\Controllers\Paciente;

use App\Http\Controllers\Controller;
use App\Notificaciones\Telefono;
use App\Paciente\ConfirmarTelefonoDelPaciente;
use App\Paciente\NivelesDeIdentidad;
use App\Paciente\SolicitarCodigoDeTelefono;
use App\Paciente\TelefonoPendiente;
use Illuminate\Http\Request;

/**
 * **Confirmar o cambiar el teléfono** de la cuenta cuando su validación venció (12 meses). Mismo código de 6 dígitos
 * que el registro; al terminar vuelve a donde iba (reprogramar). Los dos pasos viven en `App\Paciente`.
 */
class TelefonoDelPacienteController extends Controller
{
    public function formulario(Request $request, TelefonoPendiente $pendiente, NivelesDeIdentidad $niveles)
    {
        $user = $request->user();
        $datos = $pendiente->datos();
        $enCurso = $datos !== null && (int) ($datos['user_id'] ?? 0) === (int) $user->id ? $datos : null;

        return view('paciente.telefono', [
            'vigente' => $niveles->telefonoVigente($user),
            'telefonoActual' => $this->enFormatoLocal($user->telefono_validado ?: ($user->paciente?->telefono ?? '')),
            'enCurso' => $enCurso,
            'telefonoVisible' => $enCurso ? Telefono::mostrar($enCurso['telefono']) : null,
            'codigoDesarrollo' => $enCurso && app()->environment('local') ? ($enCurso['codigo'] ?? null) : null,
        ]);
    }

    public function enviarCodigo(Request $request, SolicitarCodigoDeTelefono $accion)
    {
        $datos = $request->validate(['telefono' => ['required', 'string', 'max:30'], 'reenviar' => ['nullable', 'boolean']], [
            'telefono.required' => 'Escribe tu teléfono: te enviaremos el código ahí.',
        ]);

        $resultado = $accion->ejecutar($request->user(), $datos['telefono'], $request->boolean('reenviar'));

        return redirect()
            ->route('paciente.telefono')
            ->with('estado', $resultado['reenviado'] ? 'Te enviamos un código de 6 dígitos. Escríbelo para continuar.' : 'Ya te habíamos enviado el código. Escríbelo para continuar.');
    }

    public function confirmar(Request $request, ConfirmarTelefonoDelPaciente $accion)
    {
        $request->validate(['codigo' => ['required', 'digits:6']], [
            'codigo.required' => 'Escribe el código de 6 dígitos.',
            'codigo.digits' => 'El código tiene que ser de 6 dígitos.',
        ]);

        $accion->ejecutar($request->user(), (string) $request->input('codigo'));

        $volver = $request->session()->pull('telefono.volver');

        return redirect($volver ?: route('paciente.citas'))->with('estado', 'Teléfono confirmado. Ya puedes reservar.');
    }

    /** `584142100020` → `04142100020`, el formato en que se escribe; lo que no se reconoce queda como está. */
    private function enFormatoLocal(string $telefono): string
    {
        $internacional = Telefono::internacional($telefono);

        return $internacional !== null ? '0' . substr($internacional, 2) : $telefono;
    }
}
