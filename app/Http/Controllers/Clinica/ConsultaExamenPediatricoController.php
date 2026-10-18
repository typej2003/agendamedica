<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\ArmarResumenDelExamen;
use App\Actions\Consulta\GuardarCuestionarioDeLaConsulta;
use App\Actions\Consulta\GuardarExamenFisicoPediatrico;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\ExamenPediatrico;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\PestanasDelExamen;
use App\Clinica\Crecimiento\MedicionesDeLaConsulta;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;

/**
 * **El examen físico y el cuestionario de pediatría** (WEB-12.2): guardar las dos solapas de texto y armar el resumen del examen. Se **leen** en
 * `ConsultaController::ver` (`?seccion=evaluacion&pestana=examen|cuestionario`); acá sólo se escribe. La regla —la fila de `examen_fisico` o de
 * `cuestionario`, el neurológico en `consultas.otros`, el rastro del sync— está en las Actions. La consulta se vuelve a resolver contra el contexto
 * (404 si es de otro consultorio) y la secretaría no llega (grupo `role:Root|Medico`).
 */
class ConsultaExamenPediatricoController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    private const TOPE = 20000;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardarExamen(Request $request, Consulta $consulta, GuardarExamenFisicoPediatrico $guardar)
    {
        $consulta = $this->resolver($request, $consulta);
        $datos = $request->validate($this->reglas(array_merge(array_keys(ExamenPediatrico::EXAMEN), ['fisico_resumen'])), $this->avisos());

        $resultado = $guardar->ejecutar($consulta, $datos);

        return $this->volver($consulta, PestanasDelExamen::EXAMEN, $resultado, 'el examen físico');
    }

    public function guardarCuestionario(Request $request, Consulta $consulta, GuardarCuestionarioDeLaConsulta $guardar)
    {
        $consulta = $this->resolver($request, $consulta);
        $datos = $request->validate($this->reglas(array_keys(ExamenPediatrico::CUESTIONARIO)), $this->avisos());

        $resultado = $guardar->ejecutar($consulta, $datos);

        return $this->volver($consulta, PestanasDelExamen::CUESTIONARIO, $resultado, 'el cuestionario');
    }

    /**
     * **«Actualizar»**: arma el resumen del examen con lo que hay **en el formulario** (no hace falta guardar antes) y con el peso, la talla y el
     * perímetro cefálico de las mediciones guardadas. No escribe nada.
     */
    public function resumen(Request $request, Consulta $consulta)
    {
        $consulta = $this->resolver($request, $consulta);
        $datos = $request->validate($this->reglas(array_keys(ExamenPediatrico::EXAMEN)), $this->avisos());

        $mediciones = (new MedicionesDeLaConsulta())->de($consulta);

        return response()->json(['fisico' => ArmarResumenDelExamen::pediatrico($datos + [
            'peso' => $mediciones?->peso, 'talla' => $mediciones?->talla, 'c_cefalica' => $mediciones?->c_cefalica,
        ])]);
    }

    private function resolver(Request $request, Consulta $consulta): Consulta
    {
        $contexto = $request->attributes->get('contexto_clinico');

        return $this->consultas->consulta($this->registrosDelMedico($contexto['medico']), (int) $consulta->id, $contexto['reg_medico']);
    }

    /** @return array<string,string> */
    private function reglas(array $columnas): array
    {
        return array_fill_keys($columnas, 'nullable|string|max:' . self::TOPE);
    }

    /** @return array<string,string> */
    private function avisos(): array
    {
        return ['*.max' => 'Ese cuadro es demasiado largo (hasta ' . number_format(self::TOPE, 0, ',', '.') . ' caracteres).'];
    }

    private function volver(Consulta $consulta, string $pestana, $resultado, string $que)
    {
        $destino = redirect()->route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_EVALUACION, 'pestana' => $pestana]);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Otro equipo editó una parte de ' . $que . ' más recientemente y no se pisó: vuelve a cargar la pantalla y guárdala de nuevo.');
        }

        return $destino->with('estado', $resultado->sinCambios() ? ucfirst($que) . ' no tenía cambios.' : ucfirst($que) . ' guardado.');
    }
}
