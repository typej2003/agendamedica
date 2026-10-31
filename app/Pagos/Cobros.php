<?php

namespace App\Pagos;

use App\Actions\Agenda\CobrarCita;
use App\Actions\Pagos\ConfirmarPagoDePlan;
use App\Actions\Pagos\LiberarCupoDeReservaOnline;
use App\Clinica\FechaDelConsultorio;
use App\Mail\PagoDePlanAprobado;
use App\Models\Cola;
use App\Models\ConfiguracionPago;
use App\Models\Medico;
use App\Models\MetodoPago;
use App\Models\Pago;
use App\Models\PagoEvento;
use App\Models\Plan;
use App\Models\ReglaDeComision;
use App\Models\TasaDeCambio;
use App\Models\User;
use App\Pagos\Correos\AvisoDePagoDeCita;
use App\Services\CupoDelPlan;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * **El motor de cobro** (planes del médico y cobro de citas). Crea el pago, pide el OTP, lo confirma y
 * concilia.
 *
 * La regla de oro: **el webhook y el sondeo no son la fuente de verdad**. Antes de aprobar se consulta la
 * transacción a SyPago (`GET transaction/{id}`); el webhook sólo dispara la consulta. Si SyPago no
 * responde en ese momento y el aviso vino por el webhook autenticado, se usa el payload como respaldo,
 * pero el monto ya se validó contra el pago.
 *
 * Aprobar es una transición con `lockForUpdate`: dos webhooks iguales no renuevan el plan ni cobran la
 * cita dos veces.
 */
final class Cobros
{
    /**
     * Los canales por los que puede entrar el cobro de una cita. `plan` no es uno de ellos: un plan
     * no es una cita, y `prefactura` todavía no tiene motor.
     */
    public const CANALES_DE_CITA = [
        Pago::CANAL_LINK,
        Pago::CANAL_RESERVA_ONLINE,
        Pago::CANAL_MOSTRADOR,
    ];

    public function __construct(private readonly CupoDelPlan $cupos)
    {
    }

