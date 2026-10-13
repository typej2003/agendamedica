<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Cumpleanos\EnviarSaludoDeCumpleanos;
use App\Clinica\FechaDelConsultorio;
use App\Cumpleanos\ConfiguracionDeCumpleanos;
use App\Cumpleanos\CumpleanosDeHoy;
use App\Cumpleanos\MensajeDeCumpleanos;
use App\Cumpleanos\RegistroDeCumpleanos;
use App\Cumpleanos\Vistas\CumpleanosVista;
use App\Http\Controllers\Controller;
use App\Models\Evolucion;
use App\Models\NotificacionCumple;
use App\Notificaciones\Canal;
use App\Notificaciones\ExcepcionDeEnvio;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **Cumpleaños** (WEB-2.9), la última capacidad de F2 y capacidad nueva: ni el escritorio ni el móvil
 * tenían disparador (el móvil sólo tiene el editor de `plantilla_cumple`).
 *
 * Tres cosas en una pantalla:
 *
 *  1. La **configuración por médico** del envío automático —interruptor, canal y hora—, que es
 *     **provisional hasta WEB-8.2** (la pantalla de configuración de notificaciones). Vive acá y no en
 *     el menú: es una herramienta, no un módulo.
 *  2. El **listado de los que cumplen años hoy**, acotado al `reg_medico` del contexto, con el envío
 *     manual por fila.
 *  3. El **envío** en sí, que no vive acá: es `App\Actions\Cumpleanos\EnviarSaludoDeCumpleanos`, la
 *     misma Action que usa el comando `cumpleanos:enviar`. Esta pantalla sólo traduce HTTP.
 *
 * Todo se filtra por el `reg_medico` del contexto: un paciente de otro médico no aparece ni se puede
 * saludar (el POST responde 404 sin confirmar que existe).
 */
class CumpleanosController extends Controller
{
    /**
     * El canal que manda la pantalla cuando elige el **configurado**: la fila ya decidió a quién se le
     * puede mandar con ese canal, así que el POST no lo vuelve a elegir.
     */
    private const CANAL_AUTOMATICO = 'automatico';

    public function __construct(
        private CumpleanosDeHoy $cumpleanos,
        private RegistroDeCumpleanos $registro,
        private MensajeDeCumpleanos $mensajes,
    ) {
    }

    /** La pantalla: la configuración, el listado de hoy y el diálogo de envío. */
    public function index(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $dia = FechaDelConsultorio::dia($request->query('fecha'));

        return view('clinica.cumpleanos.index', ['vm' => $this->vista($contexto, $dia, $request->user()?->trabajaLaConsulta())]);
    }

    /**
     * Guardar la configuración del envío automático.
     *
     * Es **una sola** acción —no un alta y una edición— porque la configuración es una fila por médico y
     * siempre se guarda entera, igual que el resto de las pantallas de configuración de la web. El
     * interruptor es un checkbox: apagado llega como `null` y se guarda **`null`**, no `false`, para que
     * "apagado" tenga un solo valor en la base (el mismo criterio que
     * `motivo_cita.admite_varias_citas_pendientes`).
     *
     * La plantilla se guarda junto con lo demás porque es la misma decisión: qué sale y cuándo. Es la
     * misma columna que edita el móvil (`plantilla_cumple`), así que la pantalla no inventa un segundo
     * lugar donde viviría el texto.
     */
    public function configuracion(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $datos = $request->validate([
            'enviar'    => ['nullable', 'boolean'],
            'canal'     => ['required', 'in:' . implode(',', Canal::todos())],
            'hora'      => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'plantilla' => ['nullable', 'string', 'max:500'],
        ], [
            'canal.in'      => 'Elige uno de los canales de envío.',
            'hora.regex'    => 'La hora va en formato HH:MM, entre 00:00 y 23:59.',
            'hora.required' => 'Falta la hora a la que sale el saludo.',
        ]);

        $activo = (bool) ($datos['enviar'] ?? false);

        $evolucion = Evolucion::firstOrNew(['reg_medico' => $contexto['reg_medico']]);
        $evolucion->enviar_cumpleanos = $activo ? 1 : null;
        $evolucion->canal_cumpleanos = $datos['canal'];
        $evolucion->hora_cumpleanos = ConfiguracionDeCumpleanos::horaParaGuardar($datos['hora']);
        $evolucion->plantilla_cumple = trim((string) ($datos['plantilla'] ?? '')) ?: MensajeDeCumpleanos::PLANTILLA_POR_DEFECTO;

        // `clave` es NOT NULL en el esquema legado sin default: una fila nueva de un médico que nunca
        // guardó su configuración rompería el insert (misma trampa que en `ConfiguracionMedicoController`).
        if (! $evolucion->exists && $evolucion->clave === null) {
            $evolucion->clave = 0;
        }

        $evolucion->save();

        return redirect()
            ->route('clinica.cumpleanos')
            ->with('estado', $activo
                ? 'Listo: el saludo de cumpleaños sale solo todos los días a las ' . $datos['hora'] . ' por ' . Canal::etiqueta($datos['canal']) . '.'
                : 'Listo: se guardó la configuración, pero el envío automático queda apagado.');
    }

