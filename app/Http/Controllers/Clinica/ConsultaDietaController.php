<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\EliminarDietaDeLaConsulta;
use App\Actions\Consulta\GuardarDietaDeLaConsulta;
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

        $destino = $this->destino($consulta);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Hay un cambio más reciente de la dieta (de otro equipo) y no se pisó por completo: vuelve a cargarla y guárdala de nuevo.');
        }

        return $destino->with('estado', $resultado->sinCambios() ? 'La dieta ya estaba guardada así.' : 'Dieta guardada.');
    }

    public function eliminar(Request $request, Consulta $consulta, EliminarDietaDeLaConsulta $eliminar)
    {
        $consulta = $this->delContexto($request, $consulta);

        $quitadas = $eliminar->ejecutar($consulta);

        return $this->destino($consulta)->with('estado', $quitadas > 0 ? 'Dieta eliminada.' : 'Esta consulta no tenía dieta.');
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

    private function destino(Consulta $consulta)
    {
        return redirect()->route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_SOLICITUDES,
            'solicitud' => PestanasDeSolicitudes::DIETA,
        ]);
    }
}
