<?php

use Illuminate\Support\Facades\Route;
use App\Http\Livewire\Admin\ListMedicos;
use App\Http\Livewire\Admin\ListPacientes;
use App\Http\Livewire\Admin\ListHistorias;
use App\Http\Livewire\Admin\ListUsers;
use App\Http\Livewire\Admin\CargarSql;
use App\Http\Livewire\Medico\ListMedicoCenterMedical;
use App\Http\Livewire\Admin\Privacidad;
use App\Http\Livewire\Admin\Servicio;
use App\Http\Livewire\Admin\DatosClientes;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth'])->group(function () {
    
    // Rutas de Administración de Agenda Médica (Acceso exclusivo Root)
    Route::middleware(['role:Root'])->prefix('admin')->name('admin.')->group(function () {
        Route::get('/users', ListUsers::class)->name('users');
        Route::get('/medicos', ListMedicos::class)->name('medicos');
        Route::get('/pacientes', ListPacientes::class)->name('pacientes');
        // Las sedes se administran en `/clinica/sedes` (WEB-2.8b.1): son el lugar donde atiende un
        // médico y se configuran donde se usa la agenda. El ABM que vivía acá (`ListCentroMedicos`)
        // se eliminó: escribía `email` e `is_active`, columnas que `medical_centers` nunca tuvo.
        Route::get('/medico-centro-medico', ListMedicoCenterMedical::class)->name('medico-centro-medico');
        Route::get('/historias', ListHistorias::class)->name('historias');
        Route::get('/cargar-sql', CargarSql::class)->name('cargar-sql');
    });

});

Route::get('/privacidad', Privacidad::class)->name('privacidad');
Route::get('/servicio', Servicio::class)->name('servicio');
Route::get('/datos-clientes', DatosClientes::class)->name('datos-clientes');