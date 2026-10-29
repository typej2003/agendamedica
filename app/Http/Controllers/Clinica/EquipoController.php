<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Equipo\Vistas\EquipoVista;
use App\Http\Controllers\Controller;
use App\Mail\InvitacionAlEquipo;
use App\Models\InvitacionDeMedico;
use App\Models\Medico;
use App\Services\CupoDelPlan;
use App\Services\CuentaService;
use App\Services\InvitacionesDeEquipo;
use App\Services\ServicioService;
use App\Support\RestriccionesPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * **Equipo del consultorio** (2026-10-28): los médicos que comparten el `reg_medico`, las secretarías, el
 * cupo del plan y las invitaciones pendientes.
 *
 * Sólo el **médico dueño** del `reg_medico` (o un `Root`) invita, revoca y quita médicos. Un médico
 * afiliado puede ver la pantalla, pero no gestionarla. La secretaría no entra (403 por el grupo de rutas).
 *
 * Quitar a un médico borra **sólo** el vínculo de `medico_registros`: no borra su ficha, su `reg_medico`,
 * sus pacientes ni sus datos. El sistema nunca expulsa a nadie por un cambio de plan.
 */
class EquipoController extends Controller
{
    public function index(Request $request, CupoDelPlan $cupos, CuentaService $cuentas, ServicioService $servicios)
    {
        $regMedico = $this->regMedicoDelContexto($request);
        $puedeGestionar = $this->puedeGestionar($request, $regMedico);
        $puedeInvitar = $puedeGestionar && $cupos->puedeAgregarMedico($regMedico);

        $estado = $servicios->estado($regMedico);
        $restricciones = ($estado['estado'] ?? '') === ServicioService::SIN_SERVICIO
            ? null
            : RestriccionesPlan::desde($estado['restricciones'] ?? []);

        return view('clinica.equipo.index', [
            'vm' => new EquipoVista(
                $regMedico,
                $cupos->duenioDelEquipo($regMedico),
                $cupos->medicosDelEquipo($regMedico),
                $cuentas->secretariasDe([$regMedico]),
                app(InvitacionesDeEquipo::class)->pendientesDe($regMedico),
                $puedeGestionar,
                $puedeInvitar,
                ($puedeGestionar && ! $puedeInvitar) ? $this->motivoDeCupo($cupos, $regMedico) : null,
                $request->session()->get('invitacion_link'),
                $estado['plan'] ?? null,
                $restricciones?->maxMedicos(),
                $restricciones?->maxSecretarias(),
                (int) $request->user()->id,
            ),
        ]);
    }

    public function invitar(Request $request, CupoDelPlan $cupos, InvitacionesDeEquipo $invitaciones)
    {
        $regMedico = $this->regMedicoDelContexto($request);
        abort_unless($this->puedeGestionar($request, $regMedico), 403);

        $datos = $request->validate([
            'email' => 'required|string|email|max:150',
        ], [
            'email.required' => 'Escribe el correo del médico al que quieres invitar.',
            'email.email' => 'El correo no es válido.',
            'email.max' => 'El correo admite hasta 150 caracteres.',
        ]);

        $invitacion = $invitaciones->invitar($regMedico, $datos['email'], $request->user());

        $equipo = $cupos->duenioDelEquipo($regMedico)?->nombreMostrar ?: $regMedico;
        $url = route('clinica.invitaciones', ['invitacion' => $invitacion->token]);

        $enviado = true;

        try {
            Mail::to($invitacion->email)->send(new InvitacionAlEquipo($invitacion, $equipo, (string) $request->user()->name, $url));
            $mensaje = 'Invitación enviada a ' . $invitacion->email . '.';
        } catch (Throwable $e) {
            report($e);
            $enviado = false;
            $mensaje = 'La invitación quedó creada, pero el correo no salió. Copia el enlace y compártelo.';
        }

        return redirect()->route('clinica.equipo')
            ->with('invitacion_link', ['email' => $invitacion->email, 'url' => $url])
            ->with($enviado ? 'estado' : 'error', $mensaje);
    }

    public function revocar(Request $request, InvitacionDeMedico $invitacion, InvitacionesDeEquipo $invitaciones)
    {
        $regMedico = $this->regMedicoDelContexto($request);
        abort_unless((string) $invitacion->reg_medico === $regMedico, 404);
        abort_unless($this->puedeGestionar($request, $regMedico), 403);

        $invitaciones->revocar($invitacion);

        return redirect()->route('clinica.equipo')->with('estado', 'Revocaste la invitación de ' . $invitacion->email . '.');
    }

    public function quitar(Request $request, Medico $medico, CuentaService $cuentas)
    {
        $regMedico = $this->regMedicoDelContexto($request);
        abort_unless($this->puedeGestionar($request, $regMedico), 403);

        if ((string) $medico->reg_medico === $regMedico) {
            return back()->with('error', 'No puedes quitar al dueño del consultorio de su propio registro.');
        }

        try {
            $cuentas->quitarRegistro($medico, $regMedico);
        } catch (ValidationException $e) {
            return back()->with('error', $e->errors()['regNuevo'][0] ?? $e->getMessage());
        }

        return redirect()->route('clinica.equipo')->with(
            'estado',
            'Le quitaste a ' . ($medico->nombreMostrar ?: trim($medico->name . ' ' . $medico->lastname))
                . ' el acceso al equipo. No se borró nada de su ficha ni de sus pacientes.'
        );
    }

    private function regMedicoDelContexto(Request $request): string
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = is_array($contexto) ? trim((string) ($contexto['reg_medico'] ?? '')) : '';

        abort_if($regMedico === '', 403, 'Elige un consultorio para ver el equipo.');

        return $regMedico;
    }

    /** Dueño del `reg_medico` o Root. Un médico afiliado o la secretaría no gestionan el equipo. */
    private function puedeGestionar(Request $request, string $regMedico): bool
    {
        $usuario = $request->user();
        if ($usuario === null) {
            return false;
        }
        if ($usuario->hasRole('Root')) {
            return true;
        }

        $propio = $usuario->medico;

        return $propio !== null
            && ! $propio->es_centro
            && (string) $propio->reg_medico === $regMedico;
    }

    private function motivoDeCupo(CupoDelPlan $cupos, string $regMedico): ?string
    {
        try {
            $cupos->exigirCupoMedico($regMedico);

            return null;
        } catch (ValidationException $e) {
            return $e->errors()['regNuevo'][0] ?? $e->getMessage();
        }
    }
}
