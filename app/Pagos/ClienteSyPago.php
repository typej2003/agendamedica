<?php

namespace App\Pagos;

use App\Models\MetodoPago;
use App\Models\Pago;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * El **cliente de SyPago** para un método configurado.
 *
 * Dos direcciones, como en el ejemplo funcional de DonDemand:
 *  - **Cobrar** (lo que usa el MVP): `POST request/otp` pide el código, `POST transaction/otp` lo confirma
 *    y crea la transacción; `GET transaction/{id}` la consulta.
 *  - **Pagar** (futuro, para liquidarle al médico): `POST transaction/credit` desde la cuenta de la
 *    plataforma al `receiving_user`.
 *
 * Los montos viajan en VES con dos decimales, armados desde el entero en centavos (`Centavos::aDecimal`),
 * nunca con un `float`. El host y el token salen de las credenciales cifradas del método.
 */
final class ClienteSyPago
{
    /** Estados normalizados que entiende el motor. */
    public const APROBADO = 'aprobado';
    public const EN_PROCESO = 'en_proceso';
    public const RECHAZADO = 'rechazado';
    public const DESCONOCIDO = 'desconocido';

    public function __construct(private readonly MetodoPago $metodo)
    {
    }

    public static function para(MetodoPago $metodo): self
    {
        return new self($metodo);
    }

    public function configurado(): bool
    {
        return trim($this->token()) !== '' && trim($this->host()) !== '';
    }

    public function host(): string
    {
        $credenciales = $this->metodo->credenciales ?? [];

        return rtrim(trim((string) ($credenciales['host'] ?? config('pagos.sypago.host'))), '/') . '/';
    }

    public function token(): string
    {
        $credenciales = $this->metodo->credenciales ?? [];

        return trim((string) ($credenciales['access_token'] ?? ''));
    }

    public function http(): PendingRequest
    {
        $http = Http::acceptJson()
            ->withToken($this->token())
            ->baseUrl($this->host());

        if (! config('pagos.sypago.verificar_tls', false)) {
            $http = $http->withoutVerifying();
        }

        return $http;
    }

    /**
     * El catálogo de bancos de SyPago.
     *
     * @return list<array{codigo:string,nombre:string,activo:bool,debito_otp:bool}>
     */
    public function bancos(): array
    {
        $respuesta = $this->http()->get('banks')->throw();
        $json = $respuesta->json();

        $lista = is_array($json) && isset($json['data']) && is_array($json['data']) ? $json['data'] : $json;

        if (! is_array($lista)) {
            return [];
        }

        $bancos = [];
        foreach ($lista as $banco) {
            if (! is_array($banco)) {
                continue;
            }
            $codigo = (string) ($banco['Code'] ?? $banco['code'] ?? '');
            if ($codigo === '') {
                continue;
            }
            $bancos[] = [
                'codigo' => $codigo,
                'nombre' => (string) ($banco['Name'] ?? $banco['name'] ?? $codigo),
                'activo' => (bool) ($banco['Active'] ?? $banco['active'] ?? false),
                'debito_otp' => (bool) ($banco['IsDebitOTP'] ?? $banco['is_debit_otp'] ?? false),
            ];
        }

        return $bancos;
    }

    /**
     * **Pide el código (OTP)** para debitar la cuenta del pagador. No crea la transacción todavía: eso lo
     * hace `capturarOtp()` con el código que el pagador recibe.
     *
     * @param  array{nombre:string, documento_tipo:string, documento_numero:string, banco_codigo:string, cuenta?:string, telefono?:string}  $pagador
     * @return array<string,mixed>
     */
    public function solicitarOtp(Pago $pago, array $pagador): array
    {
        $datos = [
            'creditor_account' => $this->cuentaDelComercio(),
            'debitor_document_info' => $this->documentoDelPagador($pagador),
            'debitor_account' => $this->cuentaDelPagador($pago, $pagador),
            'amount' => $this->montoDe($pago),
        ];

        return $this->post('request/otp', $datos, 'No se pudo enviar el código de confirmación.');
    }

