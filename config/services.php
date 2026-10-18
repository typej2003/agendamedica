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
     * Por ahora **sólo lo usa la prueba de concepto** `php artisan jev:triaje`
     * (`App\Console\Commands\ProbarTriajeJevCommand`): no está conectado al chatbot de WhatsApp.
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
         * `systemone` (el protocolo de Jev: preguntas tipadas y probabilidades calibradas) o `chat` (el
         * camino de los agregadores OpenAI, con JSON pedido por prompt y confianza autoinformada).
         * `auto` intenta `systemone` y, si el proveedor no tiene la ruta, sigue por `chat`.
         *
         * ⚠️ DefAPI (defapi.org) **no expone** `/v1/systemone`: sólo `/v1/chat/completions` y
         * `/v1/messages` (medido el 2026-10-18), así que ahí hay que usar `chat` —o `auto`, que cae solo—.
         */
        'protocolo' => env('JEV_PROTOCOLO', 'auto'),
        // Debajo de este umbral de confianza la decisión se deriva a la secretaría en vez de automatizarse.
        'umbral' => (float) env('JEV_UMBRAL', 0.7),
        'timeout' => (int) env('JEV_TIMEOUT', 25),
    ],

];
