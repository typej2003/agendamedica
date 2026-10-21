<?php

namespace App\Console\Commands;

use App\Services\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Diagnóstico de la configuración y del camino del webhook de WhatsApp, para la puesta en el servidor.
 *
 * Tres usos, de menos a más invasivo:
 *
 *   php artisan whatsapp:diagnostico
 *       Sólo lee la configuración y dice qué falta. No hace ni una petición.
 *
 *   php artisan whatsapp:diagnostico --firmar
 *       Manda un POST **firmado** al webhook del propio `APP_URL` (`--url=` permite apuntar a otro host, por
 *       ejemplo el `php artisan serve` de 8000) con un payload de *statuses* (no de mensajes): valida el
 *       camino completo —firma, throttle, controlador— **sin** contestarle a nadie ni crear citas. Es la
 *       forma de probar el secreto sin esperar a que Meta mande algo.
 *
 *   php artisan whatsapp:diagnostico --enviar-a=584165800403
 *       Manda un texto REAL por la Graph API al número indicado. Valida el token y el phone_number_id de
 *       salida. Cuesta un mensaje y le llega a una persona: usalo con tu propio número.
 *
 * Nunca imprime el secreto ni el token: sólo dice si están y de qué largo.
 */
class DiagnosticoWhatsAppCommand extends Command
{
    protected $signature = 'whatsapp:diagnostico
        {--firmar : manda un POST firmado al webhook del APP_URL (payload de statuses, sin contestar a nadie)}
        {--url= : URL base para --firmar (por defecto, la de APP_URL; sirve para apuntar a php artisan serve)}
        {--enviar-a= : manda un texto REAL por WhatsApp a ese número (validá el token de salida)}';

    protected $description = 'Diagnóstico del webhook de WhatsApp: qué está configurado y si el camino funciona (no toca la base)';

    public function handle(): int
    {
        $this->line('<info>WhatsApp · configuración</info>');
        $faltan = $this->informarConfiguracion();

        if ($this->option('firmar')) {
            $this->newLine();
            $this->probarFirma();
        }

        if ($this->option('enviar-a') !== null) {
            $this->newLine();
            $this->probarEnvio((string) $this->option('enviar-a'));
        }

        $this->newLine();

        if ($faltan !== []) {
            $this->warn('Falta: ' . implode(', ', $faltan));
            $this->line('El webhook responde 503 sin WHATSAPP_APP_SECRET (es el guard de la auditoría, no un bug).');
            $this->line('Después de cargar variables en el .env del servidor: <comment>php artisan config:clear</comment> y, si el deploy usa cache, <comment>php artisan config:cache</comment>.');

            return self::FAILURE;
        }

        $this->info('La configuración básica está completa.');

        return self::SUCCESS;
    }

    /** @return list<string> lo que falta */
    private function informarConfiguracion(): array
    {
        $claves = [
            'WHATSAPP_APP_SECRET' => 'app_secret (firma X-Hub-Signature-256; sin esto el POST responde 503)',
            'WHATSAPP_VERIFY_TOKEN' => 'verify_token (verificación del número en Meta)',
            'WHATSAPP_TOKEN' => 'token (Graph API de salida)',
            'WHATSAPP_PHONE_NUMBER_ID' => 'phone_number_id (número emisor)',
        ];

        $faltan = [];
        $filas = [];

        foreach ($claves as $variable => $descripcion) {
            $valor = trim((string) config('services.whatsapp.' . $this->claveDeConfig($variable)));
            $ok = $valor !== '';

            if (! $ok) {
                $faltan[] = $variable;
            }

            $filas[] = [
                $ok ? '<info>OK</info>' : '<error>FALTA</error>',
                $variable,
                $ok ? 'cargada (' . mb_strlen($valor) . ' caracteres)' : '—',
                $descripcion,
            ];
        }

        $this->table(['', 'Variable', 'Estado', 'Para qué'], $filas);

        $this->line(sprintf(
            'API Graph: <comment>%s</comment> · Webhook: <comment>%s/api/whatsapp/webhook</comment>',
            (string) config('services.whatsapp.version', 'v19.0'),
            rtrim((string) config('app.url'), '/')
        ));

        return $faltan;
    }