    /**
     * **Confirma con el código** y crea la transacción. La respuesta trae el `status` (ACCP/PEND/…).
     *
     * @return array<string,mixed>
     */
    public function capturarOtp(Pago $pago, string $otp): array
    {
        $pagador = $pago->datos_pagador ?? [];

        $datos = [
            'internal_id' => $pago->referencia,
            'group_id' => 'ddr-' . $pago->created_at->format('Ymd'),
            'account' => $this->cuentaDelComercio(),
            'amount' => $this->montoDe($pago),
            'concept' => $pago->concepto,
            'notification_urls' => [
                'web_hook_endpoint' => $this->webhookUrl(),
            ],
            'receiving_user' => [
                'name' => (string) ($pagador['nombre'] ?? ''),
                'otp' => $otp,
                'document_info' => $this->documentoDelPagador($pagador),
                'account' => $this->cuentaDelPagador($pago, $pagador),
            ],
        ];

        return $this->post('transaction/otp', $datos, 'No se pudo confirmar el pago.');
    }

    /**
     * Consulta una transacción por su id de SyPago (la fuente de verdad para conciliar).
     *
     * @return array<string,mixed>
     */
    public function consultar(string $transaccion): array
    {
        $respuesta = $this->http()->get('transaction/' . rawurlencode($transaccion));

        $json = $respuesta->json();
        if (! is_array($json)) {
            $json = ['http_response' => 'html'];
        }

        if (! $respuesta->successful()) {
            throw new RuntimeException($this->mensajeDeError($json) ?: 'SyPago respondió HTTP ' . $respuesta->status() . ' al consultar la transacción.');
        }

        return ['http_code' => $respuesta->status()] + $json;
    }

    /** Normaliza el `status` de SyPago a lo que entiende el motor. */
    public static function estadoDe(array $respuesta): string
    {
        $status = strtoupper(trim((string) ($respuesta['status'] ?? '')));

        return match ($status) {
            'ACCP' => self::APROBADO,
            'PEND', 'PROC' => self::EN_PROCESO,
            'RJCT', 'CANC' => self::RECHAZADO,
            default => self::DESCONOCIDO,
        };
    }

    /** El mensaje legible del `rejected_code` de SyPago (o el `message` que venga). */
    public function mensajeDeError(array $respuesta): ?string
    {
        $codigo = (string) ($respuesta['rejected_code'] ?? '');

        $mensaje = match ($codigo) {
            'RJCT' => 'Operación rechazada',
            'WAIT' => 'Operación en espera de validación de código',
            'AG09' => 'Pago no recibido',
            'AC00' => 'Operación en espera de respuesta del receptor',
            'AB01' => 'Tiempo de espera agotado',
            'AB07' => 'Agente fuera de línea',
            'AB08' => 'Error de comunicación con la entidad financiera',
            'AC01' => 'Número de cuenta incorrecto',
            'AC04' => 'Cuenta cancelada',
            'AC06' => 'Cuenta bloqueada',
            'AC09' => 'Moneda no válida',
            'AG10' => 'Agente suspendido o excluido',
            'AM02' => 'Monto de la transacción no permitido',
            'AM03' => 'Moneda no permitida',
            'AM04' => 'Saldo insuficiente',
            'AM05' => 'Operación duplicada',
            'BE01' => 'Los datos del cliente no corresponden a la cuenta',
            'BE20' => 'Longitud del nombre inválida',
            'CH20' => 'Número de decimales incorrecto',
            'DU01' => 'Identificación de mensaje duplicado',
            'ED05' => 'Liquidación fallida',
            'FF05' => 'Código del producto incorrecto',
            'FF07' => 'Código del subproducto incorrecto',
            'RC08' => 'Código del banco no existe en el sistema de compensación',
            'TKCM' => 'Código único de operación de débito incorrecto',
            'TM01' => 'Fuera del horario permitido',
            'VE01' => 'Rechazo técnico',
            'DT03' => 'Fecha de procesamiento no bancaria no válida',
            'TECH' => 'Error técnico al procesar la liquidación',
            'AG01' => 'Transacción restringida',
            'MD09' => 'Afiliación inactiva',
            'MD15' => 'Monto incorrecto',
            'MD21' => 'Cobro no permitido',
            'CUST' => 'Cancelación solicitada por el deudor',
            'DS02' => 'Operación cancelada',
            'MD01' => 'No posee afiliación',
            'MD22' => 'Afiliación suspendida',
            default => null,
        };

        return $mensaje
            ?? (isset($respuesta['message']) && is_string($respuesta['message']) ? $respuesta['message'] : null);
    }

