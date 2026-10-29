<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\GuardarDatosDelInternista;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\Interna\MapaDelInternista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * **Guardar una pestaña de la consulta del internista** (MI-2 a MI-5, 2026-10-28): signos vitales, examen funcional, examen
 * físico, resumen físico, EKG, Rx de tórax, laboratorio, observaciones y estimaciones pre-operatorias. La pestaña viaja en el
 * formulario; los campos y sus columnas son los de `MapaDelInternista`, y la escritura es `GuardarDatosDelInternista`.
 */
class ConsultaInternistaController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardar(Request $request, Consulta $consulta, GuardarDatosDelInternista $guardar)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $consulta = $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );

        $pestanas = [];
        foreach (MapaDelInternista::PESTANAS as $seccion => $deLaSeccion) {
            foreach (array_keys($deLaSeccion) as $pestana) {
                if ($pestana !== MapaDelInternista::PESTANA_RIESGO) {
                    $pestanas[$pestana] = $seccion;
                }
            }
        }

        $request->validate(['pestana' => ['required', 'string', Rule::in(array_keys($pestanas))]]);
        $pestana = (string) $request->input('pestana');

        $reglas = [];
        $nombres = [];
        foreach (MapaDelInternista::deLaPestana($pestana) as $campo) {
            $nombres[$campo['clave']] = mb_strtolower($campo['etiqueta']);
            $reglas[$campo['clave']] = match ($campo['tipo']) {
                // Se acepta la coma decimal (`36,8`), como en el resto de la consulta.
                'numero' => ['nullable', 'regex:/^\s*\d{1,6}([.,]\d{1,3})?\s*$/'],
                'si_no' => ['nullable', Rule::in(['S', 'N'])],
                'opcion' => ['nullable', Rule::in(array_keys($campo['opciones']))],
                default => array_values(array_filter(['nullable', 'string', isset($campo['max']) ? 'max:' . $campo['max'] : null])),
            };
        }

        $datos = $request->validate($reglas, ['regex' => 'Escribe un número (por ejemplo 36,8).'], $nombres);

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
            ? $destino->with('estado', 'Datos guardados.')
            : $destino->with('estado', 'Sin cambios: los datos ya estaban así.');
    }
}
