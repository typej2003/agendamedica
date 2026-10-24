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
    | los layouts cargan `@livewireStyles` y el parcial `layouts._livewire-scripts`.
    |
    | `@livewireScripts` arma el src y el `data-update-uri` relativos a la raíz
    | (`/livewire/...`), que bajo la subcarpeta de QA (`/medic-report`) pegan en
    | el dominio equivocado. Por eso el tag se arma en
    | `layouts/_livewire-scripts.blade.php` con `url()`, que respeta la
    | subcarpeta; no hacen falta `setUpdateRoute()` ni `setScriptRoute()`.
    |
    */

    'class_namespace' => 'App\\Http\\Livewire',

    'view_path' => resource_path('views/livewire'),

    'layout' => 'layouts.app',

    'pagination_theme' => 'bootstrap',

    'inject_assets' => false,

    'legacy_model_binding' => false,

];
