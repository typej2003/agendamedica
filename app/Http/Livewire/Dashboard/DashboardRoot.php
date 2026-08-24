<?php

namespace App\Http\Livewire\Dashboard;

use Livewire\Component;

class DashboardRoot extends Component
{
    public function render()
    {
        return view('livewire.dashboard.dashboard-root') // Carga la vista del componente
            ->extends('layouts.app'); // Y le dice que use el layout principal
    }
}