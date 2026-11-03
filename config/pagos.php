<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pagos (AppDDR)
    |--------------------------------------------------------------------------
    |
    | Lo que no se configura desde el panel: el host por defecto de SyPago. Las
    | credenciales y la cuenta se cargan desde `/admin/metodos-pago` y viven
    | cifradas en la base. La tasa VES/USD se carga desde el panel
    | (`tasas_de_cambio`) o la sincroniza `tasas:bcv`.
    |
    */

    'sypago' => [
        'host' => env('SYPAGO_HOST', 'https://app.sypago.net:8086/api/v1/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pago móvil y transferencias recibidas
    |--------------------------------------------------------------------------
    |
    | La API de consulta es **del banco que recibe**, no del que paga: se elige
    | por el `banco_codigo` de la cuenta del médico (o de la plataforma). Los
    | bancos que no están acá se confirman a mano.
    |
    | Las credenciales (consumer key y secret) van cifradas en la cuenta, no en
    | el `.env`. Del `.env` salen las URLs del ambiente y si se verifica el
    | certificado: Bancaribe usa un certificado autofirmado (`tpr.bancaribe`) en
    | desarrollo y calidad, y el público en producción.
    |
    */
    'pago_movil' => [
        // Los que hoy tienen API de consulta. Los demás se confirman a mano.
        'bancos' => [
            '0114' => ['driver' => 'bancaribe', 'nombre' => 'Bancaribe'],
            '0172' => ['driver' => 'bancamiga', 'nombre' => 'Bancamiga'],
        ],

        // El catálogo para elegir el banco en la pantalla del médico. El nombre es sólo para mostrar:
        // lo que viaja a la API es el código de cuatro dígitos.
        'catalogo' => [
            '0102' => 'Banco de Venezuela',
            '0104' => 'Venezolano de Crédito',
            '0105' => 'Mercantil',
            '0108' => 'BBVA Provincial',
            '0114' => 'Bancaribe',
            '0115' => 'Banco Exterior',
            '0128' => 'Banco Caroní',
            '0134' => 'Banesco',
            '0137' => 'Sofitasa',
            '0138' => 'Banco Plaza',
            '0146' => 'Bangente',
            '0151' => 'BFC Banco Fondo Común',
            '0156' => '100% Banco',
            '0157' => 'DelSur',
            '0163' => 'Banco del Tesoro',
            '0166' => 'Banco Agrícola de Venezuela',
            '0168' => 'Bancrecer',
            '0169' => 'Mi Banco',
            '0171' => 'Banco Activo',
            '0172' => 'Bancamiga',
            '0174' => 'Banplus',
            '0175' => 'Banco Bicentenario',
            '0177' => 'Banfanb',
            '0191' => 'Banco Nacional de Crédito',
        ],

        'bancaribe' => [
            'token_url' => env('BANCARIBE_TOKEN_URL', 'https://openbanking-desa:9443/oauth2/token'),
            'consulta_url' => env('BANCARIBE_CONSULTA_URL', 'https://openbanking-desa:8243/ConsultarOperaciones/1.0.0/queryPaymentB2P'),
            'timeout' => (int) env('BANCARIBE_TIMEOUT', 20),
            'verificar_certificado' => filter_var(env('BANCARIBE_VERIFICAR_CERTIFICADO', false), FILTER_VALIDATE_BOOL),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tasa oficial del BCV
    |--------------------------------------------------------------------------
    |
    | El comando `tasas:bcv` baja la página del Banco Central y lee el valor del
    | dólar (`#dolar strong`). No se configura desde el panel: la URL es del
    | entorno y el certificado se verifica siempre contra `ca`: la cadena de
    | Sectigo que el servidor del BCV no manda completa (sin ella, el curl de
    | Plesk falla con «cURL error 60»). La preferencia de si la tasa es
    | automática vive en `configuracion_pagos.tasa_automatica`.
    |
    */
    'bcv' => [
        'url' => env('BCV_URL', 'https://www.bcv.org.ve'),
        'timeout' => (int) env('BCV_TIMEOUT', 20),
        'ca' => env('BCV_CA', resource_path('certificados/bcv-cadena.pem')),
    ],

    // Topes de seguridad para que las cuentas en centavos no desborden un entero de 64 bits.
    'maximo_monto_usd_e2' => 100_000_000,   // 1.000.000,00 USD
    'maximo_tasa' => 10_000,                // 10.000 VES por USD

    // Un pago creado para pagar un plan vence a los N minutos (la tasa se congela al iniciarlo).
    'vigencia_pago_minutos' => (int) env('PAGO_VIGENCIA_MINUTOS', 15),

];
