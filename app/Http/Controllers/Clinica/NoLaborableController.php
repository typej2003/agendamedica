<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Agenda\DiasNoLaborables;
use App\Http\Controllers\Controller;
use App\Models\DiaNoLaborable;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Los **días no laborables** del consultorio (WEB-2.8): feriados, congresos, fines de semana, otro
 * consultorio y quirófano.
 *
 * Reemplaza al ABM que el escritorio tiene dentro de `w_horarios` (DataWindow
 * `d_calendar_feriados`) y es lo que alimenta el aviso de la agenda: el escritorio **aborta** el
 * agendamiento cuando el día tiene motivo, la web **avisa y deja decidir**.
 *
 * Es una pantalla de la agenda, no del panel: la secretaria es quien mueve los días del médico, y el
 * menú la alcanza desde Agenda (`/clinica/agenda/no-laborables`).
 */
class NoLaborableController extends Controller
{
    public function __construct(private DiasNoLaborables $dias)
    {
    }

    /**
     * El mes que se está mirando: sus días marcados y el formulario para agregar o editar uno.
     *
     * `?editar=<id>` abre el formulario con ese día cargado; sin él, el formulario queda en blanco
     * para el alta. Es una sola pantalla para las dos cosas —como el ABM del escritorio— porque el
     * caso es corto: una fecha, un tipo y un motivo.
     */
    public function index(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = $contexto['reg_medico'];
        $clave = $this->dias->claveDe($contexto['medico'], $regMedico);

        $mes = $this->mes($request->query('mes'));

        $editando = null;
        if ($request->query('editar') !== null) {
            $editando = $this->delContexto($regMedico, (int) $request->query('editar'));
        }

        return view('clinica.agenda.no-laborables', [
            'mes'      => $mes,
            'dias'     => $this->diasDelMes($regMedico, $clave, $mes),
            'editando' => $editando,
            'tipos'    => DiaNoLaborable::etiquetas(),
            // El médico del contexto es el dueño de la marca: no se elige en el formulario (la
            // pantalla trabaja sobre un consultorio y su médico, como el resto de `/clinica`).
            'medico'   => $contexto['medico'],
            'clave'    => $clave,
        ]);
    }

    /**
     * Guardar el día (alta o edición, según venga o no el `id`).
     *
     * La clave `(día, médico)` es única en el escritorio y acá se vuelve a comprobar: dos filas para
     * el mismo día dejarían el aviso a merced del orden de lectura.
     */
    public function guardar(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = $contexto['reg_medico'];
        $clave = $this->dias->claveDe($contexto['medico'], $regMedico);

        $datos = $request->validate([
            'id'     => ['nullable', 'integer'],
            'dia'    => ['required', 'date_format:Y-m-d'],
            'tipo'   => ['nullable', 'in:' . implode(',', DiaNoLaborable::tipos())],
            'motivo' => ['nullable', 'string', 'max:100'],
        ]);

        $existente = $this->dias->delDia($regMedico, $clave, Carbon::createFromFormat('!Y-m-d', $datos['dia']));

        // El día ya marcado se **edita**, no se duplica. Si el formulario venía editando otra fila,
        // se avisa en vez de pisar la que ya estaba.
        if ($existente !== null && (int) ($datos['id'] ?? 0) !== $existente->id) {
            throw ValidationException::withMessages([
                'dia' => 'Ese día ya está marcado. Editalo desde la lista para cambiarle el motivo.',
            ]);
        }

        $fila = $existente ?? new DiaNoLaborable();
        $fila->fill([
            'reg_medico' => $regMedico,
            'dia'        => $datos['dia'],
            'tipo'       => $datos['tipo'] ?? DiaNoLaborable::FERIADO,
            'motivo'     => $this->texto($datos['motivo'] ?? null),
            // La clave del médico sale del contexto, **nunca** del formulario: no se marca un día
            // para otro médico.
            'medico'     => $clave,
        ])->save();

        return redirect()
            ->route('clinica.agenda.no-laborables', ['mes' => substr($datos['dia'], 0, 7)])
            ->with('estado', $existente !== null ? 'Día actualizado.' : 'Día marcado como no laborable.');
    }

    /** Quitar la marca: el médico vuelve a atender ese día. */
    public function eliminar(Request $request, DiaNoLaborable $noLaborable)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $this->delContexto($contexto['reg_medico'], $noLaborable->id)->delete();

        return redirect()
            ->route('clinica.agenda.no-laborables', ['mes' => $noLaborable->dia->format('Y-m')])
            ->with('estado', 'Se quitó la marca del día.');
    }

    // ----------------------------------------------------------------------------------------- Ayudas

    /** El mes pedido (`Y-m`), o el actual. Un valor raro no rompe la pantalla. */
    private function mes(?string $valor): Carbon
    {
        try {
            return $valor ? Carbon::createFromFormat('!Y-m', $valor) : Carbon::today()->startOfMonth();
        } catch (\Throwable $e) {
            return Carbon::today()->startOfMonth();
        }
    }

    /** @return Collection<int,DiaNoLaborable> Los días marcados del mes, del 1 al último. */
    private function diasDelMes(string $regMedico, int $clave, Carbon $mes): Collection
    {
        return DiaNoLaborable::where('reg_medico', $regMedico)
            ->where('medico', $clave)
            ->whereDate('dia', '>=', $mes->copy()->startOfMonth()->toDateString())
            ->whereDate('dia', '<=', $mes->copy()->endOfMonth()->toDateString())
            ->orderBy('dia')
            ->get();
    }

    /** Un día del consultorio del contexto; el de otro `reg_medico` no se toca ni se confirma que exista. */
    private function delContexto(string $regMedico, int $id): DiaNoLaborable
    {
        return DiaNoLaborable::where('reg_medico', $regMedico)->whereKey($id)->firstOrFail();
    }

    private function texto($valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
