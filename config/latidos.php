<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Link de descarga
    |--------------------------------------------------------------------------
    |
    | El destino del botón «Descargar la app» de `/r/{codigo}`. Se puede pisar desde `configuracion_latidos`
    | (`/admin/referidos`). Si está vacío, la landing muestra el registro web y el aviso de que la app estará
    | disponible; nunca un botón que no lleva a ninguna parte.
    |
    */
    'url_descarga' => env('LATIDOS_URL_DESCARGA'),

    /*
    |--------------------------------------------------------------------------
    | Atribución
    |--------------------------------------------------------------------------
    |
    | La cookie que guarda el código del link. Dura `vigencia_atribucion_dias` de `configuracion_latidos`
    | (90 por defecto); es `HttpOnly` y no se lee desde JavaScript.
    |
    */
    'cookie' => env('LATIDOS_COOKIE', 'doctorisimo_ref'),

    'vigencia_dias' => 90,
];
