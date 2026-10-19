<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\EliminarDietaDeLaConsulta;
use App\Actions\Consulta\GuardarDietaDeLaConsulta;
use App\Clinica\Consulta\AlcanceDeLaEspecialidad;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\DietaDeLaConsulta;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\PestanasDeSolicitudes;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * **La dieta / recomendaciones de una consulta** (WEB-5.3b, pestaña *Dieta / Recomendaciones* de las *Solicitudes*): guardar el título y
 * el texto, y eliminarla.
 *
 * Una por consulta, con guardado explícito. Las plantillas **no pasan por acá**: llenan el editor en el navegador
 * (`consulta-solicitudes.js`) y esto recibe el texto ya terminado. La regla (el texto es obligatorio) y el rastro en `sync_changes` son de la
 * Action `GuardarDietaDeLaConsulta`. Sólo del médico.
 */
class ConsultaDietaController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardar(Request $request, Consulta $consulta, GuardarDietaDeLaConsulta $guardar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate([
            'titulo' => 'nullable|string|max:' . DietaDeLaConsulta::LARGO_TITULO,
            'descripcion' => 'required|string|max:' . DietaDeLaConsulta::LARGO_TEXTO,
        ], [
            'titulo.max' => 'El título admite hasta ' . DietaDeLaConsulta::LARGO_TITULO . ' caracteres.',
            'descripcion.required' => 'Escribe el texto de la dieta o de las recomendaciones, o elige una plantilla.',
            'descripcion.max' => 'El texto de la dieta es demasiado largo.',
        ]);

        try {
            $resultado = $guardar->ejecutar($consulta, $datos['titulo'] ?? null, $datos['descripcion']);
        } catch (InvalidArgumentException $excepcion) {
            throw ValidationException::withMessages(['descripcion' => $excepcion->getMessage()]);
        }

        $destino = $this->destino($request, $consulta);
        // En pediatría la pantalla se llama *Alimentación*, y el aviso la sigue.
        $palabra = $this->esPediatria($request) ? 'alimentación' : 'dieta';

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Hay un cambio más reciente de la ' . $palabra . ' (de otro equipo) y no se pisó por completo: vuelve a cargarla y guárdala de nuevo.');
        }

        return $destino->with('estado', $resultado->sinCambios()
            ? 'La ' . $palabra . ' ya estaba guardada así.'
            : mb_convert_case($palabra, MB_CASE_TITLE, 'UTF-8') . ' guardada.');
    }

    public function eliminar(Request $request, Consulta $consulta, EliminarDietaDeLaConsulta $eliminar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $quitadas = $eliminar->ejecutar($consulta);
        $palabra = $this->esPediatria($request) ? 'alimentación' : 'dieta';

        return $this->destino($request, $consulta)->with('estado', $quitadas > 0
            ? mb_convert_case($palabra, MB_CASE_TITLE, 'UTF-8') . ' eliminada.'
            : 'Esta consulta no tenía ' . $palabra . '.');
    }

    private function delContexto(Request $request, Consulta $consulta): Consulta
    {
        $contexto = $request->attributes->get('contexto_clinico');

        return $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );
    }

    /**
     * A dónde se vuelve después de guardar o eliminar: **a la pantalla donde estaba**, que depende de la especialidad. En
     * pediatría la dieta es su **ítem propio** *Alimentación* (`?seccion=alimentacion`, 2026-10-19) y en ginecología y las
     * demás sigue siendo la pestaña de *Solicitudes* (`?seccion=solicitudes&solicitud=dieta`). Volver siempre a
     * *Solicitudes* era lo que Alexander veía como «se está yendo a solicitudes» (O-40).
     */
    private function destino(Request $request, Consulta $consulta)
    {
        if ($this->esPediatria($request)) {
            return redirect()->route('clinica.consulta', [
                $consulta->id,
                'seccion' => ConsultaShellVista::SECCION_ALIMENTACION,
            ]);
        }

        return redirect()->route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_SOLICITUDES,
            'solicitud' => PestanasDeSolicitudes::DIETA,
        ]);
    }

    /** ¿La especialidad del contexto es pediatría? (Ahí la dieta es el ítem *Alimentación*, con sección propia.) */
    private function esPediatria(Request $request): bool
    {
        $contexto = (array) $request->attributes->get('contexto_clinico');

        return AlcanceDeLaEspecialidad::de($contexto['specialty']?->slug ?? null)->esPediatria();
    }
}
