<?php

namespace Tests\Feature;

use App\Models\MetodoPago;
use App\Pagos\ClienteSyPago;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * **La autenticación de SyPago** (2026-10-21): la API Key es un `client_id` + `secret` que se cambia por un
 * JWT en `POST auth/token`; el JWT (≈1 hora) es el que viaja en `Authorization: Bearer`.
 *
 * Lo que se fija: el intercambio se hace una vez y se cachea; las llamadas siguientes ya llevan el Bearer.
 * No se toca la red (`Http::fake`).
 */
class SypagoAutenticacionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function metodo(): MetodoPago
    {
        return MetodoPago::create([
            'propietario' => MetodoPago::PLATAFORMA,
            'reg_medico' => null,
            'pasarela' => 'sypago',
            'variante' => 'debito_inmediato',
            'nombre' => 'SyPago · Débito inmediato',
            'activo' => true,
            'entorno' => MetodoPago::PRODUCCION,
            'credenciales' => [
                'tipo' => 'api_key',
                'client_id' => 'ddrs',
                'secret' => 'api-key-secreta',
                'host' => 'https://api.sypago.test/api/v1/',
            ],
            'disponible_para' => MetodoPago::AMBOS,
        ]);
    }

    public function test_cambia_la_api_key_por_un_jwt_y_lo_usa_de_bearer(): void
    {
        Http::fake(function (ClientRequest $request) {
            if (str_contains($request->url(), 'auth/token')) {
                return Http::response(['access_token' => 'jwt-abc', 'expires_in' => 3600], 200);
            }

            return Http::response(['status' => 'ACCP', 'transaction_id' => 'tx-auth'], 200);
        });

        $cliente = ClienteSyPago::para($this->metodo());

        $cliente->consultar('tx-auth');

        Http::assertSent(function (ClientRequest $request) {
            if (! str_contains($request->url(), 'auth/token')) {
                return false;
            }

            return $request['client_id'] === 'ddrs'
                && $request['secret'] === 'api-key-secreta'
                && ! $request->hasHeader('Authorization');
        });

        Http::assertSent(function (ClientRequest $request) {
            if (! str_contains($request->url(), 'transaction/tx-auth')) {
                return false;
            }

            return $request->hasHeader('Authorization', 'Bearer jwt-abc');
        });

        // La segunda consulta no vuelve a pedir el token: está cacheado.
        $cliente->consultar('tx-auth');

        Http::assertSentCount(3); // auth/token + 2 consultas
    }
}
