<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Agenda\MotivosDeCita;
use App\Clinica\Agenda\Vistas\MotivosDeCitaVista;
use App\Http\Controllers\Controller;
use App\Models\MotivoCita;
use Illuminate\Http\Request;

/**
 * **Motivos (razones) de cita** (WEB-2.17): el catálogo del médico del contexto y la marca de "admite
 * varias citas pendientes", que es la excepción a la regla de la cita única.
 *
 * Vive dentro de la agenda —como los días no laborables— y **no** como módulo del menú: el menú sale de
 * `especialidad_modulo` y esto no es un módulo nuevo, es una herramienta del agendamiento. El ABM
 * completo de catálogos (alta, código, precio y sede) es WEB-8.1; acá solo se marca y se desmarca, que es
 * lo único que la excepción necesita.
 *
 * El catálogo **es de un médico**: `reg_medico` sale del contexto de trabajo y nunca del formulario, y el
 * motivo de otro médico responde 404.
 */
class MotivoCitaController extends Controller
{
    public function __construct(private MotivosDeCita $motivos)
    {
    }

    public function index(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        return view('clinica.agenda.motivos', ['vm' => new MotivosDeCitaVista(
            $this->motivos->delMedico($contexto['reg_medico']),
            $contexto['medico'],
        )]);
    }

    /**
     * Marcar o desmarcar el motivo. Es **una sola** acción porque la marca es binaria —`null` y `false`
     * significan lo mismo: no admite—, igual que el activar/desactivar de las sedes y los consultorios.
     *
     * Desmarcar vuelve a dejar la columna en `null`, que es el estado de las filas que nunca se marcaron
     * y el que traen las del escritorio: "no marcado" tiene un solo valor y deshacer la marca devuelve la
     * fila a como estaba, sin dejar un `false` que después no se distingue de la nada.
     */
    public function alternar(Request $request, MotivoCita $motivo)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $motivo = $this->motivos->encontrar($contexto['reg_medico'], $motivo->id);

        $marcado = ! (bool) $motivo->admite_varias_citas_pendientes;
        $motivo->update(['admite_varias_citas_pendientes' => $marcado ? true : null]);

        $razon = $motivo->tipo_atencion ?: $motivo->codigo;

        return redirect()
            ->route('clinica.agenda.motivos')
            ->with('estado', $marcado
                ? 'Listo: con «' . $razon . '» el paciente puede tener varias citas pendientes.'
                : 'Listo: con «' . $razon . '» se vuelve a avisar de la cita pendiente.');
    }
}
