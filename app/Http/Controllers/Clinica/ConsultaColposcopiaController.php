<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\GuardarColposcopiaDeLaConsulta;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\PestanasDelExamen;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;

/**
 * **La colposcopía de una consulta** (WEB-6.5, ítem 4 del panel del legado): guardar el texto del informe.
 *
 * La solapa se **lee** en `ConsultaController::ver` (`?seccion=evaluacion&pestana=colposcopia`); acá sólo se
 * escribe, que es la partición que la guía de la arquitectura pide (controladores por responsabilidad).
 *
 * Guardar y no autoguardar: es texto clínico y el médico decide cuándo queda escrito. El aviso de "cambios
 * sin guardar" y el bloqueo del navegador al salir los resuelve `public/js/clinica/consulta.js`.
 *
 * La escritura es la Action `App\Actions\Consulta\GuardarColposcopiaDeLaConsulta`: ahí viven las **dos**
 * columnas del texto (la canónica `consultas.observaciones` y su copia `examen_fisico_nuevo.colposcopia`), el
 * rastro en `sync_changes` (`source = web`, por columna) y el guard last-write-wins del sync. Acá sólo se
 * valida el HTTP y se cuenta el resultado.
 */
class ConsultaColposcopiaController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardar(Request $request, Consulta $consulta, GuardarColposcopiaDeLaConsulta $guardar)
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
            'colposcopia' => 'nullable|string|max:20000',
        ]);

        $resultado = $guardar->ejecutar($consulta, $datos['colposcopia'] ?? null, null, 'web');

        $destino = redirect()->route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_EVALUACION,
            'pestana' => PestanasDelExamen::COLPOSCOPIA,
        ]);

        // El guard del sync puede rechazar una columna porque hay una edición **más nueva** sobre ella (de
        // otro equipo, el escritorio o el teléfono). Se dice cuál, en vez de mostrar un "Guardado" falso.
        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Otro equipo editó la colposcopía más recientemente y no se pisó: '
                . 'vuelve a cargarla y guárdala de nuevo.');
        }

        return $destino->with('estado', $resultado->sinCambios()
            ? 'La colposcopía no tenía cambios.'
            : 'Colposcopía guardada.');
    }
}
