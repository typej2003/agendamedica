<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Vacunas\ProgramarDosisDeVacuna;
use App\Actions\Vacunas\QuitarDosisDeVacuna;
use App\Actions\Vacunas\RegistrarDosisDeVacuna;
use App\Clinica\Consulta\SeccionesDeLaConsulta;
use App\Clinica\FechaDelConsultorio;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Clinica\Pacientes\PacientesDelConsultorio;
use App\Clinica\Vacunas\CalendarioDeVacunas;
use App\Clinica\Impresion\MembreteDelMedico;
use App\Clinica\Pacientes\FamiliaDelPaciente;
use App\Clinica\Vacunas\CalendarioDelNino;
use App\Clinica\Vacunas\Vistas\CalendarioDeVacunasVista;
use App\Clinica\Vacunas\Vistas\CarnetDeVacunacionVista;
use App\Clinica\Vacunas\VacunasDelPaciente;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Carbon\Carbon;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **Las vacunas de un niño** (WEB-7.4.4a; O-68/O-69): la lista de sus dosis aplicadas y programadas —la pantalla principal,
 * como la solapa *Inmunizaciones* del escritorio—, el esquema del catálogo como panel plegado (que sólo propone), el registro
 * y la programación de una dosis, y el carnet imprimible. Es clínico —sólo del médico (`role:Root|Medico`)— y de un paciente
 * del consultorio: uno ajeno da 404.
 */
class VacunasDelPacienteController extends Controller
{
    public function __construct(
        private PacientesDelConsultorio $pacientes,
        private VacunasDelPaciente $lectura,
        private CalendarioDeVacunas $calendario,
        private CalendarioDelNino $calendarioDelNino,
    ) {
    }

    /** La entrada del menú: el calendario es de un niño, así que se llega desde su ficha. */
    public function entrada()
    {
        return redirect()->route('clinica.pacientes')
            ->with('estado', 'Elige al niño y abre su ficha: ahí está el botón «Vacunas» con su calendario y su carnet.');
    }

    public function ver(Request $request, int $paciente)
    {
        $regMedico = $request->attributes->get('contexto_clinico')['reg_medico'];

        $ficha = $this->pacientes->ficha($regMedico, $paciente);
        abort_unless($ficha !== null, 404);

        return view('clinica.vacunas.ver', [
            // La pantalla la arma `CalendarioDelNino`, que es el mismo que usa la sección *Inmunizaciones* de la consulta (O-63):
            // acá lo único distinto son las URLs (la vuelta a esta pantalla y a la ficha del niño).
            'vm' => $this->calendarioDelNino->de(
                $regMedico,
                $ficha,
                CalendarioDeVacunasVista::normalizarFiltro($request->query('ver')),
                fn (string $f) => route('clinica.pacientes.vacunas', [$paciente, 'ver' => $f]),
                route('clinica.pacientes.ver', $paciente),
                // Registrar una dosis (WEB-7.4.4b) exige la clave de la historia (`vacuna_paciente.historia`): sin número no se ofrece.
                $ficha->numhistoria === null ? null : route('clinica.pacientes.vacunas.registrar', $paciente),
                route('clinica.pacientes.vacunas.carnet', $paciente),
                null,
                null,
                // Programar una dosis (O-68/O-69) es la otra escritura de la misma fila, con la misma clave.
                $ficha->numhistoria === null ? null : route('clinica.pacientes.vacunas.programar', $paciente),
                $ficha->numhistoria === null ? null : fn (int $fila) => route('clinica.pacientes.vacunas.quitar', [$paciente, $fila]),
            ),
        ]);
    }

