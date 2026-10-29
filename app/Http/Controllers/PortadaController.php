<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Reservas\CatalogoDeMedicos;
use App\Services\CupoDelPlan;
use Illuminate\Http\Request;

/**
 * La portada pública (`/`). **Le habla primero al médico** —cobramos por médico, así que la conversión que
 * importa es el registro (`medico.registro`)— y deja al paciente un bloque secundario que lleva al portal de
 * reservas.
 *
 * Reemplaza al componente Livewire `Welcome`: la portada no tiene estado y cada clic de aquél era un viaje al
 * servidor sólo para redirigir. Como página Blade normal tiene su `<title>` y su descripción.
 */
class PortadaController extends Controller
{
    public function __invoke(Request $request, CatalogoDeMedicos $catalogo)
    {
        // La portada vieja filtraba médicos con `/?especialidad=<slug>`: esos enlaces van al portal de reservas.
        $especialidad = trim((string) $request->query('especialidad', ''));
        if ($especialidad !== '') {
            return redirect()->route('reservas.catalogo', ['especialidad' => $especialidad]);
        }

        $planes = Plan::where('activo', true)->where('visible', true)->orderBy('orden')->orderBy('id')->get();
        $cupos = app(CupoDelPlan::class);

        return view('portada', [
            // Los mismos planes que el médico ve en `/clinica/plan`: si no hay ninguno visible, la sección no sale.
            'planes' => $planes,
            // El “desde” del plan por asiento (o el precio fijo), en centavos.
            'preciosDesdeE2' => $planes->mapWithKeys(fn (Plan $plan) => [$plan->id => $cupos->precioDesdeUsdE2($plan)])->all(),
            'preciosAnualesDesdeE2' => $planes->mapWithKeys(fn (Plan $plan) => [$plan->id => $cupos->precioDesdeUsdE2($plan, Plan::ANUAL)])->all(),
            'ahorrosAnualesE2' => $planes->mapWithKeys(fn (Plan $plan) => [
                $plan->id => $plan->ofreceMensual()
                    ? $cupos->ahorroAnualUsdE2($plan, ['medicos' => 0, 'secretarias' => 0])
                    : 0,
            ])->all(),
            'mesesDePrueba' => max(0, (int) config('servicios.prueba_meses')),
            // Sólo las especialidades con médicos **con agenda publicada** (las mismas del catálogo de reservas):
            // un enlace a una lista vacía no ayuda a nadie.
            'especialidades' => $catalogo->especialidades(),
        ]);
    }
}
