<?php

namespace App\Pagos;

use App\Actions\Pagos\ConfirmarPagoDePlan;
use App\Latidos\Conversion;
use App\Latidos\Latidos;
use App\Mail\PagoDePlanAprobado;
use App\Models\ConfiguracionPago;
use App\Models\Medico;
use App\Models\MetodoPago;
use App\Models\Pago;
use App\Models\PagoEvento;
use App\Models\Plan;
use App\Models\TasaDeCambio;
use App\Models\User;
use App\Services\CupoDelPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * **El motor de cobro** (MVP: planes de los médicos). Crea el pago, pide el OTP, lo confirma y concilia.
 *
 * La regla de oro: **el webhook y el sondeo no son la fuente de verdad**. Antes de aprobar se consulta la
 * transacción a SyPago (`GET transaction/{id}`); el webhook sólo dispara la consulta. Si SyPago no
 * responde en ese momento y el aviso vino por el webhook autenticado, se usa el payload como respaldo,
 * pero el monto ya se validó contra el pago.
 *
 * Aprobar es una transición con `lockForUpdate`: dos webhooks iguales no renuevan el plan dos veces.
 */
final class Cobros
{
    public function __construct(
        private readonly CupoDelPlan $cupos,
        private readonly Latidos $latidos,
    ) {
    }

