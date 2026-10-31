<?php

use App\Http\Controllers\Admin\CiudadController;
use App\Http\Controllers\Clinica\CiudadBusquedaController;
use App\Http\Controllers\Comentarios\ImagenDelComentarioController;
use App\Http\Livewire\Admin\ApiKeys;
use App\Http\Livewire\Admin\CatalogoInicialPercentiles;
use App\Http\Livewire\Admin\CatalogosIniciales;
use App\Http\Livewire\Admin\CatalogoInicialVacunas;
use App\Http\Livewire\Admin\Comentarios;
use App\Http\Livewire\Admin\ReportesEscritorio;
use App\Http\Livewire\Admin\Cuentas;
use App\Http\Livewire\Admin\DetalleDelMedico;
use App\Http\Livewire\Admin\Especialidades;
use App\Http\Livewire\Admin\Latidos;
use App\Http\Livewire\Admin\MetodosPago;
use App\Http\Livewire\Admin\Pagos;
use App\Http\Livewire\Admin\Referidos;
use App\Http\Livewire\Admin\Servicios;
use App\Http\Livewire\Admin\SolicitudesDeMedicos;
use App\Http\Livewire\Admin\UsuariosPacientes;
use App\Http\Controllers\RegistroDeMedicos\CarnetDeLaSolicitudController;
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
    // **Usuarios** (menú con submenús): Médicos y Administradores son el mismo componente con su URL propia.
    Route::get('/cuentas', Cuentas::class)->name('cuentas');
    Route::get('/cuentas/administradores', Cuentas::class)->name('cuentas.administradores');
    // Cuentas de pacientes (las que se crean solas): lista de lectura y bloqueo de emergencia.
    // El detalle (sólo lectura, con pestañas) de un médico; «Editar» vuelve a Médicos con su diálogo abierto.
    Route::get('/cuentas/medicos/{medico}', DetalleDelMedico::class)->whereNumber('medico')->name('cuentas.medico');
    Route::get('/cuentas/pacientes', UsuariosPacientes::class)->name('cuentas.pacientes');
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
    // **Reportes de fallo del escritorio**: lo que mandan el launcher, el instalador y el puente cuando
    // algo se rompe en un consultorio. Se borran cuando el problema ya está resuelto.
    Route::get('/reportes-escritorio', ReportesEscritorio::class)->name('reportes-escritorio');
    // **La captura adjunta** (O-25): la bandeja la muestra y la abre. El archivo vive en un disco privado y
    // esta ruta es el único camino a él, con el permiso comprobado en el controlador.
    Route::get('/comentarios/{comentario}/imagenes/{imagen}', [ImagenDelComentarioController::class, 'deLaBandeja'])
        ->name('comentarios.imagen');
    // **Solicitudes de médicos** (ROADMAP §10.4, fase 2): las que llegan de `/registro/medico`, para aprobar o
    // rechazar. La foto del carnet vive en un disco privado y esta ruta es el único camino a ella.
    Route::get('/solicitudes-medicos', SolicitudesDeMedicos::class)->name('solicitudes-medicos');
    Route::get('/solicitudes-medicos/{solicitud}/carnet', CarnetDeLaSolicitudController::class)
        ->whereNumber('solicitud')->name('solicitudes-medicos.carnet');
    Route::get('/servicios', Servicios::class)->name('servicios');
    Route::get('/servicios/planes', Servicios::class)->name('servicios.planes');
    // **Métodos de pago de la plataforma** (2026-10-21): con qué cobra Doctorisimo los planes y, a
    // futuro, los cobros de los pacientes. Incluye la tasa VES/USD manual.
    Route::get('/metodos-pago', MetodosPago::class)->name('metodos-pago');
    Route::get('/metodos-pago/tasas', MetodosPago::class)->name('metodos-pago.tasas');
    // **Pagos** (2026-10-21): lo que entró por SyPago, con su bitácora y la conciliación manual.
    Route::get('/pagos', Pagos::class)->name('pagos');
    // **Latidos** (2026-10-31): el programa de descuentos por consultorio. El ajuste manual es sólo Root.
    Route::get('/latidos', Latidos::class)->name('latidos');
    // **Referidos** (2026-10-31): configuración del premio y anulación con reverso.
    Route::get('/referidos', Referidos::class)->name('referidos');
    // **Sedes del médico** (2026-10-20): el administrador ayuda a configurar dónde atiende cada médico, en
    // profundidad: sedes → consultorios de la sede → horarios y cupos del consultorio. Las reglas son las Actions
    // de `/clinica` (`GuardarSede`, `GuardarConsultorio`, `GuardarBloqueHorario`); el tenant, el `reg_medico` del médico.
    Route::get('/medicos/{medico}/sedes', SedesDelMedico::class)->name('medicos.sedes');
    // Selector con busqueda de ciudades (`<x-ui.selector-buscable>`): buscar y crear la que falte (con su estado).
    Route::get('/ciudades/buscar', [CiudadBusquedaController::class, 'buscar'])->name('ciudades.buscar');
    Route::post('/ciudades', [CiudadController::class, 'crear'])->name('ciudades.crear');
    Route::get('/medicos/{medico}/sedes/{sede}/consultorios', ConsultoriosDeLaSede::class)->name('medicos.sedes.consultorios');
    Route::get('/medicos/{medico}/sedes/{sede}/consultorios/{consultorio}/horarios', HorariosDelConsultorio::class)
        ->name('medicos.sedes.consultorios.horarios');
});