    /**
     * Crea la intención de pago de un plan. No cobra: deja el pago en `pendiente`, con la tasa y el monto
     * en VES congelados, para que el médico pague con el método elegido.
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
        MetodoPago $metodo,
        ?int $usuarioId = null,
        string $frecuencia = Plan::MENSUAL
    ): Pago {
        $regMedico = trim($regMedico);
        if ($regMedico === '') {
            throw new RuntimeException('Falta el consultorio del médico.');
        }
        if (! $metodo->activo || $metodo->pasarela !== CatalogoDePasarelas::SYPAGO || ! $metodo->estaConfigurado()) {
            throw new RuntimeException('El método de pago elegido no está disponible.');
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

        // La moneda la decide el método: SyPago cobra en VES; un método que cobre en USD no necesita tasa.
        $moneda = strtoupper((string) ($metodo->cuentaBancaria?->moneda ?: 'VES'));

        if ($moneda === 'VES') {
            $tasa = TasaDeCambio::actual();
            if (! $tasa) {
                throw new RuntimeException('Todavía no hay una tasa VES/USD cargada. El administrador tiene que cargarla para poder cobrar.');
            }
            $montoE2 = $tasa->convertirUsdAVes($montoUsdE2);
            $tasaPago = $tasa->tasa;
            if ($montoE2 <= 0) {
                throw new RuntimeException('El monto en bolívares quedó en cero: revisa la tasa.');
            }
        } elseif ($moneda === 'USD') {
            $montoE2 = $montoUsdE2;
            $tasaPago = null;
        } else {
            throw new RuntimeException('La moneda del método de pago no está soportada.');
        }

        $minutos = max(1, (int) ConfiguracionPago::actual()->vigencia_tasa_minutos);

        return Pago::create([
            'reg_medico' => $regMedico,
            'beneficiario_tipo' => Pago::BENEFICIARIO_PLATAFORMA,
            'beneficiario_reg_medico' => null,
            'recaudador' => Pago::RECAUDADOR_PLATAFORMA,
            'canal' => Pago::CANAL_PLAN,
            'pagable_tipo' => Pago::PAGABLE_PLAN,
            'pagable_id' => $plan->id,
            'concepto' => 'Plan ' . $plan->nombre . ' · ' . ($frecuencia === Plan::ANUAL ? 'anual' : 'mensual')
                . ($plan->cobraPorAsiento() ? ' · ' . $this->cupos->asientos($plan, $conteo) . ' asientos' : ''),
            'meses' => $plan->meses($frecuencia),
            'metodo_pago_id' => $metodo->id,
            'cuenta_bancaria_id' => $metodo->cuenta_bancaria_id,
            'moneda' => $moneda,
            'monto_e2' => $montoE2,
            'monto_usd_e2' => $montoUsdE2,
            'tasa' => $tasaPago,
            // Un plan es ingreso de la plataforma: no hay comisión ni liquidación al médico.
            'neto_usd_e2' => $montoUsdE2,
            'neto_ves_e2' => $moneda === 'VES' ? $montoE2 : 0,
            'estado' => Pago::PENDIENTE,
            'expira_el' => now()->addMinutes($minutos),
            'estado_liquidacion' => Pago::LIQUIDACION_NO_APLICA,
            'creado_por' => $usuarioId,
        ]);
    }

    /**
     * Crea la intención de pago de una **cita**. No cobra: deja el pago en `pendiente`, con la tasa y
     * el monto en VES congelados cuando el método cobra en bolívares.
     *
     * `$montoUsdE2` llega **explícito** y en centavos de USD: cuánto se cobra lo decide quien llama
     * (el link público, la reserva online o el mostrador), no este método. El pago es del médico
     * (`beneficiario = medico`); `recaudador` depende de a quién pertenece el método, y de eso sale
     * también si hay liquidación pendiente.
     *
     * Las comisiones se congelan en cascada sobre el bruto en USD: la del método primero y la de cita
     * online sobre el remanente. La comisión del método **no se suma en una cita online** si
     * `configuracion_pagos.aplica_comision_metodo_en_citas_online` está apagada, que es el valor con
     * el que nace la configuración.
     *
     * `$expiraEl` lo usa la reserva online, que da **30 minutos** para pagar; sin él se mantiene la
     * regla del link de la agenda (hasta el final del día de la cita).
     *
     * @throws RuntimeException si la cita no tiene consultorio, el canal no es de cita, el monto no
     *                          es positivo o supera el máximo, el método no está disponible o no es
     *                          del consultorio de la cita, o falta la tasa para cobrar en VES
     */
    public function crearParaCita(
        Cola $cita,
        MetodoPago $metodo,
        int $montoUsdE2,
        string $canal = Pago::CANAL_LINK,
        ?int $usuarioId = null,
        ?CarbonInterface $expiraEl = null
    ): Pago {
        $regMedico = trim((string) $cita->reg_medico);
        if ($regMedico === '') {
            throw new RuntimeException('Falta el consultorio del médico en la cita.');
        }

        if (! in_array($canal, self::CANALES_DE_CITA, true)) {
            throw new RuntimeException('El canal del pago no es uno de los canales de una cita.');
        }

        if ($montoUsdE2 <= 0) {
            throw new RuntimeException('El monto de la cita tiene que ser mayor que cero.');
        }
        if ($montoUsdE2 > (int) config('pagos.maximo_monto_usd_e2', 100_000_000)) {
            throw new RuntimeException('El monto de la cita supera el máximo permitido.');
        }

        if (! $metodo->activo || $metodo->pasarela !== CatalogoDePasarelas::SYPAGO || ! $metodo->estaConfigurado()) {
            throw new RuntimeException('El método de pago elegido no está disponible.');
        }

        // Un método del consultorio sólo puede cobrar las citas de ese consultorio.
        if (! $metodo->esDeLaPlataforma() && trim((string) $metodo->reg_medico) !== $regMedico) {
            throw new RuntimeException('El método de pago no pertenece al consultorio de la cita.');
        }

        // La moneda la decide el método: SyPago cobra en VES; un método que cobre en USD no necesita tasa.
        $moneda = strtoupper((string) ($metodo->cuentaBancaria?->moneda ?: 'VES'));

        if ($moneda === 'VES') {
            $tasa = TasaDeCambio::actual();
            if (! $tasa) {
                throw new RuntimeException('Todavía no hay una tasa VES/USD cargada. El administrador tiene que cargarla para poder cobrar.');
            }
            $montoE2 = $tasa->convertirUsdAVes($montoUsdE2);
            $tasaPago = $tasa->tasa;
            if ($montoE2 <= 0) {
                throw new RuntimeException('El monto en bolívares quedó en cero: revisa la tasa.');
            }
        } elseif ($moneda === 'USD') {
            $montoE2 = $montoUsdE2;
            $tasaPago = null;
        } else {
            throw new RuntimeException('La moneda del método de pago no está soportada.');
        }

        $recaudador = $metodo->esDeLaPlataforma() ? Pago::RECAUDADOR_PLATAFORMA : Pago::RECAUDADOR_MEDICO;

        $configuracion = ConfiguracionPago::actual();
        $esOnline = $canal === Pago::CANAL_RESERVA_ONLINE;

        // La comisión del método es por **prestar el método de la plataforma**: si el método es del
        // consultorio, la plataforma no presta nada y no hay comisión. El override del método, si
        // existe, gana sobre la regla.
        $reglaMetodo = ReglaDeComision::vigente(ReglaDeComision::TIPO_METODO, $regMedico, $metodo->id);
        $bpMetodo = $recaudador === Pago::RECAUDADOR_PLATAFORMA
            ? ($metodo->comision_bp ?? $reglaMetodo?->porcentaje_bp ?? 0)
            : 0;

        // En una cita online la plataforma ya cobra su comisión: la del método sólo se suma si la
        // configuración lo pide (por defecto no, para no cobrar dos veces lo mismo).
        if ($esOnline && ! $configuracion->aplica_comision_metodo_en_citas_online) {
            $bpMetodo = 0;
        }

        // La comisión por cita online es por la cita que trae la plataforma: sólo aplica a ese canal.
        $reglaCitaOnline = $esOnline
            ? ReglaDeComision::vigente(ReglaDeComision::TIPO_CITA_ONLINE, $regMedico)
            : null;
        $bpCitaOnline = $reglaCitaOnline?->porcentaje_bp ?? 0;

        $comisiones = Comisiones::calcular($montoUsdE2, $bpMetodo, $bpCitaOnline);

        // Equivalentes en VES: el neto es lo que queda del monto cobrado, así el recibo suma exacto
        // en las dos monedas (las comisiones se redondean una sola vez, en USD).
        $comisionTotalVesE2 = $moneda === 'VES'
            ? $tasa->convertirUsdAVes($comisiones['total'])
            : 0;
        $netoVesE2 = $moneda === 'VES' ? $montoE2 - $comisionTotalVesE2 : 0;

        $estadoLiquidacion = $recaudador === Pago::RECAUDADOR_PLATAFORMA && $comisiones['neto'] > 0
            ? Pago::LIQUIDACION_PENDIENTE
            : Pago::LIQUIDACION_NO_APLICA;

        // El token del link público es único y no se repite entre pagos.
        do {
            $tokenPublico = (string) Str::uuid();
        } while (Pago::where('token_publico', $tokenPublico)->exists());

        // La vigencia es la del **link**, no la de la tasa: un pago de cita se cobra con la tasa del
        // momento (`actualizarTasaDeLaCita()`), así que el link vive hasta el final del día de la cita y no
        // los 15 minutos que dura una tasa congelada. La reserva online manda su propio plazo.
        $expiraEl = $expiraEl ?? $this->vigenciaDelLink($cita);
        $fechaCita = $cita->fecha?->format('d/m/Y');

        return Pago::create([
            'token_publico' => $tokenPublico,
            'reg_medico' => $regMedico,
            'beneficiario_tipo' => Pago::BENEFICIARIO_MEDICO,
            'beneficiario_reg_medico' => $regMedico,
            'recaudador' => $recaudador,
            'canal' => $canal,
            'pagable_tipo' => Pago::PAGABLE_CITA,
            'pagable_id' => $cita->id,
            'concepto' => 'Cita del ' . ($fechaCita ?: 'día') . ' · #' . $cita->id,
            'meses' => null,
            'metodo_pago_id' => $metodo->id,
            'cuenta_bancaria_id' => $metodo->cuenta_bancaria_id,
            'moneda' => $moneda,
            'monto_e2' => $montoE2,
            'monto_usd_e2' => $montoUsdE2,
            'tasa' => $tasaPago,
            'comision_metodo_usd_e2' => $comisiones['metodo'],
            'comision_cita_online_usd_e2' => $comisiones['cita_online'],
            'comision_total_usd_e2' => $comisiones['total'],
            'neto_usd_e2' => $comisiones['neto'],
            'comision_total_ves_e2' => $comisionTotalVesE2,
            'neto_ves_e2' => $netoVesE2,
            'estado' => Pago::PENDIENTE,
            'expira_el' => $expiraEl,
            'estado_liquidacion' => $estadoLiquidacion,
            'creado_por' => $usuarioId,
        ]);
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
            $pago->update(['estado' => Pago::EXPIRADO]);
            $this->liberarCupoSiEsReservaOnline($pago);

            return ResultadoDeCobro::error('El pago venció. Empieza uno nuevo para tomar la tasa actual.', $pago);
        }

