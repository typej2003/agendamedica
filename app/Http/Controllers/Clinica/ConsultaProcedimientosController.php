<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\GuardarEndoscopiaDigestiva;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\EndoscopiaDigestiva;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;

/**
 * **Procedimientos** (gastroenterología pediátrica, 2026-10-19): guardar una endoscopía digestiva de la consulta.
 *
 * El `{tipo}` de la ruta es el de `EndoscopiaDigestiva` (`superior` o `inferior`): cada uno tiene su tabla
 * (`endosc_diges_super` / `endosc_diges_infer`) y su fila por consulta. La escritura es la Action
 * `App\Actions\Consulta\GuardarEndoscopiaDigestiva`. Acá sólo se valida el HTTP —con el tope real de cada columna del
 * legado— y se vuelve a la pestaña del tipo.
 */
class ConsultaProcedimientosController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardar(Request $request, Consulta $consulta, string $tipo, GuardarEndoscopiaDigestiva $guardar)
    {
        abort_unless(EndoscopiaDigestiva::existe($tipo), 404);

        $contexto = $request->attributes->get('contexto_clinico');

        $consulta = $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );

        // Los topes son los de la tabla del legado: `char(30)` el equipo, `char(60)` la indicación y la sedación, y
        // `long varchar` los párrafos. El estado es un carácter.
        $reglas = [];
        foreach (EndoscopiaDigestiva::campos($tipo) as $clave => $campo) {
            $reglas[$clave] = match ($campo['clase']) {
                'fecha' => ['nullable', 'date'],
                'opcion' => ['nullable', 'string', 'max:1'],
                'texto' => ['nullable', 'string', 'max:60'],
                default => ['nullable', 'string', 'max:20000'],
            };
        }

        $datos = $request->validate($reglas);

        $guardadas = $guardar->ejecutar($consulta, $tipo, $datos, null, 'web');

        $destino = redirect()->route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_PROCEDIMIENTOS,
            'pestana' => $tipo,
        ]);

        return $guardadas > 0
            ? $destino->with('estado', 'Procedimiento guardado.')
            : $destino->with('estado', 'Sin cambios: la endoscopía ya estaba así.');
    }
}
