<?php

use Illuminate\Support\Facades\Route;
use App\Http\Livewire\Admin\ListMedicos;
use App\Http\Livewire\Admin\ListPacientes;
use App\Http\Livewire\Admin\ListHistorias;
use App\Http\Livewire\Admin\ListUsers;
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
    
    // Rutas de Administración (acceso exclusivo Root).
    // Nota: La gestión de médicos, administradores y asistentes se centraliza en `/admin/cuentas`
    // (cuentas.php). La operativa médica (consultorio, agenda, historias, sedes, pacientes) vive en `/clinica`.
    // La antigua herramienta de importación `/cargar-sql` fue removida.
    Route::middleware(['role:Root'])->prefix('admin')->name('admin.')->group(function () {
        // Rutas heredadas conservadas solo para soporte interno si fuera necesario:
        Route::get('/users', ListUsers::class)->name('users');
        Route::get('/medicos', ListMedicos::class)->name('medicos');
        Route::get('/pacientes', ListPacientes::class)->name('pacientes');
        Route::get('/medico-centro-medico', ListMedicoCenterMedical::class)->name('medico-centro-medico');
        Route::get('/historias', ListHistorias::class)->name('historias');
    });

});

Route::get('/privacidad', Privacidad::class)->name('privacidad');
Route::get('/servicio', Servicio::class)->name('servicio');
Route::get('/datos-clientes', DatosClientes::class)->name('datos-clientes');