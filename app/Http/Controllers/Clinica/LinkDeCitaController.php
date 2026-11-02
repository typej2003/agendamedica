<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Agenda\Vistas\LinkDeCitaVista;
use App\Clinica\Verificacion\GeneradorDeQr;
use App\Http\Controllers\Clinica\Concerns\ResuelveLaCitaDelContexto;
use App\Http\Controllers\Controller;
use App\Models\Cola;
use App\Models\Historia;
use App\Models\Paciente;
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
 * **No crea nada**: el link se arma al abrirse y no reserva ni cobra. El paciente sigue necesitando su código y
 * el cupo, y si la agenda cambió entre que se compartió y se abrió, la ficha lo dice.
 */
class LinkDeCitaController extends Controller
{
    use ResuelveLaCitaDelContexto;

    public function mostrar(Request $request, Cola $cola, EnlaceDeCitaCompartida $enlace)
    {
        $cita = $this->citaDelContexto($request, $cola);

        $link = $enlace->de($cita);

        return view('clinica.agenda.link-de-cita', [
            'vm' => new LinkDeCitaVista(
                $cita,
                $link,
                $link === null ? null : app(GeneradorDeQr::class)->svg($link),
                $this->nombreDelPaciente($cita),
                $enlace->fecha($cita),
                $enlace->hora($cita),
                $enlace->motivo($cita),
            ),
        ]);
    }

    /** El nombre con el que el mensaje se refiere al paciente (sin datos clínicos). */
    private function nombreDelPaciente(Cola $cita): string
    {
        // La cita enlaza al paciente por su historia (`cola.numhistoria` → `historias.paciente_id`) o, si todavía
        // no tiene número, por `paciente_sinhistoria_id`. La relación `Cola::paciente()` del legado apunta a una
        // columna que `pacientes` no tiene, así que se resuelve por el camino que sí existe.
        $paciente = $cita->paciente_sinhistoria_id
            ? Paciente::find((int) $cita->paciente_sinhistoria_id)
            : Historia::where('numhistoria', (int) $cita->numhistoria)->first()?->paciente;

        return trim((string) ($paciente?->nombres ?? '') . ' ' . (string) ($paciente?->apellidos ?? ''))
            ?: 'el paciente';
    }
}
