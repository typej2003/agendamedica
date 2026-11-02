<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Pagos\Vistas\IngresosDelMedicoVista;
use App\Http\Controllers\Controller;
use App\Models\Cola;
use App\Pagos\IngresosDelMedico;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * **Mis ingresos** (O-53, 2026-11-02): los pagos de cita del médico con sus estados y los totales del
 * período.
 *
 * Es la vista del médico (y del Root que mira un consultorio), no la del administrador: el panel de pagos de
 * la plataforma es otro (`/admin/pagos`) y no se toca desde acá.
 *
 * Los filtros son simples —estado y rango de fechas— y viajan en la URL, así la pantalla se puede guardar en
 * un marcador. Sólo se leen pagos de cita cuyo beneficiario (o cuyo consultorio) es el del contexto.
 */
class IngresosController extends Controller
{
    public function index(Request $request, IngresosDelMedico $ingresos): View
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = (string) $contexto['reg_medico'];

        $datos = $request->validate([
            'estado' => ['nullable', 'string', 'max:20'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);

        $estado = trim((string) ($datos['estado'] ?? ''));
        $desde = ! empty($datos['desde']) ? Carbon::parse($datos['desde']) : null;
        $hasta = ! empty($datos['hasta']) ? Carbon::parse($datos['hasta']) : null;

        $pagos = $ingresos->pagos($regMedico, $estado !== '' ? $estado : null, $desde, $hasta);

        // Las citas se leen en lote: una consulta por pantalla y no una por pago.
        $citas = Cola::whereIn('id', $pagos->pluck('pagable_id')->unique()->all())
            ->get()
            ->keyBy('id');

        return view('clinica.ingresos.index', [
            'vm' => new IngresosDelMedicoVista(
                $pagos,
                $citas,
                $ingresos->totales($pagos),
                [
                    'estado' => $estado,
                    'desde' => (string) ($datos['desde'] ?? ''),
                    'hasta' => (string) ($datos['hasta'] ?? ''),
                ],
            ),
        ]);
    }
}
