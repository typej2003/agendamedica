<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consultorio\GuardarBloqueHorario;
use App\Http\Controllers\Controller;
use App\Models\Office;
use App\Models\OfficeSchedule;
use Illuminate\Http\Request;

/**
 * **Bloques de horario de un consultorio** (WEB-2.8b.3): "los lunes de 8:00 a 12:00, hasta 20
 * pacientes".
 *
 * Es la última pieza de "dónde y cuándo atiende el médico" y de la que depende la **jornada** de la
 * agenda: `ArmadorDeAgenda` agrupa las citas por fecha + sede + bloque, el cupo se mide contra el
 * bloque y, por orden de llegada, la hora con la que nace la cita es la apertura del bloque. Sin
 * bloques cargados la agenda igual funciona, pero cada cita cae en "mañana" o "tarde" según el
 * mediodía y no hay tope.
 *
 * La pantalla cuelga del consultorio —no es un módulo del menú— porque los bloques son **de un
 * consultorio concreto**: el mismo médico puede tener un hospital por orden de llegada en la mañana
 * y una clínica con hora de cita en la tarde. Como el resto de lo del médico, todo sale del contexto
 * de trabajo y un consultorio ajeno da 404.
 *
 * Las reglas del solape (rechazar dentro del consultorio, avisar entre consultorios del mismo médico)
 * viven en `GuardarBloqueHorario`; acá sólo se valida el formato y se arma la respuesta.
 */
class HorarioController extends Controller
{
    /** Los bloques del consultorio, por día y hora, con el formulario de alta o edición. */
    public function index(Request $request, Office $consultorio)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $consultorio = $this->delMedico($contexto)->with('medicalCenter')->whereKey($consultorio->id)->firstOrFail();

        $editando = $request->query('editar') !== null
            ? $consultorio->schedules()->whereKey((int) $request->query('editar'))->firstOrFail()
            : null;

        return view('clinica.consultorios.horarios', [
            'consultorio' => $consultorio,
            // Una semana se lee de lunes a domingo, no en el orden en que se cargaron los bloques.
            'bloques'     => $consultorio->schedules()->orderBy('dia_semana')->orderBy('hora_inicio')->get(),
            'editando'    => $editando,
            'dias'        => OfficeSchedule::DIAS,
        ]);
    }

    /** Alta o edición (según venga o no el id). */
    public function guardar(Request $request, Office $consultorio, GuardarBloqueHorario $accion)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $consultorio = $this->delMedico($contexto)->whereKey($consultorio->id)->firstOrFail();

        $datos = $request->validate([
            'id'          => ['nullable', 'integer'],
            'dia_semana'  => ['required', 'integer', 'min:1', 'max:7'],
            'hora_inicio' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'hora_fin'    => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/', 'after:hora_inicio'],
            'cupo'        => ['nullable', 'integer', 'min:1', 'max:65535'],
        ], [
            'dia_semana.required'  => 'Elegí el día de la semana.',
            'dia_semana.min'       => 'Elegí un día de la semana.',
            'dia_semana.max'       => 'Elegí un día de la semana.',
            'hora_inicio.required' => 'Poné la hora en que empieza el bloque.',
            'hora_inicio.regex'    => 'La hora de inicio tiene que ser HH:MM (por ejemplo 08:00).',
            'hora_fin.required'    => 'Poné la hora en que termina el bloque.',
            'hora_fin.regex'       => 'La hora de fin tiene que ser HH:MM (por ejemplo 12:00).',
            'hora_fin.after'       => 'La hora de fin tiene que ser posterior a la de inicio.',
            'cupo.min'             => 'El cupo, si lo ponés, tiene que ser de al menos 1 paciente.',
        ]);

        $bloque = $request->input('id') !== null
            ? $consultorio->schedules()->whereKey((int) $request->input('id'))->firstOrFail()
            : null;

        $aviso = $accion->ejecutar($datos, $consultorio, $contexto['reg_medico'], $bloque);

        return redirect()
            ->route('clinica.consultorios.horarios', $consultorio)
            ->with('estado', $bloque !== null ? 'Bloque actualizado.' : 'Bloque agregado al consultorio.')
            // El solape con otro consultorio del médico **no** impide guardar: se avisa y se sigue
            // (decisión del 2026-10-09). Va por `avisos` para no pisar el resultado de la operación.
            ->with('avisos', $aviso !== null ? [$aviso] : []);
    }

    /**
     * Quitar un bloque. No se borra "para siempre" nada clínico: las citas que ya caían ahí siguen
     * existiendo y la agenda las muestra fuera de bloque. Lo que sí hace falta es dejar rastro en
     * `sync_changes` para que el teléfono deje de mostrar el bloque (lo hace el modelo).
     */
    public function eliminar(Request $request, Office $consultorio, OfficeSchedule $bloque)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $consultorio = $this->delMedico($contexto)->whereKey($consultorio->id)->firstOrFail();

        // El bloque tiene que ser de **ese** consultorio: sin esto, la URL permitiría borrar el
        // bloque de otro consultorio del mismo médico (o de otro médico, si el id coincide).
        if ((int) $bloque->office_id !== (int) $consultorio->id) {
            abort(404);
        }

        $bloque->delete();

        return redirect()
            ->route('clinica.consultorios.horarios', $consultorio)
            ->with('estado', 'Bloque quitado: esas horas dejan de contarse como jornada.');
    }

    /** Los consultorios del médico del contexto (`Office::scopeDelMedico`); el ajeno da 404. */
    private function delMedico(array $contexto)
    {
        return Office::delMedico($contexto['reg_medico'], $contexto['medico']->id);
    }
}
