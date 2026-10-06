<?php

return [

    /*
    | Días después del vencimiento en que todavía se sincroniza (el servicio figura como "en gracia").
    */
    'dias_gracia' => (int) env('SERVICIO_DIAS_GRACIA', 5),

    /*
    | Meses gratis que recibe un médico recién registrado, sea cual sea el plan marcado como default (el
    | default puede ser de pago: el primer mes no se cobra).
    */
    'prueba_meses' => (int) env('SERVICIO_PRUEBA_MESES', 1),

    /*
    | Plan que se le da gratis a quien sincroniza desde el escritorio PowerBuilder (código en `planes`).
    */
    'plan_powerbuilder' => 'powerbuilder',
];