        $this->actualizarTasaDeLaCita($pago);

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
            $anterior = $pago->estado;
            $pago->update(['estado' => Pago::RECHAZADO]);
            $pago->registrarEvento($origen, 'rechazado', $respuesta, $anterior);

            // No va a pagar: el turno no puede quedar tomado por una reserva que quedó rechazada.
            $this->liberarCupoSiEsReservaOnline($pago);

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

            $this->aplicar($bloqueado);

            return ResultadoDeCobro::ok('¡Pago aprobado!', $bloqueado, $respuesta, true);
        });

        // Fuera de la transacción: un SMTP caído no puede deshacer el pago ni la renovación.
        if ($resultado->cambio && $resultado->pago) {
            if ($resultado->pago->esDePlan()) {
                $this->enviarComprobante($resultado->pago);
            }

            if ($resultado->pago->esDeCita()) {
                $this->enviarAvisosDeCita($resultado->pago);
            }
        }

        return $resultado;
    }

    /** Aplica el pago a lo que corresponda: el plan que se compró o la cita que se cobró. */
    private function aplicar(Pago $pago): void
    {
        if ($pago->esDePlan()) {
            app(ConfirmarPagoDePlan::class)->ejecutar($pago);
        }

        if ($pago->esDeCita()) {
            $this->aplicarCita($pago);
        }
    }

    /**
     * Deja la cita pagada, escribiendo con la **misma** acción del mostrador (`CobrarCita`): el precio
     * sólo se fija si la cita no tenía y lo pagado se escribe como valor **absoluto**, con el
     * last-write-wins de la columna y el rastro del sync.
     *
     * Dos cosas que no hace, a propósito:
     *
     * - **No usa `recibir()`**, que es la entrada del mostrador: esa suma y **topa** contra el precio,
     *   y al pasarse lanza. Acá el pago ya está aprobado, o sea que la plata entró: revertir la
     *   aprobación dejaría el dinero cobrado sin registrar y el pago en `en_proceso` para siempre.
     *   El pago de la pasarela es la verdad del dinero, así que se escribe lo que entró (si sobra,
     *   se ve en los montos de la cita y se devuelve, que es la tarea de reembolsos).
     * - **No revienta si la cita ya no existe**: deja el evento en el pago y lo aprueba igual, por lo
     *   mismo. La cita se borra desde la agenda y el dinero no.
     *
     * El adaptador sólo convierte la unidad: `CobrarCita` trabaja con el monto decimal de `cola`
     * (columna legada `double`), no con los centavos enteros del pago.
     */
    private function aplicarCita(Pago $pago): void
    {
        $cita = $pago->cita();

        if (! $cita) {
            $pago->registrarEvento(
                PagoEvento::ORIGEN_SISTEMA,
                'cita_inexistente',
                ['cita_id' => $pago->pagable_id]
            );

            return;
        }

        $recibido = (float) Centavos::aDecimal((int) $pago->monto_usd_e2);
        $cobrar = app(CobrarCita::class);

        if ((float) ($cita->monto ?? 0) <= 0) {
            $cobrar->fijarMonto($cita, $recibido, $pago->pagado_el);
        }

        $cobrar->fijarPagado(
            $cita,
            (float) ($cita->monto_pagado ?? 0) + $recibido,
            $pago->pagado_el
        );
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
     * Los avisos de una **cita pagada**: al médico y al paciente, desde el mismo punto y con los mismos
     * datos. Cada envío se protege por dentro; este try/catch es la última red para que nada de lo que
     * pase armando el correo pueda tumbar la aprobación, que ya está confirmada.
     */
    private function enviarAvisosDeCita(Pago $pago): void
    {
        try {
            app(AvisoDePagoDeCita::class)->enviar($pago);
        } catch (Throwable $e) {
            report($e);
            $pago->registrarEvento(PagoEvento::ORIGEN_SISTEMA, 'correo_error', [
                'destinatario' => 'ambos',
                'mensaje' => $e->getMessage(),
            ]);
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

    /**
     * Si el pago de una **reserva online** terminó sin pagarse —vencido o rechazado—, el cupo vuelve a
     * estar libre: el turno no se queda tomado por alguien que no va a ir. La regla de cuándo se puede
     * liberar está en `LiberarCupoDeReservaOnline`; acá sólo se dispara y **nunca** se deja que un error
     * de la liberación tumbe el cobro (el pago y su estado son lo importante).
     */
    private function liberarCupoSiEsReservaOnline(Pago $pago): void
    {
        if ($pago->canal !== Pago::CANAL_RESERVA_ONLINE || ! $pago->esDeCita()) {
            return;
        }

        try {
            app(LiberarCupoDeReservaOnline::class)->ejecutar($pago);
        } catch (Throwable $e) {
            report($e);
            $pago->registrarEvento(PagoEvento::ORIGEN_SISTEMA, 'liberar_cupo_error', [
                'mensaje' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Hasta cuándo vale el link de una cita: **hasta el final del día de la cita**, en la zona del
     * consultorio. Si la cita ya pasó (el consultorio manda el link tarde), se le da un día desde ahora
     * para que el paciente alcance a pagar.
     */
    private function vigenciaDelLink(Cola $cita): Carbon
    {
        $fecha = $cita->fecha?->format('Y-m-d');

        if ($fecha) {
            // El final del día es en hora del consultorio; a la base va el instante en UTC.
            $fin = Carbon::parse($fecha . ' 23:59:59', FechaDelConsultorio::zona())->utc();

            if ($fin->isFuture()) {
                return $fin;
            }
        }

        return now()->addDay();
    }

    /**
     * **Un pago de cita no congela la tasa**: se cobra con la del momento. Justo antes de pedir el OTP se
     * recalcula el monto en bolívares con la tasa vigente, para que el débito y el pago guarden la misma
     * cifra (la comisión en USD no cambia; sólo su equivalente en VES).
     *
     * Un pago de **plan** no se toca: ahí la tasa se congela al crear el pago y el médico decide si
     * reintenta cuando cambió (`RefrescarPagoDePlan`). Sin tasa cargada se sigue con la que tenía el pago.
     */
    private function actualizarTasaDeLaCita(Pago $pago): void
    {
        if (! $pago->esDeCita() || strtoupper((string) $pago->moneda) !== 'VES') {
            return;
        }

        $tasa = TasaDeCambio::actual();
        if (! $tasa) {
            return;
        }

        $montoE2 = $tasa->convertirUsdAVes((int) $pago->monto_usd_e2);
        if ($montoE2 <= 0) {
            return;
        }

        $tasaAnterior = (string) $pago->tasa;
        $montoAnterior = (int) $pago->monto_e2;

        if ($tasaAnterior === (string) $tasa->tasa && $montoAnterior === $montoE2) {
            return;
        }

        $comisionVesE2 = $tasa->convertirUsdAVes((int) $pago->comision_total_usd_e2);

        $pago->update([
            'monto_e2' => $montoE2,
            'tasa' => $tasa->tasa,
            'comision_total_ves_e2' => $comisionVesE2,
            'neto_ves_e2' => $montoE2 - $comisionVesE2,
        ]);

        $pago->registrarEvento(PagoEvento::ORIGEN_SISTEMA, 'tasa_actualizada', [
            'tasa_anterior' => $tasaAnterior,
            'tasa_nueva' => (string) $tasa->tasa,
            'monto_anterior_e2' => $montoAnterior,
            'monto_nuevo_e2' => $montoE2,
        ]);
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
