<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Latidos\Vistas\LatidosVista;
use App\Http\Controllers\Controller;
use App\Latidos\Latidos;
use App\Models\Latido;
use Illuminate\Http\Request;

/**
 * **Mis Latidos** (`/clinica/latidos`): el médico ve sus Latidos disponibles, lo retenido por pagos
 * pendientes y sus últimos movimientos. No es una wallet: no se retira ni se transfiere; se usa como
 * descuento en los planes de la plataforma.
 *
 * Sólo lectura. La compra de paquetes está apagada en el MVP; si algún día se prende, tendrá su propia
 * pantalla. El pago del plan sigue en `/clinica/plan`, que aplica los Latidos primero.
 */
class LatidosController extends Controller
{
    public function index(Request $request, Latidos $latidos)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = is_array($contexto) ? trim((string) ($contexto['reg_medico'] ?? '')) : '';
        abort_if($regMedico === '', 403, 'Elige un consultorio para ver tus Latidos.');

        $latido = Latido::where('reg_medico', $regMedico)
            ->with([
                'movimientos' => fn ($query) => $query->latest('id')->limit(50),
            ])
            ->first();

        $disponibles = $latidos->disponibles($regMedico);
        $paraUsar = $latidos->disponiblesParaUsar($regMedico);

        return view('clinica.latidos.index', [
            'vm' => new LatidosVista(
                $regMedico,
                $latido,
                $disponibles,
                $paraUsar,
                max(0, $disponibles - $paraUsar),
            ),
        ]);
    }
}
