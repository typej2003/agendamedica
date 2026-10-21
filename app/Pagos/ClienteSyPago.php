<?php

namespace App\Pagos;

use App\Models\MetodoPago;
use App\Models\Pago;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * El **cliente de SyPago** para un método configurado.
 *
 * **Autenticación** (docs de SyPago): hay dos modalidades de API Key.
 *  - **JWT fijo**: el valor ya es el token y viaja en `Authorization: Bearer`.
 *  - **API Key dinámica**: se manda `client_id` + `secret` a `POST auth/token`, que devuelve un
 *    `access_token` (≈1 hora); ése es el Bearer. El token se cachea y se renueva solo.
 *
 * **Cobro**: `POST request/otp` (con `creditor_account`/`debitor_*`) pide el código; `POST transaction/otp`
 * lo confirma y devuelve `transaction_id` + `operation_secret`; `GET transaction/{id}` consulta el estado.
 * El `operation_secret` es parte de la cadena que SyPago firma en el webhook.
 *
 * El host es configurable (producción o certificación) y las credenciales salen cifradas del método.
 */
final class ClienteSyPago
{
    public const APROBADO = 'aprobado';
    public const EN_PROCESO = 'en_proceso';
    public const RECHAZADO = 'rechazado';
    public const DESCONOCIDO = 'desconocido';

    public const TIPO_JWT = 'jwt';
    public const TIPO_API_KEY = 'api_key';

    public function __construct(private readonly MetodoPago $metodo)
    {
    }

    public static function para(MetodoPago $metodo): self
    {
        return new self($metodo);
    }

    /** `jwt` (token fijo) o `api_key` (client_id + secret). */
    public function tipo(): string
    {
        $credenciales = $this->metodo->credenciales ?? [];
        $tipo = (string) ($credenciales['tipo'] ?? '');

        if ($tipo === self::TIPO_JWT || $tipo === self::TIPO_API_KEY) {
            return $tipo;
        }

        // Compatibilidad con las credenciales que se cargaron antes de existir el tipo.
        return trim((string) ($credenciales['client_id'] ?? '')) !== ''
            ? self::TIPO_API_KEY
            : self::TIPO_JWT;
    }

    public function host(): string
    {
        $credenciales = $this->metodo->credenciales ?? [];

        return rtrim(trim((string) ($credenciales['host'] ?? config('pagos.sypago.host'))), '/') . '/';
    }

    public function clientId(): string
    {
        return trim((string) (($this->metodo->credenciales ?? [])['client_id'] ?? ''));
    }

    public function secret(): string
    {
        return trim((string) (($this->metodo->credenciales ?? [])['secret'] ?? ''));
    }

    public function accessTokenFijo(): string
    {
        return trim((string) (($this->metodo->credenciales ?? [])['access_token'] ?? ''));
    }

    public function configurado(): bool
    {
        if (trim($this->host()) === '') {
            return false;
        }

        return $this->tipo() === self::TIPO_JWT
            ? $this->accessTokenFijo() !== ''
            : $this->clientId() !== '' && $this->secret() !== '';
    }

    /**
     * El Bearer que va en cada request: el JWT fijo o el que se obtiene con client_id/secret (cacheado).
     *
     * @throws RuntimeException si SyPago rechaza las credenciales
     */
    public function token(): string
    {
        if ($this->tipo() === self::TIPO_JWT) {
            if ($this->accessTokenFijo() === '') {
                throw new RuntimeException('Falta el JWT fijo de SyPago.');
            }

            return $this->accessTokenFijo();
        }

        return $this->tokenDinamico();
    }

    private function tokenDinamico(): string
    {
        $clientId = $this->clientId();
        $secret = $this->secret();

        if ($clientId === '' || $secret === '') {
            throw new RuntimeException('Faltan el usuario SyPago (client_id) o la API Key (secret).');
        }

        $clave = 'sypago.token.' . $this->metodo->id . '.' . $this->huella();
        $cacheado = Cache::get($clave);
        if (is_string($cacheado) && $cacheado !== '') {
            return $cacheado;
        }

        $respuesta = $this->httpSinToken()->post('auth/token', [
            'client_id' => $clientId,
            'secret' => $secret,
        ]);

        $json = $respuesta->json();
        $token = is_array($json) ? (string) ($json['access_token'] ?? '') : '';

        if (! $respuesta->successful() || $token === '') {
            $detalle = is_array($json)
                ? (string) ($json['message'] ?? $json['error'] ?? '')
                : '';

            throw new RuntimeException('SyPago rechazó las credenciales'
                . ($detalle !== '' ? ': ' . $detalle : ' (HTTP ' . $respuesta->status() . ').'));
        }

        // `expires_in` viene en segundos; se renueva un par de minutos antes.
        $minutos = max(1, (int) (($json['expires_in'] ?? 3600) / 60) - 2);
        Cache::put($clave, $token, now()->addMinutes($minutos));

        return $token;
    }

    public function http(): PendingRequest
    {
        return $this->httpSinToken()->withToken($this->token());
    }

