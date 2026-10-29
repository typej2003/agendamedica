<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\GuardarRiesgoQuirurgico;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\QuirurgicoVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;

/**
 * **Riesgo quirúrgico** (cirugía pediátrica, 2026-10-19): guardar los factores marcados al paciente y el resumen de
 * la evaluación de la consulta.
 *
 * Lo marcado vive en `riesgo_paciente` (del **paciente**, no de la consulta) y el resumen en
 * `riesgo_paciente_opera`. La regla está en la Action `GuardarRiesgoQuirurgico`: acá sólo se valida el HTTP.
 */
class ConsultaRiesgoQuirurgicoController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardar(Request $request, Consulta $consulta, GuardarRiesgoQuirurgico $guardar)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $consulta = $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );

        $datos = $request->validate([
            'marcadas'            => ['nullable', 'array'],
            'marcadas.*'          => ['string', 'max:100'],
            'clase'               => ['nullable', 'string', 'max:10'],
            'conclusion'          => ['nullable', 'string', 'max:20000'],
            'nombre_intervencion' => ['nullable', 'string', 'max:150'],
        ]);

        $resultado = $guardar->ejecutar(
            $consulta,
            (array) ($datos['marcadas'] ?? []),
            $datos,
            null,
            'web',
        );

        // En medicina interna (MI-5, 2026-10-28) el riesgo operatorio es una pestaña de su evaluación pre-operatoria: se vuelve ahí.
        $internista = \App\Clinica\Consulta\AlcanceDeLaEspecialidad::de($contexto['specialty']?->slug)->esMedicinaInterna();

        $destino = redirect()->route('clinica.consulta', [
            $consulta->id,
            'seccion' => $internista ? \App\Clinica\Consulta\Interna\MapaDelInternista::SECCION_PREOPERATORIA : ConsultaShellVista::SECCION_QUIRURGICO,
            'pestana' => $internista ? \App\Clinica\Consulta\Interna\MapaDelInternista::PESTANA_RIESGO : QuirurgicoVista::RIESGO,
        ]);

        if ($resultado['marcadas'] === 0 && $resultado['columnas'] === 0) {
            return $destino->with('estado', 'Sin cambios: el riesgo ya estaba así.');
        }

        return $destino->with('estado', 'Riesgo quirúrgico guardado.');
    }
}
