<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Livewire en una subcarpeta del dominio
    |--------------------------------------------------------------------------
    |
    | Livewire arma la URL de su script (`/livewire/livewire.js`) y la de sus peticiones
    | (`/livewire/message/...`) desde la raíz del dominio. Si la app no vive en la raíz —el QA en
    | `https://ddrsistemas.com/medic-report`, donde la raíz es otra app Laravel con Livewire—, esas
    | peticiones caen en la otra app y vuelven con 419 (la pestaña no cambia, el formulario no guarda).
    |
    | En ese servidor se ponen las dos con la URL de la app (`LIVEWIRE_ASSET_URL` y `LIVEWIRE_APP_URL`).
    | Sin ellas quedan en `null`, que es el default del paquete: local, mercadoexpres y producción no
    | cambian. El resto de la configuración sale del paquete (`mergeConfigFrom` completa lo que falta).
    |
    */

    'asset_url' => env('LIVEWIRE_ASSET_URL'),

    'app_url' => env('LIVEWIRE_APP_URL'),

];
