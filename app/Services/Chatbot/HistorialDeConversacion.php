<?php

namespace App\Services\Chatbot;

use Illuminate\Support\Facades\Cache;

/**
 * **Memoria corta de la conversación de WhatsApp** (adaptada de la estrategia de DonDemand/ChatAI): los últimos
 * mensajes del paciente para que el modelo no responda cada turno aislado.
 *
 * Decisiones:
 *
 *  - **Ocho mensajes** (cuatro idas y vueltas) y **30 minutos**, la misma ventana que usa la oferta pendiente
 *    (`wa_pendiente_*`). Más historial encarece el turno y no mejora una conversación de agenda.
 *  - **Solo en Cache**, nunca en la base ni en un log: son datos del paciente y no hacen falta para la historia
 *    clínica.
 *  - Los atajos y la reserva **no** dependen del historial; el historial sólo alimenta al modelo cuando la
 *    conversación llega a la IA.
 */
final class HistorialDeConversacion
{
    public const MAX_MENSAJES = 8;

    public const TTL_MINUTOS = 30;

    /** Tope por mensaje: una respuesta larga del bot no debe desplazar los turnos útiles. */
    public const MAX_CARACTERES = 1200;

    /**
     * @return list<array{role:string,content:string}>
     */
    public function leer(?string $telefono): array
    {
        $clave = $this->clave($telefono);
        if ($clave === null) {
            return [];
        }

        $historial = Cache::get($clave, []);

        if (! is_array($historial)) {
            return [];
        }

        return array_values(array_filter($historial, function ($mensaje): bool {
            return is_array($mensaje)
                && in_array($mensaje['role'] ?? null, ['user', 'assistant'], true)
                && is_string($mensaje['content'] ?? null)
                && trim($mensaje['content']) !== '';
        }));
    }

    /** Agrega el turno actual y recorta al tope. No guarda si no hay teléfono. */
    public function recordar(?string $telefono, string $mensajeUsuario, string $respuesta): void
    {
        $clave = $this->clave($telefono);
        if ($clave === null) {
            return;
        }

        $historial = $this->leer($telefono);
        $historial[] = ['role' => 'user', 'content' => $this->limpiar($mensajeUsuario)];

        if (trim($respuesta) !== '') {
            $historial[] = ['role' => 'assistant', 'content' => $this->limpiar($respuesta)];
        }

        Cache::put($clave, array_slice($historial, -self::MAX_MENSAJES), now()->addMinutes(self::TTL_MINUTOS));
    }

    /** Se usa al empezar de cero una conversación (por ejemplo, desde una prueba o un flujo nuevo). */
    public function olvidar(?string $telefono): void
    {
        $clave = $this->clave($telefono);

        if ($clave !== null) {
            Cache::forget($clave);
        }
    }

    private function clave(?string $telefono): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $telefono) ?? '';

        return $digitos === '' ? null : "wa_historial_{$digitos}";
    }

    private function limpiar(string $texto): string
    {
        $texto = trim($texto);

        return mb_strlen($texto) > self::MAX_CARACTERES
            ? mb_substr($texto, 0, self::MAX_CARACTERES) . '…'
            : $texto;
    }
}
