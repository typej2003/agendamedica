<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReporteEscritorio;
use Illuminate\Http\Request;

/**
 * **Reportes de fallo del escritorio.**
 *
 * `POST /api/escritorio/reportes` — lo llaman el launcher, el instalador y el puente cuando algo falla.
 * Es **público a propósito**: el que falla puede ser el paso previo a tener credencial (una API key mal
 * copiada, un instalador que no arranca), y un reporte que no se puede mandar no sirve de nada. Por eso
 * se limita por tamaño y por tasa, y no lleva nada clínico: la etapa, el mensaje y el final del log.
 *
 * La bandeja para leerlos y borrarlos está en `/admin/reportes-escritorio`.
 */
class ReportesEscritorioController extends Controller
{
    /** Tope del detalle: el final del log, no el log entero. */
    private const MAX_DETALLE = 64000;

    public function guardar(Request $request)
    {
        $datos = $request->validate([
            'tipo'          => ['required', 'string', 'in:' . implode(',', ReporteEscritorio::TIPOS)],
            'mensaje'       => ['required', 'string', 'max:500'],
            'etapa'         => ['nullable', 'string', 'max:60'],
            'reg_medico'    => ['nullable', 'string', 'max:40'],
            'aplicacion'    => ['nullable', 'string', 'max:60'],
            'equipo'        => ['nullable', 'string', 'max:100'],
            'version_canal' => ['nullable', 'string', 'max:30'],
            'windows'       => ['nullable', 'string', 'max:100'],
            'detalle'       => ['nullable', 'string', 'max:' . self::MAX_DETALLE],
        ]);

        // Se recorta también acá: el cliente puede mentir con el largo.
        if (isset($datos['detalle'])) {
            $datos['detalle'] = mb_substr($datos['detalle'], 0, self::MAX_DETALLE);
        }

        $datos['ip'] = $request->ip();

        $reporte = ReporteEscritorio::create($datos);

        return response()->json([
            'ok' => true,
            'id' => $reporte->id,
        ], 201);
    }
}
