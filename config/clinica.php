<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Zona horaria del consultorio
    |--------------------------------------------------------------------------
    |
    | `config('app.timezone')` es **UTC** (el default de Laravel) y se deja así: cambiarlo mueve la
    | interpretación de todas las fechas ya guardadas y de la columna `updated_at` de cada tabla. Pero
    | las decisiones de la web clínica que dependen de "qué día es hoy" y "qué hora es" son del
    | **consultorio**, no del servidor: el saludo de cumpleaños sale cuando en el consultorio son las
    | 09:00, no a las 13:00 UTC (Venezuela es UTC-4).
    |
    | Por eso la zona vive acá, aparte, y se aplica con `App\Clinica\FechaDelConsultorio` en los pocos
    | lugares donde importa. El default es Venezuela, que es donde opera el proyecto; queda en el `.env`
    | (`CLINICA_ZONA`) para cuando se venda en otro país — igual que el `+58` de
    | `App\Notificaciones\Telefono`, que tiene el mismo pendiente multi-país.
    |
    */

    'zona' => env('CLINICA_ZONA', 'America/Caracas'),

    /*
    |--------------------------------------------------------------------------
    | Formato de fechas y horas por defecto (WEB-13.5)
    |--------------------------------------------------------------------------
    |
    | Orden de la fecha (`dmy`/`mdy`), separador (`/`, `-`, `.`) y reloj (`12`/`24`). En la pantalla manda la
    | regionalización del dispositivo (`App\Support\Regionalizacion`); esto es lo que se usa cuando no hay un
    | dispositivo detrás (correos, SMS, WhatsApp, comandos) o el navegador no la dijo. Venezuela: `dmy|/|12`.
    |
    */

    'regionalizacion' => env('CLINICA_REGIONALIZACION', 'dmy|/|12'),

    /*
    |--------------------------------------------------------------------------
    | Verificación pública del récipe (WEB-5.6)
    |--------------------------------------------------------------------------
    |
    | El QR de la hoja del récipe lleva a `<url>/verificar/<token>`. La dirección sale de acá y no de la
    | petición que imprime: el QR se escanea desde un teléfono de una farmacia, así que tiene que ser la
    | dirección **pública** del sitio (en producción `https://doctorisimo.app`). Si `VERIFICACION_URL` no
    | está, vale `APP_URL`.
    |
    */

    'verificacion' => [
        'url' => env('VERIFICACION_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Facturación (O-45)
    |--------------------------------------------------------------------------
    |
    | La **pre-factura** de la consulta existe y funciona, pero el módulo de facturación todavía no está
    | terminado. Con la bandera **apagada** —el default— la sección no aparece en el árbol de ninguna
    | especialidad, no se resuelve por URL (cae en el estado vacío del shell), no cuenta en «X de Y» y su
    | documento no se ofrece para imprimir. Lo ya cargado en `his_con_pre_factura` queda intacto.
    |
    | Encenderla cuando cierre el módulo es cambiar este valor (`FACTURACION_PREFACTURA=true`), no desandar
    | código.
    |
    */

    'facturacion' => [
        'prefactura' => env('FACTURACION_PREFACTURA', false),
    ],

];
