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

];
