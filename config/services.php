<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'whatsapp' => [
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        // El secreto de la app de Meta (App settings → Basic). Con él se valida la firma
        // `X-Hub-Signature-256` de cada POST del webhook: sin este valor el webhook **rechaza**
        // todo (ver `App\Http\Middleware\VerificaLaFirmaDeWhatsApp`).
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'version' => env('WHATSAPP_API_VERSION', 'v19.0'),
        // Plantilla aprobada en Meta para el código de la reserva pública. El mensaje lo inicia el
        // negocio (el paciente no escribió primero), así que **no** se puede mandar texto libre:
        // sin esta plantilla el portal usa SMS (Twilio) o, en local, simula el envío.
        'template_codigo' => env('WHATSAPP_TEMPLATE_CODIGO'),
    ],

    /*
     * Proveedor de SMS de la web (WEB-2.6). La credencial es **del servidor**: los médicos no
     * configuran proveedores de comunicación (decisión de Alexander, 2026-10-09) — el servidor manda
     * por todos y el gasto se mide por médico con `notificaciones_cita`.
     */
    'twilio' => [
        'sid'   => env('TWILIO_ACCOUNT_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'from'  => env('TWILIO_FROM'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.8-flash'),
    ],

    /*
     * **Jev AI — modelo de decisiones, no de conversación** (evaluado el 2026-10-17, ver
     * `Docs/tecnico/auditoria-chatbot-whatsapp.md` §10.3 y `ROADMAP.md` §10.3). Devuelve valores tipados
     * con probabilidad y confianza sobre un `state` y un mapa de preguntas (`choice`, `score`, `noul`).
     *
     * Lo usan la prueba de concepto `php artisan jev:triaje` (`App\Console\Commands\ProbarTriajeJevCommand`) y,
     * para una sola decisión, el chatbot de WhatsApp: si un pedido de cita es una urgencia (se agenda con 90 min
     * en vez de 24 h; `App\Reservas\UrgenciaClinicaJev`). No hay triaje previo del bot con Jev.
     *
     * La clave vive en el `.env` (ignorado por git) como `JEV_API_KEY`; **nunca** en el código ni en un
     * documento. `base_url` y `model` cambian según de dónde salga la clave:
     *
     *   Jev AI (oficial)      https://thejevai.com      typesafe/jev-1.14
     *   DigitalOcean Inference https://inference.do-ai.run  typesafe-jev-1.13.0
     */
    'jev' => [
        'api_key' => env('JEV_API_KEY'),
        'base_url' => env('JEV_BASE_URL', 'https://thejevai.com'),
        'model' => env('JEV_MODEL', 'typesafe/jev-1.14'),
        /*
         * El transporte, con sus dos rutas de **decisiones** (las que traen probabilidades calibradas):
         *
         *   systemone → POST {base}/v1/systemone        (Jev oficial y DigitalOcean)
         *   decisions → POST {base}/api/v1/decisions    (DefAPI; mismo cuerpo, mismo estado)
         *   chat      → POST {base}/v1/chat/completions (agregadores sólo OpenAI: confianza autoinformada)
         *   auto      → prueba las dos de decisiones en orden y, si no hay ninguna, cae a chat
         *
         * Medido el 2026-10-18: DefAPI **no** sirve `typesafe/jev-1.13` por chat ni por messages («Model not
         * supported»), y no tiene `/v1/systemone`. Su ruta es `/api/v1/decisions`.
         */
        'protocolo' => env('JEV_PROTOCOLO', 'auto'),
        // Debajo de este umbral de confianza la decisión se deriva a la secretaría en vez de automatizarse.
        'umbral' => (float) env('JEV_UMBRAL', 0.7),
        'timeout' => (int) env('JEV_TIMEOUT', 25),
        /*
         * **Urgencia en el chatbot de WhatsApp** (`App\Reservas\UrgenciaClinicaJev`): sólo decide si se relaja la
         * anticipación de 24 h a 90 min. Timeout corto y un único intento para no frenar el chat; ante cualquier
         * fallo rige siempre la regla de 24 h.
         */
        'urgencia_timeout' => (int) env('JEV_URGENCIA_TIMEOUT', 4),
    ],

];
