<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Agenda\EliminarCita;
use App\Actions\Pacientes\CambiarCedula;
use App\Clinica\Pacientes\CitasDelPaciente;
use App\Clinica\Pacientes\PacientesDelConsultorio;
use App\Clinica\Pacientes\Vistas\CitasDelPacienteVista;
use App\Http\Controllers\Controller;
use App\Models\Cola;
use App\Models\Paciente;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Las citas de **un paciente** (`/clinica/pacientes/{paciente}/citas`): el botón "Cita x Paciente" del
 * escritorio (`w_hacer_cita.srw:1039` → `w_listado_pacientes_a_citas`, WEB-2.15).
 *
 * Controlador delgado: lee el contexto, comprueba que el paciente sea del `reg_medico` del contexto
 * —uno ajeno da 404, como la ficha—, llama a la consulta o a la Action y devuelve la vista o un redirect.
 *
 * Las dos acciones de fila **no viven acá**: "Modificar" es el alta de edición de la agenda
 * (`clinica.agenda.editar`) y "Eliminar" es `EliminarCita` (WEB-2.3), que es la que aplica la regla
 * (no se elimina una cita atendida ni una con pago). Lo único propio de esta pantalla es el **cambio de
 * cédula**, que va por su Action con su validación. El "Borrar Paciente" de la fuente **no se implementa**
 * a propósito: ver la nota de la Action.
 */
class CitasDelPacienteController extends Controller
{
    public function __construct(
        private PacientesDelConsultorio $pacientes,
        private CitasDelPaciente $citas,
    ) {
    }

    public function index(Request $request, int $paciente)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $ficha = $this->pacientes->ficha($contexto['reg_medico'], $paciente);

        abort_unless($ficha, 404);

        // El vínculo con el médico es el que tiene el número de historia (y es por médico); la cita
        // puede estar enganchada además por `paciente_sinhistoria_id` si el paciente todavía no tiene uno.
        $numHistoria = $ficha->numhistoria !== null ? (int) $ficha->numhistoria : null;

        return view('clinica.pacientes.citas', [
            'vm' => new CitasDelPacienteVista(
                $ficha,
                $this->citas->todas($contexto['reg_medico'], $numHistoria, (int) $ficha->id),
            ),
        ]);
    }

    /**
     * El cambio de cédula ("Cédula actual → modificada" de la fuente, `:255-294`): la validación
     * —incluida la cédula que ya es de otro paciente— vive en `CambiarCedula`. Su error se vuelve un
     * error de validación para que la pantalla lo muestre arriba, como cualquier otro.
     */
    public function cambiarCedula(Request $request, int $paciente, CambiarCedula $cambiar)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $ficha = $this->pacientes->ficha($contexto['reg_medico'], $paciente);

        abort_unless($ficha, 404);

        $datos = $request->validate([
            'cedula' => ['required', 'string', 'max:' . CambiarCedula::LARGO_MAXIMO],
        ]);

        try {
            $cambiar->ejecutar(Paciente::findOrFail($ficha->id), $datos['cedula']);
        } catch (InvalidArgumentException $excepcion) {
            throw ValidationException::withMessages(['cedula' => $excepcion->getMessage()]);
        }

        return redirect()
            ->route('clinica.pacientes.citas', $ficha->id)
            ->with('estado', 'Cédula actualizada a ' . trim($datos['cedula']) . '.');
    }

    /**
     * Eliminar una cita **desde esta pantalla**: la misma Action que la agenda —una sola regla, escrita
     * una sola vez—, con la diferencia de volver acá y no a la agenda. El parámetro se llama `$cola`
     * porque así se llama en la ruta y en el resto de la web (`clinica.agenda.eliminar`): Laravel resuelve
     * el modelo por el **nombre** del parámetro, así que tiene que coincidir.
     */
    public function eliminar(Request $request, int $paciente, Cola $cola, EliminarCita $eliminar)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $ficha = $this->pacientes->ficha($contexto['reg_medico'], $paciente);

        abort_unless($ficha, 404);

        // La cita tiene que ser de este médico y de este paciente: sin lo segundo, cambiar el id de la
        // URL alcanzaría para borrar la cita de cualquier otro. El paciente se busca como en el listado:
        // por `numhistoria` —el camino normal— o por `paciente_sinhistoria_id` —una cita de un paciente al
        // que todavía no se le asignó número—, que es como queda la cita de un paciente recién agendado.
        $esDeEsteMedico = $cola->reg_medico === $contexto['reg_medico'];
        $esDeEstePaciente = ($ficha->numhistoria !== null && (int) $cola->numhistoria === (int) $ficha->numhistoria)
            || (int) $cola->paciente_sinhistoria_id === (int) $ficha->id;

        abort_unless($esDeEsteMedico && $esDeEstePaciente, 404);

        $destino = redirect()->route('clinica.pacientes.citas', $ficha->id);

        try {
            $eliminar->ejecutar($cola);
        } catch (InvalidArgumentException $excepcion) {
            return $destino->with('error', $excepcion->getMessage());
        }

        return $destino->with('estado', 'Cita eliminada.');
    }
}
