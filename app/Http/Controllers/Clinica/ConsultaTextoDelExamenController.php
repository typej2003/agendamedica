<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\GuardarTextoDelExamen;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\TextosDelExamen;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;

/**
 * **El texto de una solapa del examen** (WEB-6.5, ítem 4 del panel): la escritura de la colposcopía, la
 * citología, la histeroscopía y el resultado de exámenes.
 *
 * Las cuatro guardan igual —un texto en **una columna de `consultas`**, con su rastro por columna y el
 * last-write-wins del sync— y la única que tiene una copia en otra tabla es la colposcopía; todo eso vive en
 * la Action `App\Actions\Consulta\GuardarTextoDelExamen`, que es la misma regla para las cuatro. Acá queda lo
 * del HTTP: de quién es la consulta, qué solapa es (la clave de la URL, acotada al catálogo: una que no existe
 * es un **404** antes de tocar la base) y qué se le dice al médico.
 *
 * Como todos los caminos de escritura de la consulta, la Action deja su rastro en `sync_changes` con
 * `source = web`: **el sync del escritorio no cambia**, sigue subiendo su `UPDATE` de la fila entera.
 */
class ConsultaTextoDelExamenController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardar(Request $request, Consulta $consulta, string $solapa, GuardarTextoDelExamen $guardar)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $consulta = $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );

        // El texto es libre: no hay un largo de negocio que validar (la columna es un `text` del legado). El
        // tope es sólo para no aceptar un cuerpo absurdo. El campo se llama como la solapa.
        $datos = $request->validate([
            $solapa => 'nullable|string|max:20000',
        ]);

        $resultado = $guardar->ejecutar($consulta, $solapa, $datos[$solapa] ?? null, null, 'web');

        // El tratamiento tiene su propio ítem del panel; las demás son solapas del examen.
        $destino = redirect()->route('clinica.consulta', $solapa === TextosDelExamen::TRATAMIENTO
            ? [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_TRATAMIENTO]
            : [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_EVALUACION, 'pestana' => $solapa]);

        // El guard del sync puede rechazar una columna porque hay una edición **más nueva** sobre ella (de
        // otro equipo, el escritorio o el teléfono). Se dice, en vez de mostrar un "Guardado" falso.
        if ($resultado->huboConflicto()) {
            return $destino->with('error', TextosDelExamen::avisoBloqueada($solapa));
        }

        return $destino->with('estado', $resultado->sinCambios()
            ? TextosDelExamen::avisoSinCambios($solapa)
            : TextosDelExamen::avisoGuardada($solapa));
    }
}
