<?php

namespace App\Http\Controllers\Referidos;

use App\Http\Controllers\Controller;
use App\Models\ConfiguracionLatidos;
use App\Referidos\CodigosDeReferido;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * **El link de descarga de un referido** (`/r/{codigo}`, fase 4, 2026-10-31).
 *
 * No es el link de Play Store: es un link de la plataforma para poder cambiar el destino sin cambiar el
 * link compartido, contar clics y atribuir el registro. Guarda el código en una cookie de
 * `vigencia_atribucion_dias` (90 por defecto) y muestra una landing con el botón de descarga configurable y
 * el de registro web.
 *
 * Si el código no existe o está apagado, muestra una página simple para registrarse igual, sin atribución.
 */
class EnlaceDeReferidoController extends Controller
{
    public function __invoke(Request $request, string $codigo, CodigosDeReferido $codigos)
    {
        $codigo = $codigos->resolver($codigo);
        $config = ConfiguracionLatidos::actual();

        if (! $codigo) {
            return response()
                ->view('referidos.enlace', [
                    'codigo' => null,
                    'urlDescarga' => null,
                    'urlRegistro' => route('medico.registro'),
                ])
                ->header('X-Robots-Tag', 'noindex, nofollow');
        }

        $codigos->registrarClic($codigo);

        $dias = max(1, (int) $config->vigencia_atribucion_dias);
        Cookie::queue(cookie(
            (string) config('latidos.cookie', 'doctorisimo_ref'),
            $codigo->codigo,
            $dias * 24 * 60,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'lax',
        ));

        $urlDescarga = trim((string) ($config->url_descarga ?: config('latidos.url_descarga')));

        return response()
            ->view('referidos.enlace', [
                'codigo' => $codigo->codigo,
                'urlDescarga' => $urlDescarga !== '' ? $urlDescarga : null,
                'urlRegistro' => route('medico.registro', ['ref' => $codigo->codigo]),
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
