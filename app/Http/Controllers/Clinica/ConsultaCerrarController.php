<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Agenda\CitaNoAtendible;
use App\Actions\Consulta\CerrarConsulta;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\FechaDelConsultorio;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Models\Historia;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * **Terminar la consulta** (WEB-4.8): la acción primaria del shell, que cierra la atención y deja la cita
 * de la agenda como atendida.
 *
 * Controlador propio —y no un método más de `ConsultaController`— por la misma partición que la agenda:
 * `ConsultaController` **lee** la consulta y sus secciones; esto **escribe** sobre la cita. La regla vive
 * en la Action `App\Actions\Consulta\CerrarConsulta` (que delega la marca en la Action de agenda
 * compartida con el sync del móvil) y acá sólo se resuelve el contexto, se traduce el resultado a un
 * mensaje y se vuelve a la agenda del día.
 *
 * **Vuelve explícitamente a la agenda** y no con `back()`: terminar la atención es volver al listado del
 * día —lo que el escritorio hace al cerrar `w_consulta_principal`—, y el destino tiene que ser el mismo
 * sin depender del `Referer` (misma decisión que "Pasar a cita formal", WEB-2.11). La sede sale de la
 * consulta (la de su historia) y no del contexto, así el resultado cae en el panel donde estaba la cita.
 *
 * La consulta es de otro consultorio ⇒ **404**, como el resto de la consulta: lo resuelve
 * `ConsultasDelContexto`, la misma vara que la pantalla y las demás acciones que escriben.
 */
class ConsultaCerrarController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function cerrar(Request $request, Consulta $consulta, CerrarConsulta $accion): RedirectResponse
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $registros = $this->registrosDelMedico($contexto['medico']);

        $consulta = $this->consultas->consulta($registros, (int) $consulta->id, $contexto['reg_medico']);

        $paciente = $this->consultas->paciente($consulta, $registros);

        abort_unless($paciente !== null, 404);

        // Los datos obligatorios de la filiación (WEB-11.25): no se termina la consulta sin ellos. El médico los completa en la sección
        // «Datos personales», a la que se lo lleva. La lista depende de la especialidad (pediatría: sin cédula y con el teléfono del representante).
        $ficha = \App\Models\Paciente::find($paciente->id);
        $faltan = $ficha === null ? [] : \App\Clinica\Pacientes\FiliacionPendiente::de(
            $ficha, $contexto['reg_medico'], $paciente->numhistoria ?? null, $contexto['specialty_id'] ?? null,
        );
        if ($faltan !== []) {
            return redirect()
                ->route('clinica.consulta', [$consulta->id, 'seccion' => \App\Clinica\Consulta\Vistas\ConsultaShellVista::SECCION_DATOS])
                ->with('error', 'Antes de terminar la consulta completa los datos del paciente: ' . implode(', ', $faltan) . '.');
        }

        // El día de la consulta y su sede: el día elige la agenda a la que se vuelve y la cita que se
        // marca (dos citas del mismo paciente el mismo día en dos consultorios son dos filas distintas).
        $dia = $consulta->fecha ? Carbon::parse($consulta->fecha) : FechaDelConsultorio::hoy();
        $sede = $this->sedeDe($consulta);

        try {
            $resultado = $accion->ejecutar(
                $consulta,
                $dia,
                // El "ahora" del consultorio, resuelto una sola vez para la escritura: es la marca con la
                // que el sync decide el last-write-wins por columna frente a lo que ya subió el teléfono.
                FechaDelConsultorio::ahora(),
                (int) $paciente->id,
                $sede,
                // El médico que cierra es el del contexto: si la cita del centro no tenía doctor, queda a su
                // nombre (Fase 3 del cambio de `reg_medico`).
                'web',
                $contexto['medico'],
            );
        } catch (CitaNoAtendible $excepcion) {
            // La regla del servidor (WEB-4.8). En la web no debería pasar —sin historia no se abre una
            // consulta—, pero el mensaje tiene que decir qué hacer si pasa.
            return $this->aLaAgenda($dia, $sede)->with('error', $excepcion->getMessage());
        }

        return $this->aLaAgenda($dia, $sede)
            ->with('estado', $resultado->mensaje((string) $consulta->nroconsulta));
    }

    /**
     * La sede donde fue la consulta: la de su historia (`historias.medical_center_id`, la misma que usa
     * el resto de la web para saber dónde atiende un paciente).
     */
    private function sedeDe(Consulta $consulta): ?int
    {
        $sede = Historia::where('reg_medico', $consulta->reg_medico)
            ->where('numhistoria', (string) (int) $consulta->numhistoria)
            ->value('medical_center_id');

        return $sede === null ? null : (int) $sede;
    }

    /** La agenda del día de la consulta, con la sede (o "todas" si no se sabe). */
    private function aLaAgenda(Carbon $dia, ?int $sede): RedirectResponse
    {
        return redirect()->route('clinica.agenda', [
            'vista' => 'dia',
            'fecha' => $dia->toDateString(),
            'sede'  => $sede ?? 'todas',
        ]);
    }
}
