@php
    $usuario = Auth::user();
    $enPanel = auth()->check();
@endphp

<nav class="navbar navbar-expand-lg app-topbar fixed-top py-2">
    <div class="container-fluid px-3">
        <div class="d-flex align-items-center gap-2">
            @auth
                {{-- Sólo en pantallas chicas: abre el menú lateral del panel. --}}
                <button class="btn btn-sm btn-light d-lg-none" type="button" data-toggle-sidebar
                        onclick="window.dispatchEvent(new CustomEvent('toggleSidebar'))"
                        aria-label="Abrir menú" aria-expanded="false" aria-controls="sidebarMenu">
                    <i class="bi bi-list fs-5"></i>
                </button>
            @endauth

            <a class="app-marca navbar-brand fs-4 m-0" href="{{ $enPanel ? url('/dashboard') : url('/') }}">
                <i class="bi bi-heart-pulse-fill app-marca-icono me-2"></i>Doctorísimo<span class="app-marca-acento">.App</span>
            </a>
        </div>

        @guest
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent"
                    aria-controls="navbarContent" aria-expanded="false" aria-label="Abrir navegación">
                <span class="navbar-toggler-icon"></span>
            </button>
        @endguest

        <div class="collapse navbar-collapse" id="navbarContent">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center">
                @guest
                    {{-- Secciones del sitio público: no tienen nada que hacer dentro del panel. --}}
                    <li class="nav-item"><a class="nav-link px-3 py-2 py-lg-1" href="{{ url('/') }}#inicio">Inicio</a></li>
                    <li class="nav-item"><a class="nav-link px-3 py-2 py-lg-1" href="{{ url('/') }}#especialidades">Especialidades</a></li>
                    <li class="nav-item"><a class="nav-link px-3 py-2 py-lg-1" href="{{ url('/') }}#por-que">Por qué elegirnos</a></li>
                    <li class="nav-item"><a class="nav-link px-3 py-2 py-lg-1" href="{{ url('/') }}#servicios">Servicios</a></li>
                    <li class="nav-item">
                        <a class="nav-link px-3 py-2 py-lg-1 fw-semibold" href="{{ route('reservas.catalogo') }}">
                            <i class="bi bi-calendar2-check me-1" aria-hidden="true"></i>Reservar cita
                        </a>
                    </li>
                @endguest

                @auth
                    @if ($usuario->hasRole('Paciente'))
                        <li class="nav-item">
                            <a class="nav-link px-3 py-2 py-lg-1 fw-semibold" href="{{ route('paciente.citas') }}">
                                <i class="bi bi-calendar2-check me-1" aria-hidden="true"></i>Mis citas
                            </a>
                        </li>
                    @endif

                    {{-- El tablero de Laravel Pulse (`/pulse`): sólo lo ve quien puede entrar (Root). --}}
                    @can('viewPulse')
                        <li class="nav-item me-lg-2 mt-3 mt-lg-0">
                            <a class="btn btn-outline-primary d-inline-flex align-items-center justify-content-center"
                               href="{{ route('pulse') }}" title="Pulse: rendimiento de la aplicación"
                               aria-label="Abrir el tablero de Pulse">
                                <i class="bi bi-activity fs-5" aria-hidden="true"></i>
                            </a>
                        </li>
                    @endcan
                @endauth

                <li class="nav-item ms-lg-2 mt-3 mt-lg-0">
                    @if (Route::has('login'))
                        @auth
                            <div class="dropdown" wire:ignore.self>
                                <a href="#" class="btn btn-outline-primary dropdown-toggle d-flex align-items-center justify-content-between justify-content-lg-start gap-2 w-100 w-lg-auto"
                                   role="button" data-bs-toggle="dropdown" data-bs-auto-close="true" aria-expanded="false">
                                    <span class="d-flex align-items-center gap-2">
                                        <i class="bi bi-person-circle fs-5"></i>
                                        <span>{{ $usuario->name }}</span>
                                    </span>
                                </a>
                                <ul class="dropdown-menu dropdown-menu-end w-100 w-lg-auto">
                                    <li>
                                        <div class="dropdown-header">
                                            <span class="d-block fw-semibold text-body menu-usuario-correo" title="{{ $usuario->email }}">{{ $usuario->email }}</span>
                                            <span class="d-block">{{ $usuario->getRoleNames()->first() ?? 'Sin rol' }}</span>
                                        </div>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    @if ($usuario->esAdministrador() || $usuario->hasRole('Paciente'))
                                        <li>
                                            <a class="dropdown-item py-2" href="{{ route('dashboard') }}">
                                                <i class="bi bi-speedometer2 me-2"></i>Escritorio
                                            </a>
                                        </li>
                                    @endif

                                    @if ($usuario->hasAnyRole(['Medico', 'Secretaria']))
                                        <li>
                                            <a class="dropdown-item py-2" href="{{ route('clinica.inicio') }}">
                                                <i class="bi bi-clipboard2-pulse me-2"></i>Consultorio
                                            </a>
                                        </li>
                                    @endif

                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <form method="POST" action="{{ route('logout') }}" onsubmit="return confirmarCierreSesion(event, this);">
                                            @csrf
                                            <button type="submit" class="dropdown-item text-danger w-100 text-start py-2">
                                                <i class="bi bi-box-arrow-right me-2"></i>Cerrar sesión
                                            </button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        @else
                            <div class="d-flex flex-column flex-lg-row align-items-stretch align-items-lg-center gap-2">
                                <a href="{{ route('login') }}" class="btn btn-outline-secondary w-100 w-lg-auto">Ingresar</a>
                                @if (Route::has('register'))
                                    <a href="{{ route('register') }}" class="btn btn-primary w-100 w-lg-auto">Registrarse</a>
                                @endif
                            </div>
                        @endauth
                    @endif
                </li>
            </ul>
        </div>
    </div>
</nav>
