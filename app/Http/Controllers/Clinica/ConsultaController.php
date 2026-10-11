<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\FechaDelConsultorio;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Models\MedicoPaciente;
use App\Models\Paciente;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * **La consulta** (F4): el shell de la consulta de un paciente —el encabezado y el panel de 13 ítems del
 * legado— y, en las tareas siguientes (WEB-4.2 a 4.7), el contenido de cada ítem.
 *
 * Entrar es **siempre por una acción del médico**: "Atender" desde la agenda, que abre —o crea— la
 * consulta del día (`App\Actions\Consulta\AbrirConsulta`). Por eso no hay una ruta que liste consultas ni
 * un módulo en el menú lateral: el panel del médico es la agenda del día, y el shell se abre sobre la
 * consulta que se está atendiendo. Una lista de consultas tendría un solo elemento por día y no
 * respondería ninguna pregunta que la agenda no responda ya.
 *
 * La lectura se filtra por los `reg_medico` del contexto **y** por el vínculo del paciente
 * (`medico_pacientes`), que es lo que dice de quién es la historia: una consulta de otro consultorio
 * responde **404** sin confirmar que exista (misma regla que la ficha y la historia del paciente).
 *
 * Controlador propio y no un método más de `MostradorController`: el mostrador **escribe** sobre la cita
 * (confirmar, atender, cobrar) y esto **lee** la consulta para mostrarla. Es la misma partición que ya
 * hacen la agenda y sus acciones.
 */
class ConsultaController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function ver(Request $request, Consulta $consulta)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $registros = $this->registrosDelMedico($contexto['medico']);

        abort_unless(in_array($consulta->reg_medico, $registros, true), 404);

        $paciente = $this->pacienteDeLaHistoria($consulta, $registros);
        abort_unless($paciente !== null, 404);

        // El día de la consulta, ya interpretado una sola vez: es lo que muestra el encabezado y lo que
        // elige la agenda a la que se vuelve.
        $dia = $consulta->fecha ? Carbon::parse($consulta->fecha) : FechaDelConsultorio::hoy();

        return view('clinica.consulta.ver', [
            'vm' => new ConsultaShellVista(
                $paciente,
                (int) $consulta->nroconsulta,
                $dia->toDateString(),
                route('clinica.pacientes.ver', $paciente->id),
                route('clinica.pacientes.historia', $paciente->id),
                // Volver a la agenda del día **de la consulta**: es donde el médico la estaba atendiendo,
                // y sirve igual para la de hoy que para una anterior (una consulta vieja se abre desde la
                // historia del paciente, y volver a la agenda de su día es una respuesta válida).
                route('clinica.agenda', [
                    'vista' => 'dia',
                    'fecha' => $dia->toDateString(),
                    'sede' => 'todas',
                ]),
            ),
        ]);
    }

    /**
     * El paciente de la historia de la consulta, con el `numhistoria` **del pivote** (que es donde vive el
     * número de este médico, como texto y con sus ceros). `null` si no hay vínculo: la consulta es de otro
     * consultorio o quedó huérfana.
     *
     * @param  array<int,string>  $registros
     */
    private function pacienteDeLaHistoria(Consulta $consulta, array $registros): ?object
    {
        $vinculo = MedicoPaciente::whereIn('reg_medico', $registros)
            ->where('numhistoria', (string) (int) $consulta->numhistoria)
            ->first(['paciente_id', 'numhistoria']);

        if ($vinculo === null) {
            return null;
        }

        $paciente = Paciente::find($vinculo->paciente_id);

        if ($paciente === null) {
            return null;
        }

        return (object) [
            'id' => (int) $paciente->id,
            'nombres' => $paciente->nombres,
            'apellidos' => $paciente->apellidos,
            'numhistoria' => $vinculo->numhistoria,
        ];
    }
}