    /**
     * Mandar el saludo de **un** paciente, con el mensaje que el usuario vio en el diálogo (que arranca
     * con la plantilla del médico).
     *
     * El canal viaja como `automatico` cuando lo manda la pantalla: la fila decide a quién se le puede
     * mandar por el canal **configurado** (el que usa el envío automático), así que el POST lo resuelve
     * acá y queda una sola versión de esa decisión. Un canal explícito se acepta igual: es lo que va a
     * usar el envío manual por otro canal cuando WEB-8.2 unifique la configuración.
     *
     * No se bloquea un reenvío manual: si alguien aprieta "Enviar" en una fila que ya dice "Enviado", el
     * diálogo lo advierte y sale. Lo que sí es idempotente es el **automático** (el comando), que es el
     * que corre sin que nadie mire; ahí el registro manda.
     */
    public function enviar(Request $request, EnviarSaludoDeCumpleanos $accion)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $datos = $request->validate([
            'paciente' => ['required', 'integer'],
            'fecha'    => ['nullable', 'date'],
            'canal'    => ['required', 'string'],
            'mensaje'  => ['nullable', 'string', 'max:500'],
        ]);

        $dia = FechaDelConsultorio::dia($datos['fecha'] ?? null);
        $configuracion = ConfiguracionDeCumpleanos::de(Evolucion::where('reg_medico', $contexto['reg_medico'])->first());
        $canal = $datos['canal'] === self::CANAL_AUTOMATICO ? $configuracion->canal() : $datos['canal'];

        if (! Canal::existe($canal)) {
            return back()->with('error', 'Elige uno de los canales de envío.');
        }

        $cumpleano = $this->cumpleanos->delMedico($contexto['reg_medico'], $dia)
            ->first(fn ($c) => $c->pacienteId() === (int) $datos['paciente']);

        // Tenancy: el POST puede traer cualquier id; si no está entre los que cumplen años **de este
        // médico** hoy, no se toca (y no se confirma si existe).
        abort_unless($cumpleano !== null, 404);

        try {
            $notificacion = $accion->ejecutar($cumpleano, $contexto['reg_medico'], $canal, $datos['mensaje'] ?? null, $request->user(), forzar: true);
        } catch (ExcepcionDeEnvio|InvalidArgumentException $excepcion) {
            return back()->with('error', $excepcion->getMessage());
        }

        if ($notificacion->estado !== NotificacionCumple::ESTADO_ENVIADA) {
            return back()->with('error', 'El proveedor no aceptó el saludo. El intento quedó registrado.');
        }

        return back()->with('estado', 'Saludo de cumpleaños enviado a ' . $cumpleano->nombre() . ' por ' . Canal::etiqueta($canal) . '.');
    }

    /**
     * Arma el ViewModel de la pantalla. Es lo que comparten `index()` y el redirect de un envío (que
     * vuelve a `index`), y el único lugar donde se leen la configuración y el registro.
     *
     * El **canal** que la pantalla usa para decidir a quién se le puede mandar es el configurado (con el
     * default aplicado): es el que va a usar el comando, así que la fila tiene que decir lo mismo que
     * va a pasar solo.
     */
    private function vista(array $contexto, $dia, ?bool $puedeEditarElMensaje = false): CumpleanosVista
    {
        $cumpleanos = $this->cumpleanos->delMedico($contexto['reg_medico'], $dia);
        $configuracion = ConfiguracionDeCumpleanos::de(Evolucion::where('reg_medico', $contexto['reg_medico'])->first());

        return new CumpleanosVista(
            $dia,
            $cumpleanos,
            $configuracion,
            $configuracion->canal(),
            // La plantilla que se va a mandar de verdad: si el médico nunca escribió una, se muestra (y
            // se guarda, si toca Guardar) la de por defecto — la misma que usa el envío automático.
            (string) ($configuracion->evolucion()?->plantilla_cumple ?: MensajeDeCumpleanos::PLANTILLA_POR_DEFECTO),
            (string) ($contexto['medico']->nombreMostrar ?? ''),
            $this->mensajes,
            $this->registro->cuandoSeSaludo($dia, $cumpleanos->map(fn ($c) => $c->pacienteId())->all()),
            // «Editar el mensaje» (WEB-8.4) sólo para quien puede entrar a esa pantalla: la secretaría no escribe las palabras del médico.
            $puedeEditarElMensaje ? route('clinica.mensajes') : null,
        );
    }
}
