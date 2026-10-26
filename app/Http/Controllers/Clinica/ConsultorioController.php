<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consultorio\GuardarConsultorio;
use App\Clinica\Consultorio\ConsultoriosDelMedico;
use App\Clinica\Consultorio\SedesDeLaClinica;
use App\Http\Controllers\Controller;
use App\Models\Office;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * **Consultorios del médico** (WEB-2.8b.2): dónde atiende, con qué modalidad y con qué duración.
 *
 * Es la pieza de la que depende la agenda: `ArmadorDeAgenda` calcula la jornada con la **modalidad** de la
 * sede y su horario, y el formulario de la cita pregunta la hora solo en las de **hora de cita**.
 *
 * El consultorio **es de un médico**: `reg_medico` y `medico_id` salen siempre del contexto de trabajo, nunca del
 * formulario. Y desde el 2026-10-26 la **sede también**: la del formulario tiene que estar entre las suyas
 * (dueño, asignada o donde ya tiene un consultorio — ver `SedesDeLaClinica`), si no es un error de validación con
 * palabras. La comprobación vive acá y no en `GuardarConsultorio` porque esa Action la comparte el panel de Root,
 * que sí puede asignarle al médico una sede del catálogo.
 *
 * Desde el 2026-10-20 no tiene pantalla propia: se carga desde el panel lateral de **Sedes y consultorios**
 * (`SedeController`), y las escrituras de acá vuelven ahí.
 *
 * La baja es **desactivar** (`activo = false`): el consultorio define la jornada de las citas que ya tiene y
 * borrarlo las dejaría sin explicación. Los **bloques de horario** son de `HorarioController`.
 */
class ConsultorioController extends Controller
{
    public function __construct(private ConsultoriosDelMedico $consultorios)
    {
    }

    /**
     * La pantalla de consultorios se fusionó con la de sedes (2026-10-20): cada sede es una tarjeta con sus
     * consultorios. Esta ruta queda para no romper enlaces viejos y lleva a la pantalla unificada con lo que pedía
     * (`?editar=` abre el panel del consultorio, `?inactivos=1` es «Todas»).
     */
    public function index(Request $request)
    {
        return redirect()->route('clinica.sedes', array_filter([
            'todas'       => $request->boolean('inactivos') ? 1 : null,
            'consultorio' => $request->query('editar'),
        ], fn ($valor) => $valor !== null));
    }

    /** Alta o edición (según venga o no el id). La regla vive en `GuardarConsultorio`; la de la sede, acá. */
    public function guardar(Request $request, GuardarConsultorio $accion, SedesDeLaClinica $sedes)
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
            'medical_center_id.required' => 'Elige el consultorio donde atiendes.',
            'medical_center_id.integer'  => 'El consultorio elegido no es válido.',
            'medical_center_id.exists'   => 'El consultorio elegido no existe: elige otro.',
            'office_number.required'     => 'Pon el número o la sala donde atiendes.',
            'duracion_cita.min'          => 'La duración tiene que ser de al menos 5 minutos.',
        ]);

        // El consultorio (la sede del catálogo) tiene que ser **suyo** (dueño, asignado o donde ya tiene un
        // consultorio). Los de otros médicos no existen para él: se avisa con palabras y no con un 404, porque
        // viene de un formulario.
        $sede = $sedes->buscar($contexto, (int) $datos['medical_center_id']);

        if ($sede === null) {
            throw ValidationException::withMessages([
                'medical_center_id' => 'Ese consultorio no es tuyo: elige uno de tus consultorios.',
            ]);
        }

        if (! $sede->activo) {
            throw ValidationException::withMessages([
                'medical_center_id' => 'Ese consultorio está desactivado: actívalo antes de agregarle un número o sala.',
            ]);
        }

        $consultorio = $request->input('id') !== null
            ? $this->consultorios->encontrar($contexto, (int) $request->input('id'))
            : null;

        $accion->ejecutar($datos, $contexto['medico']->id, $contexto['reg_medico'], $consultorio);

        return $this->volver($request)
            ->with('guiado', true)
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

        return $this->volver($request)
            ->with('estado', $consultorio->activo
                ? 'Consultorio activado.'
                : 'Consultorio desactivado: deja de ofrecerse al agendar.');
    }

    /** Vuelve a la pantalla de sedes y consultorios, con «Todas» si se estaba mirando así (`ver_inactivos` es el nombre de antes). */
    private function volver(Request $request)
    {
        $todas = $request->boolean('ver_todas') || $request->boolean('ver_inactivos');

        return redirect()->route('clinica.sedes', $todas ? ['todas' => 1] : []);
    }
}