    /**
     * Crea la intención de pago de un plan. **Usa primero los Latidos** del consultorio: si cubren el total,
     * el pago nace aprobado y no se le pide nada a SyPago; si no, deja el pago en `pendiente` por la
     * diferencia, con la tasa congelada.
     *
     * `$metodo` es nullable porque un pago cubierto al 100 % con Latidos no tiene método. Si queda una parte
     * para la pasarela, el método es obligatorio.
     *
     * `$frecuencia` elige mensual (1 mes) o anual (12 meses, con el precio anual del plan). El pago guarda
     * `meses` para que la aprobación cree el período correcto.
     *
     * @throws RuntimeException si falta la tasa, el método no está activo, el plan no tiene precio, no
     *                          cubre al equipo actual (salvo que sea la renovación del mismo plan) o no
     *                          ofrece la frecuencia elegida
     */
    public function crearParaPlan(
        string $regMedico,
        Plan $plan,
        ?MetodoPago $metodo = null,
        ?int $usuarioId = null,
        string $frecuencia = Plan::MENSUAL
    ): Pago {
        $regMedico = trim($regMedico);
        if ($regMedico === '') {
            throw new RuntimeException('Falta el consultorio del médico.');
        }

        $frecuencia = $frecuencia === Plan::ANUAL ? Plan::ANUAL : Plan::MENSUAL;
        if ($frecuencia === Plan::ANUAL && ! $plan->ofreceAnual()) {
            throw new RuntimeException('Este plan no tiene precio anual.');
        }
        if ($frecuencia === Plan::MENSUAL && ! $plan->ofreceMensual()) {
            throw new RuntimeException('Este plan no tiene precio mensual.');
        }

        try {
            $this->cupos->exigirQueCubra($plan, $regMedico);
        } catch (ValidationException $e) {
            throw new RuntimeException($e->errors()['plan'][0] ?? $e->getMessage());
        }

        $conteo = $this->cupos->conteo($regMedico);
        $montoUsdE2 = $this->cupos->precioUsdE2($plan, $conteo, $frecuencia);
        if ($montoUsdE2 <= 0) {
            throw new RuntimeException('Este plan es gratuito: no hay nada que cobrar.');
        }
        if ($montoUsdE2 > (int) config('pagos.maximo_monto_usd_e2', 100_000_000)) {
            throw new RuntimeException('El monto del plan supera el máximo permitido.');
        }

        // **Los Latidos primero.** 100 Latidos = USD 1 de descuento; el descuento se congela en USD.
        $latidosNecesarios = Conversion::desdeUsdE2($montoUsdE2);
        $latidosDisponibles = $this->latidos->disponiblesParaUsar($regMedico);
        $latidosUsar = min($latidosDisponibles, $latidosNecesarios);
        $descuentoUsdE2 = Conversion::aUsdE2($latidosUsar);
        $pasarelaUsdE2 = $montoUsdE2 - $descuentoUsdE2;

        // Si queda algo para SyPago, el método es obligatorio y tiene que estar operable.
        if ($pasarelaUsdE2 > 0) {
            if (! $metodo) {
                throw new RuntimeException('Falta el método de pago para cobrar la diferencia del plan.');
            }
            if (! $metodo->activo || $metodo->pasarela !== CatalogoDePasarelas::SYPAGO || ! $metodo->estaConfigurado()) {
                throw new RuntimeException('El método de pago elegido no está disponible.');
            }
        }

        // La moneda la decide el método: SyPago cobra en VES; un método que cobre en USD no necesita tasa.
        // Un pago 100 % con Latidos no tiene moneda de cobro ni tasa: queda en USD con monto cero.
        $moneda = $pasarelaUsdE2 > 0
            ? strtoupper((string) ($metodo->cuentaBancaria?->moneda ?: 'VES'))
            : 'USD';
        $montoE2 = 0;
        $tasaPago = null;
        $netoVesE2 = 0;

        if ($pasarelaUsdE2 > 0) {
            if ($moneda === 'VES') {
                $tasa = TasaDeCambio::actual();
                if (! $tasa) {
                    throw new RuntimeException('Todavía no hay una tasa VES/USD cargada. El administrador tiene que cargarla para poder cobrar.');
                }
                $montoE2 = $tasa->convertirUsdAVes($pasarelaUsdE2);
                $netoVesE2 = $tasa->convertirUsdAVes($montoUsdE2);
                $tasaPago = $tasa->tasa;
                if ($montoE2 <= 0) {
                    throw new RuntimeException('El monto en bolívares quedó en cero: revisa la tasa.');
                }
            } elseif ($moneda === 'USD') {
                $montoE2 = $pasarelaUsdE2;
            } else {
                throw new RuntimeException('La moneda del método de pago no está soportada.');
            }
        }

        $minutos = max(1, (int) ConfiguracionPago::actual()->vigencia_tasa_minutos);
        $latidoId = $latidosUsar > 0 ? $this->latidos->para($regMedico)->id : null;

        $pago = DB::transaction(function () use (
            $regMedico, $plan, $metodo, $frecuencia, $conteo, $montoUsdE2, $latidosUsar,
            $descuentoUsdE2, $pasarelaUsdE2, $moneda, $montoE2, $tasaPago, $netoVesE2, $minutos, $latidoId, $usuarioId
        ) {
            $concepto = 'Plan ' . $plan->nombre . ' · ' . ($frecuencia === Plan::ANUAL ? 'anual' : 'mensual')
                . ($plan->cobraPorAsiento() ? ' · ' . $this->cupos->asientos($plan, $conteo) . ' asientos' : '')
                . ($latidosUsar > 0 ? ' · ' . Conversion::formatear($latidosUsar) . ' de descuento' : '');

            $pago = Pago::create([
                'reg_medico' => $regMedico,
                'beneficiario_tipo' => Pago::BENEFICIARIO_PLATAFORMA,
                'beneficiario_reg_medico' => null,
                'recaudador' => Pago::RECAUDADOR_PLATAFORMA,
                'canal' => Pago::CANAL_PLAN,
                'pagable_tipo' => Pago::PAGABLE_PLAN,
                'pagable_id' => $plan->id,
                'concepto' => $concepto,
                'meses' => $plan->meses($frecuencia),
                'metodo_pago_id' => $pasarelaUsdE2 > 0 ? $metodo?->id : null,
                'cuenta_bancaria_id' => $pasarelaUsdE2 > 0 ? $metodo?->cuenta_bancaria_id : null,
                'moneda' => $moneda,
                'monto_e2' => $montoE2,
                'monto_usd_e2' => $montoUsdE2,
                'latidos_usados' => $latidosUsar,
                'descuento_usd_e2' => $descuentoUsdE2,
                'monto_pasarela_usd_e2' => $pasarelaUsdE2,
                'latido_id' => $latidoId,
                'tasa' => $tasaPago,
                // Un plan es ingreso de la plataforma: no hay comisión ni liquidación al médico.
                'neto_usd_e2' => $montoUsdE2,
                'neto_ves_e2' => $netoVesE2,
                'estado' => Pago::PENDIENTE,
                'expira_el' => $pasarelaUsdE2 > 0 ? now()->addMinutes($minutos) : null,
                'estado_liquidacion' => Pago::LIQUIDACION_NO_APLICA,
                'creado_por' => $usuarioId,
            ]);

            if ($latidosUsar > 0) {
                $this->latidos->retener($pago, $latidosUsar);
            }

            // Si los Latidos cubren todo, no hay nada que pedirle a SyPago: se aprueba y se renueva acá.
            if ($pasarelaUsdE2 === 0) {
                $pago->update(['estado' => Pago::APROBADO, 'pagado_el' => now()]);
                $pago->registrarEvento(PagoEvento::ORIGEN_SISTEMA, 'aprobado_con_latidos', [
                    'latidos' => $latidosUsar,
                    'descuento_usd_e2' => $descuentoUsdE2,
                ], Pago::PENDIENTE);
                $this->latidos->consumir($pago);
                app(ConfirmarPagoDePlan::class)->ejecutar($pago);
            }

            return $pago;
        });

        // Un SMTP caído no puede deshacer el pago ni la renovación. Se manda sólo si nació aprobado.
        if ($pago->estaAprobado()) {
            $this->enviarComprobante($pago);
        }

        return $pago;
    }

