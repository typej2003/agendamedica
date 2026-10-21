<?php

use App\Http\Controllers\Comentarios\ImagenDelComentarioController;
use App\Http\Livewire\Admin\ApiKeys;
use App\Http\Livewire\Admin\CatalogoInicialPercentiles;
use App\Http\Livewire\Admin\CatalogosIniciales;
use App\Http\Livewire\Admin\CatalogoInicialVacunas;
use App\Http\Livewire\Admin\Comentarios;
use App\Http\Livewire\Admin\Cuentas;
use App\Http\Livewire\Admin\Especialidades;
use App\Http\Livewire\Admin\MetodosPago;
use App\Http\Livewire\Admin\Servicios;
use App\Http\Livewire\Admin\Sedes\ConsultoriosDeLaSede;
use App\Http\Livewire\Admin\Sedes\HorariosDelConsultorio;
use App\Http\Livewire\Admin\Sedes\SedesDelMedico;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Gestión de usuarios y API keys (Paso 26)
|--------------------------------------------------------------------------
| Archivo propio, aparte de `admin.php`, para no mezclarse con las rutas que ya existían. Acceso: Root o
| Administrador. Las acciones de los componentes Livewire vuelven a comprobarlo en cada petición (ver `hydrate()`).
*/

Route::middleware(['auth', 'role:Root|Administrador'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/cuentas', Cuentas::class)->name('cuentas');
    // Con qué especialidades trabaja cada médico (lo que enciende los módulos de la web clínica).
    Route::get('/especialidades', Especialidades::class)->name('especialidades');
    // **Catálogo inicial · Vacunas** (WEB-12.8): lo que cada médico de pediatría recibe como punto de partida.
    Route::get('/catalogo-inicial/vacunas', CatalogoInicialVacunas::class)->name('catalogo-inicial.vacunas');
    // **Catálogo inicial · Referencia de crecimiento** (WEB-7.4.3e): `percentil_nino` y `percentil_nina` sin dueño.
    Route::get('/catalogo-inicial/percentiles', CatalogoInicialPercentiles::class)->name('catalogo-inicial.percentiles');
    // **Catálogos iniciales** (WEB-12.8): un solo componente para todos los catálogos del registro (`CatalogosIniciales`).
    Route::get('/catalogo-inicial/catalogos/{catalogo}', CatalogosIniciales::class)->where('catalogo', '[a-z][a-z-]*')->name('catalogo-inicial.catalogo');
    Route::get('/api-keys', ApiKeys::class)->name('api-keys');
    // Planes de servicio y vencimiento por médico (Paso 27).
    // **Ayúdanos a mejorar** (ROADMAP §9): la bandeja donde recibimos los comentarios del consultorio.
    Route::get('/comentarios', Comentarios::class)->name('comentarios');
    // **La captura adjunta** (O-25): la bandeja la muestra y la abre. El archivo vive en un disco privado y
    // esta ruta es el único camino a él, con el permiso comprobado en el controlador.
    Route::get('/comentarios/{comentario}/imagenes/{imagen}', [ImagenDelComentarioController::class, 'deLaBandeja'])
        ->name('comentarios.imagen');
    Route::get('/servicios', Servicios::class)->name('servicios');
    // **Métodos de pago de la plataforma** (2026-10-21): con qué cobra Doctorisimo los planes y, a
    // futuro, los cobros de los pacientes. Incluye la tasa VES/USD manual.
    Route::get('/metodos-pago', MetodosPago::class)->name('metodos-pago');
    // **Sedes del médico** (2026-10-20): el administrador ayuda a configurar dónde atiende cada médico, en
    // profundidad: sedes → consultorios de la sede → horarios y cupos del consultorio. Las reglas son las Actions
    // de `/clinica` (`GuardarSede`, `GuardarConsultorio`, `GuardarBloqueHorario`); el tenant, el `reg_medico` del médico.
    Route::get('/medicos/{medico}/sedes', SedesDelMedico::class)->name('medicos.sedes');
    Route::get('/medicos/{medico}/sedes/{sede}/consultorios', ConsultoriosDeLaSede::class)->name('medicos.sedes.consultorios');
    Route::get('/medicos/{medico}/sedes/{sede}/consultorios/{consultorio}/horarios', HorariosDelConsultorio::class)
        ->name('medicos.sedes.consultorios.horarios');
});

