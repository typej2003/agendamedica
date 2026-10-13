<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Recipes\AnularVerificacionDeRecipe;
use App\Actions\Recipes\ReactivarVerificacionDeRecipe;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Verificacion\VerificacionesDeLaConsulta;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * **Anular y reactivar el QR de un récipe** (WEB-5.6): el médico puede dejar de avalar un récipe que ya imprimió (lo emitió por
 * error, se lo robaron…) y, si fue sin querer, devolverlo. Desde que se anula, la página pública del QR dice «Anulado por el
 * médico»; reactivarlo la devuelve a la normalidad con el mismo token.
 *
 * Va en el grupo `role:Root|Medico` de la consulta (la secretaría recibe 403) y se resuelve contra el contexto: una consulta de
 * otro consultorio da 404 sin confirmar que existe, y un récipe que nunca se imprimió (sin QR) también. La regla vive en las
 * Actions `AnularVerificacionDeRecipe` y `ReactivarVerificacionDeRecipe`.
 */
class ConsultaRecipeVerificacionController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(
        private ConsultasDelContexto $consultas,
        private VerificacionesDeLaConsulta $verificaciones,
    ) {
    }

    public function anular(Request $request, Consulta $consulta, int $numero, AnularVerificacionDeRecipe $anular): RedirectResponse
    {
        return $this->cambiar($request, $consulta, $numero, fn ($verificacion) => $anular->ejecutar($verificacion), true);
    }

    public function reactivar(Request $request, Consulta $consulta, int $numero, ReactivarVerificacionDeRecipe $reactivar): RedirectResponse
    {
        return $this->cambiar($request, $consulta, $numero, fn ($verificacion) => $reactivar->ejecutar($verificacion), false);
    }

    public function anularPareja(Request $request, Consulta $consulta, AnularVerificacionDeRecipe $anular): RedirectResponse
    {
        return $this->cambiar($request, $consulta, null, fn ($verificacion) => $anular->ejecutar($verificacion), true);
    }

    public function reactivarPareja(Request $request, Consulta $consulta, ReactivarVerificacionDeRecipe $reactivar): RedirectResponse
    {
        return $this->cambiar($request, $consulta, null, fn ($verificacion) => $reactivar->ejecutar($verificacion), false);
    }

    /**
     * @param  int|null  $numero  el récipe del paciente, o `null` para el de la pareja
     */
    private function cambiar(Request $request, Consulta $consulta, ?int $numero, callable $accion, bool $anula): RedirectResponse
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $consulta = $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );

        $verificacion = $this->verificaciones->de($consulta, $numero);
        abort_if($verificacion === null, 404);

        $accion($verificacion);

        $cual = $numero === null ? 'el récipe de la pareja' : 'el récipe ' . $numero;
        $parametros = ['seccion' => ConsultaShellVista::SECCION_RECIPES] + ($numero === null ? ['recipe' => ConsultaShellVista::RECIPE_PAREJA] : []);

        return redirect()
            ->route('clinica.consulta', [$consulta->id] + $parametros)
            ->with('estado', $anula
                ? 'Anulaste el QR de ' . $cual . ': quien lo escanee verá «Anulado por el médico». Puedes reactivarlo cuando quieras.'
                : 'Reactivaste el QR de ' . $cual . ': la hoja impresa vuelve a verificarse.');
    }
}
