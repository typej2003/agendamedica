<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\AgregarDoctorAlCatalogo;
use App\Actions\Consulta\EliminarReferenciaDeLaConsulta;
use App\Actions\Consulta\GuardarReferenciaDeLaConsulta;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\DoctoresDelMedico;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\PestanasDeSolicitudes;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Models\Referencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **Las referencias de una consulta** (WEB-5.3a, pestaña *Referencia* de las *Solicitudes*): buscar al médico en el
 * catálogo `doctores`, referirlo, corregir el motivo, quitar la referencia y dar de alta al colega que falta.
 *
 * Como en el escritorio, referir (*Pasar*) crea la fila **sin motivo** y el motivo se escribe y guarda después, en la
 * tarjeta de esa referencia. Varias por consulta, una por médico. La regla (el médico tiene que estar en el catálogo del
 * propio médico, el motivo es por referencia) y el rastro en `sync_changes` son de las Actions; acá se valida el HTTP.
 * El buscador y el alta del médico son **online** (como los otros catálogos clínicos de la web): el alta crea el médico y
 * lo refiere de una vez. Sólo del médico (`role:Root|Medico`).
 */
class ConsultaReferenciasController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas, private DoctoresDelMedico $doctores)
    {
    }

    /** El buscador del catálogo, en JSON: lo que se escribió, los médicos que coinciden y cuáles ya están referidos. */
    public function buscar(Request $request, Consulta $consulta): JsonResponse
    {
        [$consulta, $registros] = $this->delContexto($request, $consulta);

        $texto = trim((string) $request->query('q', ''));

        $referidos = Referencia::where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta)
            ->pluck('ceduladoctor')
            ->map(fn ($cedula) => (int) $cedula)
            ->all();

        return response()->json([
            'buscar' => $texto,
            'medicos' => $this->doctores->buscar($registros, $texto)->map(fn (object $medico) => [
                'cedula' => $medico->cedula,
                'nombre' => $medico->nombre,
                'especialidad' => $medico->especialidad,
                'clinica' => $medico->clinica,
                'telefono' => $medico->telefono,
                'yaEsta' => in_array($medico->cedula, $referidos, true),
            ])->values(),
        ]);
    }

    /** Referir al paciente a un médico del catálogo (sin motivo todavía). */
    public function agregar(Request $request, Consulta $consulta, GuardarReferenciaDeLaConsulta $guardar)
    {
        [$consulta, $registros] = $this->delContexto($request, $consulta);

        $datos = $request->validate(['cedula' => 'required|integer|min:0'], [
            'cedula.required' => 'Elige a qué médico referir al paciente.',
            'cedula.integer' => 'El médico elegido no es válido.',
            'cedula.min' => 'El médico elegido no es válido.',
        ]);

        try {
            $resultado = $guardar->ejecutar($consulta, $registros, (int) $datos['cedula'], null);
        } catch (InvalidArgumentException $excepcion) {
            return $this->destino($consulta)->with('error', $excepcion->getMessage());
        }

        return $this->destino($consulta, (int) $datos['cedula'])->with('estado', $resultado->creado
            ? 'Referencia creada: escribe el motivo y guárdalo.'
            : 'El paciente ya estaba referido a ese médico.');
    }

    /** Guardar el motivo de la referencia a ese médico. */
    public function guardar(Request $request, Consulta $consulta, int $cedula, GuardarReferenciaDeLaConsulta $guardar)
    {
        [$consulta, $registros] = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            'motivo' => 'nullable|string|max:' . GuardarReferenciaDeLaConsulta::LARGO_MAXIMO,
        ], [
            'motivo.max' => 'El motivo de la referencia es demasiado largo.',
        ]);

        try {
            $resultado = $guardar->ejecutar($consulta, $registros, $cedula, $datos['motivo'] ?? null);
        } catch (InvalidArgumentException $excepcion) {
            return $this->destino($consulta)->with('error', $excepcion->getMessage());
        }

        $destino = $this->destino($consulta, $cedula);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Hay un cambio más reciente de esta referencia (de otro equipo) y no se pisó: vuelve a cargarla y guárdala de nuevo.');
        }

        return $destino->with('estado', $resultado->sinCambios() ? 'El motivo ya estaba guardado así.' : 'Referencia guardada.');
    }

    public function eliminar(Request $request, Consulta $consulta, int $cedula, EliminarReferenciaDeLaConsulta $eliminar)
    {
        [$consulta] = $this->delContexto($request, $consulta);

        $quitadas = $eliminar->ejecutar($consulta, $cedula);

        return $this->destino($consulta)->with('estado', $quitadas > 0 ? 'Referencia quitada.' : 'Esa referencia ya no estaba.');
    }

    /**
     * Dar de alta al colega que falta y referirlo en el mismo paso. Si ya hay uno igual en el catálogo, se refiere ése.
     */
    public function medicoNuevo(Request $request, Consulta $consulta, AgregarDoctorAlCatalogo $alta, GuardarReferenciaDeLaConsulta $guardar)
    {
        [$consulta, $registros] = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            'apellidos' => 'nullable|string|max:' . AgregarDoctorAlCatalogo::LARGO_APELLIDOS,
            'nombres' => 'nullable|string|max:' . AgregarDoctorAlCatalogo::LARGO_NOMBRES,
            'codeespecial' => 'required|string|max:3',
            'clinica' => 'nullable|string|max:' . AgregarDoctorAlCatalogo::LARGO_CLINICA,
            'direccion' => 'nullable|string|max:' . AgregarDoctorAlCatalogo::LARGO_DIRECCION,
            'telefono' => 'nullable|string|max:' . AgregarDoctorAlCatalogo::LARGO_TELEFONO,
            'ciudad' => 'nullable|string|max:' . AgregarDoctorAlCatalogo::LARGO_CIUDAD,
        ], [
            'apellidos.max' => 'Los apellidos admiten hasta ' . AgregarDoctorAlCatalogo::LARGO_APELLIDOS . ' caracteres.',
            'nombres.max' => 'Los nombres admiten hasta ' . AgregarDoctorAlCatalogo::LARGO_NOMBRES . ' caracteres.',
            'codeespecial.required' => 'Elige la especialidad del médico.',
            'codeespecial.max' => 'La especialidad elegida no es válida.',
            'clinica.max' => 'La clínica admite hasta ' . AgregarDoctorAlCatalogo::LARGO_CLINICA . ' caracteres.',
            'direccion.max' => 'La dirección es demasiado larga.',
            'telefono.max' => 'El teléfono admite hasta ' . AgregarDoctorAlCatalogo::LARGO_TELEFONO . ' caracteres.',
            'ciudad.max' => 'La ciudad admite hasta ' . AgregarDoctorAlCatalogo::LARGO_CIUDAD . ' caracteres.',
        ]);

        try {
            [$doctor, $creado] = $alta->ejecutar(
                $consulta->reg_medico,
                $registros,
                $datos['apellidos'] ?? null,
                $datos['nombres'] ?? null,
                $datos['codeespecial'],
                $datos['clinica'] ?? null,
                $datos['direccion'] ?? null,
                $datos['telefono'] ?? null,
                $datos['ciudad'] ?? null,
            );
            $guardar->ejecutar($consulta, $registros, (int) $doctor->cedula, null);
        } catch (InvalidArgumentException $excepcion) {
            // El diálogo del alta se vuelve a abrir solo, con lo escrito, para poder corregirlo (guía de UI: ante un
            // error no se borra lo escrito).
            return $this->destino($consulta)
                ->withInput()
                ->withErrors(['nombres' => $excepcion->getMessage()])
                ->with('crear_medico', true);
        }

        return $this->destino($consulta, (int) $doctor->cedula)->with('estado', $creado
            ? 'Médico agregado al catálogo y referido: escribe el motivo y guárdalo.'
            : 'Ese médico ya estaba en tu catálogo: se refirió a él.');
    }

    /** @return array{0:Consulta, 1:array<int,string>} la consulta del contexto y los registros del médico */
    private function delContexto(Request $request, Consulta $consulta): array
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $registros = $this->registrosDelMedico($contexto['medico']);

        return [$this->consultas->consulta($registros, (int) $consulta->id, $contexto['reg_medico']), $registros];
    }

    private function destino(Consulta $consulta, ?int $cedula = null)
    {
        $url = route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_SOLICITUDES,
            'solicitud' => PestanasDeSolicitudes::REFERENCIAS,
        ]);

        return redirect($cedula === null ? $url : $url . '#referencia-' . $cedula);
    }
}
