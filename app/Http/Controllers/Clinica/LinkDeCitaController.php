<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Agenda\PacientesDeCita;
use App\Clinica\Agenda\Vistas\LinkDeCitaVista;
use App\Clinica\Verificacion\GeneradorDeQr;
use App\Http\Controllers\Clinica\Concerns\ResuelveLaCitaDelContexto;
use App\Http\Controllers\Controller;
use App\Models\Cola;
use App\Reservas\EnlaceDeCitaCompartida;
use Illuminate\Http\Request;

/**
 * **Compartir una cita con el paciente** (O-52, 2026-11-02): la pantalla que muestra el link firmado de la
 * ficha pública con la fecha y el motivo ya elegidos, para copiarlo o pasarlo a un QR.
 *
 * Es la superficie de la agenda, como el link de pago: la cita es la del `reg_medico` del contexto
 * (`ResuelveLaCitaDelContexto`), así que la de otro médico responde 404, y los roles son los del grupo de la
 * agenda (médico, secretaría y Root).
 *
 * **A quién nombra**: el paciente de la cita se resuelve con la regla de la agenda
 * (`PacientesDeCita::nombreDeLaCita()`): el dueño del número de historia, acotado al registro de la cita, y el
 * ancla `paciente_sinhistoria_id` sólo cuando la cita no tiene número. Antes esta pantalla lo hacía al revés y
 * buscaba la historia **sin acotar al registro**, así que con el mismo número en dos consultorios del médico
 * nombraba al paciente del otro (trampa 145: el número es único **por registro**).
 *
 * **No crea nada**: el link se arma al abrirse y no reserva ni cobra. El paciente sigue necesitando su código y
 * el cupo, y si la agenda cambió entre que se compartió y se abrió, la ficha lo dice.
 */
class LinkDeCitaController extends Controller
{
    use ResuelveLaCitaDelContexto;

    public function mostrar(Request $request, Cola $cola, EnlaceDeCitaCompartida $enlace, PacientesDeCita $pacientes)
    {
        $cita = $this->citaDelContexto($request, $cola);

        $link = $enlace->de($cita);

        return view('clinica.agenda.link-de-cita', [
            'vm' => new LinkDeCitaVista(
                $cita,
                $link,
                $link === null ? null : app(GeneradorDeQr::class)->svg($link),
                $pacientes->nombreDeLaCita($cita),
                $enlace->fecha($cita),
                $enlace->hora($cita),
                $enlace->motivo($cita),
            ),
        ]);
    }
}
