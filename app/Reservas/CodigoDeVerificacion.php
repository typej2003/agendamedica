<?php

namespace App\Reservas;

use App\Mail\CodigoDeReserva;
use App\Models\Medico;
use App\Notificaciones\Correo;
use App\Services\SmsService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

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
 *  3. **Correo** (O-54, 2026-11-02): al que dejó el paciente en el formulario. Es el respaldo que mantiene
 *     el portal funcionando cuando los dos canales del teléfono no están disponibles: las credenciales de
 *     WhatsApp y de Twilio son del servidor y pueden faltar o estar rechazadas.
 *  4. **Simulado** en `local`/`testing`: deja el código en el log y el controlador lo muestra en pantalla
 *     solo en local. En producción, sin canal configurado **no se reserva**: se avisa y no se crea nada.
 *
 * El texto del SMS es genérico («código de verificación»), porque el mismo servicio lo usan la reserva y el
 * registro de la cuenta del paciente.
 *
 * El código en claro nunca se guarda en la base: viaja al teléfono (o al correo) y en la sesión queda su
 * hash (más el claro solo en local/testing para poder verificar el flujo).
 */
final class CodigoDeVerificacion
{
    public const CANAL_WHATSAPP = 'whatsapp';
    public const CANAL_SMS = 'sms';
    public const CANAL_CORREO = 'correo';
    public const CANAL_SIMULADO = 'simulado';

    /** Los minutos que dura el código; es lo que dicen el SMS y el correo que lo llevan. */
    public const MINUTOS_DE_VALIDEZ = 10;

    public function generar(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Manda el código por el primer canal que funcione: WhatsApp, SMS y, si el paciente dejó un correo, el
     * correo.
     *
     * @param  string|null  $correo  el del paciente, si lo dejó: es el respaldo cuando el teléfono no puede
     * @return array{ok: bool, canal: string, error?: string}
     */
    public function enviar(string $telefono, string $codigo, ?string $correo = null): array
    {
        // Tope por hora y teléfono (`LimitesDeReserva`): también frena el gasto de SMS/WhatsApp.
        if (! app(LimitesDeReserva::class)->registrarCodigo($telefono)) {
            return ['ok' => false, 'canal' => 'ninguno', 'error' => LimitesDeReserva::mensajeDeCodigos()];
        }

        // Los canales que se intentaron, por su nombre, para poder decirlo si no sale por ninguno.
        $intentados = [];

        $plantilla = (string) config('services.whatsapp.template_codigo', '');
        $whatsapp = app(WhatsAppService::class);

        if ($plantilla !== '' && $this->whatsappConfigurado()) {
            $intentados[] = 'WhatsApp';

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
            $intentados[] = 'SMS';

            $envio = $sms->enviar(
                $telefono,
                "Tu código de verificación de Doctorísimo es {$codigo}. Vence en 10 minutos.",
            );

            if ($envio['ok']) {
                return ['ok' => true, 'canal' => self::CANAL_SMS];
            }

            Log::warning('No se pudo enviar el código por SMS.', ['telefono' => $telefono, 'respuesta' => $envio['respuesta']]);
        }

        $destino = Correo::valido($correo);

        if ($destino !== null) {
            $intentados[] = 'correo';

            if ($this->porCorreo($destino, $codigo)) {
                return ['ok' => true, 'canal' => self::CANAL_CORREO];
            }
        }

        if (app()->environment(['local', 'testing'])) {
            Log::info("Código de reserva (simulado) para {$telefono}: {$codigo}");

            return ['ok' => true, 'canal' => self::CANAL_SIMULADO];
        }

        return [
            'ok' => false,
            'canal' => 'ninguno',
            'error' => $this->mensajeSinCanal($intentados),
        ];
    }

    /**
     * El correo con el código. Devuelve si salió: un SMTP caído no es una excepción del trámite, el que llama
     * decide qué hacer con el `false`.
     */
    private function porCorreo(string $destino, string $codigo): bool
    {
        try {
            Mail::to($destino)->send(new CodigoDeReserva($codigo, self::MINUTOS_DE_VALIDEZ));

            return true;
        } catch (Throwable $e) {
            // El destinatario no se registra: es un dato del paciente.
            Log::warning('No se pudo enviar el código por correo.', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * El aviso de que no salió por ningún lado, diciendo **qué se intentó** y sin lenguaje técnico.
     *
     * @param  array<int,string>  $intentados
     */
    private function mensajeSinCanal(array $intentados): string
    {
        $detalle = $intentados === []
            ? ''
            : ' Probamos ' . $this->listaDeCanales($intentados) . '.';

        return 'No pudimos enviarte el código.' . $detalle
            . ' Vuelve a intentarlo en un momento o comunícate con el consultorio.';
    }

    /**
     * «con WhatsApp», «con WhatsApp y SMS», «con WhatsApp, SMS y correo».
     *
     * @param  array<int,string>  $canales
     */
    private function listaDeCanales(array $canales): string
    {
        $ultimo = array_pop($canales);

        return 'con ' . ($canales === [] ? $ultimo : implode(', ', $canales) . ' y ' . $ultimo);
    }

    private function whatsappConfigurado(): bool
    {
        return (string) config('services.whatsapp.token', '') !== ''
            && (string) config('services.whatsapp.phone_number_id', '') !== '';
    }
}