    /**
     * Pide el OTP. Deja el pago `en_proceso` con los datos del pagador (sin el código) y la referencia de
     * SyPago, si la devuelve.
     *
     * @param  array{nombre:string, documento_tipo:string, documento_numero:string, banco_codigo:string, cuenta?:string, telefono?:string}  $pagador
     */
    public function iniciar(Pago $pago, array $pagador): ResultadoDeCobro
    {
        if (! $pago->estaPendiente()) {
            return ResultadoDeCobro::error('Este pago ya no está pendiente. Empieza uno nuevo.', $pago);
        }
        if ($pago->expira_el && $pago->expira_el->isPast()) {
            if ((int) $pago->latidos_usados > 0) {
                $this->latidos->liberar($pago);
            }
            $pago->update(['estado' => Pago::EXPIRADO]);

            return ResultadoDeCobro::error('El pago venció. Empieza uno nuevo para tomar la tasa actual.', $pago);
        }

        $metodo = $pago->metodoPago;
        if (! $metodo || ! $metodo->activo || ! $metodo->estaConfigurado()) {
            return ResultadoDeCobro::error('El método de pago no está configurado. Avísale al administrador.', $pago);
        }

        try {
            $respuesta = ClienteSyPago::para($metodo)->solicitarOtp($pago, $pagador);
        } catch (Throwable $e) {
            $pago->registrarEvento(PagoEvento::ORIGEN_SISTEMA, 'otp_error', ['mensaje' => $e->getMessage()], $pago->estado);

            return ResultadoDeCobro::error($e->getMessage(), $pago);
        }

        $anterior = $pago->estado;
        $pago->update([
            'datos_pagador' => $this->datosDelPagador($pagador),
            'respuesta' => $respuesta,
            'referencia_externa' => $this->referenciaExterna($respuesta) ?? $pago->referencia_externa,
            'operation_secret' => $this->operationSecret($respuesta) ?? $pago->operation_secret,
            'otp_enviado_el' => now(),
            'estado' => Pago::EN_PROCESO,
        ]);
        $pago->registrarEvento(PagoEvento::ORIGEN_MANUAL, 'otp_solicitado', $respuesta, $anterior);

        return ResultadoDeCobro::ok('Te enviamos el código a tu teléfono. Escríbelo para confirmar el pago.', $pago, $respuesta);
    }

