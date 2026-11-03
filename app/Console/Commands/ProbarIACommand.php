<?php

namespace App\Console\Commands;

use App\Services\IA\OpenRouterService;
use App\Services\IA\ResultadoDeHerramienta;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * **Prueba de conectividad y respuesta de la IA** (chatbot): manda un mensaje corto al proveedor configurado y
 * muestra el texto, el modelo y lo que tardó. Sirve para verificar `OPENROUTER_API_KEY` o `GEMINI_API_KEY` sin
 * pasar por WhatsApp ni crear ninguna cita.
 *
 * No usa tools ni lee pacientes: sólo comprueba clave, red, modelo y formato de respuesta.
 *
 *     php artisan ia:probar
 *     php artisan ia:probar "¿En qué horario atienden?" --proveedor=openrouter
 *     php artisan ia:probar "Hola" --proveedor=openrouter --modelo=google/gemma-4-31b-it
 */
class ProbarIACommand extends Command
{
    protected $signature = 'ia:probar
        {mensaje=Hola, responde en una frase. : Mensaje de prueba}
        {--proveedor= : gemini u openrouter (por defecto CHATBOT_PROVEEDOR)}
        {--modelo= : Modelo a usar (por defecto, el configurado para el proveedor)}';

    protected $description = 'Prueba la conexión y la respuesta del proveedor de IA configurado (no escribe nada)';

    public function handle(): int
    {
        $proveedor = strtolower((string) ($this->option('proveedor') ?: config('services.chatbot.proveedor', 'gemini')));
        $mensaje = (string) $this->argument('mensaje');
        $inicio = microtime(true);

        [$modelo, $texto, $error] = match ($proveedor) {
            'openrouter' => $this->probarOpenRouter($mensaje),
            'gemini' => $this->probarGemini($mensaje),
            default => [null, null, "Proveedor desconocido: {$proveedor}. Usa gemini u openrouter."],
        };

        $ms = (int) round((microtime(true) - $inicio) * 1000);

        if ($error !== null) {
            $this->error($error);
            $this->line('Proveedor: <comment>' . $proveedor . '</comment>' . ($modelo !== null ? ' · modelo: ' . $modelo : ''));

            return self::FAILURE;
        }

        $this->line('Proveedor: <info>' . $proveedor . '</info> · modelo: <info>' . $modelo . '</info> · ' . $ms . ' ms');
        $this->newLine();
        $this->line(trim((string) $texto));

        return self::SUCCESS;
    }

    /** @return array{0:?string,1:?string,2:?string} modelo, texto, error */
    private function probarOpenRouter(string $mensaje): array
    {
        if ($this->option('modelo')) {
            config(['services.openrouter.model' => (string) $this->option('modelo')]);
        }

        $servicio = new OpenRouterService();

        if (! $servicio->disponible()) {
            return [$servicio->modelo(), null, 'Falta OPENROUTER_API_KEY en el .env de MedicAPI.'];
        }

        $texto = $servicio->responder(
            'Eres un asistente breve. Responde en una sola frase, en español.',
            $mensaje,
            [],
            fn (): ResultadoDeHerramienta => new ResultadoDeHerramienta()
        );

        if ($texto === null || trim($texto) === '') {
            return [$servicio->modelo(), null, 'OpenRouter no devolvió una respuesta utilizable. Revisa el modelo y el log.'];
        }

        return [$servicio->modeloUsado(), $texto, null];
    }

    /** @return array{0:?string,1:?string,2:?string} modelo, texto, error */
    private function probarGemini(string $mensaje): array
    {
        $clave = trim((string) config('services.gemini.api_key', ''));
        $modelo = (string) ($this->option('modelo') ?: config('services.gemini.model', 'gemini-3.8-flash'));

        if ($clave === '') {
            return [$modelo, null, 'Falta GEMINI_API_KEY en el .env de MedicAPI.'];
        }

        $respuesta = Http::timeout(25)
            ->withHeaders(['x-goog-api-key' => $clave, 'Content-Type' => 'application/json'])
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$modelo}:generateContent", [
                'system_instruction' => ['parts' => [['text' => 'Eres un asistente breve. Responde en una sola frase, en español.']]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $mensaje]]]],
                'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 700],
            ]);

        if (! $respuesta->successful()) {
            return [$modelo, null, "Gemini respondió HTTP {$respuesta->status()}."];
        }

        $texto = (string) $respuesta->json('candidates.0.content.parts.0.text', '');

        if (trim($texto) === '') {
            return [$modelo, null, 'Gemini no devolvió texto. Revisa el modelo y el log.'];
        }

        return [$modelo, $texto, null];
    }
}
