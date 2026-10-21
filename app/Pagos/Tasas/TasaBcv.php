<?php

namespace App\Pagos\Tasas;

use App\Models\TasaDeCambio;
use App\Pagos\Tasa;
use DateTimeInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * **La tasa oficial del BCV** (USD→VES), tomada de la página del Banco Central como en el
 * `BvcScrappingExtService` de DonDemand: se busca el bloque `#dolar`, se lee su `<strong>` y se normaliza
 * el número (`36,1234` → `36.123400`).
 *
 * La sincronización es **idempotente**: si la última tasa del BCV es la misma, no se guarda otra fila.
 * Así el comando puede correr cada media hora sin llenar el historial de duplicados.
 *
 * No decide si la tasa se usa: eso lo dice `configuracion_pagos.tasa_origen`. El administrador puede
 * dejarla en manual y el comando no toca nada.
 */
final class TasaBcv
{
    /**
     * Baja la página y devuelve la tasa normalizada a 6 decimales (`"36.123400"`).
     *
     * @throws RuntimeException si la página no responde o no se encuentra el valor
     */
    public function obtener(): string
    {
        $url = (string) config('pagos.bcv.url', 'https://www.bcv.org.ve');

        $http = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml',
        ])->timeout(max(5, (int) config('pagos.bcv.timeout', 20)));

        if (! config('pagos.bcv.verificar_tls', true)) {
            $http = $http->withoutVerifying();
        }

        $respuesta = $http->get($url)->throw();

        return $this->parsear((string) $respuesta->body());
    }

    /**
     * Extrae la tasa del HTML del BCV. Es público para poder probarlo sin red.
     *
     * @throws RuntimeException si no está el bloque `#dolar` o su valor no es un número
     */
    public function parsear(string $html): string
    {
        $dom = new \DOMDocument();

        $erroresPrevios = libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($erroresPrevios);

        $bloque = $dom->getElementById('dolar');
        if (! $bloque) {
            throw new RuntimeException('No se encontró el bloque del dólar (#dolar) en la página del BCV.');
        }

        $valores = $bloque->getElementsByTagName('strong');
        if ($valores->count() === 0) {
            throw new RuntimeException('El bloque del dólar del BCV no tiene el valor en un <strong>.');
        }

        // El texto puede venir con espacios duros y con el separador de miles; `Tasa::aEscala` entiende
        // tanto `36,1234` como `1.234,56`.
        $texto = str_replace(["\xC2\xA0", "\xE2\x80\xAF"], '', $valores->item(0)->textContent);
        $texto = trim(preg_replace('/[^\d.,]/u', '', $texto));

        $escala = Tasa::aEscala($texto);
        if ($escala <= 0) {
            throw new RuntimeException('El valor del dólar del BCV no es un número válido: "' . $texto . '".');
        }

        return Tasa::desdeEscala($escala);
    }

    /**
     * Baja la tasa y la guarda si cambió (o siempre, con `$forzar`).
     *
     * @return TasaDeCambio|null `null` si no había nada nuevo que guardar
     */
    public function sincronizar(?DateTimeInterface $cuando = null, bool $forzar = false): ?TasaDeCambio
    {
        return $this->guardar($this->obtener(), $cuando, $forzar);
    }

    /**
     * Guarda una tasa ya leída. Compara contra la **última del BCV**: si es la misma, no crea otra fila.
     *
     * @return TasaDeCambio|null `null` si no cambió y no se forzó
     */
    public function guardar(string $tasa, ?DateTimeInterface $cuando = null, bool $forzar = false): ?TasaDeCambio
    {
        $escala = Tasa::aEscala($tasa);
        if ($escala <= 0) {
            throw new RuntimeException('La tasa del BCV tiene que ser mayor que cero.');
        }

        $normalizada = Tasa::desdeEscala($escala);

        if (! $forzar) {
            $ultima = TasaDeCambio::where('origen', TasaDeCambio::BCV)
                ->orderByDesc('vigente_desde')
                ->orderByDesc('id')
                ->first();

            if ($ultima && (string) $ultima->tasa === $normalizada) {
                return null;
            }
        }

        return TasaDeCambio::fijar($normalizada, TasaDeCambio::BCV, null, $cuando);
    }
}
