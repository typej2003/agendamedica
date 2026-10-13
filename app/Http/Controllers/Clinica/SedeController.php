<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consultorio\GuardarSede;
use App\Clinica\Consultorio\SedesDeLaClinica;
use App\Clinica\Consultorio\Vistas\SedesVista;
use App\Http\Controllers\Controller;
use App\Models\MedicalCenter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * **Sedes** de la web clínica (WEB-2.8b.1): el lugar donde atiende un médico.
 *
 * El lugar es del **catálogo de la clínica**, no de un médico (decisión del 2026-10-09): "Clínica
 * Metropolitana" es una sola para todos los que atienden ahí, y lo que cambia por médico es el consultorio
 * que ocupa dentro. Por eso acá no hay `reg_medico` y la pantalla no depende del contexto de trabajo.
 *
 * La baja es **desactivar** (`activo = false`), nunca borrar: `cola.medical_center_id` referencia la sede
 * y una cita vieja no puede perder su jornada.
 */
class SedeController extends Controller
{
    public function __construct(private SedesDeLaClinica $sedes)
    {
    }

    public function index(Request $request)
    {
        $verTodas = $request->boolean('todas');

        return view('clinica.sedes.index', ['vm' => new SedesVista(
            $this->sedes->listar($verTodas),
            $request->query('editar') !== null ? $this->sedes->encontrar((int) $request->query('editar')) : null,
            $verTodas,
            $this->sedes->ciudades(),
            $this->sedes->totalActivas(),
        )]);
    }

    /** Alta o edición (según venga o no el id), con `GuardarSede` como único lugar donde vive la regla. */
    public function guardar(Request $request, GuardarSede $accion)
    {
        $datos = $request->validate([
            'id'       => ['nullable', 'integer'],
            'name'     => [
                'required', 'string', 'max:200',
                Rule::unique('medical_centers', 'name')->ignore($request->input('id')),
            ],
            'address'  => ['required', 'string', 'max:1000'],
            'phone'    => ['nullable', 'string', 'max:50'],
            'city_id'  => ['required', 'integer', 'exists:cities,id'],
            'activo'   => ['nullable', 'boolean'],
        ], [
            'name.unique'      => 'Ya hay una sede con ese nombre. Si es la misma, edítala desde la lista.',
            'address.required' => 'La dirección es obligatoria: es lo que identifica al lugar.',
        ]);

        $sede = $request->input('id') !== null
            ? $this->sedes->encontrar((int) $request->input('id'))
            : null;

        $accion->ejecutar($datos, $sede);

        return redirect()
            ->route('clinica.sedes', $request->boolean('ver_todas') ? ['todas' => 1] : [])
            ->with('estado', $sede !== null ? 'Sede actualizada.' : 'Sede registrada.');
    }

    /**
     * Activar o desactivar la sede. **No se borra**: la cita guarda `medical_center_id` y el historial
     * tiene que poder seguir leyendo el lugar.
     */
    public function activar(Request $request, MedicalCenter $sede)
    {
        $sede->update(['activo' => ! $sede->activo]);

        return redirect()
            ->route('clinica.sedes', $request->boolean('ver_todas') ? ['todas' => 1] : [])
            ->with('estado', $sede->activo ? 'Sede activada.' : 'Sede desactivada: deja de ofrecerse al agendar.');
    }
}
