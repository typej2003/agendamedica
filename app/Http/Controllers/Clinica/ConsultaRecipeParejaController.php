<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\EliminarRecipeDeLaPareja;
use App\Actions\Consulta\GuardarRecipeDeLaPareja;
use App\Actions\Consulta\ResultadoDeRecipeDeLaPareja;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\RecipeDeLaPareja;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Http\Controllers\Clinica\Concerns\ItemsDeUnRecipeEnElFormulario;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * **El récipe de la pareja de una consulta** (WEB-5.1b, dentro del ítem 10 del panel del legado): guardarlo
 * —crearlo o corregirlo en el lugar— y eliminarlo.
 *
 * La pantalla se **lee** en `ConsultaController::ver` (`?seccion=recipes&recipe=pareja`); el vademécum, los
 * tratamientos y el alta del medicamento que falta son los **mismos** endpoints del récipe del paciente
 * (`ConsultaRecipesController`): no se duplican. Acá están sólo las dos escrituras propias de la pareja.
 *
 * Tres decisiones que se ven en el código:
 *
 *  - **El `reg_medico` sale del contexto**, nunca del formulario: la consulta se resuelve contra el médico del
 *    contexto (una consulta de otro consultorio da **404** sin confirmar que exista) y la ruta de la
 *    secretaría ni se alcanza (el grupo de rutas de la consulta es `role:Root|Medico`).
 *  - **La regla de negocio no está acá**: guardar es `App\Actions\Consulta\GuardarRecipeDeLaPareja` y eliminar
 *    es `EliminarRecipeDeLaPareja`. Este controlador valida el formulario, le pide a `RecipeDeLaPareja` que
 *    compruebe los medicamentos contra los catálogos y cuenta qué pasó.
 *  - **Una pareja por consulta**: un solo `POST` crea o corrige (no hay número de récipe) y manda la cédula y el
 *    nombre de la pareja con todos sus medicamentos. Los datos de la pareja son texto libre: no se busca ni se
 *    crea ningún paciente.
 */
class ConsultaRecipeParejaController extends Controller
{
    use ResuelveLosRegistrosDelMedico;
    use ItemsDeUnRecipeEnElFormulario;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    /** Guardar el récipe de la pareja: lo crea si la consulta no tiene, o lo corrige en el lugar. */
    public function guardar(Request $request, Consulta $consulta, RecipeDeLaPareja $lectura, GuardarRecipeDeLaPareja $guardar): RedirectResponse
    {
        $consulta = $this->delContexto($request, $consulta);

        $datos = $request->validate(
            [
                'cedula' => 'required|string|max:' . GuardarRecipeDeLaPareja::LARGO_CEDULA,
                'nombre' => 'required|string|max:' . GuardarRecipeDeLaPareja::LARGO_NOMBRE,
            ] + $this->reglasDeLosItems(),
            [
                'cedula.required' => 'Escribe la cédula de la pareja.',
                'cedula.string' => 'La cédula de la pareja no es válida.',
                'cedula.max' => 'La cédula de la pareja admite hasta ' . GuardarRecipeDeLaPareja::LARGO_CEDULA . ' caracteres.',
                'nombre.required' => 'Escribe el nombre de la pareja.',
                'nombre.string' => 'El nombre de la pareja no es válido.',
                'nombre.max' => 'El nombre de la pareja admite hasta ' . GuardarRecipeDeLaPareja::LARGO_NOMBRE . ' caracteres.',
            ] + $this->mensajesDeLosItems(),
        );

        $resolucion = $lectura->resolverItems($consulta, (array) $datos['items']);

        if ($resolucion['desconocidos'] !== []) {
            // Un medicamento que no está en el vademécum del médico (ni en sus tratamientos) no se puede
            // recetar: `recipes_pareja.codemedicina` es obligatorio y no hay texto libre. Se vuelve al editor
            // con lo escrito para que se corrija (guía de UI §3).
            return $this->volverAlEditor($request, $consulta)->withErrors([
                'items' => 'Hay medicamentos que no están en tu vademécum: '
                    . implode(', ', $resolucion['desconocidos']) . '. Quítalos o créalos en el vademécum.',
            ]);
        }

        $resultado = $guardar->ejecutar(
            $consulta,
            (string) $datos['cedula'],
            (string) $datos['nombre'],
            $resolucion['items'],
            null,
            'web',
        );

        $destino = $this->aLaPareja($consulta);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Otro equipo editó ' . $this->listar($resultado->bloqueados)
                . ' más recientemente y no se pisó: vuelve a abrir el récipe de la pareja y revisa los cambios.');
        }

        if ($resultado->sinCambios()) {
            return $destino->with('estado', 'El récipe de la pareja no tenía cambios.');
        }

        return $destino->with('estado', $this->resumen($resultado));
    }

    /** Eliminar el récipe de la pareja entero: siempre se puede. */
    public function eliminar(Request $request, Consulta $consulta, RecipeDeLaPareja $lectura, EliminarRecipeDeLaPareja $eliminar): RedirectResponse
    {
        $consulta = $this->delContexto($request, $consulta);

        abort_unless($lectura->existe($consulta), 404);

        $quitadas = $eliminar->ejecutar($consulta, null, 'web');

        return $this->aLaPareja($consulta)
            ->with('estado', 'Eliminaste el récipe de la pareja de la consulta ('
                . $quitadas . ($quitadas === 1 ? ' medicamento' : ' medicamentos') . ').');
    }

    // ------------------------------------------------------------------------------------------ Adentro

    /** La pantalla del récipe de la pareja de esa consulta. */
    private function aLaPareja(Consulta $consulta): RedirectResponse
    {
        return redirect()->route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_RECIPES,
            'recipe' => ConsultaShellVista::RECIPE_PAREJA,
        ]);
    }

    /** La vuelta al editor con lo escrito (el `old()` del formulario). */
    private function volverAlEditor(Request $request, Consulta $consulta): RedirectResponse
    {
        return $this->aLaPareja($consulta)->withInput($request->all());
    }

    /** "Récipe de la pareja creado: …", "Récipe de la pareja actualizado: agregado …, quitado …": lo que pasó, con nombres. */
    private function resumen(ResultadoDeRecipeDeLaPareja $resultado): string
    {
        if ($resultado->esNuevo) {
            return 'Récipe de la pareja creado: ' . $this->listar($resultado->agregados) . '.';
        }

        $partes = [];

        if ($resultado->agregados !== []) {
            $partes[] = 'agregado' . (count($resultado->agregados) === 1 ? '' : 's') . ' ' . $this->listar($resultado->agregados);
        }
        if ($resultado->modificados !== []) {
            $partes[] = 'modificado' . (count($resultado->modificados) === 1 ? '' : 's') . ' ' . $this->listar($resultado->modificados);
        }
        if ($resultado->quitados !== []) {
            $partes[] = 'quitado' . (count($resultado->quitados) === 1 ? '' : 's') . ' ' . $this->listar($resultado->quitados);
        }
        if ($resultado->parejaCambiada) {
            $partes[] = 'cambiados los datos de la pareja';
        }

        return 'Récipe de la pareja actualizado: ' . implode(', ', $partes) . '.';
    }

    /** La consulta del contexto (404 si es de otro médico). */
    private function delContexto(Request $request, Consulta $consulta): Consulta
    {
        $contexto = $request->attributes->get('contexto_clinico');

        return $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );
    }
}
