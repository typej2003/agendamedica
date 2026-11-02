<?php

namespace App\Pagos;

use App\Models\Pago;
use App\Models\PagoEvento;
use App\Models\PagoTransaccion;
use App\Pagos\Conciliacion\Conciliadores;
use App\Pagos\Conciliacion\ConsultaDePago;
use App\Pagos\Conciliacion\ResultadoDeConsulta;
use App\Pagos\Correos\AvisoDePagoPorConfirmar;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * **El cobro por pago móvil o transferencia**, que es distinto del débito con OTP de SyPago.
 *
 * Acá no se debita nada: el paciente **envía** el pago desde su banco y después declara la referencia.
 * Con esa referencia se le pregunta al banco que recibe si la operación existe, por ese monto y de ese
 * pagador:
 *
 *  - **Confirmación automática** (la cuenta tiene driver y credenciales): se consulta en el momento.
 *  - **Confirmación manual** (el banco no tiene API): el pago queda pendiente y lo confirma el
 *    consultorio, que ve la plata en su banco.
 *
 * El monto lo decide el banco, no el paciente: si no coincide con el esperado, el pago **no se aprueba**
 * (por ahora; los abonos vienen después). La misma referencia no se acredita dos veces porque es única
 * por cuenta.
 */
final class CobrosDePagoMovil
{
    public function __construct(private readonly Cobros $cobros)
    {
    }

    /**
     * **El paciente declara que pagó**: guarda sus datos y la referencia, y consulta (si se puede).
     *
     * @param  array{documento_tipo?:string,documento_numero?:string,nombre?:string,telefono?:string,banco_codigo?:string}  $pagador
     */
    public function declarar(
        Pago $pago,
        array $pagador,
        string $referencia,
        ?string $tipo = null,
        string $origen = PagoTransaccion::ORIGEN_PACIENTE
    ): ResultadoDeCobro {
        if ($pago->estaAprobado()) {
            return ResultadoDeCobro::ok('Este pago ya estaba aprobado.', $pago);
        }

        if (! $pago->estaPendiente()) {
            return ResultadoDeCobro::error('Este pago ya no está pendiente. Empieza uno nuevo.', $pago);
        }

        if ($pago->expira_el && $pago->expira_el->isPast()) {
            return ResultadoDeCobro::error('El link venció. Pídele al consultorio que te envíe uno nuevo.', $pago);
        }

        $metodo = $pago->metodoPago;
        $cuenta = $metodo?->cuentaBancaria;

        if (! $metodo || ! $metodo->esDePagoMovil() || ! $metodo->estaConfigurado() || ! $cuenta) {
            return ResultadoDeCobro::error('Este link no tiene una cuenta de pago móvil configurada.', $pago);
        }

        $referencia = $this->limpiarReferencia($referencia);
        if ($referencia === '') {
            return ResultadoDeCobro::error('Escribe el número de referencia del pago.', $pago);
        }

        $tipo = $tipo ?: ((string) ($pagador['telefono'] ?? '') !== ''
            ? PagoTransaccion::TIPO_PAGO_MOVIL
            : PagoTransaccion::TIPO_TRANSFERENCIA);

        $datos = $this->datosDelPagador($pagador);
        $pago->update(['datos_pagador' => array_merge((array) ($pago->datos_pagador ?? []), $datos)]);

        $transaccion = $this->transaccionDeclarada($pago, $cuenta->id, $referencia, $tipo, max(1, $pago->saldoE2()), $origen);

        if ($transaccion->estaAprobada()) {
            return ResultadoDeCobro::ok('Ese pago ya estaba confirmado.', $pago);
        }

        $pago->registrarEvento($origen === PagoTransaccion::ORIGEN_PACIENTE ? PagoEvento::ORIGEN_MANUAL : PagoEvento::ORIGEN_SISTEMA, 'pago_movil_declarado', [
            'referencia' => $referencia,
            'tipo' => $tipo,
            'monto_e2' => (int) $pago->monto_e2,
            'conciliacion' => $cuenta->conciliaSola() ? 'automatica' : 'manual',
        ], $pago->estado);

        if (! $cuenta->conciliaSola()) {
            // Quien confirma tiene que enterarse: el médico si recauda él, los Root si recauda la
            // plataforma. Un SMTP caído no deshace la declaración.
            $pago->load('transacciones');
            app(AvisoDePagoPorConfirmar::class)->enviar($pago);

            return ResultadoDeCobro::ok(
                'Recibimos tu aviso. El consultorio va a confirmar el pago y te avisamos.',
                $pago,
            );
        }

        return $this->consultarTransaccion($pago, $transaccion, PagoEvento::ORIGEN_MANUAL);
    }

