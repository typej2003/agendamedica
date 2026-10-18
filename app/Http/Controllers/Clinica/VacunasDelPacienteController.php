<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Vacunas\RegistrarDosisDeVacuna;
use App\Clinica\FechaDelConsultorio;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Clinica\Pacientes\PacientesDelConsultorio;
use App\Clinica\Vacunas\CalendarioDeVacunas;
use App\Clinica\Impresion\MembreteDelMedico;
use App\Clinica\Pacientes\FamiliaDelPaciente;
use App\Clinica\Vacunas\Vistas\CalendarioDeVacunasVista;
use App\Clinica\Vacunas\Vistas\CarnetDeVacunacionVista;
use App\Clinica\Vacunas\VacunasDelPaciente;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **El calendario de vacunas de un niño** (WEB-7.4.4a) y el registro de sus dosis (WEB-7.4.4b): las dosis que le tocan según el catálogo de su médico y lo que ya se le
 * aplicó. Es clínico —sólo del médico (`role:Root|Medico`)— y de un paciente del consultorio: uno ajeno da 404.
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
                // Registrar una dosis (WEB-7.4.4b) exige la clave de la historia (`vacuna_paciente.historia`): sin número no se ofrece.
                $ficha->numhistoria === null ? null : route('clinica.pacientes.vacunas.registrar', $paciente),
                FechaDelConsultorio::hoy()->toDateString(),
                route('clinica.pacientes.vacunas.carnet', $paciente),
            ),
        ]);
    }

    /**
     * **El carnet de vacunación** (WEB-7.4.4c): la hoja A4 con el membrete del médico, las dosis aplicadas y las que faltan. La imprime el
     * navegador; con `?incrustado=1` va sin barra, para la vista previa («Visualizar»).
     */
    public function carnet(Request $request, int $paciente, MembreteDelMedico $membretes, FamiliaDelPaciente $familias)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = $contexto['reg_medico'];

        $ficha = $this->pacientes->ficha($regMedico, $paciente);
        abort_unless($ficha !== null, 404);

        $datos = $this->lectura->de($regMedico, CamposDePaciente::historiaNumerica($ficha->numhistoria));
        $hoy = FechaDelConsultorio::hoy()->toDateString();
        $familia = $familias->de($regMedico, $ficha->numhistoria)['familia'] ?? null;

        return view('clinica.vacunas.carnet', [
            'vm' => new CarnetDeVacunacionVista(
                $membretes->de($contexto['medico']),
                $ficha,
                $datos['aplicadas'],
                $this->calendario->armar($ficha->fnacimiento ? (string) $ficha->fnacimiento : null, $ficha->sexo, $hoy, $datos['vacunas'], $datos['dosis'], $datos['aplicadas']),
                $familia === null ? null : (collect([$familia->nom_madre ?? null, $familia->nom_padre ?? null])
                    ->map(fn ($n) => CamposDePaciente::texto($n))->filter()->implode(' y ') ?: null),
                $hoy,
                route('clinica.pacientes.vacunas', $paciente),
                $request->boolean('incrustado'),
            ),
        ]);
    }

    /** **Registrar una dosis aplicada** (WEB-7.4.4b). La regla es la Action `RegistrarDosisDeVacuna`; acá sólo se traduce HTTP. */
    public function registrar(Request $request, int $paciente, RegistrarDosisDeVacuna $accion)
    {
        $regMedico = $request->attributes->get('contexto_clinico')['reg_medico'];

        $ficha = $this->pacientes->ficha($regMedico, $paciente);
        abort_unless($ficha !== null, 404);

        $historia = CamposDePaciente::historiaNumerica($ficha->numhistoria);
        $volver = route('clinica.pacientes.vacunas', $paciente);

        if ($historia === null) {
            return redirect($volver)->with('error', 'Las dosis se registran con el número de historia del niño. Asígnale el número y después se cargan.');
        }

        $datos = $request->validate([
            'codi_vacuna' => ['required', 'string', 'max:4'],
            'dosis_numero' => ['nullable', 'integer', 'min:1', 'max:99'],
            'rotulo' => ['nullable', 'string', 'max:50'],
            'planeada' => ['nullable', 'date'],
            'fecha_ejecutada' => ['required', 'date_format:Y-m-d'],
            'lote' => ['nullable', 'string', 'max:20'],
            'via' => ['nullable', 'string', 'max:20'],
            'zona' => ['nullable', 'string', 'max:20'],
            'fecha_vence' => ['nullable', 'date_format:Y-m-d'],
            'fecha_proxima_dosis' => ['nullable', 'date_format:Y-m-d'],
            'nota' => ['nullable', 'string', 'max:2000'],
        ], [
            'codi_vacuna.required' => 'Falta elegir la vacuna.',
            'fecha_ejecutada.required' => 'Escribe la fecha en que se aplicó la dosis.',
            'fecha_ejecutada.date_format' => 'La fecha de aplicación va como día/mes/año.',
            'fecha_vence.date_format' => 'La fecha de vencimiento va como día/mes/año.',
            'fecha_proxima_dosis.date_format' => 'La fecha de la próxima dosis va como día/mes/año.',
            'lote.max' => 'El lote no puede pasar de 20 caracteres.',
            'via.max' => 'La vía no puede pasar de 20 caracteres.',
            'zona.max' => 'La zona no puede pasar de 20 caracteres.',
            'nota.max' => 'La nota no puede pasar de 2000 caracteres.',
        ]);

        try {
            $accion->ejecutar(
                $regMedico,
                $historia,
                $datos['codi_vacuna'],
                isset($datos['dosis_numero']) ? (int) $datos['dosis_numero'] : null,
                $datos['rotulo'] ?? null,
                $datos,
                $ficha->fnacimiento ? (string) $ficha->fnacimiento : null,
                $datos['planeada'] ?? null,
            );
        } catch (InvalidArgumentException $excepcion) {
            return redirect($volver)->with('error', $excepcion->getMessage());
        }

        return redirect($volver)->with('estado', 'Dosis registrada: ' . ($datos['rotulo'] ?? 'dosis') . '.');
    }
}
