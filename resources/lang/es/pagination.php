<?php

/*
|--------------------------------------------------------------------------
| Paginación en español (O-15)
|--------------------------------------------------------------------------
|
| `config('app.locale')` pasó a `es` para que los mensajes de validación salgan en español. Los paginadores
| de los catálogos usan `links()`, que traduce «Anterior» y «Siguiente» con este archivo: sin él, la pantalla
| mostraría la clave cruda (`pagination.previous`).
|
*/

return [

    'previous' => '« Anterior',
    'next' => 'Siguiente »',

];