    /** Confirma el cobro con el OTP que recibió el pagador. */
    public function confirmar(Pago $pago, string $otp, string $origen = PagoEvento::ORIGEN_MANUAL): ResultadoDeCobro
    {
        if ($pago->estaAprobado()) {
            return ResultadoDeCobro::ok('Este pago ya estaba aprobado.', $pago);
        }
        if (! $pago->estaPendiente()) {
            return ResultadoDeCobro::error('Este pago ya no está pendiente.', $pago);
        }
        if (empty($pago->datos_pagador)) {
            return ResultadoDeCobro::error('Falta pedir el código antes de confirmar.', $pago);
        }

        $metodo = $pago->metodoPago;
        if (! $metodo || ! $metodo->estaConfigurado()) {
            return ResultadoDeCobro::error('El método de pago no está configurado.', $pago);
        }

        try {
            $respuesta = ClienteSyPago::para($metodo)->capturarOtp($pago, trim($otp));
        } catch (Throwable $e) {
            $pago->update(['otp_intentos' => $pago->otp_intentos + 1]);
            $pago->registrarEvento($origen, 'confirmacion_error', ['mensaje' => $e->getMessage()], $pago->estado);

            return ResultadoDeCobro::error($e->getMessage(), $pago);
        }

        $pago->update([
            'respuesta' => $respuesta,
            'referencia_externa' => $this->referenciaExterna($respuesta) ?? $pago->referencia_externa,
            'operation_secret' => $this->operationSecret($respuesta) ?? $pago->operation_secret,
            'otp_intentos' => $pago->otp_intentos + 1,
        ]);

        return $this->procesarRespuesta($pago, $respuesta, $origen);
    }

    /**
     * Consulta el estado real a SyPago y aplica el resultado. Es lo que corre el sondeo y lo que dispara el
     * webhook. `$respaldo` es el payload del webhook, que sólo se usa si la consulta falla.
     *
     * @param  array<string,mixed>|null  $respaldo
     */
    public function conciliar(Pago $pago, string $origen = PagoEvento::ORIGEN_CONSULTA, ?array $respaldo = null): ResultadoDeCobro
    {
        if ($pago->estaAprobado()) {
            return ResultadoDeCobro::ok('Este pago ya estaba aprobado.', $pago);
        }

        $metodo = $pago->metodoPago;
        if (! $metodo || ! $metodo->estaConfigurado()) {
            return ResultadoDeCobro::error('El método de pago no está configurado.', $pago);
        }

        $transaccion = $pago->referencia_externa ?: $this->referenciaExterna($respaldo ?? []);
        if (! $transaccion) {
            return ResultadoDeCobro::error('El pago todavía no tiene una transacción que consultar.', $pago);
        }

        try {
            $respuesta = ClienteSyPago::para($metodo)->consultar($transaccion);
        } catch (Throwable $e) {
            if ($respaldo === null) {
                return ResultadoDeCobro::error('No se pudo consultar el pago: ' . $e->getMessage(), $pago);
            }
            $respuesta = $respaldo;
        }

        return $this->procesarRespuesta($pago, $respuesta, $origen);
    }

    /* ------------------------------------------------------------------ */
    /* Interno                                                             */
    /* ------------------------------------------------------------------ */

    /** @param array<string,mixed> $respuesta */
    private function procesarRespuesta(Pago $pago, array $respuesta, string $origen): ResultadoDeCobro
    {
        $estado = ClienteSyPago::estadoDe($respuesta);

        if ($estado === ClienteSyPago::APROBADO) {
            return $this->aprobar($pago, $respuesta, $origen);
        }

        if ($estado === ClienteSyPago::RECHAZADO) {
            // Si el pago retenía Latidos, se sueltan: el médico no pierde el descuento por un rechazo.
            if ((int) $pago->latidos_usados > 0) {
                $this->latidos->liberar($pago);
            }

            $anterior = $pago->estado;
            $pago->update(['estado' => Pago::RECHAZADO]);
            $pago->registrarEvento($origen, 'rechazado', $respuesta, $anterior);

            $mensaje = $pago->metodoPago
                ? (ClienteSyPago::para($pago->metodoPago)->mensajeDeError($respuesta) ?: 'El pago fue rechazado.')
                : 'El pago fue rechazado.';

            return ResultadoDeCobro::error($mensaje, $pago, $respuesta);
        }

        $anterior = $pago->estado;
        $pago->update(['estado' => Pago::EN_PROCESO]);
        $pago->registrarEvento($origen, 'en_proceso', $respuesta, $anterior);

        return ResultadoDeCobro::ok('El pago todavía está en proceso. En unos minutos se confirma solo.', $pago, $respuesta);
    }