    private function claveDeConfig(string $variable): string
    {
        return match ($variable) {
            'WHATSAPP_APP_SECRET' => 'app_secret',
            'WHATSAPP_VERIFY_TOKEN' => 'verify_token',
            'WHATSAPP_TOKEN' => 'token',
            'WHATSAPP_PHONE_NUMBER_ID' => 'phone_number_id',
            default => strtolower($variable),
        };
    }

    /**
     * POST firmado con un evento de *statuses*: ejercita firma, throttle y controlador sin efecto externo.
     */
    private function probarFirma(): void
    {
        $secreto = trim((string) config('services.whatsapp.app_secret'));

        if ($secreto === '') {
            $this->error('Sin WHATSAPP_APP_SECRET no hay nada que firmar: el webhook va a responder 503.');

            return;
        }

        $cuerpo = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'diagnostico',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => (string) config('services.whatsapp.phone_number_id')],
                        'statuses' => [[
                            'id' => 'wamid.diagnostico',
                            'status' => 'delivered',
                            'timestamp' => (string) time(),
                            'recipient_id' => '000000000000',
                        ]],
                    ],
                ]],
            ]],
        ]);

        $url = rtrim((string) ($this->option('url') ?: config('app.url')), '/') . '/api/whatsapp/webhook';

        $this->line("POST firmado a {$url} (evento de status, no contesta a nadie)…");

        try {
            $respuesta = Http::withHeaders([
                'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $cuerpo, $secreto),
                'Content-Type' => 'application/json',
            ])->withBody($cuerpo, 'application/json')->timeout(15)->post($url);
        } catch (\Throwable $e) {
            $this->error('No se pudo conectar: ' . $e->getMessage());
            $this->line('Si es local, levanta el servidor (<comment>php artisan serve</comment>) y prueba de nuevo.');

            return;
        }

        $status = $respuesta->status();

        match (true) {
            $status === 200 => $this->info("HTTP 200: la firma valida y el webhook acepta el evento. Camino OK."),
            $status === 403 => $this->error('HTTP 403: hay secreto pero la firma no coincide. Revisa que WHATSAPP_APP_SECRET sea el *app secret* de Meta (no el token del número).'),
            $status === 503 => $this->error('HTTP 503: el webhook no ve el secreto. Carga WHATSAPP_APP_SECRET y corre config:clear (o config:cache si el deploy lo usa).'),
            $status === 429 => $this->warn('HTTP 429: el throttle de la ruta te frenó. Espera un minuto.'),
            default => $this->error("HTTP {$status}: " . mb_substr((string) $respuesta->body(), 0, 300)),
        };
    }

    /** Envío real por la Graph API: valida token y phone_number_id. */
    private function probarEnvio(string $numero): void
    {
        if (trim($numero) === '') {
            $this->error('--enviar-a necesita un número (ejemplo: --enviar-a=584165800403).');

            return;
        }

        $this->line('Enviando un texto REAL a ' . $numero . ' (llega a la persona: usa tu propio número)…');

        $resultado = app(WhatsAppService::class)->sendTextMessage(
            $numero,
            'Prueba de diagnóstico de Doctorisimo (' . now()->format('d/m/Y H:i') . '). Si recibís esto, el envío funciona.'
        );

        if (($resultado['success'] ?? false) === true) {
            $this->info('Enviado. HTTP ' . ($resultado['status'] ?? '?') . ' — revisa el teléfono.');

            return;
        }

        $this->error('Falló el envío' . (isset($resultado['status']) ? ' (HTTP ' . $resultado['status'] . ')' : '') . '.');
        $this->line('Detalle: ' . mb_substr(json_encode($resultado['data'] ?? ($resultado['error'] ?? [])), 0, 400));
        $this->line('Si el número no es de prueba, Meta responde 131030 / 131047: hay que habilitarlo o usar una plantilla aprobada.');
    }
}
