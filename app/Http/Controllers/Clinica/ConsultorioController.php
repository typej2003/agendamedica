<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consultorio\GuardarConsultorio;
use App\Http\Controllers\Controller;
use App\Models\MedicalCenter;
use App\Models\Office;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * **Consultorios del médico** (WEB-2.8b.2): dónde atiende, con qué modalidad y con qué duración.
 *
 * Es la pieza de la que depende la agenda: `ArmadorDeAgenda` calcula la jornada con la **modalidad**
 * de la sede y su horario, y el formulario de la cita pregunta la hora solo en las de **hora de
 * cita**. Hasta ahora esto se sembraba; sin pantalla, un médico nuevo no podía agendar.
 *
 * A diferencia de las sedes —que son del catálogo de la clínica y se cargan sin contexto—, el
 * consultorio **es de un médico**: `reg_medico` y `medico_id` salen siempre del contexto de trabajo,
 * nunca del formulario. La ruta vive dentro del grupo que exige contexto.
 *
 * La baja es **desactivar** (`activo = false`): el consultorio define la jornada de las citas que ya
 * tiene y borrarlo las dejaría sin explicación. Los **bloques de horario** son WEB-2.8b.3.
 */
class ConsultorioController extends Controller
{
    /**
     * Los consultorios del médico del contexto, con su sede, su modalidad y sus bloques cargados.
     *
     * Los inactivos se esconden por defecto (mismo criterio que las sedes): la pantalla es para
     * trabajar, y lo dado de baja es historia.
     */
    public function index(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $verInactivos = $request->boolean('inactivos');

        $consultorios = $this->delMedico($contexto)
            ->with(['medicalCenter', 'schedules'])
            ->when(! $verInactivos, fn ($consulta) => $consulta->where('activo', true))
            ->orderBy('medical_center_id')
            ->orderBy('office_number')
            ->get();

        $editando = $request->query('editar') !== null
            ? $this->delMedico($contexto)->whereKey((int) $request->query('editar'))->firstOrFail()
            : null;

        return view('clinica.consultorios.index', [
            'consultorios' => $consultorios,
            'editando'     => $editando,
            'verInactivos' => $verInactivos,
            'sedes'        => MedicalCenter::activos()->orderBy('name')->get(),
            'modalidades'  => Office::modalidades(),
            'medico'       => $contexto['medico'],
            'duracionPorDefecto' => Office::DURACION_CITA_POR_DEFECTO,
        ]);
    }

    /** Alta o edición (según venga o no el id). La regla vive en `GuardarConsultorio`. */
    public function guardar(Request $request, GuardarConsultorio $accion)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $datos = $request->validate([
            'id'                => ['nullable', 'integer'],
            'medical_center_id' => ['required', 'integer', 'exists:medical_centers,id'],
            'office_number'     => ['required', 'string', 'max:50'],
            'phone'             => ['nullable', 'string', 'max:50'],
            'modalidad'         => ['required', 'in:' . implode(',', array_keys(Office::modalidades()))],
            'duracion_cita'     => ['nullable', 'integer', 'min:5', 'max:480'],
            'activo'            => ['nullable', 'boolean'],
        ], [
            'office_number.required' => 'Poné el número o el nombre del consultorio.',
            'duracion_cita.min'      => 'La duración tiene que ser de al menos 5 minutos.',
        ]);

        $sede = MedicalCenter::findOrFail($datos['medical_center_id']);
        if (! $sede->activo) {
            throw ValidationException::withMessages([
                'medical_center_id' => 'Esa sede está desactivada: activala antes de cargarle un consultorio.',
            ]);
        }

        $consultorio = $request->input('id') !== null
            ? $this->delMedico($contexto)->whereKey((int) $request->input('id'))->firstOrFail()
            : null;

        $accion->ejecutar($datos, $contexto['medico']->id, $contexto['reg_medico'], $consultorio);

        return redirect()
            ->route('clinica.consultorios', $request->boolean('ver_inactivos') ? ['inactivos' => 1] : [])
            ->with('estado', $consultorio !== null ? 'Consultorio actualizado.' : 'Consultorio registrado.');
    }

    /**
     * Activar o desactivar el consultorio. **No se borra**: define la modalidad, la duración y los
     * bloques con los que se calculó la jornada de las citas que ya tiene.
     */
    public function activar(Request $request, Office $consultorio)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $this->delMedico($contexto)->whereKey($consultorio->id)->firstOrFail();

        $consultorio->update(['activo' => ! $consultorio->activo]);

        return redirect()
            ->route('clinica.consultorios', $request->boolean('ver_inactivos') ? ['inactivos' => 1] : [])
            ->with('estado', $consultorio->activo
                ? 'Consultorio activado.'
                : 'Consultorio desactivado: deja de ofrecerse al agendar.');
    }

    /**
     * Los consultorios que le corresponden al médico del contexto: los de su registro o los suyos
     * por `medico_id` (las filas viejas sin `reg_medico`, igual que el scope de vigentes).
     */
    private function delMedico(array $contexto)
    {
        return Office::where(function ($consulta) use ($contexto) {
            $consulta->where('reg_medico', $contexto['reg_medico'])
                ->orWhere('medico_id', $contexto['medico']->id);
        });
    }
}
