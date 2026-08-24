<?php

namespace App\Http\Livewire\Layouts;

use Livewire\Component;

class Aside extends Component
{
    /**
     * Controla si el sidebar está minimizado o no.
     * @var bool
     */
    public $isMinimized = false;

    /**
     * Alterna el estado del sidebar entre minimizado y expandido.
     */
    public function toggleMinimize()
    {
        $this->isMinimized = !$this->isMinimized;
    }

    /**
     * Renderiza la vista del componente.
     */
    public function render()
    {
        return view('livewire.layouts.aside');
    }
}