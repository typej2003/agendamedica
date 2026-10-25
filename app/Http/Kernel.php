<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array
     */
    protected $middleware = [
        // \App\Http\Middleware\TrustHosts::class,
        \App\Http\Middleware\TrustProxies::class,
        \Illuminate\Http\Middleware\HandleCors::class,
        \App\Http\Middleware\PreventRequestsDuringMaintenance::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            // \Illuminate\Session\Middleware\AuthenticateSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            // Fechas y horas con la regionalización del dispositivo (WEB-13.5): la cookie `ddr_region`.
            \App\Http\Middleware\AplicarRegionalizacion::class,
            \App\Http\Middleware\UserOnlineMiddleware::class, // Middleware para marcar al usuario como activo
            \App\Http\Middleware\EnsureAccountActive::class,
            \App\Http\Middleware\EnsurePasswordChanged::class,
            // Última actividad web de la cuenta (sección "API Keys"): se escribe en la cola, no en el request.
            \App\Http\Middleware\RegistrarActividadWeb::class,
        ],

        'api' => [
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],

        // La página pública de verificación del récipe (WEB-5.6): sin sesión, sin cookies y sin CSRF (sólo GET). No usa el
        // grupo `web` a propósito: quien escanea el QR no inicia sesión ni debe recibir una cookie.
        'verificacion' => [
            \App\Http\Middleware\VerificacionPublica::class,
        ],
    ];

    /**
     * The application's route middleware.
     *
     * These middleware may be assigned to groups or used individually.
     *
     * @var array
     */
    protected $routeMiddleware = [
        'auth' => \App\Http\Middleware\Authenticate::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
        'signed' => \Illuminate\Routing\Middleware\ValidateSignature::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,

        // Cuentas (dashboard de administración): bloqueo y clave temporal. En web ya corren dentro del grupo
        // `web`; para el API se agregan a mano en las rutas autenticadas.
        'account.active' => \App\Http\Middleware\EnsureAccountActive::class,
        'password.changed' => \App\Http\Middleware\EnsurePasswordChanged::class,

        // Canal de publicación del escritorio (el instalador "Standalone"): autoriza publicar, no descargar.
        'clave.canal' => \App\Http\Middleware\ClaveDelCanalEscritorio::class,

        // Portal del paciente: no reserva ni reprograma si está bloqueado o si la validación de su teléfono venció.
        'paciente.habilitado' => \App\Http\Middleware\ExigirPacienteHabilitado::class,

        // Middlewares de Spatie/laravel-permission
        'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
        'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
        'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,

        // Web clínica: exige que el usuario haya elegido con qué médico/registro opera (PLAN-WEB.md).
        'clinica.contexto' => \App\Http\Middleware\AsegurarContextoClinico::class,

        // Web clínica: el «Imprimir» de una sección guarda primero y después imprime (O-16).
        'clinica.imprimir' => \App\Http\Middleware\ImprimeDespuesDeGuardar::class,

        // Webhook de WhatsApp: exige la firma `X-Hub-Signature-256` de Meta (auditoría del chatbot, 2026-10-17).
        'whatsapp.firma' => \App\Http\Middleware\VerificaLaFirmaDeWhatsApp::class,
    ];
}
