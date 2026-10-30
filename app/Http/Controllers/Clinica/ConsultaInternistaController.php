<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\ArmarResumenDelExamen;
use App\Actions\Consulta\GuardarDatosDelInternista;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\Interna\DatosDelInternista;
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
 *
 * Desde el 2026-10-29 también arma el **resumen físico** con «Actualizar» (`resumen`): lee los signos y el examen guardados
 * y devuelve el texto, como el `ue_resume` del escritorio. No escribe nada.
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

    /**
     * **«Actualizar» del resumen físico** (Alexander, 2026-10-29): el `ue_resume` del escritorio, que arma
     * `consultas.resultadoexamencomp` con los signos y el examen de esta consulta. No escribe nada: el texto vuelve a
     * la pantalla, el médico lo revisa y se guarda con el botón (o el guardado de la pestaña).
     *
     * Lee lo **guardado**, no el formulario: los signos y el examen viven en otras pestañas y la pantalla sólo tiene
     * una abierta a la vez. Cambiar de pestaña guarda antes lo escrito (el diálogo de salida), así que al llegar acá
     * lo que se lee es lo último que el médico cargó.
     */
    public function resumen(Request $request, Consulta $consulta, DatosDelInternista $datos)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $consulta = $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );

        return response()->json([
            'fisico' => ArmarResumenDelExamen::internista(
                $datos->valores($consulta, 'signos'),
                $datos->valores($consulta, 'examen'),
            ),
        ]);
    }
}
