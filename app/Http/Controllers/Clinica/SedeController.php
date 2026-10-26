<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consultorio\GuardarSede;
use App\Clinica\Consultorio\ConsultoriosDelMedico;
use App\Clinica\Consultorio\SedesDeLaClinica;
use App\Clinica\Consultorio\Vistas\SedesYConsultoriosVista;
use App\Http\Controllers\Controller;
use App\Models\MedicalCenter;
use App\Models\Medico;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * **Sedes y consultorios** de la web clínica (WEB-2.8b.1/2; fusionadas en una pantalla el 2026-10-20).
 *
 * Desde el 2026-10-26 la sede **es de un médico** (observación de Alexander: «cada doctor tiene sus sedes»).
 * La pantalla muestra y administra sólo **las suyas** —dueño, asignadas en `medico_medical_center` y sedes
 * donde ya tiene un consultorio, ver `SedesDeLaClinica`—, y las de otro dan **404**. Por eso se abre sin
 * contexto (`clinica.contexto:consultorios`, con el aviso): sin médico no hay sedes que mostrar ni pueden
 * crearse (una sede sin dueño no se crea desde acá).
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
     *
     * Sin contexto la pantalla sólo muestra el aviso de `avisoSinContexto()`: no hay tarjetas ni altas.
     */
    public function index(Request $request, ConsultoriosDelMedico $consultorios)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $verTodas = $request->boolean('todas');
        $sedeAEditar = $request->query('sede') ?? $request->query('editar');

        return view('clinica.sedes.index', ['vm' => new SedesYConsultoriosVista(
            $this->sedes->listar($contexto, $verTodas),
            $contexto ? $consultorios->listar($contexto, $verTodas) : null,
            $this->sedes->ciudades(),
            $verTodas,
            // Sólo se edita lo que administra: la sede de otro (o una que sólo ve por consultorio) da 404.
            $sedeAEditar !== null ? $this->sedes->encontrarAdministrable($contexto, (int) $sedeAEditar) : null,
            $contexto && $request->query('consultorio') !== null
                ? $consultorios->encontrar($contexto, (int) $request->query('consultorio'))
                : null,
            $request->query('panel'),
            $request->query('en') !== null ? (int) $request->query('en') : null,
            $request->session()->has('sede_creada') ? (int) $request->session()->get('sede_creada') : null,
            $this->sedes->totalActivas($contexto),
            $this->sedes->administrables($contexto),
        )]);
    }

    /**
     * Alta o edición (según venga o no el id), con `GuardarSede` como único lugar donde vive la regla.
     *
     * **Al crear, el dueño es el médico del contexto** y el nombre se valida contra **sus** sedes. **Sin
     * contexto no se crea nada**: se vuelve con el aviso de que hay que elegir con qué médico se trabaja.
     *
     * Si la pide el panel del consultorio («Nueva sede» al lado del selector) llega por `fetch` y responde
     * **JSON** —la sede creada, para sumarla al selector sin perder lo escrito; los errores, como 422—. Si no,
     * vuelve a la pantalla y, en un alta, deja `sede_creada` para la pregunta «¿Agregar un consultorio en esta sede?».
     */
    public function guardar(Request $request, GuardarSede $accion)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $medico = $contexto['medico'] ?? null;

        if (! $medico instanceof Medico) {
            $mensaje = 'Elige con qué médico trabajas antes de crear una sede: cada médico tiene las suyas.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $mensaje, 'errors' => ['name' => [$mensaje]]], 422);
            }

            return redirect()->route('clinica.sedes')->withErrors(['name' => $mensaje]);
        }

        $sede = $request->input('id') !== null
            ? $this->sedes->encontrarAdministrable($contexto, (int) $request->input('id'))
            : null;

        // El nombre es único **por médico**: el de la sede si tiene dueño (editar una asignada no cambia su
        // espacio de nombres) y, en el alta, el del médico del contexto.
        $duenio = $sede !== null && $sede->medico_id !== null ? (int) $sede->medico_id : (int) $medico->id;

        $datos = $request->validate([
            'id'       => ['nullable', 'integer'],
            'name'     => [
                'required', 'string', 'max:200',
                Rule::unique('medical_centers', 'name')->where('medico_id', $duenio)->ignore($sede?->id),
            ],
            'address'  => ['required', 'string', 'max:1000'],
            'phone'    => ['nullable', 'string', 'max:50'],
            'city_id'  => ['required', 'integer', 'exists:cities,id'],
            'activo'   => ['nullable', 'boolean'],
        ], [
            'name.unique'      => 'Ya tienes una sede con ese nombre. Si es la misma, edítala desde la lista.',
            'address.required' => 'La dirección es obligatoria: es lo que identifica al lugar.',
        ]);

        $guardada = $accion->ejecutar($datos, $sede, (int) $medico->id);

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
     * tiene que poder seguir leyendo el lugar. Sólo el dueño o una sede asignada: la de otro da 404.
     */
    public function activar(Request $request, MedicalCenter $sede)
    {
        $this->sedes->encontrarAdministrable($request->attributes->get('contexto_clinico'), (int) $sede->id);

        $sede->update(['activo' => ! $sede->activo]);

        return redirect()
            ->route('clinica.sedes', $request->boolean('ver_todas') ? ['todas' => 1] : [])
            ->with('estado', $sede->activo ? 'Sede activada.' : 'Sede desactivada: deja de ofrecerse al agendar.');
    }
}