    /** Sin Bearer: lo usan `auth/token` y `GET banks` (la doc dice que bancos no requiere autenticación). */
    private function httpSinToken(): PendingRequest
    {
        $http = Http::acceptJson()->baseUrl($this->host());

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
        $respuesta = $this->httpSinToken()->get('banks')->throw();
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
     * La clave pública con la que SyPago firma sus webhooks (`GET user/key`). Se cachea un día.
     *
     * @throws RuntimeException si no se puede obtener
     */
    public function clavePublica(): string
    {
        $clave = 'sypago.public_key.' . $this->metodo->id . '.' . $this->huella();
        $cacheada = Cache::get($clave);
        if (is_string($cacheada) && $cacheada !== '') {
            return $cacheada;
        }

        $respuesta = $this->http()->get('user/key');
        $json = $respuesta->json();
        $publica = is_array($json) ? (string) ($json['public_key'] ?? '') : '';

        if (! $respuesta->successful() || $publica === '') {
            throw new RuntimeException('No se pudo obtener la clave pública de SyPago (HTTP ' . $respuesta->status() . ').');
        }

        Cache::put($clave, $publica, now()->addDay());

        return $publica;
    }

    /**
     * **Pide el código (OTP)** para debitar la cuenta del pagador.
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
     * **Confirma con el código** y crea la transacción. Devuelve `transaction_id` y `operation_secret`.
     *
     * @return array<string,mixed>
     */
    public function capturarOtp(Pago $pago, string $otp): array
    {
        $pagador = $pago->datos_pagador ?? [];

        $datos = [
            'internal_id' => $pago->internal_id ?: substr(str_replace('-', '', (string) $pago->referencia), 0, 35),
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
        // SyPago acepta cuenta bancaria (`CNTA`) o teléfono de pago móvil (`CELE`). El tipo lo elige el
        // pagador en el checkout; si no vino, se usa el de la variante del método.
        $tipo = (string) ($pagador['tipo_cuenta'] ?? '');
        if (! in_array($tipo, ['cuenta', 'pago_movil'], true)) {
            $tipo = $this->metodo->variante === 'pago_movil' ? 'pago_movil' : 'cuenta';
        }

        $movil = $tipo === 'pago_movil';
        $numero = $movil
            ? preg_replace('/\D/', '', (string) ($pagador['telefono'] ?? $pagador['cuenta'] ?? ''))
            : preg_replace('/\D/', '', (string) ($pagador['cuenta'] ?? ''));

        if ($numero === '') {
            throw new RuntimeException($movil ? 'Falta el teléfono del pagador.' : 'Falta el número de cuenta del pagador.');
        }

        if ($movil && ! preg_match('/^04\d{9}$/', $numero)) {
            throw new RuntimeException('El teléfono del pago móvil debe tener 11 dígitos y empezar con 04.');
        }

        $banco = (string) ($pagador['banco_codigo'] ?? '');
        if (! $movil && $banco !== '' && ! str_starts_with($numero, $banco)) {
            throw new RuntimeException('El número de cuenta debe empezar con el código del banco (' . $banco . ').');
        }

        return [
            'bank_code' => $banco,
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

    /**
     * El monto que viaja a SyPago. **`amt` va como número JSON, no como string**: la API está en .NET y
     * deserializa `amount.amt` a `System.Decimal`; si se manda `"730.00"` responde
     * «The JSON value could not be converted to System.Decimal». Se arma desde el entero en centavos y se
     * redondea a 2 decimales para no arrastrar la representación binaria del float.
     *
     * @return array{amt:float,currency:string}
     */
    private function montoDe(Pago $pago): array
    {
        return [
            'amt' => round(((int) $pago->monto_e2) / 100, 2),
            'currency' => (string) $pago->moneda,
        ];
    }

    private function webhookUrl(): string
    {
        return route('pagos.webhook.sypago', ['token' => $this->metodo->webhook_token]);
    }

    /** Una huella de host + credenciales: si el admin cambia el secreto, la caché vieja no se usa. */
    private function huella(): string
    {
        $credenciales = $this->metodo->credenciales ?? [];

        return md5(implode('|', [
            $this->host(),
            (string) ($credenciales['client_id'] ?? ''),
            (string) ($credenciales['secret'] ?? ''),
            (string) ($credenciales['access_token'] ?? ''),
        ]));
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
     * Prueba el host y las credenciales sin lanzar: devuelve el resultado para el panel.
     *
     * @return array{ok:bool, mensaje:string, bancos:list<array{codigo:string,nombre:string,activo:bool,debito_otp:bool}>}
     */
    public function probar(): array
    {
        if (trim($this->host()) === '') {
            return ['ok' => false, 'mensaje' => 'Falta el host de SyPago.', 'bancos' => []];
        }

        try {
            $bancos = $this->bancos();
        } catch (Throwable $e) {
            return ['ok' => false, 'mensaje' => 'No se pudo conectar con el host: ' . $e->getMessage(), 'bancos' => []];
        }

        try {
            $this->token();
        } catch (Throwable $e) {
            return ['ok' => false, 'mensaje' => 'El host responde, pero las credenciales no: ' . $e->getMessage(), 'bancos' => $bancos];
        }

        return [
            'ok' => true,
            'mensaje' => 'Conexión correcta: ' . count($bancos) . ' bancos y credenciales OK.',
            'bancos' => $bancos,
        ];
    }
}
