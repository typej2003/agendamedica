<nav class="navbar navbar-expand-lg navbar-gineco fixed-top py-3 shadow-sm bg-white">
    <div class="container">
        <div class="d-flex align-items-center">
            @auth
                <!-- Botón exclusivo para alternar el Sidebar en móviles -->
                <button class="btn btn-link text-dark p-0 me-3 d-lg-none" type="button" onclick="window.dispatchEvent(new CustomEvent('toggleSidebar'))" aria-label="Toggle Sidebar">
                    <i class="bi bi-list fs-2"></i>
                </button>
            @endauth

            <a class="navbar-brand fs-3 fw-bold m-0" href="{{ url('/') }}">
                <i class="bi bi-heart-pulse-fill me-2" style="color: #c28fd6;"></i>Agenda<span style="color: #6500da;">Médica</span>
            </a>
        </div>

        @guest
            <!-- El botón hamburguesa del Navbar solo se muestra a visitantes no autenticados en móvil -->
            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
        @endguest

        <div class="collapse navbar-collapse" id="navbarContent">
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center">
                <li class="nav-item">
                    <a class="nav-link px-3 py-2 py-lg-1" href="{{ url('/') }}#inicio">Inicio</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link px-3 py-2 py-lg-1" href="{{ url('/') }}#especialidades">Especialidades</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link px-3 py-2 py-lg-1" href="{{ url('/') }}#servicios">Servicios</a>
                </li>

                <li class="nav-item ms-lg-2 mt-3 mt-lg-0">
                    @if (Route::has('login'))
                        @auth
                            <!-- En móviles, el acceso de usuario se mantiene visible en el Navbar sin necesidad de colapsar -->
                            <div class="dropdown" wire:ignore.self>
                                <a href="#" class="btn btn-outline-primary dropdown-toggle d-flex align-items-center justify-content-between justify-content-lg-start gap-2 w-100 w-lg-auto" role="button" data-bs-toggle="dropdown" data-bs-auto-close="true" aria-expanded="false">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-person-circle fs-5"></i>
                                        <span>{{ Auth::user()->name }}</span>
                                    </div>
                                </a>
                                <ul class="dropdown-menu dropdown-menu-end custom-dropdown w-100 w-lg-auto">
                                    <li>
                                        <a class="dropdown-item py-2" href="{{ url('/dashboard') }}">
                                            <i class="bi bi-speedometer2 me-2"></i>Escritorio
                                        </a>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <form method="POST" action="{{ route('logout') }}">
                                            @csrf
                                            <button type="submit" class="dropdown-item text-danger w-100 text-start py-2">
                                                <i class="bi bi-box-arrow-right me-2"></i>Cerrar Sesión
                                            </button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        @else
                            <div class="d-flex flex-column flex-lg-row align-items-stretch align-items-lg-center gap-2">
                                <a href="{{ route('login') }}" class="btn btn-outline-secondary w-100 w-lg-auto">Ingresar</a>
                                @if (Route::has('register'))
                                    <a href="{{ route('register') }}" class="btn btn-primary w-100 w-lg-auto" style="background-color: var(--purple-wifiexpres); border-color: var(--purple-wifiexpres);">Registrarse</a>
                                @endif
                            </div>
                        @endauth
                    @endif
                </li>
            </ul>
        </div>
    </div>
</nav>