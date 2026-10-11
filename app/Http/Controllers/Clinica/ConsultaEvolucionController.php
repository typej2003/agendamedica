<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\GuardarEvolucionDeLaConsulta;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;

/**
 * **La evolución de una consulta** (WEB-4.6, ítem 9 del panel del legado): guardar el texto libre de
 * `consultas.evolucion`, que es el seguimiento de la atención.
 *
 * Guardar y no autoguardar es la misma decisión de producto que la enfermedad actual (WEB-4.2): es texto
 * clínico y el médico decide cuándo queda escrito. El aviso de "cambios sin guardar", el del navegador al
 * salir y la copia de una plantilla al campo los resuelve `public/js/clinica/consulta.js`.
 *
 * La escritura es la Action `App\Actions\Consulta\GuardarEvolucionDeLaConsulta`: ahí viven el rastro en
 * `sync_changes` (`source = web`, por columna, para que el escritorio no se pise con algo viejo) y el
 * guard last-write-wins del sync. Acá sólo se valida el HTTP y se cuenta el resultado.
 */
class ConsultaEvolucionController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardar(Request $request, Consulta $consulta, GuardarEvolucionDeLaConsulta $guardar)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $consulta = $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );

        // El texto es libre: no hay un largo de negocio que validar (la columna es un `longText` del
        // legado). El tope es sólo para no aceptar un cuerpo absurdo, el mismo de la enfermedad actual.
        $datos = $request->validate([
            'evolucion' => 'nullable|string|max:20000',
        ]);

        $guardado = $guardar->ejecutar($consulta, $datos['evolucion'] ?? null, null, 'web');

        $destino = redirect()->route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_EVOLUCION,
        ]);

        // El guard del sync puede rechazar la escritura porque hay una edición **más nueva** sobre esa
        // columna (del escritorio o de otro equipo). Se dice, en vez de mostrar un "Guardado" falso.
        return $guardado
            ? $destino->with('estado', 'Evolución guardada.')
            : $destino->with('error', 'Hay un cambio más reciente de la evolución (de otro equipo) y no se pisó: vuelve a cargarla y guárdala de nuevo.');
    }
}