    /**
     * **Vuelve a preguntarle al banco** por las transacciones pendientes de este pago. Es lo que corre el
     * botón «Ya pagué» de nuevo, la pantalla del consultorio y el job de conciliación.
     */
    public function consultar(Pago $pago, string $origen = PagoEvento::ORIGEN_CONSULTA): ResultadoDeCobro
    {
        if ($pago->estaAprobado()) {
            return ResultadoDeCobro::ok('Este pago ya estaba aprobado.', $pago);
        }

        $cuenta = $pago->metodoPago?->cuentaBancaria;

        if (! $cuenta || ! $cuenta->conciliaSola()) {
            return ResultadoDeCobro::ok(
                'Este pago lo tiene que confirmar el consultorio. Ya le avisamos.',
                $pago,
            );
        }

        $pendientes = $pago->transacciones()->where('estado', PagoTransaccion::PENDIENTE)->get();

        if ($pendientes->isEmpty()) {
            return ResultadoDeCobro::error('Todavía no nos dijiste el número de referencia del pago.', $pago);
        }

        $ultimo = null;

        foreach ($pendientes as $transaccion) {
            $ultimo = $this->consultarTransaccion($pago, $transaccion, $origen);

            if ($pago->fresh()->estaAprobado() || ! $ultimo->ok) {
                return $ultimo;
            }
        }

        return $ultimo ?? ResultadoDeCobro::ok('Seguimos esperando la confirmación del banco.', $pago);
    }

    /**
     * **El consultorio confirma a mano**: vio la plata en su banco. Puede corregir el monto y dejar la
     * referencia de la operación.
     */
    public function confirmarAMano(
        Pago $pago,
        string $referencia,
        ?int $montoE2,
        int $usuarioId
    ): ResultadoDeCobro {
        if ($pago->estaAprobado()) {
            return ResultadoDeCobro::ok('Este pago ya estaba aprobado.', $pago);
        }

        $referencia = $this->limpiarReferencia($referencia);
        if ($referencia === '') {
            return ResultadoDeCobro::error('Escribe la referencia del pago que viste en el banco.', $pago);
        }

        $cuenta = $pago->metodoPago?->cuentaBancaria;
        $monto = $montoE2 ?? (int) $pago->monto_e2;

        if ($monto <= 0) {
            return ResultadoDeCobro::error('El monto tiene que ser mayor que cero.', $pago);
        }

        $resultado = DB::transaction(function () use ($pago, $cuenta, $referencia, $monto, $usuarioId) {
            $transaccion = $this->transaccionDeclarada(
                $pago,
                $cuenta?->id,
                $referencia,
                (string) ($pago->datos_pagador['telefono'] ?? '') !== ''
                    ? PagoTransaccion::TIPO_PAGO_MOVIL
                    : PagoTransaccion::TIPO_TRANSFERENCIA,
                $monto,
                PagoTransaccion::ORIGEN_MEDICO,
            );

            $transaccion->update([
                'estado' => PagoTransaccion::APROBADA,
                'monto_e2' => $monto,
                'confirmada_el' => now(),
                'mensaje' => null,
            ]);

            $pago->update([
                'monto_confirmado_e2' => $monto,
                'confirmado_por' => $usuarioId,
            ]);

            return $this->cobros->aprobarConciliado(
                $pago,
                ['confirmado_a_mano' => true, 'referencia' => $referencia, 'monto_e2' => $monto],
                PagoEvento::ORIGEN_MANUAL,
            );
        });

        return $resultado;
    }

