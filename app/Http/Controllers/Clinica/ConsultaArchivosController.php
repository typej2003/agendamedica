<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\GuardarArchivoDeLaConsulta;
use App\Actions\Consulta\QuitarArchivoDeLaConsulta;
use App\Clinica\Consulta\Cardiologia\ArchivosDeLaConsulta;
use App\Clinica\Consulta\Cardiologia\MapaDelCardiologo;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\ArchivoDeHistoria;
use App\Models\Consulta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * **Los archivos PDF de la consulta del cardiólogo** (CAR-5, 2026-11-04): subir, descargar y quitar. El archivo vive en el
 * disco privado `clinica`; la descarga es el único camino al archivo y comprueba que sea del médico del contexto.
 */
class ConsultaArchivosController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function subir(Request $request, Consulta $consulta, GuardarArchivoDeLaConsulta $guardar)
    {
        $consulta = $this->consultaDelContexto($request, $consulta);

        $request->validate([
            'archivo' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ], [], ['archivo' => 'archivo PDF']);

        $guardar->ejecutar($consulta, $request->file('archivo'));

        return redirect()
            ->route('clinica.consulta', [$consulta->id, 'seccion' => MapaDelCardiologo::SECCION, 'pestana' => MapaDelCardiologo::PESTANA_ARCHIVOS])
            ->with('estado', 'Archivo subido.');
    }

    public function descargar(Request $request, Consulta $consulta, ArchivoDeHistoria $archivo)
    {
        $consulta = $this->consultaDelContexto($request, $consulta);
        abort_unless((int) $archivo->nrohistoria === (int) $consulta->numhistoria
            && (int) $archivo->nroconsulta === (int) $consulta->nroconsulta
            && (string) $archivo->reg_medico === (string) $consulta->reg_medico, 404);

        abort_unless(ArchivosDeLaConsulta::esDeLaWeb($archivo->archivo), 404);

        return Storage::disk('clinica')->download($archivo->archivo, ArchivosDeLaConsulta::nombreVisible($archivo->archivo));
    }

    public function quitar(Request $request, Consulta $consulta, ArchivoDeHistoria $archivo, QuitarArchivoDeLaConsulta $quitar)
    {
        $consulta = $this->consultaDelContexto($request, $consulta);
        abort_unless((int) $archivo->nrohistoria === (int) $consulta->numhistoria
            && (int) $archivo->nroconsulta === (int) $consulta->nroconsulta
            && (string) $archivo->reg_medico === (string) $consulta->reg_medico, 404);
        abort_unless(ArchivosDeLaConsulta::esDeLaWeb($archivo->archivo), 404, 'Ese archivo no está en la web: es una ruta del escritorio.');

        $quitar->ejecutar($archivo);

        return redirect()
            ->route('clinica.consulta', [$consulta->id, 'seccion' => MapaDelCardiologo::SECCION, 'pestana' => MapaDelCardiologo::PESTANA_ARCHIVOS])
            ->with('estado', 'Archivo quitado.');
    }

    /** La consulta del contexto (404 si es de otro consultorio), como en el resto de la consulta. */
    private function consultaDelContexto(Request $request, Consulta $consulta): Consulta
    {
        $contexto = $request->attributes->get('contexto_clinico');

        return $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );
    }
}
