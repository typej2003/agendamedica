<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Valida la firma `X-Hub-Signature-256` de los POST del webhook de WhatsApp.
 *
 * Meta firma el **cuerpo crudo** de cada petición con el *app secret* (`hash_hmac('sha256', …)`); la
 * cabecera viene como `sha256=<hex>`. Sin esta comprobación cualquiera con la URL podía escribirle a
 * las pacientes y crear citas con el número del consultorio (auditoría del chatbot, 2026-10-17, riesgo 1).
 *
 * **Sin secreto configurado no se acepta nada**: la comparación de antes (`null === null`) daba por
 * buena cualquier petición, que es justamente el agujero que esto cierra. Se responde 503 para que el
 * fallo sea visible en los logs y en el reintento de Meta, en vez de un 200 silencioso.
 */
class VerificaLaFirmaDeWhatsApp
{
    public function handle(Request $request, Closure $next)
    {
        $secreto = trim((string) config('services.whatsapp.app_secret'));

        if ($secreto === '') {
            Log::error('Webhook de WhatsApp sin WHATSAPP_APP_SECRET: se rechaza la petición. Carga el secreto de la app de Meta en el .env.');

            return response()->json(['error' => 'Webhook no configurado'], 503);
        }

        $firma = (string) $request->header('X-Hub-Signature-256', '');
        $esperada = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secreto);

        if ($firma === '' || ! hash_equals($esperada, $firma)) {
            Log::warning('Webhook de WhatsApp con firma inválida: se rechaza la petición.', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Firma inválida'], 403);
        }

        return $next($request);
    }
}