    /** **El consultorio rechaza**: no llegó, o no es lo que dice el paciente. */
    public function rechazarAMano(Pago $pago, string $motivo, int $usuarioId): ResultadoDeCobro
    {
        $pago->update(['confirmado_por' => $usuarioId]);

        return $this->cobros->rechazarConciliado($pago, $motivo !== '' ? $motivo : 'El consultorio no encontró el pago.', PagoEvento::ORIGEN_MANUAL);
    }

    /* ------------------------------------------------------------------ */
    /* Interno                                                             */
    /* ------------------------------------------------------------------ */

    /** Consulta **una** transacción y aplica lo que dijo el banco. */
    private function consultarTransaccion(
        Pago $pago,
        PagoTransaccion $transaccion,
        string $origen
    ): ResultadoDeCobro {
        $cuenta = $pago->metodoPago?->cuentaBancaria;
        $conciliador = $cuenta ? Conciliadores::para($cuenta) : null;

        if (! $cuenta || ! $conciliador) {
            return ResultadoDeCobro::ok('Este pago lo tiene que confirmar el consultorio. Ya le avisamos.', $pago);
        }

        $consulta = new ConsultaDePago(
            tipo: $transaccion->tipo,
            referencia: (string) $transaccion->referencia,
            montoE2: (int) $transaccion->monto_e2,
            documento: $this->documentoDelPagador($pago),
            telefono: (string) (($pago->datos_pagador ?? [])['telefono'] ?? ''),
        );

        try {
            $resultado = $conciliador->consultar($cuenta, $consulta);
        } catch (Throwable $e) {
            $pago->registrarEvento(PagoEvento::ORIGEN_SISTEMA, 'consulta_bancaria_error', [
                'referencia' => $transaccion->referencia,
                'mensaje' => $e->getMessage(),
            ]);

            return ResultadoDeCobro::error('No pudimos consultar el banco: ' . $e->getMessage(), $pago);
        }

        if ($resultado->esError()) {
            $pago->registrarEvento(PagoEvento::ORIGEN_SISTEMA, 'consulta_bancaria_error', [
                'referencia' => $transaccion->referencia,
                'mensaje' => $resultado->mensaje,
            ]);

            return ResultadoDeCobro::error($resultado->mensaje ?: 'El banco no respondió como esperábamos.', $pago);
        }

        if (! $resultado->fueAprobado()) {
            $transaccion->update([
                'estado' => $resultado->estadoDeLaTransaccion(),
                'respuesta' => $resultado->respuesta,
                'mensaje' => $resultado->mensaje,
            ]);

            return ResultadoDeCobro::error(
                $resultado->mensaje ?: 'El banco no encontró el pago. Revisa la referencia.',
                $pago,
            );
        }

        // El banco confirma el monto que él ve. Si no cubre lo esperado, es un **abono**: se acredita y el
        // pago sigue abierto para que el paciente complete el resto en el momento (sólo en citas).
        $montoBanco = (int) $resultado->montoE2;
        $saldo = $pago->saldoE2();
        // Lo ya acreditado **antes** de esta transacción: después de marcarla aprobada, la suma la incluye.
        $previo = $pago->montoConfirmado();

        if ($montoBanco < $saldo) {
            if (! $pago->esDeCita()) {
                $transaccion->update([
                    'estado' => PagoTransaccion::RECHAZADA,
                    'respuesta' => $resultado->respuesta,
                    'mensaje' => 'El monto no cubre el total y los abonos son sólo para citas.',
                ]);

                return ResultadoDeCobro::error(
                    'El monto no coincide: el banco confirma ' . Centavos::formatear($montoBanco, (string) $pago->moneda)
                    . ' y el pago es de ' . Centavos::formatear((int) $pago->monto_e2, (string) $pago->moneda) . '.',
                    $pago,
                );
            }

            $transaccion->update([
                'estado' => PagoTransaccion::APROBADA,
                'respuesta' => $resultado->respuesta,
                'mensaje' => null,
                'confirmada_el' => now(),
            ]);

            $pago->update(['monto_confirmado_e2' => $previo + $montoBanco]);

            $pago->registrarEvento($origen, 'abono_recibido', [
                'referencia' => $transaccion->referencia,
                'monto_e2' => $montoBanco,
                'saldo_e2' => $pago->fresh()->saldoE2(),
            ], $pago->estado);

            return ResultadoDeCobro::ok(
                'Recibimos un abono de ' . Centavos::formatear($montoBanco, (string) $pago->moneda)
                . '. Faltan ' . Centavos::formatear($pago->fresh()->saldoE2(), (string) $pago->moneda)
                . ': paga el resto para completar.',
                $pago,
                $resultado->respuesta,
            );
        }

        // Cubre o supera el saldo. Si pagó de más, la diferencia queda a favor del paciente y se registra:
        // la devolución la resuelve el consultorio (los reembolsos no son automáticos).
        $diferencia = $montoBanco - $saldo;

        $transaccion->update([
            'estado' => PagoTransaccion::APROBADA,
            'respuesta' => $resultado->respuesta,
            'mensaje' => null,
            'confirmada_el' => now(),
        ]);

        $pago->update(['monto_confirmado_e2' => $previo + $montoBanco]);

        if ($diferencia > 0) {
            $pago->registrarEvento($origen, 'diferencia_a_favor', [
                'referencia' => $transaccion->referencia,
                'monto_e2' => $montoBanco,
                'esperado_e2' => $saldo,
                'diferencia_e2' => $diferencia,
            ], $pago->estado);
        }

        return $this->cobros->aprobarConciliado($pago, $resultado->respuesta, $origen);
    }

