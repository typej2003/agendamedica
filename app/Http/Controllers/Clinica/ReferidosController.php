<?php

namespace App\Http\Controllers\Clinica;

use App\Http\Controllers\Controller;
use App\Mail\InvitacionDeReferido as CorreoDeInvitacion;
use App\Models\ConfiguracionLatidos;
use App\Models\InvitacionDeReferido;
use App\Models\Medico;
use App\Models\Referido;
use App\Referidos\CodigosDeReferido;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * **Invita y gana** (`/clinica/referidos`, fase 4): el link, el código y los referidos del consultorio.
 *
 * Sólo el médico (la secretaría no comparte el link ni ve el premio). No muestra datos clínicos: el nombre
 * del colega y el estado del premio alcanzan. El link es `/r/{codigo}`; regenerarlo invalida el anterior.
 *
 * Desde O-50 (2026-11-02) también **manda la invitación por correo**: el médico escribe el correo del
 * colega y la plataforma manda el link. El tope es de 10 correos por día y por médico —sólo para lo que
 * manda la plataforma: compartir el link a mano no tiene tope— y nunca se le manda a un correo que ya es
 * de un médico registrado.
 */
class ReferidosController extends Controller
{
    /** Cuántas invitaciones por correo puede mandar un médico en un día (O-50). */
    public const INVITACIONES_POR_DIA = 10;

    public function index(Request $request, CodigosDeReferido $codigos)
    {
        $regMedico = $this->regMedicoDelContexto($request);
        $codigo = $codigos->para($regMedico);
        $config = ConfiguracionLatidos::actual();

        $referidos = Referido::with('referido')
            ->where('referente_reg_medico', $regMedico)
            ->latest('id')
            ->get();

        return view('clinica.referidos.index', [
            'regMedico' => $regMedico,
            'codigo' => $codigo,
            'config' => $config,
            'url' => url('/r/' . $codigo->codigo),
            'referidos' => $referidos,
            'totalGanado' => (int) $referidos->where('estado', Referido::ACREDITADO)->sum('latidos_referente'),
            'acreditados' => $referidos->where('estado', Referido::ACREDITADO)->count(),
            'invitaciones' => InvitacionDeReferido::where('reg_medico', $regMedico)
                ->orderByDesc('ultima_vez')
                ->limit(20)
                ->get(),
            'invitacionesHoy' => $this->invitacionesDeHoy($regMedico),
            'topeInvitaciones' => self::INVITACIONES_POR_DIA,
        ]);
    }

    /** Cambia el código: el link anterior deja de atribuir. */
    public function regenerar(Request $request, CodigosDeReferido $codigos)
    {
        $regMedico = $this->regMedicoDelContexto($request);
        $codigos->regenerar($regMedico);

        return redirect()->route('clinica.referidos')->with('estado', 'Link regenerado. El anterior ya no atribuye.');
    }

    /**
     * **Manda la invitación por correo a un colega** (O-50).
     *
     * Una por vez. Si a ese correo ya se le mandó **hoy**, no se repite (y se dice); si fue otro día, se
     * reenvía. El correo que ya es de un médico registrado no se toca: se avisa y se ofrece compartir el
     * link. El envío lo hace la plataforma, así que el remitente es el global y no se pone `reply-to`.
     */
    public function invitar(Request $request, CodigosDeReferido $codigos)
    {
        $regMedico = $this->regMedicoDelContexto($request);

        $datos = $request->validate([
            'email' => ['required', 'string', 'email', 'max:191'],
        ], [
            'email.required' => 'Escribe el correo del colega al que quieres invitar.',
            'email.email' => 'Ese correo no parece una dirección válida.',
        ]);

        $email = mb_strtolower(trim($datos['email']));

        // Un médico registrado no se invita: ya es cliente y el link no le daría nada.
        if (Medico::whereRaw('lower(email) = ?', [$email])->exists()) {
            return back()->withInput()->with('error', 'Ese correo ya es de un médico registrado en Doctorísimo. Compártele tu link y no hace falta mandarle la invitación.');
        }

        $codigo = $codigos->para($regMedico);
        $invitacion = InvitacionDeReferido::where('codigo_id', $codigo->id)->where('email', $email)->first();

        if ($invitacion?->ultima_vez !== null && $invitacion->ultima_vez->isToday()) {
            return back()->withInput()->with('error', 'A ese correo ya le mandaste la invitación hoy (' . $invitacion->ultima_vez->format('d/m/Y H:i') . '). Si quieres insistir, prueba mañana.');
        }

        if ($this->invitacionesDeHoy($regMedico) >= self::INVITACIONES_POR_DIA) {
            return back()->withInput()->with('error', 'Llegaste al tope de ' . self::INVITACIONES_POR_DIA . ' invitaciones por correo en el día. Mañana se renueva; mientras tanto, comparte tu link.');
        }

        $medico = Medico::where('reg_medico', $regMedico)->first();
        $config = ConfiguracionLatidos::actual();

        try {
            Mail::to($email)->send(new CorreoDeInvitacion(
                invitador: $this->nombreDelInvitador($medico, $request),
                url: url('/r/' . $codigo->codigo),
                latidosReferente: (int) $config->latidos_referente,
                latidosReferido: (int) $config->latidos_referido,
            ));
        } catch (\Throwable $e) {
            // El correo salió mal (SMTP caído, buzón rechazado): no se registra el envío, así el médico
            // puede reintentar sin gastar el tope del día.
            report($e);

            return back()->withInput()->with('error', 'No se pudo enviar la invitación en este momento. Vuelve a intentarlo; si sigue igual, avísale al administrador.');
        }

        $invitacion ??= new InvitacionDeReferido([
            'codigo_id' => $codigo->id,
            'reg_medico' => $regMedico,
            'email' => $email,
            'enviada_el' => now(),
        ]);

        $invitacion->veces = (int) $invitacion->veces + 1;
        $invitacion->ultima_vez = now();
        $invitacion->enviada_por = $request->user()?->id;
        $invitacion->save();

        $reintento = $invitacion->veces > 1
            ? ' Ya se le había mandado el ' . ($invitacion->enviada_el?->format('d/m/Y') ?? 'otro día') . '; este es el envío ' . $invitacion->veces . '.'
            : '';

        return back()->with('estado', 'Invitación enviada a ' . $email . '.' . $reintento);
    }

    /** Los destinatarios distintos a los que se les mandó hoy (es lo que consume el tope diario). */
    private function invitacionesDeHoy(string $regMedico): int
    {
        return InvitacionDeReferido::where('reg_medico', $regMedico)
            ->whereDate('ultima_vez', today())
            ->count();
    }

    /**
     * El nombre con el que firma la invitación: `Dr. Nombre Apellido`. `nombreMostrar` ya trae el
     * tratamiento cuando la ficha lo tiene; si no, se agrega para que la frase no empiece con un nombre
     * pelado.
     */
    private function nombreDelInvitador(?Medico $medico, Request $request): string
    {
        $nombre = trim((string) ($medico?->nombreMostrar ?: $request->user()?->name ?: ''));

        if ($nombre === '') {
            return 'Un colega de Doctorísimo';
        }

        return preg_match('/^(dr|dra)\.?\s/iu', $nombre) === 1 ? $nombre : 'Dr. ' . $nombre;
    }

    private function regMedicoDelContexto(Request $request): string
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = is_array($contexto) ? trim((string) ($contexto['reg_medico'] ?? '')) : '';
        abort_if($regMedico === '', 403, 'Elige un consultorio para invitar a un colega.');

        return $regMedico;
    }
}
