<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Pacientes\PacientesDelConsultorio;
use App\Clinica\Pacientes\Vistas\FichaDePacienteVista;
use App\Clinica\Pacientes\Vistas\ListadoDePacientesVista;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Pacientes del `reg_medico` del contexto: listado con búsqueda y ficha. **Sólo lectura** por ahora
 * (primer módulo implementado de la web clínica, PLAN-WEB.md F1).
 *
 * Es la pantalla de referencia de la estructura de la web clínica (Docs/Web/guia-arquitectura-web.md): el
 * controlador solo traduce HTTP, `PacientesDelConsultorio` lee y los ViewModels de `app/Clinica/Pacientes/Vistas`
 * arman lo que la vista pinta.
 */
class PacienteController extends Controller
{
    public function __construct(private PacientesDelConsultorio $pacientes)
    {
    }

    public function index(Request $request)
    {
        $regMedico = $request->attributes->get('contexto_clinico')['reg_medico'];
        $buscar = trim((string) $request->query('buscar', ''));

        return view('clinica.pacientes.index', [
            'vm' => new ListadoDePacientesVista($this->pacientes->listar($regMedico, $buscar), $buscar),
        ]);
    }

    /** JSON para el buscador de "Nueva cita": pocos pacientes del contexto que coinciden con `q`. */
    public function buscar(Request $request)
    {
        $regMedico = $request->attributes->get('contexto_clinico')['reg_medico'];

        return response()->json($this->pacientes->sugerencias($regMedico, trim((string) $request->query('q', ''))));
    }

    public function ver(Request $request, int $paciente)
    {
        $regMedico = $request->attributes->get('contexto_clinico')['reg_medico'];

        $ficha = $this->pacientes->ficha($regMedico, $paciente);

        // Un paciente de otro médico no se muestra ni se confirma que exista.
        abort_unless($ficha, 404);

        $numhistoria = $ficha->numhistoria !== null ? (int) $ficha->numhistoria : null;

        return view('clinica.pacientes.ver', [
            'vm' => new FichaDePacienteVista($ficha, $this->pacientes->consultasDe($regMedico, $numhistoria)),
        ]);
    }
}
