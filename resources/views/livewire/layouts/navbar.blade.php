<nav class="navbar navbar-expand-lg navbar-gineco fixed-top py-3 shadow-sm bg-white">
    <div class="container">
        <a class="navbar-brand fs-3 fw-bold" href="{{ url('/') }}">
            <i class="bi bi-heart-pulse-fill me-2" style="color: #c28fd6;"></i>Agenda<span style="color: #6500da;">Médica</span>
        </a>

        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarContent">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center">
                <li class="nav-item">
                    <a class="nav-link px-3" href="{{ url('/') }}#inicio">Inicio</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link px-3" href="{{ url('/') }}#busqueda">Especialistas</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link px-3" href="{{ url('/') }}#servicios">Servicios</a>
                </li>

                <li class="nav-item ms-lg-2">
                    @if (Route::has('login'))
                        @auth
                            <!-- wire:ignore.self evita que Livewire destruya la instancia JS del dropdown al actualizar -->
                            <div class="dropdown" wire:ignore.self>
                                <a href="#" class="btn btn-outline-primary dropdown-toggle d-flex align-items-center gap-2" role="button" data-bs-toggle="dropdown" data-bs-auto-close="true" aria-expanded="false">
                                    <i class="bi bi-person-circle fs-5"></i>
                                    <span>{{ Auth::user()->name }}</span>
                                </a>
                                <ul class="dropdown-menu dropdown-menu-end custom-dropdown">
                                    <li>
                                        <a class="dropdown-item" href="{{ url('/dashboard') }}">
                                            <i class="bi bi-speedometer2 me-2"></i>Mi Panel
                                        </a>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <form method="POST" action="{{ route('logout') }}">
                                            @csrf
                                            <button type="submit" class="dropdown-item text-danger w-100 text-start">
                                                <i class="bi bi-box-arrow-right me-2"></i>Cerrar Sesión
                                            </button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        @else
                            <div class="d-flex align-items-center gap-2">
                                <a href="{{ route('login') }}" class="btn btn-outline-secondary me-2">Ingresar</a>
                                @if (Route::has('register'))
                                    <a href="{{ route('register') }}" class="btn btn-primary" style="background-color: var(--purple-wifiexpres); border-color: var(--purple-wifiexpres);">Registrarse</a>
                                @endif
                            </div>
                        @endauth
                    @endif
                </li>
            </ul>
        </div>
    </div>
</nav>