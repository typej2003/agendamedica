<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consultorio\GuardarConsultorio;
use App\Clinica\Consultorio\ConsultoriosDelMedico;
use App\Clinica\Consultorio\Vistas\ConsultoriosVista;
use App\Http\Controllers\Controller;
use App\Models\MedicalCenter;
use App\Models\Office;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * **Consultorios del médico** (WEB-2.8b.2): dónde atiende, con qué modalidad y con qué duración.
 *
 * Es la pieza de la que depende la agenda: `ArmadorDeAgenda` calcula la jornada con la **modalidad** de la
 * sede y su horario, y el formulario de la cita pregunta la hora solo en las de **hora de cita**.
 *
 * A diferencia de las sedes —que son del catálogo de la clínica—, el consultorio **es de un médico**:
 * `reg_medico` y `medico_id` salen siempre del contexto de trabajo, nunca del formulario.
 *
 * La baja es **desactivar** (`activo = false`): el consultorio define la jornada de las citas que ya tiene y
 * borrarlo las dejaría sin explicación. Los **bloques de horario** son de `HorarioController`.
 */
class ConsultorioController extends Controller
{
    public function __construct(private ConsultoriosDelMedico $consultorios)
    {
    }

    public function index(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $verInactivos = $request->boolean('inactivos');

        return view('clinica.consultorios.index', ['vm' => new ConsultoriosVista(
            $this->consultorios->listar($contexto, $verInactivos),
            $request->query('editar') !== null ? $this->consultorios->encontrar($contexto, (int) $request->query('editar')) : null,
            $verInactivos,
            $this->consultorios->sedesActivas(),
            $contexto['medico'],
        )]);
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
            'office_number.required' => 'Pon el número o el nombre del consultorio.',
            'duracion_cita.min'      => 'La duración tiene que ser de al menos 5 minutos.',
        ]);

        $sede = MedicalCenter::findOrFail($datos['medical_center_id']);
        if (! $sede->activo) {
            throw ValidationException::withMessages([
                'medical_center_id' => 'Esa sede está desactivada: actívala antes de cargarle un consultorio.',
            ]);
        }

        $consultorio = $request->input('id') !== null
            ? $this->consultorios->encontrar($contexto, (int) $request->input('id'))
            : null;

        $accion->ejecutar($datos, $contexto['medico']->id, $contexto['reg_medico'], $consultorio);

        return redirect()
            ->route('clinica.consultorios', $request->boolean('ver_inactivos') ? ['inactivos' => 1] : [])
            ->with('estado', $consultorio !== null ? 'Consultorio actualizado.' : 'Consultorio registrado.');
    }

    /**
     * Activar o desactivar el consultorio. **No se borra**: define la modalidad, la duración y los bloques
     * con los que se calculó la jornada de las citas que ya tiene.
     */
    public function activar(Request $request, Office $consultorio)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $this->consultorios->encontrar($contexto, $consultorio->id);

        $consultorio->update(['activo' => ! $consultorio->activo]);

        return redirect()
            ->route('clinica.consultorios', $request->boolean('ver_inactivos') ? ['inactivos' => 1] : [])
            ->with('estado', $consultorio->activo
                ? 'Consultorio activado.'
                : 'Consultorio desactivado: deja de ofrecerse al agendar.');
    }
}
