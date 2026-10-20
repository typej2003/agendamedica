<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Pacientes\SembrarTiposDeAntecedentePediatricos;
use App\Clinica\Consulta\AntecedentesDelPaciente;
use App\Clinica\Consulta\Vistas\SeccionDeAntecedentesVista;
use App\Clinica\Crecimiento\CrecimientoDelPaciente;
use App\Clinica\Crecimiento\ReferenciaDeCrecimiento;
use App\Clinica\Crecimiento\Vistas\CrecimientoVista;
use App\Clinica\FechaDelConsultorio;
use App\Clinica\Historia\Vistas\HistoriaPediatricaVista;
use App\Clinica\Impresion\MembreteDelMedico;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Clinica\Pacientes\FamiliaDelPaciente;
use App\Clinica\Pacientes\PacientesDelConsultorio;
use App\Clinica\Pacientes\Vistas\FamiliaDePacienteVista;
use App\Clinica\Vacunas\CalendarioDeVacunas;
use App\Clinica\Vacunas\Vistas\CarnetDeVacunacionVista;
use App\Clinica\Vacunas\VacunasDelPaciente;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * **La historia pediátrica imprimible** (WEB-12.5): la hoja A4 con el membrete del médico, la familia, los antecedentes por categoría, las mediciones y las vacunas del niño.
 * Es clínica —sólo del médico (`role:Root|Medico`)— y de un paciente del consultorio (uno ajeno da 404). Sólo lee: compone lo que ya leen la ficha, la consulta, el
 * crecimiento y el carnet de vacunación.
 */
class HistoriaPediatricaController extends Controller
{
    public function __construct(private PacientesDelConsultorio $pacientes)
    {
    }

    public function imprimir(
        Request $request,
        int $paciente,
        MembreteDelMedico $membretes,
        FamiliaDelPaciente $familias,
        AntecedentesDelPaciente $antecedentes,
        VacunasDelPaciente $vacunas,
        CalendarioDeVacunas $calendario,
        CrecimientoDelPaciente $crecimiento,
        ReferenciaDeCrecimiento $referencia,
    ) {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = $contexto['reg_medico'];

        $ficha = $this->pacientes->ficha($regMedico, $paciente);
        abort_unless($ficha !== null, 404);

        $historia = CamposDePaciente::historiaNumerica($ficha->numhistoria);
        $hoy = FechaDelConsultorio::hoy()->toDateString();
        $volver = route('clinica.pacientes.ver', $paciente);

        // Los tipos de antecedente de pediatría (Patológicos, C.A., Madre…) con el nombre que tiene el catálogo del médico.
        app(SembrarTiposDeAntecedentePediatricos::class)->ejecutar($regMedico);

        $datos = $vacunas->de($regMedico, $historia);
        $armado = $calendario->armar($ficha->fnacimiento ? (string) $ficha->fnacimiento : null, $ficha->sexo, $hoy, $datos['vacunas'], $datos['dosis'], $datos['aplicadas']);
        $datosDeLaFamilia = $familias->de($regMedico, $ficha->numhistoria);

        return view('clinica.historia.pediatrica', ['vm' => new HistoriaPediatricaVista(
            // El membrete es el del **formato del consultorio** de la historia (Fase 5 del cambio de `reg_medico`): el
            // del centro si él lo eligió y el del doctor si no.
            new CarnetDeVacunacionVista($membretes->deLaImpresion($contexto['medico'], $regMedico), $ficha, $datos['aplicadas'], $armado, null, $hoy, $volver),
            new FamiliaDePacienteVista($datosDeLaFamilia, fn (int $id) => route('clinica.pacientes.ver', $id), null, true),
            $historia === null ? collect() : $antecedentes->delPaciente($regMedico, $ficha->numhistoria),
            new CrecimientoVista(
                $ficha,
                $historia === null ? collect() : $crecimiento->de($regMedico, $historia),
                $referencia->cargar($regMedico),
                CrecimientoVista::POR_DEFECTO,
                '0-5',
                fn (string $g, string $j) => $volver,
                $volver,
                '',
                $hoy,
            ),
            $volver,
            $request->boolean('incrustado'),
        )]);
    }
}
