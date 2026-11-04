<?php

namespace App\Pagos\Conciliacion;

use App\Models\CuentaBancaria;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * **La consulta de operaciones de Bancaribe** (`queryPaymentB2P`, API Manager WSO2).
 *
 * Autenticación: OAuth2 `client_credentials` contra el token endpoint, con `Basic base64(key:secret)`; el
 * token se cachea y se renueva solo. La consulta va con Bearer y JSON.
 *
 * El documento del banco (versión 1.0, 11-06-2024) **no trae la tabla de valores de `estadoEjecucion`**:
 * para no aprobar por un código que no conocemos, el estado se lee de `estadoTransaccion` (el ejemplo
 * usa `EJ`) y de `descripcion`. Cuando la certificación entregue la tabla, se cambia sólo `interpretar()`.
 *
 * La consulta busca **una** transacción por referencia (o factura): no lista lo que entró en el día.
 */
final class ConciliadorBancaribe implements Conciliador
{
    /** Los estados que el banco usa para una operación ejecutada. */
    public const ESTADOS_APROBADOS = ['EJ', 'AP', 'APR', 'APROBADA', 'APROBADO', 'EXITOSA', 'EXITOSO'];

    /** Los estados de una operación rechazada. */
    public const ESTADOS_RECHAZADOS = ['RE', 'RJ', 'R', 'RECHAZADA', 'RECHAZADO', 'ANULADA', 'ANULADO', 'NEGADA', 'NEGADO'];

    public function consultar(CuentaBancaria $cuenta, ConsultaDePago $consulta): ResultadoDeConsulta
    {
        $cuerpo = [
            'tipoTrx' => $consulta->tipo,
            'rif' => $this->rif($cuenta),
            'referencia' => $consulta->referencia,
            'montoTransaccion' => $consulta->monto(),
            'identificadorPersona' => $consulta->documento,
            'telefonoDebito' => $consulta->esTransferencia() ? '' : $consulta->telefono,
            // La factura no la conocemos: el pagador no la escribe en un pago móvil.
            'factura' => null,
            'fecha' => $consulta->fecha?->format('m/d/Y') ?? '',
        ];

        $respuesta = $this->http($cuenta)
            ->withToken($this->token($cuenta))
            ->post($this->consultaUrl($cuenta), $cuerpo);

        $json = $respuesta->json();

        if (! $respuesta->successful()) {
            throw new RuntimeException($this->mensajeDeError($json)
                ?: 'Bancaribe respondió HTTP ' . $respuesta->status() . ' al consultar la operación.');
        }

        if (! is_array($json)) {
            return ResultadoDeConsulta::error('Bancaribe devolvió una respuesta que no se entiende.');
        }

        // La respuesta puede venir como objeto o como una lista de un elemento.
        if (array_is_list($json)) {
            $json = $json[0] ?? null;
            if (! is_array($json)) {
                return ResultadoDeConsulta::noEncontrado('Bancaribe no devolvió ninguna operación.');
            }
        }

        return $this->interpretar($json);
    }

    public function probar(CuentaBancaria $cuenta): array
    {
        if ($this->consumerKey($cuenta) === '' || $this->consumerSecret($cuenta) === '') {
            return ['ok' => false, 'mensaje' => 'Faltan la clave y el secreto del comercio.'];
        }

        try {
            $this->token($cuenta, true);
        } catch (Throwable $e) {
            return ['ok' => false, 'mensaje' => 'No se pudo autenticar con Bancaribe: ' . $e->getMessage()];
        }

        return ['ok' => true, 'mensaje' => 'Conexión correcta: Bancaribe aceptó las credenciales.'];
    }

    /* ------------------------------------------------------------------ */
    /* Interno                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Interpreta la respuesta del banco. El monto viene como texto con punto decimal («10.00»).
     *
     * @param  array<string,mixed>  $json
     */
    private function interpretar(array $json): ResultadoDeConsulta
    {
        $estado = strtoupper(trim((string) ($json['estadoTransaccion'] ?? '')));
        $descripcion = strtoupper(trim((string) ($json['descripcion'] ?? '')));
        $montoE2 = $this->montoEnCentavos($json['monto'] ?? null);
        $referencia = (string) ($json['codigoConfirmacion'] ?? $json['referencia'] ?? '');

        if (in_array($estado, self::ESTADOS_APROBADOS, true)
            || preg_match('/EJECUTAD|APROBAD|EXITOS/', $descripcion) === 1) {
            if ($montoE2 === null) {
                return ResultadoDeConsulta::error('Bancaribe confirmó la operación pero no devolvió el monto.', $json);
            }

            return ResultadoDeConsulta::aprobado($montoE2, $referencia !== '' ? $referencia : null, $json);
        }

        if (in_array($estado, self::ESTADOS_RECHAZADOS, true)
            || preg_match('/RECHAZ|NEGAD|ANULAD/', $descripcion) === 1) {
            return ResultadoDeConsulta::rechazado(
                $descripcion !== '' ? $this->legible($descripcion) : 'El banco rechazó la operación.',
                $json,
            );
        }

        if (str_contains($descripcion, 'NO EXISTE') || str_contains($descripcion, 'NO SE ENCUENTRA')) {
            return ResultadoDeConsulta::noEncontrado('El banco no encontró una operación con esos datos.', $json);
        }

        // Un código de ejecución distinto de cero con una descripción de error es un «no encontrado»:
        // la transacción todavía no llegó o los datos no coinciden.
        $codigo = (string) ($json['estadoEjecucion'] ?? '');
        if ($codigo !== '' && $codigo !== '0') {
            return ResultadoDeConsulta::noEncontrado(
                $this->legible($descripcion) ?: 'El banco no encontró la operación (código ' . $codigo . ').',
                $json,
            );
        }

        return ResultadoDeConsulta::error(
            'El banco respondió algo que no se pudo interpretar: ' . $this->legible($descripcion),
            $json,
        );
    }

