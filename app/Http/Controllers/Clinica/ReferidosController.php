<?php

namespace App\Http\Controllers\Clinica;

use App\Http\Controllers\Controller;
use App\Models\ConfiguracionLatidos;
use App\Models\Referido;
use App\Referidos\CodigosDeReferido;
use Illuminate\Http\Request;

/**
 * **Invita y gana** (`/clinica/referidos`, fase 4): el link, el código y los referidos del consultorio.
 *
 * Sólo el médico (la secretaría no comparte el link ni ve el premio). No muestra datos clínicos: el nombre
 * del colega y el estado del premio alcanzan. El link es `/r/{codigo}`; regenerarlo invalida el anterior.
 */
class ReferidosController extends Controller
{
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
        ]);
    }

    /** Cambia el código: el link anterior deja de atribuir. */
    public function regenerar(Request $request, CodigosDeReferido $codigos)
    {
        $regMedico = $this->regMedicoDelContexto($request);
        $codigos->regenerar($regMedico);

        return redirect()->route('clinica.referidos')->with('estado', 'Link regenerado. El anterior ya no atribuye.');
    }

    private function regMedicoDelContexto(Request $request): string
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = is_array($contexto) ? trim((string) ($contexto['reg_medico'] ?? '')) : '';
        abort_if($regMedico === '', 403, 'Elige un consultorio para invitar a un colega.');

        return $regMedico;
    }
}
