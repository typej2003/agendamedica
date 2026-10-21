<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pagos (AppDDR)
    |--------------------------------------------------------------------------
    |
    | Lo que no se configura desde el panel: el host por defecto de SyPago y el
    | interruptor de TLS. Las credenciales y la cuenta se cargan desde
    | `/admin/metodos-pago` y viven cifradas en la base. La tasa VES/USD también
    | se carga desde el panel (`tasas_de_cambio`).
    |
    */

    'sypago' => [
        'host' => env('SYPAGO_HOST', 'https://app.sypago.net:8086/api/v1/'),

        // El host de SyPago usa un certificado que en el ejemplo funcional de DonDemand se
        // salta. Se deja configurable: en producción, si el certificado es válido, poner true.
        'verificar_tls' => (bool) env('SYPAGO_VERIFICAR_TLS', false),
    ],

    // Topes de seguridad para que las cuentas en centavos no desborden un entero de 64 bits.
    'maximo_monto_usd_e2' => 100_000_000,   // 1.000.000,00 USD
    'maximo_tasa' => 10_000,                // 10.000 VES por USD

    // Un pago creado para pagar un plan vence a los N minutos (la tasa se congela al iniciarlo).
    'vigencia_pago_minutos' => (int) env('PAGO_VIGENCIA_MINUTOS', 15),

];
