<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\CopiarDeLaConsultaAnterior;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;

/**
 * **La casilla «Anterior»** de motivos y diagnósticos (WEB-11.4), las dos secciones que guardan al instante: marcarla (`activar=1`)
 * trae lo de la consulta anterior de la misma historia y desmarcarla saca de ésta lo que coincide con la anterior. Es un `POST` con
 * redirect a la sección; la regla es la Action `CopiarDeLaConsultaAnterior`. (Los récipes y los textos traen en el editor y guardan
 * con su propio botón: no pasan por acá.)
 */
class ConsultaAnteriorController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    /** `qué se trae => [método que trae, método que saca, sección a la que se vuelve, singular, plural]` */
    private const COPIABLES = [
        'motivos' => ['motivos', 'quitarMotivos', ConsultaShellVista::SECCION_MOTIVOS, 'motivo', 'motivos'],
        'diagnosticos' => ['diagnosticos', 'quitarDiagnosticos', ConsultaShellVista::SECCION_DIAGNOSTICO, 'diagnóstico', 'diagnósticos'],
    ];

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function copiar(Request $request, Consulta $consulta, string $que, CopiarDeLaConsultaAnterior $copiar)
    {
        abort_unless(isset(self::COPIABLES[$que]), 404);

        $contexto = $request->attributes->get('contexto_clinico');
        $consulta = $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );

        [$traer, $sacar, $seccion, $singular, $plural] = self::COPIABLES[$que];

        $destino = redirect()->route('clinica.consulta', [$consulta->id, 'seccion' => $seccion]);

        if (! $copiar->hayAnterior($consulta)) {
            return $destino->with('error', 'Es la primera consulta de la historia: no hay una anterior.');
        }

        $activar = $request->boolean('activar');
        $resultado = $copiar->{$activar ? $traer : $sacar}($consulta, 'web');

        if ($resultado['desde'] === null) {
            return $destino->with('error', 'Ninguna consulta anterior tiene ' . $plural . '.');
        }

        $cuantos = $resultado[$activar ? 'copiados' : 'quitados'];
        $numero = 'la consulta N.º ' . $resultado['desde'];
        $cosa = $cuantos === 1 ? $singular : $plural;

        $mensaje = match (true) {
            $activar && $cuantos === 0 => 'Los ' . $plural . ' de ' . $numero . ' ya estaban en ésta.',
            $activar => 'Traídos ' . $cuantos . ' ' . $cosa . ' de ' . $numero . '.',
            $cuantos === 0 => 'Esta consulta no tenía ' . $plural . ' de ' . $numero . '.',
            default => 'Quitados ' . $cuantos . ' ' . $cosa . ' que venían de ' . $numero . '.',
        };

        return $destino->with('estado', $mensaje);
    }
}
