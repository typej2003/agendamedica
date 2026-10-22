<?php

namespace App\Reservas;

use App\Models\Medico;
use App\Services\SmsService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;

/**
 * **El código de 6 dígitos con el que el paciente prueba que controla el teléfono** antes de reservar una
 * cita o de crear su cuenta. Es la decisión del usuario (2026-10-19) para el portal público.
 *
 * Canales, en orden:
 *
 *  1. **WhatsApp** con una plantilla aprobada (`WHATSAPP_TEMPLATE_CODIGO`). No se manda texto libre: un
 *     mensaje iniciado por el negocio fuera de la ventana de 24 horas lo rechaza Meta.
 *  2. **SMS** por Twilio (`SmsService`), que no necesita plantilla. **Twilio está configurado en QA y
 *     producción** (confirmado por el usuario el 2026-10-21).
 *  3. **Simulado** en `local`/`testing`: deja el código en el log y el controlador lo muestra en pantalla
 *     solo en local. En producción, sin canal configurado **no se reserva**: se avisa y no se crea nada.
 *
 * El texto del SMS es genérico («código de verificación»), porque el mismo servicio lo usan la reserva y el
 * registro de la cuenta del paciente.
 *
 * El código en claro nunca se guarda en la base: viaja al teléfono y en la sesión queda su hash (más el
 * claro solo en local/testing para poder verificar el flujo).
 */
final class CodigoDeVerificacion
{
    public const CANAL_WHATSAPP = 'whatsapp';
    public const CANAL_SMS = 'sms';
    public const CANAL_SIMULADO = 'simulado';

    public function generar(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * @return array{ok: bool, canal: string, error?: string}
     */
    public function enviar(string $telefono, string $codigo): array
    {
        // Tope por hora y teléfono (`LimitesDeReserva`): también frena el gasto de SMS/WhatsApp.
        if (! app(LimitesDeReserva::class)->registrarCodigo($telefono)) {
            return ['ok' => false, 'canal' => 'ninguno', 'error' => LimitesDeReserva::mensajeDeCodigos()];
        }

        $plantilla = (string) config('services.whatsapp.template_codigo', '');
        $whatsapp = app(WhatsAppService::class);

        if ($plantilla !== '' && $this->whatsappConfigurado()) {
            $respuesta = $whatsapp->sendTemplate($telefono, $plantilla, [$codigo], 'es');

            if (! empty($respuesta['messages'])) {
                return ['ok' => true, 'canal' => self::CANAL_WHATSAPP];
            }

            Log::warning('No se pudo enviar el código por WhatsApp; se intenta por SMS.', [
                'telefono' => $telefono,
                'respuesta' => $respuesta,
            ]);
        }

        $sms = app(SmsService::class);

        if ($sms->configurado()) {
            $envio = $sms->enviar(
                $telefono,
                "Tu código de verificación de Doctorísimo es {$codigo}. Vence en 10 minutos.",
            );

            if ($envio['ok']) {
                return ['ok' => true, 'canal' => self::CANAL_SMS];
            }

            Log::warning('No se pudo enviar el código por SMS.', ['telefono' => $telefono, 'respuesta' => $envio['respuesta']]);
        }

        if (app()->environment(['local', 'testing'])) {
            Log::info("Código de reserva (simulado) para {$telefono}: {$codigo}");

            return ['ok' => true, 'canal' => self::CANAL_SIMULADO];
        }

        return [
            'ok' => false,
            'canal' => 'ninguno',
            'error' => 'El servicio de verificación no está disponible en este momento. Comunícate con el consultorio.',
        ];
    }

    private function whatsappConfigurado(): bool
    {
        return (string) config('services.whatsapp.token', '') !== ''
            && (string) config('services.whatsapp.phone_number_id', '') !== '';
    }
}
