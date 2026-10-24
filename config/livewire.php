<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Livewire 3 sobre la estructura de siempre
    |--------------------------------------------------------------------------
    |
    | Livewire 3 cambió el namespace por defecto a `App\Livewire` y el layout de
    | página a `components.layouts.app`. La app sigue con `App\Http\Livewire` y
    | `layouts.app`, así que se declaran acá. `inject_assets` va en false porque
    | `layouts.app` ya carga `@livewireStyles` y `@livewireScripts`.
    |
    | Las URLs del script y del endpoint de actualización las arma Livewire 3
    | desde la ruta y la URL base de la app; los `LIVEWIRE_ASSET_URL` y
    | `LIVEWIRE_APP_URL` de Livewire 2 ya no existen. La subcarpeta de QA
    | (`/medic-report`) hay que verificarla en el primer despliegue: si no
    | resuelve, se fija con `Livewire::setUpdateRoute()` y `setScriptRoute()`.
    |
    */

    'class_namespace' => 'App\\Http\\Livewire',

    'view_path' => resource_path('views/livewire'),

    'layout' => 'layouts.app',

    'pagination_theme' => 'bootstrap',

    'inject_assets' => false,

    'legacy_model_binding' => false,

];
