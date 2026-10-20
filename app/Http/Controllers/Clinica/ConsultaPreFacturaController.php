<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\GuardarItemDePreFactura;
use App\Actions\Consulta\QuitarItemDePreFactura;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\PreFacturaDeLaConsulta;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * **La pre-factura de la consulta** (F9): cargar o corregir un concepto (`POST …/prefactura`) y quitarlo
 * (`DELETE …/prefactura`, con el código en el cuerpo: los códigos del legado pueden traer cualquier carácter).
 *
 * Las reglas de escritura viven en `GuardarItemDePreFactura` y `QuitarItemDePreFactura`; acá se valida el HTTP, se
 * resuelve el concepto contra el catálogo del médico y se vuelve a la sección.
 */
class ConsultaPreFacturaController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas, private PreFacturaDeLaConsulta $lectura)
    {
    }

    public function guardar(Request $request, Consulta $consulta, GuardarItemDePreFactura $guardar)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $consulta = $this->resolver($request, $consulta);

        $datos = $request->validate([
            'codigo'   => ['required', 'string', 'max:' . PreFacturaDeLaConsulta::LARGO_CODIGO],
            'cantidad' => ['required', 'numeric', 'gt:0', 'max:' . PreFacturaDeLaConsulta::CANTIDAD_MAXIMA],
            'monto'    => ['nullable', 'numeric', 'min:0', 'max:' . PreFacturaDeLaConsulta::MONTO_MAXIMO],
        ]);

        $concepto = $this->lectura->concepto($this->registrosDelMedico($contexto['medico']), $datos['codigo']);

        if ($concepto === null) {
            throw ValidationException::withMessages(['codigo' => 'Ese concepto no está en el catálogo del consultorio.']);
        }

        $guardadas = $guardar->ejecutar(
            $consulta,
            $concepto,
            (float) $datos['cantidad'],
            isset($datos['monto']) && $datos['monto'] !== '' ? (float) $datos['monto'] : null,
            null,
            'web',
        );

        return $this->volver($consulta)->with(
            'estado',
            $guardadas > 0 ? 'Pre-factura guardada.' : 'Sin cambios: el concepto ya estaba así.',
        );
    }

    public function quitar(Request $request, Consulta $consulta, QuitarItemDePreFactura $quitar)
    {
        $consulta = $this->resolver($request, $consulta);
        $datos = $request->validate(['codigo' => ['required', 'string', 'max:' . PreFacturaDeLaConsulta::LARGO_CODIGO]]);

        $quitado = $quitar->ejecutar($consulta, $datos['codigo'], null, 'web');

        return $this->volver($consulta)->with(
            'estado',
            $quitado ? 'Concepto quitado de la pre-factura.' : 'Ese concepto no estaba en la pre-factura.',
        );
    }

    private function resolver(Request $request, Consulta $consulta): Consulta
    {
        $contexto = $request->attributes->get('contexto_clinico');

        return $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );
    }

    private function volver(Consulta $consulta)
    {
        return redirect()->route('clinica.consulta', [$consulta->id, 'seccion' => ConsultaShellVista::SECCION_PREFACTURA]);
    }
}
