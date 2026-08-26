<?php

use Illuminate\Support\Facades\Route;
use App\Livewire\Admin\ListMedicos;
use App\Livewire\Admin\ListPacientes;
use App\Livewire\Admin\ListCentroMedicos;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth'])->group(function () {
    
    // Rutas de Administración de Agenda Médica (Acceso exclusivo Root)
    Route::middleware(['role:Root'])->prefix('admin')->name('admin.')->group(function () {
        Route::get('/medicos', ListMedicos::class)->name('medicos');
        Route::get('/pacientes', ListPacientes::class)->name('pacientes');
        Route::get('/centros-medicos', ListCentroMedicos::class)->name('centros-medicos');
    });

});