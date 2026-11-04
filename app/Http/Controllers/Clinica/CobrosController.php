<?php

namespace App\Http\Controllers\Clinica;

use App\Http\Controllers\Controller;
use App\Models\CuentaBancaria;
use App\Models\MetodoPago;
use App\Models\Pago;
use App\Models\PreferenciaDeCobro;
use App\Pagos\Centavos;
use App\Pagos\CatalogoDePasarelas;
use App\Pagos\CobrosDePagoMovil;
use App\Pagos\Conciliacion\Conciliadores;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * **Cobros** (`/clinica/cobros`): las cuentas de pago móvil del consultorio y los pagos que esperan su
 * confirmación.
 *
 * Dos mitades, en la misma pantalla:
 *
 *  - **Mis cuentas**: el médico carga el banco, el teléfono, la cuenta y el documento del titular; si el
 *    banco tiene API de consulta puede sumar las credenciales y elegir la confirmación automática (con su
 *    botón de prueba). Cada cuenta crea el método de pago de pago móvil, que es el que usa el link de la
 *    cita cuando el médico decide cobrar con lo suyo.
 *  - **Por confirmar**: las declaraciones de los pacientes que el banco no pudo confirmar solas. Las
 *    confirman el médico **y** la secretaría, que son quienes ven la plata en la cuenta.
 *
 * No se cobra desde acá: eso es del paciente en el link. Acá se configura y se confirma.
 */
class CobrosController extends Controller
{
    public function index(Request $request): View
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = (string) $contexto['reg_medico'];
        $administra = $request->user()->hasAnyRole(['Root', 'Medico']);

