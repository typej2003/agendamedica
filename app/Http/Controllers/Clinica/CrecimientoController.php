<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Crecimiento\CrecimientoDelPaciente;
use App\Clinica\Crecimiento\PercentilesDeCrecimiento;
use App\Clinica\Crecimiento\ReferenciaDeCrecimiento;
use App\Clinica\Crecimiento\Vistas\CrecimientoVista;
use App\Clinica\FechaDelConsultorio;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Clinica\Pacientes\PacientesDelConsultorio;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * **El crecimiento de un niño** (WEB-7.4.3c): las gráficas de peso, talla, perímetro cefálico, IMC y peso para la talla sobre las curvas de
 * referencia, la tabla de sus mediciones y la hoja para imprimir. Sólo lee; las mediciones se cargan en la consulta (WEB-7.4.3a).
 *
 * Es del médico (grupo `role:Root|Medico`): son datos clínicos. De quién es el paciente lo dice el vínculo con el consultorio; uno ajeno da 404.
 * El módulo *Crecimiento* del menú (`/clinica/crecimiento`) no tiene pantalla propia: manda a elegir al niño, porque el crecimiento es de un paciente.
 */
class CrecimientoController extends Controller
{
    public function __construct(
        private PacientesDelConsultorio $pacientes,
        private CrecimientoDelPaciente $crecimiento,
        private ReferenciaDeCrecimiento $referencia,
    ) {
    }

    /** La entrada del menú: el crecimiento se mira desde la ficha de un niño. */
    public function entrada()
    {
        return redirect()->route('clinica.pacientes')
            ->with('estado', 'Elige al niño y abre su ficha: ahí está el botón «Crecimiento» con sus gráficas.');
    }

    public function ver(Request $request, int $paciente)
    {
        $vm = $this->vista($request, $paciente);

        return view('clinica.crecimiento.ver', ['vm' => $vm]);
    }

    public function imprimir(Request $request, int $paciente)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $vm = $this->vista($request, $paciente);

        return view('clinica.crecimiento.imprimir', [
            'vm' => $vm,
            'medico' => $contexto['medico']->nombreMostrar,
        ]);
    }

    private function vista(Request $request, int $paciente): CrecimientoVista
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = $contexto['reg_medico'];

        $ficha = $this->pacientes->ficha($regMedico, $paciente);
        abort_unless($ficha !== null, 404);

        $historia = CamposDePaciente::historiaNumerica($ficha->numhistoria);
        $hoy = FechaDelConsultorio::hoy()->toDateString();

        $grafica = CrecimientoVista::normalizarGrafica($request->query('grafica'));
        $juego = CrecimientoVista::normalizarJuego($request->query('juego'), $ficha->fnacimiento, $hoy);

        return new CrecimientoVista(
            $ficha,
            $historia === null ? collect() : $this->crecimiento->de($regMedico, $historia),
            $this->referencia->cargar($regMedico),
            $grafica,
            $juego,
            fn (string $g, string $j) => route('clinica.pacientes.crecimiento', [$paciente, 'grafica' => $g, 'juego' => $j]),
            route('clinica.pacientes.ver', $paciente),
            route('clinica.pacientes.crecimiento.imprimir', [$paciente, 'juego' => $juego]),
            $hoy,
        );
    }
}
