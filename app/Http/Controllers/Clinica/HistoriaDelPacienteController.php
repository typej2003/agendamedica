<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\FechaDelConsultorio;
use App\Clinica\Historia\HistoriaDelPaciente;
use App\Clinica\Historia\Vistas\HistoriaDePacienteVista;
use App\Clinica\Historia\Vistas\ResumenDeHistoriaVista;
use App\Clinica\Pacientes\PacientesDelConsultorio;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * La **historia del paciente** (WEB-3.5): sus consultas, sus antecedentes y sus documentos, con la
 * impresión del resumen. Es una pantalla de **sólo lectura**: la escritura de la consulta es F4 y la de los
 * documentos, F5.
 *
 * Controlador propio y no un método más de `PacienteController`: son dos responsabilidades distintas —la
 * ficha (filiación y contacto, que administra la secretaría) y la historia clínica (lo que pasó en las
 * consultas)—, y es la misma partición que ya hace el escritorio entre *Mantenimiento de pacientes* y la
 * consulta. El listado y la ficha siguen en `PacienteController`.
 *
 * **Quién es el paciente lo dice el vínculo** (`medico_pacientes`), y el `numhistoria` sale de ahí, no de
 * la URL: un paciente de otro `reg_medico` responde **404** sin confirmar que exista, igual que su ficha.
 */
class HistoriaDelPacienteController extends Controller
{
    public function __construct(
        private PacientesDelConsultorio $pacientes,
        private HistoriaDelPaciente $historia,
    ) {
    }

    public function ver(Request $request, int $paciente)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $ficha = $this->ficha($contexto['reg_medico'], $paciente);
        $consultas = $this->historia->consultas($contexto['reg_medico'], $ficha->numhistoria);

        return view('clinica.historia.ver', [
            'vm' => new HistoriaDePacienteVista(
                $ficha,
                $consultas,
                $this->historia->antecedentes($contexto['reg_medico'], $ficha->numhistoria),
                $this->historia->documentos($contexto['reg_medico'], $ficha->numhistoria, $consultas),
                // Las URLs las resuelve el controlador: el ViewModel se prueba sin contenedor.
                route('clinica.pacientes.ver', $paciente),
                route('clinica.pacientes.historia.imprimir', $paciente),
                // La hoja impresa de cada récipe (WEB-5.2): sólo para quien puede abrir la consulta —el médico—.
                // La secretaría imprime desde la fila de la cita en la agenda; este enlace le daría 403.
                $request->user()?->trabajaLaConsulta()
                    ? fn (int $consulta, int $numero) => route('clinica.consulta.recipes.imprimir', [$consulta, $numero])
                    : null,
            ),
        ]);
    }

    /**
     * El **"Resumen de historia clínica"** que imprime el navegador (PLAN-WEB.md, R6), como el listado del
     * día de la agenda: una vista sin el armazón de la web y un CSS con `@page`. No es el PDF del servidor.
     *
     * Un paciente sin historia llega igual a la página (no es un error: la pantalla principal ya lo dice):
     * la impresión muestra el encabezado y avisa que no hay consultas que resumir.
     */
    public function imprimir(Request $request, int $paciente)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $ficha = $this->ficha($contexto['reg_medico'], $paciente);

        return view('clinica.historia.imprimir', [
            'vm' => new ResumenDeHistoriaVista(
                $ficha,
                $contexto['medico']->nombreMostrar,
                $this->historia->consultas($contexto['reg_medico'], $ficha->numhistoria),
                // "Hoy" en la zona del consultorio, no en UTC (`FechaDelConsultorio`, WEB-2.9).
                FechaDelConsultorio::hoy(),
            ),
        ]);
    }

    /**
     * La ficha del paciente **con el número de historia del pivote**, o 404 si es de otro médico. Es la
     * misma lectura que usa la ficha (`PacientesDelConsultorio::ficha`): un solo lugar decide de quién es
     * un paciente y cómo se limpia su número.
     */
    private function ficha(string $regMedico, int $paciente): object
    {
        $ficha = $this->pacientes->ficha($regMedico, $paciente);

        abort_unless($ficha !== null, 404);

        return $ficha;
    }
}