    /* ------------------------------------------------------------------ */
    /* Piezas del payload                                                  */
    /* ------------------------------------------------------------------ */

    /** @return array{bank_code:string,type:string,number:string} */
    private function cuentaDelComercio(): array
    {
        $cuenta = $this->metodo->cuentaBancaria;

        if (! $cuenta || ! $cuenta->estaCompleta()) {
            throw new RuntimeException('El método de pago no tiene una cuenta de destino configurada.');
        }

        return [
            'bank_code' => (string) $cuenta->banco_codigo,
            'type' => 'CNTA',
            'number' => preg_replace('/\D/', '', (string) $cuenta->numero),
        ];
    }

    /**
     * La cuenta del **pagador**: cuenta bancaria para débito inmediato, teléfono para pago móvil.
     *
     * @return array{bank_code:string,type:string,number:string}
     */
    private function cuentaDelPagador(Pago $pago, array $pagador): array
    {
        $movil = $this->metodo->variante === 'pago_movil';
        $numero = $movil
            ? preg_replace('/\D/', '', (string) ($pagador['telefono'] ?? ''))
            : preg_replace('/\D/', '', (string) ($pagador['cuenta'] ?? ''));

        if ($numero === '') {
            throw new RuntimeException($movil ? 'Falta el teléfono del pagador.' : 'Falta el número de cuenta del pagador.');
        }

        return [
            'bank_code' => (string) ($pagador['banco_codigo'] ?? ''),
            'type' => $movil ? 'CELE' : 'CNTA',
            'number' => $numero,
        ];
    }

    /** @return array{type:string,number:string} */
    private function documentoDelPagador(array $pagador): array
    {
        return [
            'type' => (string) ($pagador['documento_tipo'] ?? ''),
            'number' => (string) ($pagador['documento_numero'] ?? ''),
        ];
    }

    /** @return array{amt:string,currency:string} */
    private function montoDe(Pago $pago): array
    {
        return [
            'amt' => Centavos::aDecimal((int) $pago->monto_e2),
            'currency' => (string) $pago->moneda,
        ];
    }

    private function webhookUrl(): string
    {
        return route('pagos.webhook.sypago', ['token' => $this->metodo->webhook_token]);
    }

    /**
     * @param  array<string,mixed>  $datos
     * @return array<string,mixed>
     */
    private function post(string $ruta, array $datos, string $mensaje): array
    {
        $respuesta = $this->http()->asJson()->post($ruta, $datos);

        $json = $respuesta->json();
        if (! is_array($json)) {
            $json = ['http_response' => 'html'];
        }

        if (! $respuesta->successful()) {
            throw new RuntimeException($this->mensajeDeError($json) ?: ($mensaje . ' (HTTP ' . $respuesta->status() . ')'));
        }

        return ['http_code' => $respuesta->status()] + $json;
    }

    /**
     * Prueba la configuración sin lanzar: devuelve el resultado para mostrarlo en el panel.
     *
     * @return array{ok:bool, mensaje:string, bancos:list<array{codigo:string,nombre:string,activo:bool,debito_otp:bool}>}
     */
    public function probar(): array
    {
        if (! $this->configurado()) {
            return ['ok' => false, 'mensaje' => 'Falta el token de acceso o el host de SyPago.', 'bancos' => []];
        }

        try {
            $bancos = $this->bancos();

            return [
                'ok' => true,
                'mensaje' => 'Conexión correcta: ' . count($bancos) . ' bancos disponibles.',
                'bancos' => $bancos,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'mensaje' => 'No se pudo conectar: ' . $e->getMessage(),
                'bancos' => [],
            ];
        }
    }
}
