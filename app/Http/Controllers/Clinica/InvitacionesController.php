<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Equipo\Vistas\InvitacionesVista;
use App\Http\Controllers\Controller;
use App\Models\InvitacionDeMedico;
use App\Services\CupoDelPlan;
use App\Services\InvitacionesDeEquipo;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * **Mis invitaciones** (`/clinica/invitaciones`, 2026-10-28): lo que un médico recibió para unirse al
 * equipo de otro consultorio.
 *
 * No exige contexto clínico: un médico recién registrado, todavía sin consultorio elegido, tiene que poder
 * aceptar. El enlace del correo trae `?invitacion=<token>` y el login devuelve acá con `url.intended`.
 *
 * Sólo el destinatario (mismo correo) puede aceptar o rechazar; cualquier otro id responde 404.
 */
class InvitacionesController extends Controller
{
    public function index(Request $request, InvitacionesDeEquipo $invitaciones, CupoDelPlan $cupos)
    {
        $usuario = $request->user();
        $pendientes = $invitaciones->pendientesPara($usuario);
        $destacada = null;
        $token = trim((string) $request->query('invitacion', ''));

        if ($token !== '') {
            $invitacion = InvitacionDeMedico::where('token', $token)->first();

            abort_unless(
                $invitacion !== null && mb_strtolower(trim((string) $invitacion->email)) === mb_strtolower(trim((string) $usuario->email)),
                404
            );

            $destacada = [
                'invitacion' => $invitacion,
                'equipo' => $cupos->duenioDelEquipo((string) $invitacion->reg_medico)?->nombreMostrar ?: (string) $invitacion->reg_medico,
                'invitador' => $invitacion->invitador?->name,
            ];
        }

        $sinMedico = $usuario->medico === null
            ? 'Tu cuenta no tiene ficha de médico. Completa tu registro de médico antes de aceptar una invitación.'
            : null;

        return view('clinica.invitaciones.index', [
            'vm' => new InvitacionesVista($pendientes, $destacada, $usuario->medico !== null, $sinMedico),
        ]);
    }

    public function aceptar(Request $request, InvitacionDeMedico $invitacion, InvitacionesDeEquipo $invitaciones, CupoDelPlan $cupos)
    {
        $usuario = $request->user();
        $this->exigirDestinatario($invitacion, (string) $usuario->email);

        try {
            $invitaciones->aceptar($invitacion, $usuario);
        } catch (ValidationException $e) {
            return back()->with('error', $e->errors()['invitacion'][0] ?? $e->errors()['regNuevo'][0] ?? $e->getMessage());
        }

        $equipo = $cupos->duenioDelEquipo((string) $invitacion->reg_medico)?->nombreMostrar ?: (string) $invitacion->reg_medico;

        return redirect()->route('clinica.inicio')->with(
            'estado',
            'Ya eres parte del equipo de ' . $equipo . '. Cambia de consultorio cuando quieras para ver su agenda y sus pacientes.'
        );
    }

    public function rechazar(Request $request, InvitacionDeMedico $invitacion, InvitacionesDeEquipo $invitaciones)
    {
        $this->exigirDestinatario($invitacion, (string) $request->user()->email);

        try {
            $invitaciones->rechazar($invitacion, $request->user());
        } catch (ValidationException $e) {
            return back()->with('error', $e->errors()['invitacion'][0] ?? $e->getMessage());
        }

        return redirect()->route('clinica.invitaciones')->with('estado', 'Rechazaste la invitación de ' . $invitacion->email . '.');
    }

    private function exigirDestinatario(InvitacionDeMedico $invitacion, string $email): void
    {
        abort_unless(
            mb_strtolower(trim((string) $invitacion->email)) === mb_strtolower(trim($email)),
            404
        );
    }
}
