<div class="d-flex flex-column flex-shrink-0 h-100 {{ $isMinimized ? 'minimized' : '' }}">
    <div class="sidebar-header d-flex align-items-center justify-content-between p-3">
        @if(!$isMinimized)
        <div class="user-info d-flex align-items-center">
            <img src="https://ui-avatars.com/api/?name={{ urlencode(Auth::user()->name) }}&background=6500da&color=fff&rounded=true" alt="User" class="me-2" width="40">
            <div class="d-flex flex-column">
                <span class="fw-bold fs-6">{{ Auth::user()->name }}</span>
                <small class="text-muted">{{ Auth::user()->getRoleNames()->first() }}</small>
            </div>
        </div>
        @endif
        <button class="btn btn-link text-purple d-none d-lg-block" wire:click="toggleMinimize">
            <i class="bi {{ $isMinimized ? 'bi-arrow-right-square' : 'bi-arrow-left-square' }} fs-4"></i>
        </button>
    </div>
    <hr class="m-0">

    <ul class="nav nav-pills flex-column mb-auto p-2 sidebar-nav">
        <li class="nav-item">
            <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <i class="bi bi-speedometer2"></i>
                <span class="link-text">Dashboard</span>
            </a>
        </li>

        <li class="nav-header">ADMINISTRACIÓN</li>

        @if(auth()->user()->can('ver-usuarios') || auth()->user()->hasRole('Root'))
        <li class="nav-item">
            <a href="{{ route('users.index') }}" class="nav-link {{ request()->routeIs('users.index') ? 'active' : '' }}">
                <i class="bi bi-people-fill"></i>
                <span class="link-text">Usuarios</span>
            </a>
        </li>
        @endif

        @if(auth()->user()->hasRole(['Root', 'Administrador']))
        <li class="nav-item">
            <a href="{{ route('users.permissions') }}" class="nav-link {{ request()->routeIs('users.permissions') ? 'active' : '' }}">
                <i class="bi bi-shield-lock-fill"></i>
                <span class="link-text">Permisos de Usuario</span>
            </a>
        </li>
        @endif

        <li class="nav-item">
            <a href="#" class="nav-link">
                <i class="bi bi-person-rolodex"></i>
                <span class="link-text">Clientes</span>
            </a>
        </li>

        <li class="nav-header">INVENTARIO</li>

        <li class="nav-item">
            <a href="#" class="nav-link">
                <i class="bi bi-box-seam"></i>
                <span class="link-text">Productos</span>
            </a>
        </li>
        <li class="nav-item">
            <a href="#" class="nav-link">
                <i class="bi bi-tags"></i>
                <span class="link-text">Categorías</span>
            </a>
        </li>

        <li class="nav-item mt-auto">
            <a href="{{ route('logout') }}" class="nav-link"
               onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                <i class="bi bi-box-arrow-left"></i>
                <span class="link-text">Cerrar Sesión</span>
            </a>
        </li>

    </ul>
    <hr class="m-0">
    <div class="sidebar-footer p-3 text-center">
        <small class="text-muted">© {{ date('Y') }} WifiExprés</small>
    </div>
</div>