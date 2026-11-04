<?php

namespace App\Services\Chatbot;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * **Tope de mensajes por teléfono** para el chatbot de WhatsApp (adaptado del rate limit de DonDemand/ChatAI).
 * Protege el presupuesto de IA y el webhook de una ráfaga o de un abuso automatizado.
 *
 * - 40 mensajes en 5 minutos por teléfono, configurables en `services.chatbot`.
 * - **Fail-open**: si el Cache falla, deja pasar; es preferible un mensaje de más a dejar a un paciente sin
 *   respuesta.
 * - No expone el límite exacto en el mensaje al paciente y no loguea el contenido, sólo la cuenta.
 */
final class LimiteDeMensajes
{
    private int $limite;

    private int $ventanaSegundos;

    public function __construct(?int $limite = null, ?int $ventanaSegundos = null)
    {
        $this->limite = max(1, $limite ?? (int) config('services.chatbot.limite_mensajes', 40));
        $this->ventanaSegundos = max(60, $ventanaSegundos ?? (int) config('services.chatbot.ventana_mensajes', 300));
    }

    public function superado(?string $telefono): bool
    {
        $clave = $this->clave($telefono);

        if ($clave === null) {
            return false;
        }

        try {
            Cache::add($clave, 0, now()->addSeconds($this->ventanaSegundos));
            $cantidad = Cache::increment($clave);

            // Algunos drivers devuelven false si no soportan increment: se deja pasar.
            if (! is_int($cantidad)) {
                return false;
            }

            if ($cantidad > $this->limite) {
                Log::warning('Chatbot: límite de mensajes superado.', [
                    'cantidad' => $cantidad,
                    'limite' => $this->limite,
                    'ventana_segundos' => $this->ventanaSegundos,
                ]);

                return true;
            }

            return false;
        } catch (\Throwable $e) {
            Log::warning('Chatbot: el límite de mensajes no pudo contar; se deja pasar.', [
                'error' => class_basename($e),
            ]);

            return false;
        }
    }

    public function respuesta(): string
    {
        return 'Recibí muchos mensajes seguidos. Espera unos minutos y escríbeme de nuevo para continuar con tu cita. '
            . 'Si es una urgencia, llama directamente al consultorio.';
    }

    private function clave(?string $telefono): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $telefono) ?? '';

        return $digitos === '' ? null : "wa_limite_{$digitos}";
    }
}
