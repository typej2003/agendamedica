<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * El armazón del tablero de Laravel Pulse (`/pulse`), con el tag de Livewire de DDR.
 *
 * El paquete trae su propio `<x-pulse>` con `@livewireScriptConfig`, que emite la URI de Livewire
 * **relativa a la raíz del dominio** (`/livewire/update`): en QA, que vive bajo `/medic-report`, el AJAX
 * pegaba en `https://ddrsistemas.com/livewire/update` y daba 404. Este componente usa el parcial
 * `layouts._livewire-scripts`, que arma las dos URLs con `url()` y respeta la subcarpeta.
 *
 * Se registra como alias del componente `pulse` en `AppServiceProvider::boot()` y pisa al del paquete
 * porque los alias de clase tienen prioridad sobre los componentes anónimos.
 */
class PulseLayout extends Component
{
    public function __construct(
        public int $cols = 12,
        public bool $fullWidth = false,
    ) {
    }

    public function render(): View
    {
        return view('components.pulse');
    }
}
