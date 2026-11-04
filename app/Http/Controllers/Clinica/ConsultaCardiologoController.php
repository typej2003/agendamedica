<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\GuardarDatosDelCardiologo;
use App\Clinica\Consulta\Cardiologia\MapaDelCardiologo;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * **Guardar una pestaña de los estudios cardiológicos** (CAR-2, 2026-11-04): hoy el ecocardiograma transtorácico.
 * La pestaña viaja en el formulario; los campos y sus columnas son los de `MapaDelCardiologo`, y la escritura es
 * `GuardarDatosDelCardiologo`.
 */
class ConsultaCardiologoController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardar(Request $request, Consulta $consulta, GuardarDatosDelCardiologo $guardar)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $consulta = $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );

        $pestanas = [];
        foreach (MapaDelCardiologo::PESTANAS as $seccion => $deLaSeccion) {
            foreach (array_keys($deLaSeccion) as $pestana) {
                $pestanas[$pestana] = $seccion;
            }
        }

        $request->validate(['pestana' => ['required', 'string', Rule::in(array_keys($pestanas))]]);
        $pestana = (string) $request->input('pestana');

        $reglas = [];
        $nombres = [];
        foreach (MapaDelCardiologo::deLaPestana($pestana) as $campo) {
            $nombres[$campo['clave']] = mb_strtolower($campo['etiqueta']);
            $reglas[$campo['clave']] = array_values(array_filter([
                'nullable', 'string', isset($campo['max']) ? 'max:' . $campo['max'] : null,
            ]));
        }

        $datos = $request->validate($reglas, [], $nombres);

        $resultado = $guardar->ejecutar($consulta, $pestana, $datos, null, 'web');

        $destino = redirect()->route('clinica.consulta', [
            $consulta->id,
            'seccion' => $pestanas[$pestana],
            'pestana' => $pestana,
        ]);

        if ($resultado->huboConflicto()) {
            return $destino->with('estado', 'Se guardó lo demás, pero algún dato tiene un cambio más nuevo hecho en otro equipo y no se pisó.');
        }

        return $resultado->seEscribio()
            ? $destino->with('estado', 'Estudio guardado.')
            : $destino->with('estado', 'Sin cambios: el estudio ya estaba así.');
    }
}