    /** @param array<string,mixed> $respuesta */
    private function aprobar(Pago $pago, array $respuesta, string $origen): ResultadoDeCobro
    {
        $resultado = DB::transaction(function () use ($pago, $respuesta, $origen) {
            /** @var Pago $bloqueado */
            $bloqueado = Pago::whereKey($pago->id)->lockForUpdate()->firstOrFail();

            if ($bloqueado->estaAprobado()) {
                return ResultadoDeCobro::ok('Este pago ya estaba aprobado.', $bloqueado, $respuesta);
            }

            $anterior = $bloqueado->estado;
            $bloqueado->update([
                'estado' => Pago::APROBADO,
                'pagado_el' => now(),
                'respuesta' => $respuesta,
                'referencia_externa' => $this->referenciaExterna($respuesta) ?? $bloqueado->referencia_externa,
                'operation_secret' => $this->operationSecret($respuesta) ?? $bloqueado->operation_secret,
            ]);
            $bloqueado->registrarEvento($origen, 'aprobado', $respuesta, $anterior);

            // Los Latidos se descuentan recién acá, cuando el pago quedó aprobado. Si la retención no
            // existe, la transacción se revierte: el pago no se aplica sin el descuento que prometió.
            if ((int) $bloqueado->latidos_usados > 0) {
                $this->latidos->consumir($bloqueado);
            }

            $this->aplicar($bloqueado);

            return ResultadoDeCobro::ok('¡Pago aprobado!', $bloqueado, $respuesta, true);
        });

        // Fuera de la transacción: un SMTP caído no puede deshacer el pago ni la renovación.
        if ($resultado->cambio && $resultado->pago && $resultado->pago->esDePlan()) {
            $this->enviarComprobante($resultado->pago);
        }

        return $resultado;
    }

    /** Aplica el pago a lo que corresponda. Hoy sólo hay planes. */
    private function aplicar(Pago $pago): void
    {
        if ($pago->esDePlan()) {
            app(ConfirmarPagoDePlan::class)->ejecutar($pago);
        }
    }

    /** El comprobante por correo al médico que hizo el pago; si falla, se registra y no se revierte nada. */
    private function enviarComprobante(Pago $pago): void
    {
        try {
            $usuario = $pago->creado_por ? User::find($pago->creado_por) : null;
            $email = $usuario?->email ?: Medico::where('reg_medico', $pago->reg_medico)->value('email');

            if (! $email) {
                return;
            }

            Mail::to($email)->send(new PagoDePlanAprobado($pago, $usuario?->name));
        } catch (Throwable $e) {
            report($e);
            $pago->registrarEvento(PagoEvento::ORIGEN_SISTEMA, 'comprobante_error', ['mensaje' => $e->getMessage()]);
        }
    }

    /**
     * Los datos del pagador que se guardan: nombre, documento y cuenta/teléfono. Nunca el OTP.
     *
     * @param  array<string,mixed>  $pagador
     * @return array<string,mixed>
     */
    private function datosDelPagador(array $pagador): array
    {
        return array_filter([
            'nombre' => trim((string) ($pagador['nombre'] ?? '')),
            'documento_tipo' => strtoupper(trim((string) ($pagador['documento_tipo'] ?? ''))),
            'documento_numero' => trim((string) ($pagador['documento_numero'] ?? '')),
            'banco_codigo' => trim((string) ($pagador['banco_codigo'] ?? '')),
            'tipo_cuenta' => trim((string) ($pagador['tipo_cuenta'] ?? '')),
            'cuenta' => trim((string) ($pagador['cuenta'] ?? '')),
            'telefono' => trim((string) ($pagador['telefono'] ?? '')),
        ], fn ($valor) => $valor !== '');
    }

    /** @param array<string,mixed> $respuesta */
    private function referenciaExterna(array $respuesta): ?string
    {
        foreach (['transaction_id', 'ref_ibp', 'id'] as $clave) {
            if (! empty($respuesta[$clave]) && is_scalar($respuesta[$clave])) {
                return (string) $respuesta[$clave];
            }
        }

        return null;
    }

    /**
     * El `operation_secret` que SyPago devuelve al iniciar la operación. Es la tercera parte de la cadena
     * que firma el webhook; sin él no se puede verificar la firma.
     *
     * @param  array<string,mixed>  $respuesta
     */
    private function operationSecret(array $respuesta): ?string
    {
        $secreto = $respuesta['operation_secret'] ?? null;

        return is_scalar($secreto) && (string) $secreto !== '' ? (string) $secreto : null;
    }
}
