<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\FechaDelConsultorio;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Clinica\Pacientes\PacientesDelConsultorio;
use App\Clinica\Vacunas\CalendarioDeVacunas;
use App\Clinica\Vacunas\Vistas\CalendarioDeVacunasVista;
use App\Clinica\Vacunas\VacunasDelPaciente;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * **El calendario de vacunas de un niño** (WEB-7.4.4a), de sólo lectura: las dosis que le tocan según el catálogo de su médico y lo que ya se le
 * aplicó. Registrar una dosis es WEB-7.4.4b. Es clínico —sólo del médico (`role:Root|Medico`)— y de un paciente del consultorio: uno ajeno da 404.
 */
class VacunasDelPacienteController extends Controller
{
    public function __construct(
        private PacientesDelConsultorio $pacientes,
        private VacunasDelPaciente $lectura,
        private CalendarioDeVacunas $calendario,
    ) {
    }

    public function ver(Request $request, int $paciente)
    {
        $regMedico = $request->attributes->get('contexto_clinico')['reg_medico'];

        $ficha = $this->pacientes->ficha($regMedico, $paciente);
        abort_unless($ficha !== null, 404);

        $datos = $this->lectura->de($regMedico, CamposDePaciente::historiaNumerica($ficha->numhistoria));
        $filtro = CalendarioDeVacunasVista::normalizarFiltro($request->query('ver'));

        return view('clinica.vacunas.ver', [
            'vm' => new CalendarioDeVacunasVista(
                $ficha,
                $this->calendario->armar(
                    $ficha->fnacimiento ? (string) $ficha->fnacimiento : null,
                    $ficha->sexo,
                    FechaDelConsultorio::hoy()->toDateString(),
                    $datos['vacunas'],
                    $datos['dosis'],
                    $datos['aplicadas'],
                ),
                count($datos['vacunas']),
                $filtro,
                fn (string $f) => route('clinica.pacientes.vacunas', [$paciente, 'ver' => $f]),
                route('clinica.pacientes.ver', $paciente),
            ),
        ]);
    }
}
