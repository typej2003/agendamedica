<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Pagos\GenerarLinkDePagoDeCita;
use App\Clinica\Agenda\Vistas\LinkDePagoVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLaCitaDelContexto;
use App\Http\Controllers\Controller;
use App\Models\Cola;
use App\Models\Medico;
use Illuminate\Http\Request;

/**
 * **El link de pago de una cita** (punto 3 del cobro de citas): la pantalla donde el consultorio lo genera
 * y lo comparte. Es la superficie de la agenda, no el cobro: el pago lo crea
 * `App\Actions\Pagos\GenerarLinkDePagoDeCita` con el mismo `Cobros` de la reserva y del link público.
 *
 * La pantalla **crea el link al abrirse**, y es idempotente: la Action reusa el pendiente del mismo monto.
 * No se elige un botón «Generar» porque abrir la pantalla ya es la acción que pide la secretaría desde el
 * menú de la fila, y así no hay un estado intermedio vacío. No cobra nada: sólo deja la intención
 * pendiente y muestra el token.
 *
 * La cita es la del `reg_medico` del contexto (`ResuelveLaCitaDelContexto`): la de otro médico responde
 * 404, y los roles son los del grupo de la agenda (médico, secretaría y Root).
 */
class LinkDePagoController extends Controller
{
    use ResuelveLaCitaDelContexto;

    public function mostrar(Request $request, Cola $cola, GenerarLinkDePagoDeCita $generar)
    {
        $cita = $this->citaDelContexto($request, $cola);

        $resultado = $generar->ejecutar($cita, $request->user()?->id);

        return view('clinica.agenda.link-de-pago', [
            'vm' => new LinkDePagoVista($cita, $resultado, $this->medicoDe($cita)),
        ]);
    }

    /**
     * El nombre del médico de la cita para el mensaje: el asignado (`cola.medico_id`) y, si no lo tiene, el
     * del consultorio por la relación legacy. Es la misma resolución que usa la pantalla pública del pago;
     * acá se repite porque esa es privada de su controlador y no se toca en esta tarea.
     */
    private function medicoDe(Cola $cita): ?string
    {
        $medico = $cita->medico_id
            ? Medico::find((int) $cita->medico_id)
            : $cita->medico()->first();

        $medico ??= Medico::where('reg_medico', $cita->reg_medico)->first();

        return $medico?->nombre_mostrar;
    }
}
