<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consultorio\GuardarBloqueHorario;
use App\Clinica\Consultorio\ConsultoriosDelMedico;
use App\Clinica\Consultorio\Vistas\HorariosVista;
use App\Http\Controllers\Controller;
use App\Models\Office;
use App\Models\OfficeSchedule;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * **Bloques de horario de un consultorio** (WEB-2.8b.3): "los lunes de 8:00 a 12:00, hasta 20 pacientes".
 *
 * Es la última pieza de "dónde y cuándo atiende el médico" y de la que depende la **jornada** de la
 * agenda: `ArmadorDeAgenda` agrupa las citas por fecha + sede + bloque, el cupo se mide contra el bloque y,
 * por orden de llegada, la hora con la que nace la cita es la apertura del bloque.
 *
 * La pantalla cuelga del consultorio —no es un módulo del menú— porque los bloques son **de un consultorio
 * concreto**. Desde A-10 (revisión de Alexander) el listado de consultorios los muestra y los edita **en
 * línea** por estas mismas rutas —es lo que hace que la duración de la cita y el horario se encuentren—, y su
 * pantalla propia se conserva para cargar muchos. Como el resto de lo del médico, todo sale del contexto de
 * trabajo y un consultorio ajeno da 404. Las reglas del solape (rechazar dentro del consultorio, avisar entre
 * consultorios del mismo médico) viven en `GuardarBloqueHorario`; acá solo se valida el formato y se arma la
 * respuesta.
 */
class HorarioController extends Controller
{
    public function __construct(private ConsultoriosDelMedico $consultorios)
    {
    }

    /** Los bloques del consultorio, por día y hora, con el formulario de alta o edición. */
    public function index(Request $request, Office $consultorio)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $consultorio = $this->consultorios->delMedico($contexto)->with('medicalCenter')->whereKey($consultorio->id)->firstOrFail();

        $editando = $request->query('editar') !== null
            ? $consultorio->schedules()->whereKey((int) $request->query('editar'))->firstOrFail()
            : null;

        return view('clinica.consultorios.horarios', ['vm' => new HorariosVista(
            $consultorio,
            // Una semana se lee de lunes a domingo, no en el orden en que se cargaron los bloques.
            $consultorio->schedules()->orderBy('dia_semana')->orderBy('hora_inicio')->get(),
            $editando,
        )]);
    }

    /** Alta o edición (según venga o no el id). */
    public function guardar(Request $request, Office $consultorio, GuardarBloqueHorario $accion)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $consultorio = $this->consultorios->encontrar($contexto, $consultorio->id);

        $datos = $request->validate([
            'id'          => ['nullable', 'integer'],
            // Editar un bloque manda su `dia_semana`; el alta manda los `dias` encendidos (uno o varios).
            'dia_semana'  => ['nullable', 'integer', 'min:1', 'max:7'],
            'dias'        => ['nullable', 'array', 'max:7'],
            'dias.*'      => ['integer', 'min:1', 'max:7'],
            'hora_inicio' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'hora_fin'    => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/', 'after:hora_inicio'],
            'cupo'        => ['nullable', 'integer', 'min:1', 'max:65535'],
        ], [
            'dia_semana.min'       => 'Elige un día de la semana.',
            'dia_semana.max'       => 'Elige un día de la semana.',
            'dias.*.min'           => 'Elige un día de la semana.',
            'dias.*.max'           => 'Elige un día de la semana.',
            'hora_inicio.required' => 'Pon la hora en que empieza el bloque.',
            'hora_inicio.regex'    => 'La hora de inicio tiene que ser HH:MM (por ejemplo 08:00).',
            'hora_fin.required'    => 'Pon la hora en que termina el bloque.',
            'hora_fin.regex'       => 'La hora de fin tiene que ser HH:MM (por ejemplo 12:00).',
            'hora_fin.after'       => 'La hora de fin tiene que ser posterior a la de inicio.',
            'cupo.min'             => 'El cupo, si lo pones, tiene que ser de al menos 1 paciente.',
        ]);

        $bloque = $request->input('id') !== null
            ? $consultorio->schedules()->whereKey((int) $request->input('id'))->firstOrFail()
            : null;

        // Un bloque que ya existe es de **un** día; el alta puede traer varios («Lu a Vi»). Quien manda sólo
        // `dia_semana` (el formulario de antes, el teléfono) sigue funcionando como un alta de un día.
        $dias = $bloque !== null
            ? [(int) $datos['dia_semana']]
            : array_map('intval', $datos['dias'] ?? (isset($datos['dia_semana']) ? [$datos['dia_semana']] : []));

        if ($dias === []) {
            throw ValidationException::withMessages(['dias' => 'Elige al menos un día de la semana.']);
        }

        if ($bloque !== null || count($dias) === 1) {
            $aviso = $accion->ejecutar(['dia_semana' => $dias[0]] + $datos, $consultorio, $contexto['reg_medico'], $bloque);
            $avisos = $aviso !== null ? [$aviso] : [];
            $estado = $bloque !== null ? 'Bloque actualizado.' : 'Bloque agregado al consultorio.';
        } else {
            $avisos = $accion->ejecutarVarios($datos, $dias, $consultorio, $contexto['reg_medico']);
            $estado = count($dias) . ' bloques agregados al consultorio: '
                . implode(', ', array_map(fn ($dia) => mb_strtolower(OfficeSchedule::DIAS[$dia]), collect($dias)->sort()->values()->all())) . '.';
        }

        return $this->volver($request, $consultorio)
            ->with('guiado', true)
            ->with('estado', $estado)
            // El solape con otro consultorio del médico **no** impide guardar: se avisa y se sigue
            // (decisión del 2026-10-09). Va por `avisos` para no pisar el resultado de la operación.
            ->with('avisos', $avisos);
    }

    /**
     * Quitar un bloque. No se borra "para siempre" nada clínico: las citas que ya caían ahí siguen
     * existiendo y la agenda las muestra fuera de bloque. El modelo deja rastro en `sync_changes` para que
     * el teléfono deje de mostrar el bloque.
     */
    public function eliminar(Request $request, Office $consultorio, OfficeSchedule $bloque)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $consultorio = $this->consultorios->encontrar($contexto, $consultorio->id);

        // El bloque tiene que ser de **ese** consultorio: sin esto, la URL permitiría borrar el bloque de
        // otro consultorio del mismo médico (o de otro médico, si el id coincide).
        if ((int) $bloque->office_id !== (int) $consultorio->id) {
            abort(404);
        }

        $bloque->delete();

        return $this->volver($request, $consultorio)
            ->with('estado', 'Bloque quitado: esas horas dejan de contarse como jornada.');
    }

    /**
     * A dónde se vuelve después de escribir el bloque.
     *
     * Los bloques se cargan **en dos pantallas**: la de horarios del consultorio (`clinica.consultorios.horarios`,
     * la de siempre, para cargar muchos) y la fila del listado de consultorios, que desde A-10 los muestra y los
     * edita en línea. El formulario dice de dónde viene con un `volver` oculto, para no devolver al médico a una
     * pantalla que no estaba mirando; sin él, la vuelta es la de siempre (así la pantalla aparte y sus enlaces
     * siguen funcionando igual).
     */
    private function volver(Request $request, Office $consultorio)
    {
        if ($request->input('volver') === 'consultorios') {
            return redirect()->route('clinica.consultorios', $request->boolean('ver_inactivos') ? ['inactivos' => 1] : []);
        }

        return redirect()->route('clinica.consultorios.horarios', $consultorio);
    }
}