    /** La transacción de esa referencia en esa cuenta, o una nueva pendiente. */
    private function transaccionDeclarada(
        Pago $pago,
        ?int $cuentaId,
        string $referencia,
        string $tipo,
        int $montoE2,
        string $origen
    ): PagoTransaccion {
        $existente = $cuentaId !== null
            ? PagoTransaccion::where('cuenta_bancaria_id', $cuentaId)->where('referencia', $referencia)->first()
            : null;

        if ($existente) {
            if ($existente->estaAprobada()) {
                return $existente;
            }

            // Reintento de una declaración anterior: vuelve a quedar pendiente con el monto actual.
            $existente->update([
                'estado' => PagoTransaccion::PENDIENTE,
                'monto_e2' => $montoE2,
                'tipo' => $tipo,
                'origen' => $origen,
                'mensaje' => null,
            ]);

            return $existente;
        }

        return $pago->transacciones()->create([
            'cuenta_bancaria_id' => $cuentaId,
            'referencia' => $referencia,
            'tipo' => $tipo,
            'monto_e2' => $montoE2,
            'moneda' => (string) $pago->moneda,
            'estado' => PagoTransaccion::PENDIENTE,
            'origen' => $origen,
        ]);
    }

    /** El documento del pagador en la forma que pide el banco: `V12345678`. */
    private function documentoDelPagador(Pago $pago): string
    {
        $datos = (array) ($pago->datos_pagador ?? []);
        $tipo = strtoupper(trim((string) ($datos['documento_tipo'] ?? '')));
        $numero = (string) preg_replace('/\D/', '', (string) ($datos['documento_numero'] ?? ''));

        return $tipo . $numero;
    }

    /**
     * @param  array<string,mixed>  $pagador
     * @return array<string,string>
     */
    private function datosDelPagador(array $pagador): array
    {
        $datos = array_filter([
            'nombre' => trim((string) ($pagador['nombre'] ?? '')),
            'documento_tipo' => strtoupper(trim((string) ($pagador['documento_tipo'] ?? ''))),
            'documento_numero' => trim((string) ($pagador['documento_numero'] ?? '')),
            'banco_codigo' => trim((string) ($pagador['banco_codigo'] ?? '')),
            'telefono' => trim((string) preg_replace('/\D/', '', (string) ($pagador['telefono'] ?? ''))),
        ], fn ($valor) => $valor !== '');

        return $datos;
    }

    /** Las referencias bancarias son dígitos; se toleran espacios y guiones que pegue el paciente. */
    private function limpiarReferencia(string $referencia): string
    {
        return (string) preg_replace('/[^0-9A-Za-z\-]/', '', trim($referencia));
    }
}
