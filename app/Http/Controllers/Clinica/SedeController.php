<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consultorio\GuardarSede;
use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\MedicalCenter;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * **Sedes** de la web clínica (WEB-2.8b.1): el lugar donde atiende un médico.
 *
 * Es la primera mitad de la configuración de agenda que el escritorio tiene en `w_horarios` y que hoy
 * sólo se sembraba. La segunda mitad —el consultorio del médico con su modalidad, duración y bloques
 * de horario— es WEB-2.8b.2/3.
 *
 * El lugar es del **catálogo de la clínica**, no de un médico (decisión del 2026-10-09): "Clínica
 * Metropolitana" es una sola para todos los que atienden ahí, y lo que cambia por médico es el
 * consultorio que ocupa dentro. Por eso acá no hay `reg_medico` y la pantalla no depende del contexto
 * de trabajo: cualquier usuario del consultorio puede dar de alta el lugar donde va a atender.
 *
 * La baja es **desactivar** (`activo = false`), nunca borrar: `cola.medical_center_id` referencia la
 * sede y una cita vieja no puede perder su jornada.
 */
class SedeController extends Controller
{
    /**
     * El listado con los dos datos que hacen falta para decidir: dónde está y **cuántos médicos
     * atienden ahí** (el consultorio activo cuelga de la sede). Una sede sin consultorios es una sede
     * cargada y sin usar, que es justo lo que hay que ver antes de desactivarla.
     */
    public function index(Request $request)
    {
        $verTodas = $request->boolean('todas');

        $sedes = MedicalCenter::with('city')
            ->withCount(['offices as consultorios_activos' => fn ($consulta) => $consulta->where('activo', true)])
            ->when(! $verTodas, fn ($consulta) => $consulta->where('activo', true))
            ->orderBy('name')
            ->get();

        $editando = $request->query('editar') !== null
            ? MedicalCenter::findOrFail((int) $request->query('editar'))
            : null;

        return view('clinica.sedes.index', [
            'sedes'      => $sedes,
            'editando'   => $editando,
            'verTodas'   => $verTodas,
            'ciudades'   => $this->ciudades(),
            'totalActivas' => MedicalCenter::where('activo', true)->count(),
        ]);
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
            'name.unique'   => 'Ya hay una sede con ese nombre. Si es la misma, editala desde la lista.',
            'address.required' => 'La dirección es obligatoria: es lo que identifica al lugar.',
        ]);

        $sede = $request->input('id') !== null
            ? MedicalCenter::findOrFail((int) $request->input('id'))
            : null;

        $accion->ejecutar($datos, $sede);

        return redirect()
            ->route('clinica.sedes', $request->boolean('ver_todas') ? ['todas' => 1] : [])
            ->with('estado', $sede !== null ? 'Sede actualizada.' : 'Sede registrada.');
    }

    /**
     * Activar o desactivar la sede. **No se borra**: la cita guarda `medical_center_id` y el
     * historial tiene que poder seguir leyendo el lugar.
     */
    public function activar(Request $request, MedicalCenter $sede)
    {
        $sede->update(['activo' => ! $sede->activo]);

        return redirect()
            ->route('clinica.sedes', $request->boolean('ver_todas') ? ['todas' => 1] : [])
            ->with('estado', $sede->activo ? 'Sede activada.' : 'Sede desactivada: deja de ofrecerse al agendar.');
    }

    /**
     * Las ciudades con su estado y su país, ya ordenadas para el `<select>`.
     *
     * Son ~30 filas sembradas (las del catálogo de Venezuela), así que se sirven enteras: un buscador
     * por Ajax para esto agregaría una ruta y una consulta para ahorrar nada.
     *
     * @return Collection<int,City>
     */
    private function ciudades(): Collection
    {
        return City::with('state.country')
            ->get()
            ->sortBy(fn (City $ciudad) => ($ciudad->state->name ?? '') . ' ' . $ciudad->name)
            ->values();
    }
}
