<?php

namespace App\Http\Controllers;

use App\Reservas\CatalogoDeMedicos;
use Illuminate\Support\Facades\Cache;

/**
 * **`/sitemap.xml` y `/robots.txt`** (auditoría SEO de Plesk, 2026-10-29).
 *
 * Los dos se sirven desde Laravel y no como archivos de `public/` porque llevan direcciones absolutas: QA corre bajo otro
 * dominio y subcarpeta (`/medic-report`) y un archivo estático obligaría a fijar el dominio de uno de los dos entornos.
 * Con `url()` cada servidor dice el suyo.
 */
class SeoPublicoController extends Controller
{
    /** Lo que ningún buscador tiene que recorrer: el consultorio, el panel, el API y las cuentas. */
    private const PRIVADO = [
        '/admin', '/clinica', '/dashboard', '/api', '/livewire', '/pulse',
        '/mis-citas', '/agendar', '/users', '/cambiar-password', '/elegir-panel',
    ];

    public function robots()
    {
        $lineas = ['User-agent: *'];
        foreach (self::PRIVADO as $prefijo) {
            $lineas[] = 'Disallow: ' . $prefijo;
        }
        $lineas[] = '';
        $lineas[] = 'Sitemap: ' . url('/sitemap.xml');

        return response(implode("\n", $lineas) . "\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(CatalogoDeMedicos $catalogo)
    {
        // Las fichas salen de los médicos con agenda publicada, que no es una lectura gratis: una hora de caché alcanza.
        $fichas = Cache::remember('seo.sitemap.fichas', 3600, fn () => $catalogo->medicos()
            ->map(fn ($medico) => route('reservas.medico', ['medico' => $medico->id]))
            ->all());

        $direcciones = array_merge([
            url('/'),
            route('reservas.catalogo'),
            route('medico.registro'),
        ], $fichas);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($direcciones as $direccion) {
            $xml .= '  <url><loc>' . e($direccion) . '</loc></url>' . "\n";
        }
        $xml .= '</urlset>' . "\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
