<?php

namespace App\Pagos;

use App\Models\MetodoPago;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * El **cliente de SyPago** para un método configurado.
 *
 * Por ahora sólo lo que necesita el panel para probar la configuración y traer el catálogo de bancos
 * (`GET banks`). Los endpoints de cobro (`request/otp`, `transaction/otp`, `transaction/credit`) y la
 * consulta (`GET transaction/{id}`) se agregan en la ola del cobro; el contrato es el del ejemplo
 * funcional de DonDemand.
 *
 * El host y el token salen de las credenciales cifradas del método. Si el certificado del host no es
 * válido, `config('pagos.sypago.verificar_tls')` permite saltarlo (el ejemplo funcional lo hace).
 */
final class ClienteSyPago
{
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

        // El ejemplo devuelve una lista; por si el día de mañana la envuelve en `data`.
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