    /**
     * **El carnet de vacunación** (WEB-7.4.4c): la hoja Carta con el membrete del médico, las dosis aplicadas y las que faltan. La imprime el
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
                // El membrete es el del **formato del consultorio** (Fase 5 del cambio de `reg_medico`): el del centro
                // si él lo eligió y el del doctor si no.
                $membretes->deLaImpresion($contexto['medico'], $regMedico),
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
        $contexto = $this->paraEscribir($request, $paciente);
        $regMedico = $contexto['regMedico'];
        $historia = $contexto['historia'];
        $volver = $contexto['volver'];

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
                $contexto['ficha']->fnacimiento ? (string) $contexto['ficha']->fnacimiento : null,
                $datos['planeada'] ?? null,
            );
        } catch (InvalidArgumentException $excepcion) {
            return redirect($volver)->with('error', $excepcion->getMessage());
        }

        return redirect($volver)->with('estado', 'Dosis registrada: ' . ($datos['rotulo'] ?? 'dosis') . '.');
    }

    /**
     * **Programar una dosis** (O-68/O-69): la fila planeada de `vacuna_paciente`, con la fecha en que se le va a aplicar.
     * La regla es la Action `ProgramarDosisDeVacuna`; acá sólo se traduce HTTP. La usan el panel del esquema («Programar») y el
     * diálogo de «Agregar una vacuna» cuando el médico elige que queda programada.
     */
    public function programar(Request $request, int $paciente, ProgramarDosisDeVacuna $accion)
    {
        $contexto = $this->paraEscribir($request, $paciente);
        $volver = $contexto['volver'];

        if ($contexto['historia'] === null) {
            return redirect($volver)->with('error', 'Las dosis se programan con el número de historia del niño. Asígnale el número y después se cargan.');
        }

        $datos = $request->validate([
            'codi_vacuna' => ['required', 'string', 'max:4'],
            'dosis_numero' => ['required', 'integer', 'min:1', 'max:99'],
            'rotulo' => ['nullable', 'string', 'max:50'],
            'planeada' => ['required', 'date_format:Y-m-d'],
            'nota' => ['nullable', 'string', 'max:2000'],
        ], [
            'codi_vacuna.required' => 'Falta elegir la vacuna.',
            'dosis_numero.required' => 'Elige la dosis que vas a programar.',
            'planeada.required' => 'Escribe la fecha en que se le va a aplicar la dosis.',
            'planeada.date_format' => 'La fecha a aplicar va como día/mes/año.',
            'nota.max' => 'La nota no puede pasar de 2000 caracteres.',
        ]);

        try {
            $accion->ejecutar(
                $contexto['regMedico'],
                $contexto['historia'],
                $datos['codi_vacuna'],
                (int) $datos['dosis_numero'],
                $datos['rotulo'] ?? null,
                // La Action habla en columnas (`fecha_planeada`), el formulario en fechas del médico (`planeada`).
                ['fecha_planeada' => $datos['planeada'], 'nota' => $datos['nota'] ?? null],
                $contexto['ficha']->fnacimiento ? (string) $contexto['ficha']->fnacimiento : null,
            );
        } catch (InvalidArgumentException $excepcion) {
            return redirect($volver)->with('error', $excepcion->getMessage());
        }

        return redirect($volver)->with('estado', 'Dosis programada: ' . ($datos['rotulo'] ?? 'dosis') . ', para el '
            . Carbon::parse($datos['planeada'])->format(\App\Support\Regionalizacion::formatoActual()) . '.');
    }

    /**
     * **Quitar de la lista una dosis programada** (O-68/O-69), el «Quitar registro» del escritorio: la Action
     * `QuitarDosisDeVacuna` rechaza una fila aplicada (es historia clínica, no se borra desde acá).
     */
    public function quitar(Request $request, int $paciente, int $fila, QuitarDosisDeVacuna $accion)
    {
        $contexto = $this->paraEscribir($request, $paciente);
        $volver = $contexto['volver'];

        if ($contexto['historia'] === null) {
            return redirect($volver)->with('error', 'Las dosis se quitan con el número de historia del niño.');
        }

        try {
            $quitada = $accion->ejecutar($contexto['regMedico'], $contexto['historia'], $fila);
        } catch (InvalidArgumentException $excepcion) {
            return redirect($volver)->with('error', $excepcion->getMessage());
        }

        $nombre = trim(implode(' · ', array_filter([$quitada->fila->vacuna, $quitada->fila->dosis])));

        return redirect($volver)->with('estado', 'Quité de la lista: ' . ($nombre !== '' ? $nombre : 'la dosis') . '.');
    }

    /**
     * Lo que necesitan las tres escrituras de una dosis (registrar, programar y quitar): la ficha del niño en este consultorio
     * —404 si es de otro—, su número de historia y a dónde se vuelve.
     *
     * @return array{regMedico:string, ficha:object, historia:?int, volver:string}
     */
    private function paraEscribir(Request $request, int $paciente): array
    {
        $regMedico = $request->attributes->get('contexto_clinico')['reg_medico'];

        $ficha = $this->pacientes->ficha($regMedico, $paciente);
        abort_unless($ficha !== null, 404);

        $historia = CamposDePaciente::historiaNumerica($ficha->numhistoria);

        return [
            'regMedico' => $regMedico,
            'ficha' => $ficha,
            'historia' => $historia,
            'volver' => $this->aDondeVuelve($request, $paciente, $regMedico, $historia),
        ];
    }

    /**
     * A dónde vuelve el registro de una dosis: **la sección «Inmunizaciones» de la consulta** cuando el formulario se abrió
     * desde ella (O-63) y esa consulta es de este niño y de este consultorio; si no, la pantalla del módulo, como siempre.
     *
     * La consulta se resuelve acá y **no** se acepta una URL de vuelta: el formulario no elige el destino, sólo dice en qué
     * consulta se está atendiendo. Una consulta de otro paciente o de otro `reg_medico` no existe (vuelve al módulo).
     */
    private function aDondeVuelve(Request $request, int $paciente, string $regMedico, ?int $historia): string
    {
        $consultaId = (int) $request->input('consulta', 0);

        if ($consultaId > 0 && $historia !== null) {
            $consulta = Consulta::where('reg_medico', $regMedico)->where('numhistoria', $historia)->find($consultaId);

            if ($consulta !== null) {
                return route('clinica.consulta', [
                    'consulta' => (int) $consulta->id,
                    'seccion' => SeccionesDeLaConsulta::SECCION_INMUNIZACIONES,
                ]);
            }
        }

        return route('clinica.pacientes.vacunas', $paciente);
    }
}
