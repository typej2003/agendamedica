<?php

use Illuminate\Support\Facades\Route;
use App\Http\Livewire\Medico\ListPacientes;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth'])->group(function () {
    
    // Rutas de Administración de Agenda Médica (Acceso exclusivo Root)
    Route::middleware(['role:Medico'])->prefix('medico')->name('medico.')->group(function () {
        Route::get('/pacientes', ListPacientes::class)->name('medico.pacientes');
    });

});