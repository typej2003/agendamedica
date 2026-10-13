<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\GuardarEnfermedadActual;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;

/**
 * **La enfermedad actual de una consulta** (WEB-4.2, ítem 2 del panel del legado): guardar el texto libre
 * de `consultas.enfermedadactual`.
 *
 * Guardar y no autoguardar es la decisión de producto (TODO-WEB.md WEB-4.2: Alexander lo pidió editable el
 * 2026-10-09): es texto clínico y el médico decide cuándo queda escrito. El aviso de "cambios sin
 * guardar" y el bloqueo del navegador al salir los resuelve `public/js/clinica/consulta.js`.
 *
 * La escritura es la Action `App\Actions\Consulta\GuardarEnfermedadActual`: ahí viven el rastro en
 * `sync_changes` (`source = web`, por columna, para que el teléfono no pise el cambio) y el guard
 * last-write-wins del sync. Acá sólo se valida el HTTP y se cuenta el resultado.
 */
class ConsultaEnfermedadController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardar(Request $request, Consulta $consulta, GuardarEnfermedadActual $guardar)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $consulta = $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );

        // El texto es libre: no hay un largo de negocio que validar (la columna es un `text` del legado).
        // El tope es sólo para no aceptar un cuerpo absurdo.
        $datos = $request->validate([
            'enfermedadactual' => 'nullable|string|max:20000',
        ]);

        $guardado = $guardar->ejecutar($consulta, $datos['enfermedadactual'] ?? null, null, 'web');

        $destino = redirect()->route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_ENFERMEDAD,
        ]);

        // El guard del sync puede rechazar la escritura porque hay una edición **más nueva** sobre esa
        // columna (de otro dispositivo o del escritorio). Se dice, en vez de mostrar un "Guardado" falso.
        return $guardado
            ? $destino->with('estado', 'Enfermedad actual guardada.')
            : $destino->with('error', 'Hay un cambio más reciente de la enfermedad actual (de otro equipo) y no se pisó: vuelve a cargarla y guárdala de nuevo.');
    }
}
