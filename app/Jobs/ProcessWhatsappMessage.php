<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\GeminiService;
use App\Services\WhatsappService;
use App\Models\Mensaje;

class ProcessWhatsappMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $telefono,
        public string $mensajeTexto
    ) {}

    public function handle(GeminiService $gemini, WhatsappService $whatsapp): void
    {
        // 1. Obtener contexto/historial previo si aplica
        $historial = $this->obtenerHistorialContexto($this->telefono);

        // 2. Procesar respuesta con Gemini y las herramientas asignadas
        $respuestaIA = $gemini->procesarMensaje($this->telefono, $this->mensajeTexto, $historial);

        // 3. Enviar la respuesta de regreso al paciente por WhatsApp
        $whatsapp->enviarMensajeTexto($this->telefono, $respuestaIA);

        // 4. Registrar en la base de datos
        Mensaje::create([
            'telefono' => $this->telefono,
            'mensaje' => $this->mensajeTexto,
            'respuesta' => $respuestaIA,
            'origen' => 'whatsapp_bot'
        ]);
    }

    private function obtenerHistorialContexto(string $telefono): array
    {
        // Cargar últimos 5 mensajes para mantener coherencia en la conversación
        return Mensaje::where('telefono', $telefono)
            ->latest()
            ->take(5)
            ->get()
            ->reverse()
            ->flatMap(function ($msg) {
                return [
                    Part::text("Usuario: " . $msg->mensaje),
                    Part::text("Modelo: " . $msg->respuesta)
                ];
            })
            ->toArray();
    }
}