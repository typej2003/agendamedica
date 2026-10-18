<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\GuardarPlantillaDelFormato;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\Vistas\TiposDeEstudio;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **«Guardar como plantilla» del *formato* de un estudio ecográfico** (O-17): el alta —o la corrección— de la
 * plantilla que guarda los campos descriptivos del estudio para reusarlos en la próxima paciente.
 *
 * Responde JSON, como `PlantillaDeTextoController`, porque el panel del formato lo pide sin recargar la
 * consulta: lo que el médico escribió no se pierde. La regla de escritura está en
 * `GuardarPlantillaDelFormato`; acá sólo se resuelve el HTTP y se comprueba que la consulta sea del
 * consultorio del contexto (mismo 404 que la sección y el guardado del estudio).
 *
 * **Aplicar** una plantilla no pasa por acá: el panel completa en el navegador los campos **vacíos** del
 * formulario (la regla está escrita en `PlantillasDelFormato::aplicar()` y el JavaScript hace la misma
 * cuenta), así que se puede aplicar y seguir escribiendo, y nada se guarda hasta apretar «Guardar el
 * estudio».
 */
class ConsultaPlantillaDelFormatoController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardar(Request $request, Consulta $consulta, string $tipo, GuardarPlantillaDelFormato $guardar)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        // La consulta se resuelve contra el contexto: la de otro consultorio da 404.
        $consulta = $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );

        if (! TiposDeEstudio::existe($tipo)) {
            abort(404);
        }

        $datos = $request->validate([
            'titulo' => 'required|string|max:400',
            'valores' => 'required|array',
        ], [
            'titulo.required' => 'Escribe el título de la plantilla.',
            'valores.required' => 'El estudio no tiene ningún campo cargado para guardar.',
        ]);

        try {
            $fila = $guardar->ejecutar((string) $consulta->reg_medico, TiposDeEstudio::normalizar($tipo), $datos);
        } catch (InvalidArgumentException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        $guardado = json_decode((string) $fila->descripcion, true);

        return response()->json([
            'titulo' => trim((string) $fila->titulo),
            'valores' => is_array($guardado['campos'] ?? null) ? $guardado['campos'] : [],
        ], 201);
    }
}
