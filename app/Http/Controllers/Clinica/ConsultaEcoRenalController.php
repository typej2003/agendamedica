<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\GuardarEcoRenal;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\EcoRenal;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\IndicadoresIncidentesVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;

/**
 * **Eco renal** (nefrología pediátrica, 2026-10-19): guardar el eco de la consulta. Es la segunda solapa de
 * *Indicadores incidentes*, porque el escritorio lo abre desde el botón de ese panel.
 *
 * Una fila por consulta en `ultra_renal` (clave `historia` + `consulta`), con las columnas de `EcoRenal`. La escritura
 * es la Action `GuardarEcoRenal`.
 */
class ConsultaEcoRenalController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardar(Request $request, Consulta $consulta, GuardarEcoRenal $guardar)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $consulta = $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );

        // Los topes son los de las columnas del legado: `long varchar` en los textos y `date` en la fecha.
        $reglas = [];
        foreach (EcoRenal::campos() as $clave => $campo) {
            $reglas[$clave] = $campo['clase'] === 'fecha'
                ? ['nullable', 'date']
                : ['nullable', 'string', 'max:20000'];
        }

        $datos = $request->validate($reglas);

        $guardadas = $guardar->ejecutar($consulta, $datos, null, 'web');

        $destino = redirect()->route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_INDICADORES,
            'pestana' => IndicadoresIncidentesVista::RENAL,
        ]);

        return $guardadas > 0
            ? $destino->with('estado', 'Eco renal guardado.')
            : $destino->with('estado', 'Sin cambios: el eco ya estaba así.');
    }
}