    private function montoEnCentavos(mixed $monto): ?int
    {
        if ($monto === null || $monto === '') {
            return null;
        }

        if (! is_numeric($monto)) {
            return null;
        }

        return (int) round(((float) $monto) * 100);
    }

    /** Pasa una descripción en mayúsculas del banco a algo legible. */
    private function legible(string $texto): string
    {
        return $texto === '' ? '' : ucfirst(mb_strtolower($texto));
    }

    /**
     * El Bearer: cacheado por cuenta y por credenciales (si cambian, la caché vieja no se usa).
     *
     * @throws RuntimeException si el banco rechaza las credenciales
     */
    public function token(CuentaBancaria $cuenta, bool $renovar = false): string
    {
        $clave = 'bancaribe.token.' . $cuenta->id . '.' . $this->huella($cuenta);

        if (! $renovar) {
            $cacheado = Cache::get($clave);
            if (is_string($cacheado) && $cacheado !== '') {
                return $cacheado;
            }
        }

        $claveApi = $this->consumerKey($cuenta);
        $secreto = $this->consumerSecret($cuenta);

        if ($claveApi === '' || $secreto === '') {
            throw new RuntimeException('Faltan la clave y el secreto de Bancaribe de esta cuenta.');
        }

        $respuesta = $this->http($cuenta)
            ->asForm()
            ->withHeaders(['Authorization' => 'Basic ' . base64_encode($claveApi . ':' . $secreto)])
            ->post($this->tokenUrl($cuenta), ['grant_type' => 'client_credentials']);

        $json = $respuesta->json();
        $token = is_array($json) ? (string) ($json['access_token'] ?? '') : '';

        if (! $respuesta->successful() || $token === '') {
            $detalle = is_array($json)
                ? (string) ($json['error_description'] ?? $json['error'] ?? $json['message'] ?? '')
                : '';

            throw new RuntimeException('Bancaribe rechazó las credenciales'
                . ($detalle !== '' ? ': ' . $detalle : ' (HTTP ' . $respuesta->status() . ').'));
        }

        $segundos = max(60, (int) (($json['expires_in'] ?? 3600) - 120));
        Cache::put($clave, $token, now()->addSeconds($segundos));

        return $token;
    }

    private function http(CuentaBancaria $cuenta): PendingRequest
    {
        $http = Http::acceptJson()->timeout((int) config('pagos.pago_movil.bancaribe.timeout', 20));

        // Desarrollo y calidad usan el certificado autofirmado `tpr.bancaribe`; producción, el público.
        if (! config('pagos.pago_movil.bancaribe.verificar_certificado', false)) {
            $http = $http->withoutVerifying();
        }

        return $http;
    }

    private function tokenUrl(CuentaBancaria $cuenta): string
    {
        $credenciales = $cuenta->credenciales ?? [];

        return (string) (($credenciales['token_url'] ?? '') ?: config('pagos.pago_movil.bancaribe.token_url'));
    }

    private function consultaUrl(CuentaBancaria $cuenta): string
    {
        $credenciales = $cuenta->credenciales ?? [];

        return (string) (($credenciales['consulta_url'] ?? '') ?: config('pagos.pago_movil.bancaribe.consulta_url'));
    }

    private function consumerKey(CuentaBancaria $cuenta): string
    {
        return trim((string) (($cuenta->credenciales ?? [])['consumer_key'] ?? ''));
    }

    private function consumerSecret(CuentaBancaria $cuenta): string
    {
        return trim((string) (($cuenta->credenciales ?? [])['consumer_secret'] ?? ''));
    }

    /** El RIF del comercio afiliado, como lo pide el banco: tipo + número, sin guiones. */
    private function rif(CuentaBancaria $cuenta): string
    {
        $documento = $cuenta->documentoDelTitular();

        if ($documento === '') {
            throw new RuntimeException('La cuenta no tiene el documento del titular: sin él el banco no puede consultar.');
        }

        return $documento;
    }

    /** @param  array<string,mixed>|null  $json */
    private function mensajeDeError(?array $json): ?string
    {
        if ($json === null) {
            return null;
        }

        $mensaje = $json['descripcion'] ?? $json['message'] ?? $json['error_description'] ?? null;

        return is_string($mensaje) && $mensaje !== '' ? $this->legible($mensaje) : null;
    }

    private function huella(CuentaBancaria $cuenta): string
    {
        return md5(implode('|', [
            $this->tokenUrl($cuenta),
            $this->consumerKey($cuenta),
            $this->consumerSecret($cuenta),
        ]));
    }
}
