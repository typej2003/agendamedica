<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cola;
use App\Models\Paciente;
use App\Services\GeminiService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    protected WhatsAppService $whatsAppService;
    protected GeminiService $geminiService;

    public function __construct(WhatsAppService $whatsAppService, GeminiService $geminiService)
    {
        $this->whatsAppService = $whatsAppService;
        $this->geminiService = $geminiService;
    }

    /**
     * Verificación del Webhook por parte de Meta.
     */
    public function verify(Request $request)
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $verifyToken = config('services.whatsapp.verify_token');

        if ($mode === 'subscribe' && $token === $verifyToken) {
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    /**
     * Recepción de eventos entrantes de WhatsApp.
     */
    public function handle(Request $request)
    {
        $data = $request->all();

        // 1. Validar que vengan mensajes en el payload de Meta
        $entry = $data['entry'][0] ?? null;
        $changes = $entry['changes'][0] ?? null;
        $value = $changes['value'] ?? null;
        $messages = $value['messages'] ?? null;

        if (!$messages || !is_array($messages) || empty($messages)) {
            // Pueden ser eventos de entrega (status: sent, delivered, read)
            return response()->json(['status' => 'ignored'], 200);
        }

        $messageData = $messages[0];
        $fromNumber = $messageData['from'] ?? null;
        $type = $messageData['type'] ?? null;

        // Solo procesamos mensajes de tipo texto
        if ($type !== 'text' || !$fromNumber) {
            return response()->json(['status' => 'unsupported_type'], 200);
        }

        $incomingText = $messageData['text']['body'] ?? '';

        if (trim($incomingText) === '') {
            return response()->json(['status' => 'empty_body'], 200);
        }

        // 2. Extraer contexto del paciente según su número
        $contexto = $this->obtenerContextoPaciente($fromNumber);

        // 3. Generar respuesta con la IA de Gemini
        $respuestaIA = $this->geminiService->responderMensaje($incomingText, $contexto);

        // 4. Enviar la respuesta vía WhatsApp
        $this->whatsAppService->sendTextMessage($fromNumber, $respuestaIA);

        return response()->json(['status' => 'success'], 200);
    }

    /**
     * Busca información previa del paciente y sus citas.
     */
    protected function obtenerContextoPaciente(string $phoneNumber): array
    {
        $contexto = [
            'nombre_paciente' => null,
            'citas_pendientes' => []
        ];

        // Se buscan los últimos 7 u 8 dígitos para compatibilidad con prefijos internacionales
        $ultimosDigitos = substr(preg_replace('/[^0-9]/', '', $phoneNumber), -8);

        $paciente = Paciente::where('telefono', 'like', "%{$ultimosDigitos}%")
            ->orWhere('celular', 'like', "%{$ultimosDigitos}%")
            ->first();

        if ($paciente) {
            $contexto['nombre_paciente'] = trim("{$paciente->nombres} {$paciente->apellidos}");

            // Citas pendientes en la tabla cola
            $citas = Cola::where('numhistoria', $paciente->numhistoria)
                ->whereDate('fecha', '>=', now()->toDateString())
                ->orderBy('fecha', 'asc')
                ->take(3)
                ->get(['fecha', 'hora', 'status', 'reg_medico']);

            if ($citas->isNotEmpty()) {
                $contexto['citas_pendientes'] = $citas->toArray();
            }
        }

        return $contexto;
    }
}