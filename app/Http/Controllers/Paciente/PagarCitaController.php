<?php

namespace App\Http\Controllers\Paciente;

use App\Actions\Pagos\GenerarLinkDePagoDeCita;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Paciente\Concerns\ResuelveAlPaciente;
use App\Models\Cola;
use Illuminate\Http\Request;

/**
 * Pagar una cita desde «Mis citas» (punto 4 del cobro de citas): el botón «Pagar» de la ficha abre el
 * link de pago de esa cita, que es la misma pantalla pública de `/pagar/{token}`. No hay una pantalla
 * nueva: esta ruta sólo genera —o reusa— el link y redirige al token.
 *
 * La cita tiene que ser del paciente logueado: el trait `ResuelveAlPaciente` responde **404** (no 403)
 * cuando no lo es, igual que cancelar y reprogramar, para no revelar que existe.
 *
 * Los motivos por los que no hay link (`sinPrecio`, `sinMetodo`, `yaPagada` y los errores del motor) se
 * traducen en un aviso sobre «Mis citas», en vez de mostrar una pantalla rota. No se crea un pago si la
 * cita ya está pagada: eso lo resuelve `GenerarLinkDePagoDeCita`.
 */
class PagarCitaController extends Controller
{
    use ResuelveAlPaciente;

    public function mostrar(Request $request, Cola $cita, GenerarLinkDePagoDeCita $generar)
    {
        // Sólo comprueba el dueño: 404 si la cita no está entre las del paciente logueado.
        $this->citaDelPaciente($request, $cita);

        $resultado = $generar->ejecutar($cita, $request->user()?->id);

        if (! $resultado->hayLink()) {
            return redirect()
                ->route('paciente.citas')
                ->with('error', $resultado->mensaje);
        }

        return redirect()->route('pagar.mostrar', $resultado->pago->token_publico);
    }
}
