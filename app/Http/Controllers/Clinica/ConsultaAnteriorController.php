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
 * **«Anterior»** (WEB-11.4): copia a la consulta los motivos, los diagnósticos o los récipes de la consulta anterior de la misma historia.
 * Es un `POST` con redirect a la sección (el resultado se cuenta en la franja de arriba); la regla es la Action `CopiarDeLaConsultaAnterior`.
 */
class ConsultaAnteriorController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    /** `qué se copia => [método de la Action, sección a la que se vuelve, cómo se nombra lo copiado]` */
    private const COPIABLES = [
        'motivos' => ['motivos', ConsultaShellVista::SECCION_MOTIVOS, 'motivo', 'motivos'],
        'diagnosticos' => ['diagnosticos', ConsultaShellVista::SECCION_DIAGNOSTICO, 'diagnóstico', 'diagnósticos'],
        'recipes' => ['recipes', ConsultaShellVista::SECCION_RECIPES, 'récipe', 'récipes'],
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

        [$metodo, $seccion, $singular, $plural] = self::COPIABLES[$que];

        $destino = redirect()->route('clinica.consulta', [$consulta->id, 'seccion' => $seccion]);

        if (! $copiar->hayAnterior($consulta)) {
            return $destino->with('error', 'Esta es la primera consulta de la historia: no hay una anterior de la que copiar.');
        }

        $resultado = $copiar->{$metodo}($consulta, 'web');

        if ($resultado['desde'] === null) {
            return $destino->with('error', 'Ninguna consulta anterior de este paciente tiene ' . $plural . ' para copiar.');
        }

        if ($resultado['copiados'] === 0) {
            return $destino->with('estado', 'La consulta N.º ' . $resultado['desde'] . ' no tenía ' . $plural . ' nuevos: ya estaban en esta.');
        }

        return $destino->with('estado', 'Copiados ' . $resultado['copiados'] . ' ' . ($resultado['copiados'] === 1 ? $singular : $plural)
            . ' de la consulta N.º ' . $resultado['desde'] . '.');
    }
}