        return view('clinica.cobros.index', [
            'regMedico' => $regMedico,
            'administra' => $administra,
            'cuentas' => $administra ? $this->cuentas($regMedico) : collect(),
            'preferencia' => PreferenciaDeCobro::de($regMedico),
            'porConfirmar' => $this->porConfirmar($regMedico),
            'bancos' => (array) config('pagos.pago_movil.catalogo', []),
        ]);
    }

    /** Guarda (alta o edición) una cuenta de cobro del consultorio. */
    public function guardarCuenta(Request $request): RedirectResponse
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = (string) $contexto['reg_medico'];

        $datos = $request->validate([
            'id' => ['nullable', 'integer'],
            'banco_codigo' => ['required', 'digits:4'],
            'telefono' => ['nullable', 'string', 'max:15'],
            'numero' => ['nullable', 'string', 'max:30'],
            'titular' => ['required', 'string', 'max:120'],
            'documento_tipo' => ['required', Rule::in(CuentaBancaria::DOCUMENTOS)],
            'documento_numero' => ['required', 'string', 'max:20'],
            'conciliacion' => ['required', Rule::in([
                CuentaBancaria::CONCILIACION_MANUAL,
                CuentaBancaria::CONCILIACION_AUTOMATICA,
            ])],
            'consumer_key' => ['nullable', 'string', 'max:120'],
            'consumer_secret' => ['nullable', 'string', 'max:200'],
            'token_url' => ['nullable', 'url', 'max:200'],
            'consulta_url' => ['nullable', 'url', 'max:200'],
        ], [
            'banco_codigo.required' => 'Elige el banco de la cuenta.',
            'banco_codigo.digits' => 'El banco es un código de cuatro dígitos.',
            'titular.required' => 'Escribe el nombre del titular.',
            'documento_numero.required' => 'Falta el documento del titular.',
        ]);

        $cuenta = null;
        if (! empty($datos['id'])) {
            $cuenta = CuentaBancaria::where('propietario', CuentaBancaria::MEDICO)
                ->where('reg_medico', $regMedico)
                ->find($datos['id']);
            abort_if($cuenta === null, 404);
        }

        $telefono = (string) preg_replace('/\D/', '', (string) ($datos['telefono'] ?? ''));
        $numero = (string) preg_replace('/\D/', '', (string) ($datos['numero'] ?? ''));

        if ($telefono !== '' && ! preg_match('/^04\d{9}$/', $telefono)) {
            return back()->withInput()->withErrors(['telefono' => 'El teléfono tiene 11 dígitos y empieza por 04.']);
        }

        if ($telefono === '' && $numero === '') {
            return back()->withInput()->withErrors(['telefono' => 'Carga el teléfono del pago móvil o el número de cuenta.']);
        }

        $esAutomatica = $datos['conciliacion'] === CuentaBancaria::CONCILIACION_AUTOMATICA;
        $banco = (array) config('pagos.pago_movil.bancos.' . $datos['banco_codigo'], []);

        if ($esAutomatica && empty($banco['driver'])) {
            return back()->withInput()->withErrors([
                'conciliacion' => 'Ese banco todavía no tiene confirmación automática. Déjala manual y confirma los pagos a mano.',
            ]);
        }

        $credenciales = array_filter([
            'consumer_key' => trim((string) ($datos['consumer_key'] ?? '')),
            'consumer_secret' => trim((string) ($datos['consumer_secret'] ?? '')),
            'token_url' => trim((string) ($datos['token_url'] ?? '')),
            'consulta_url' => trim((string) ($datos['consulta_url'] ?? '')),
        ], fn ($valor) => $valor !== '');

        if ($esAutomatica && ! $this->tieneCredenciales($credenciales)) {
            return back()->withInput()->withErrors([
                'consumer_key' => 'Para la confirmación automática hacen falta la clave y el secreto del comercio.',
            ]);
        }

        if ($credenciales === [] && $cuenta !== null) {
            $credenciales = (array) ($cuenta->credenciales ?? []);
        }

        $valores = [
            'propietario' => CuentaBancaria::MEDICO,
            'reg_medico' => $regMedico,
            'moneda' => 'VES',
            'tipo' => $telefono !== '' ? CuentaBancaria::PAGO_MOVIL : CuentaBancaria::CUENTA,
            'banco_codigo' => $datos['banco_codigo'],
            'telefono' => $telefono !== '' ? $telefono : null,
            'numero' => $numero !== '' ? $numero : null,
            'titular' => trim($datos['titular']),
            'documento_tipo' => $datos['documento_tipo'],
            'documento_numero' => $datos['documento_numero'],
            'conciliacion' => $datos['conciliacion'],
            'credenciales' => $credenciales !== [] ? $credenciales : null,
            'activa' => true,
        ];

        if ($cuenta === null) {
            $valores['predeterminada'] = ! CuentaBancaria::where('propietario', CuentaBancaria::MEDICO)
                ->where('reg_medico', $regMedico)
                ->exists();
            $cuenta = CuentaBancaria::create($valores);
        } else {
            $cuenta->update($valores);
        }

        $this->sincronizarMetodo($cuenta);

        return redirect()
            ->route('clinica.cobros')
            ->with('estado', 'Guardamos la cuenta de ' . $cuenta->bancoNombre() . '.');
    }

    /** Prueba las credenciales del banco sin guardar nada más. */
    public function probarCuenta(Request $request, CuentaBancaria $cuenta): RedirectResponse
    {
        $this->autorizarCuenta($request, $cuenta);

        $conciliador = Conciliadores::delBanco($cuenta);

        if ($conciliador === null) {
            return redirect()->route('clinica.cobros')
                ->with('error', 'Ese banco todavía no tiene confirmación automática.');
        }

        try {
            $resultado = $conciliador->probar($cuenta);
        } catch (Throwable $e) {
            $resultado = ['ok' => false, 'mensaje' => $e->getMessage()];
        }

        $cuenta->update([
            'conciliacion_verificada_el' => $resultado['ok'] ? now() : null,
            'conciliacion_error' => $resultado['ok'] ? null : mb_substr((string) $resultado['mensaje'], 0, 250),
        ]);

        return redirect()->route('clinica.cobros')
            ->with($resultado['ok'] ? 'estado' : 'error', $resultado['mensaje']);
    }

    /** Deja una cuenta como la predeterminada del consultorio. */
    public function predeterminada(Request $request, CuentaBancaria $cuenta): RedirectResponse
    {
        $this->autorizarCuenta($request, $cuenta);

        CuentaBancaria::where('propietario', CuentaBancaria::MEDICO)
            ->where('reg_medico', $cuenta->reg_medico)
            ->update(['predeterminada' => false]);

        $cuenta->update(['predeterminada' => true]);

        return redirect()->route('clinica.cobros')
            ->with('estado', 'Ahora cobras con ' . $cuenta->bancoNombre() . ' por defecto.');
    }

    /** Elige quién recauda las citas: la plataforma o el consultorio. */
    public function preferencia(Request $request): RedirectResponse
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = (string) $contexto['reg_medico'];

        $datos = $request->validate([
            'recaudador' => ['required', Rule::in([PreferenciaDeCobro::PLATAFORMA, PreferenciaDeCobro::MEDICO])],
        ], ['recaudador.required' => 'Elige quién cobra las citas.']);

        if ($datos['recaudador'] === PreferenciaDeCobro::MEDICO) {
            $tieneMetodo = MetodoPago::delMedico($regMedico)
                ->activos()
                ->paraUso(MetodoPago::COBROS)
                ->where('pasarela', 'pago_movil')
                ->get()
                ->contains(fn (MetodoPago $metodo): bool => $metodo->estaConfigurado());

            if (! $tieneMetodo) {
                return redirect()->route('clinica.cobros')
                    ->with('error', 'Primero carga una cuenta de pago móvil: sin ella no puedes cobrar tus citas.');
            }
        }

        PreferenciaDeCobro::fijar($regMedico, $datos['recaudador']);

        return redirect()->route('clinica.cobros')->with(
            'estado',
            $datos['recaudador'] === PreferenciaDeCobro::MEDICO
                ? 'Tus citas se cobran en tus cuentas.'
                : 'Tus citas se cobran con la cuenta de la plataforma.',
        );
    }

    /** Confirma a mano un pago que el banco no confirmó (o que no tiene API). */
    public function confirmar(Request $request, Pago $pago, CobrosDePagoMovil $cobros): RedirectResponse
    {
        $this->autorizarPago($request, $pago);

        $datos = $request->validate([
            'referencia' => ['nullable', 'string', 'max:40'],
            'monto' => ['nullable', 'string', 'max:20'],
        ]);

        $referencia = trim((string) ($datos['referencia'] ?? ''));
        if ($referencia === '') {
            $referencia = (string) ($pago->transacciones()->latest('id')->value('referencia') ?? '');
        }

        $monto = null;
        if (isset($datos['monto']) && trim((string) $datos['monto']) !== '') {
            $monto = Centavos::deDecimal($datos['monto']);
        }

        $resultado = $cobros->confirmarAMano($pago, $referencia, $monto, (int) $request->user()->id);

        return redirect()->route('clinica.cobros')
            ->with($resultado->ok ? 'estado' : 'error', $resultado->mensaje);
    }

    /** Rechaza un pago que no llegó. */
    public function rechazar(Request $request, Pago $pago, CobrosDePagoMovil $cobros): RedirectResponse
    {
        $this->autorizarPago($request, $pago);

        $datos = $request->validate(['motivo' => ['nullable', 'string', 'max:200']]);

        $resultado = $cobros->rechazarAMano(
            $pago,
            trim((string) ($datos['motivo'] ?? '')),
            (int) $request->user()->id,
        );

        return redirect()->route('clinica.cobros')
            ->with($resultado->ok ? 'estado' : 'error', $resultado->mensaje);
    }

    /* ------------------------------------------------------------------ */
    /* Interno                                                             */
    /* ------------------------------------------------------------------ */

    /** @return \Illuminate\Support\Collection<int,CuentaBancaria> */
    private function cuentas(string $regMedico)
    {
        return CuentaBancaria::where('propietario', CuentaBancaria::MEDICO)
            ->where('reg_medico', $regMedico)
            ->orderByDesc('predeterminada')
            ->orderBy('id')
            ->get();
    }

    /** @return \Illuminate\Support\Collection<int,Pago> */
    private function porConfirmar(string $regMedico)
    {
        return Pago::with(['metodoPago', 'cuentaBancaria', 'transacciones'])
            ->where('recaudador', Pago::RECAUDADOR_MEDICO)
            ->where('beneficiario_reg_medico', $regMedico)
            ->whereIn('estado', [Pago::PENDIENTE, Pago::EN_PROCESO])
            ->whereHas('metodoPago', fn ($query) => $query->where('pasarela', CatalogoDePasarelas::PAGO_MOVIL))
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Cada cuenta tiene su método de pago: es lo que el link de la cita resuelve cuando el médico eligió
     * cobrar con lo suyo.
     */
    private function sincronizarMetodo(CuentaBancaria $cuenta): MetodoPago
    {
        $metodo = MetodoPago::where('propietario', MetodoPago::MEDICO)
            ->where('reg_medico', $cuenta->reg_medico)
            ->where('cuenta_bancaria_id', $cuenta->id)
            ->where('pasarela', 'pago_movil')
            ->first();

        $valores = [
            'propietario' => MetodoPago::MEDICO,
            'reg_medico' => $cuenta->reg_medico,
            'pasarela' => 'pago_movil',
            'variante' => 'pago_movil',
            'nombre' => 'Pago móvil · ' . $cuenta->bancoNombre(),
            'activo' => true,
            'entorno' => MetodoPago::PRODUCCION,
            'cuenta_bancaria_id' => $cuenta->id,
            'disponible_para' => MetodoPago::COBROS,
            'orden' => $metodo?->orden ?? 0,
        ];

        if ($metodo === null) {
            $valores['webhook_token'] = Str::random(64);
            $metodo = MetodoPago::create($valores);
        } else {
            $metodo->update($valores);
        }

        return $metodo;
    }

    /** @param  array<string,string>  $credenciales */
    private function tieneCredenciales(array $credenciales): bool
    {
        return trim((string) ($credenciales['consumer_key'] ?? '')) !== ''
            && trim((string) ($credenciales['consumer_secret'] ?? '')) !== '';
    }

    private function autorizarCuenta(Request $request, CuentaBancaria $cuenta): void
    {
        $contexto = $request->attributes->get('contexto_clinico');

        abort_unless(
            $cuenta->propietario === CuentaBancaria::MEDICO
            && (string) $cuenta->reg_medico === (string) ($contexto['reg_medico'] ?? ''),
            404,
        );
    }

    private function autorizarPago(Request $request, Pago $pago): void
    {
        $contexto = $request->attributes->get('contexto_clinico');

        abort_unless(
            $pago->recaudador === Pago::RECAUDADOR_MEDICO
            && (string) $pago->beneficiario_reg_medico === (string) ($contexto['reg_medico'] ?? '')
            && $pago->esDePagoMovil(),
            404,
        );
    }
}
