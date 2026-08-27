<?php

use Illuminate\Support\Facades\Route;
use App\Http\Livewire\Medico\ListPacientes;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth'])->group(function () {
    
    // Al colocar name('medico.'), la sub-ruta solo requiere name('pacientes')
    Route::middleware(['role:Medico'])->prefix('medico')->name('medico.')->group(function () {
        Route::get('/pacientes', ListPacientes::class)->name('pacientes');
    });

});