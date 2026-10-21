<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consultorio\GuardarSede;
use App\Clinica\Consultorio\ConsultoriosDelMedico;
use App\Clinica\Consultorio\SedesDeLaClinica;
use App\Clinica\Consultorio\Vistas\SedesYConsultoriosVista;
use App\Http\Controllers\Controller;
use App\Models\MedicalCenter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * **Sedes y consultorios** de la web clínica (WEB-2.8b.1/2; fusionadas en una pantalla el 2026-10-20).
 *
 * El lugar es del **catálogo de la clínica**, no de un médico (decisión del 2026-10-09): "Clínica
 * Metropolitana" es una sola para todos los que atienden ahí, y lo que cambia por médico es el consultorio
 * que ocupa dentro. Por eso la pantalla se abre **también sin contexto** (`clinica.contexto:opcional`): sin él
 * muestra las sedes y nada del consultorio, que sí es del médico (`ConsultorioController`).
 *
 * La baja es **desactivar** (`activo = false`), nunca borrar: `cola.medical_center_id` referencia la sede
 * y una cita vieja no puede perder su jornada.
 */
class SedeController extends Controller
{
    public function __construct(private SedesDeLaClinica $sedes)
    {
    }

    /**
     * La pantalla: tarjetas de sedes con sus consultorios y los paneles laterales. `?sede=` edita una sede
     * (`?editar=` es el nombre de antes), `?consultorio=` edita un consultorio, `?panel=sede|consultorio` abre
     * el alta y `?en=` deja la sede elegida en el consultorio nuevo.
     */
    public function index(Request $request, ConsultoriosDelMedico $consultorios)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $verTodas = $request->boolean('todas');
        $sedeAEditar = $request->query('sede') ?? $request->query('editar');

        return view('clinica.sedes.index', ['vm' => new SedesYConsultoriosVista(
            $this->sedes->listar($verTodas),
            $contexto ? $consultorios->listar($contexto, $verTodas) : null,
            $this->sedes->ciudades(),
            $verTodas,
            $sedeAEditar !== null ? $this->sedes->encontrar((int) $sedeAEditar) : null,
            $contexto && $request->query('consultorio') !== null
                ? $consultorios->encontrar($contexto, (int) $request->query('consultorio'))
                : null,
            $request->query('panel'),
            $request->query('en') !== null ? (int) $request->query('en') : null,
            $request->session()->has('sede_creada') ? (int) $request->session()->get('sede_creada') : null,
            $this->sedes->totalActivas(),
        )]);
    }

    /**
     * Alta o edición (según venga o no el id), con `GuardarSede` como único lugar donde vive la regla.
     *
     * Si la pide el panel del consultorio («Nueva sede» al lado del selector) llega por `fetch` y responde
     * **JSON** —la sede creada, para sumarla al selector sin perder lo escrito; los errores, como 422—. Si no,
     * vuelve a la pantalla y, en un alta, deja `sede_creada` para la pregunta «¿Agregar un consultorio en esta sede?».
     */
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

        $guardada = $accion->ejecutar($datos, $sede);

        if ($request->expectsJson()) {
            return response()->json([
                'id'     => $guardada->id,
                'nombre' => $guardada->name,
                'estado' => $sede !== null ? 'Sede actualizada.' : 'Sede registrada.',
            ], $sede !== null ? 200 : 201);
        }

        $vuelta = redirect()
            ->route('clinica.sedes', $request->boolean('ver_todas') ? ['todas' => 1] : [])
            ->with('guiado', true)
            ->with('estado', $sede !== null ? 'Sede actualizada.' : 'Sede registrada.');

        return $sede === null ? $vuelta->with('sede_creada', $guardada->id) : $vuelta;
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
