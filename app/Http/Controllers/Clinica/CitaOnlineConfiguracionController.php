<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\CitaOnline\ConfiguracionDeCitaOnline;
use App\Clinica\CitaOnline\Vistas\CitaOnlineConfiguracionVista;
use App\Http\Controllers\Controller;
use App\Models\Evolucion;
use App\Models\MotivoCita;
use Illuminate\Http\Request;

/**
 * **Cita Online** dentro de Configuración (2026-10-30), la mitad de configuración del cobro de citas.
 *
 * Es una fila por médico (`evolucion`, bajo el `reg_medico` del contexto) y se guarda entera, como las
 * demás pantallas de configuración de la web. Lo que se configura acá es **cuánto se cobra al reservar**
 * (en puntos base) y **si la agenda se publica** en el portal y en el bot; el cobro al reservar, el plazo
 * de 30 minutos y la liberación del cupo son de la tarea siguiente.
 *
 * Mismos roles que «Mi plan» y el resto de Configuración: sólo el médico (`role:Root|Medico`); la
 * secretaría recibe 403.
 */
class CitaOnlineConfiguracionController extends Controller
{
    /** La pantalla: el porcentaje, la casilla y el ejemplo con un motivo con precio. */
    public function index(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $configuracion = ConfiguracionDeCitaOnline::de(
            Evolucion::where('reg_medico', $contexto['reg_medico'])->first(),
        );

        return view('clinica.cita-online.index', [
            'vm' => new CitaOnlineConfiguracionVista($configuracion, $this->motivoConPrecio($contexto['reg_medico'])),
        ]);
    }

    /**
     * Guardar las dos decisiones juntas, como el resto de Configuración.
     *
     * El porcentaje se escribe 0–100 y se guarda en puntos base (`10000` = 100 %). La casilla se guarda
     * explícita (`true`/`false`): `null` es «publicada» y queda para las filas viejas y las que sube el
     * escritorio, así que apagarla desde acá tiene que dejar `false`, no `null`.
     */
    public function guardar(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $datos = $request->validate([
            'porcentaje' => ['required', 'numeric', 'min:0', 'max:100'],
            'publicar_agenda' => ['nullable', 'boolean'],
        ], [
            'porcentaje.required' => 'Indica el porcentaje que se cobra al reservar.',
            'porcentaje.numeric' => 'El porcentaje tiene que ser un número.',
            'porcentaje.min' => 'El porcentaje no puede ser menor que 0.',
            'porcentaje.max' => 'El porcentaje no puede ser mayor que 100.',
            'publicar_agenda.boolean' => 'La casilla de publicación no es válida.',
        ]);

        $evolucion = Evolucion::firstOrNew(['reg_medico' => $contexto['reg_medico']]);
        $evolucion->porcentaje_cita_online_bp = ConfiguracionDeCitaOnline::puntosBaseDe($datos['porcentaje']);
        $evolucion->publicar_agenda = $request->boolean('publicar_agenda');

        // `clave` es NOT NULL en el esquema legado sin default: una fila nueva de un médico que nunca
        // guardó su configuración rompería el insert (misma trampa que en `CumpleanosController`).
        if (! $evolucion->exists && $evolucion->clave === null) {
            $evolucion->clave = 0;
        }

        $evolucion->save();

        return redirect()
            ->route('clinica.configuracion.cita-online')
            ->with('estado', 'Listo: se guardó la configuración de la cita online.');
    }

    /** El primer motivo de cita con precio del médico, en orden alfabético (sólo para el ejemplo). */
    private function motivoConPrecio(string $regMedico): ?MotivoCita
    {
        return MotivoCita::where('reg_medico', $regMedico)
            ->whereNotNull('precio')
            ->where('precio', '>', 0)
            ->orderBy('tipo_atencion')
            ->first();
    }
}
