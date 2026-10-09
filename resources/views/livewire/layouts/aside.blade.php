@php
    $usuario = Auth::user();
    $esAdmin = $usuario->esAdministrador();
    // Quién trabaja en el consultorio: su pantalla es `/clinica`, no el panel.
    $trabajaEnConsultorio = $usuario->hasAnyRole(['Medico', 'Secretaria']);
    // El panel (Escritorio) es de administración; el paciente sigue entrando ahí mientras no exista
    // `/consultorio` (decisión del 2026-10-08, ver ROADMAP).
    $veEscritorio = $esAdmin || $usuario->hasRole('Paciente');
    $iniciales = \Illuminate\Support\Str::of($usuario->name)->explode(' ')->filter()->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('');
@endphp

<div id="sidebarMenu" class="app-sidebar {{ $isMinimized ? 'minimizado' : '' }}">
  <div class="app-sidebar-interior py-2 px-2">
    {{-- Identidad de la sesión --}}
    <div class="d-flex align-items-center justify-content-between gap-2 px-2 py-2">
        <div class="d-flex align-items-center gap-2 overflow-hidden">
            <span class="stat-icono" style="background: var(--ds-violeta-100); color: var(--ds-violeta-700); width: 38px; height: 38px; font-weight: 700;">
                {{ $iniciales ?: '·' }}
            </span>
            <div class="d-flex flex-column overflow-hidden link-text">
                <span class="fw-semibold text-truncate" style="color: var(--ds-tinta);">{{ $usuario->name }}</span>
                <small class="text-muted text-truncate">{{ $usuario->getRoleNames()->first() ?? 'Sin rol' }}</small>
            </div>
        </div>

        <button class="btn btn-sm btn-link p-1 d-none d-lg-inline-flex" type="button"
                wire:click="toggleMinimize" title="{{ $isMinimized ? 'Expandir el menú' : 'Plegar el menú' }}"
                aria-label="{{ $isMinimized ? 'Expandir el menú' : 'Plegar el menú' }}">
            <i class="bi {{ $isMinimized ? 'bi-chevron-double-right' : 'bi-chevron-double-left' }} fs-5"></i>
        </button>
    </div>

    <ul class="nav flex-column">
        @if ($veEscritorio)
            <li class="nav-item">
                <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                    <i class="bi bi-speedometer2"></i>
                    <span class="link-text">Escritorio</span>
                </a>
            </li>
        @endif

        @if ($trabajaEnConsultorio)
            <li class="nav-item">
                <a href="{{ route('clinica.inicio') }}" class="nav-link {{ request()->routeIs('clinica.*') ? 'active' : '' }}">
                    <i class="bi bi-clipboard2-pulse"></i>
                    <span class="link-text">Consultorio</span>
                </a>
            </li>
        @endif

        @if ($usuario->hasRole('Root'))
            <li class="app-sidebar-titulo link-text">Agenda médica</li>

            <li class="nav-item">
                <a href="{{ route('admin.medicos') }}" class="nav-link {{ request()->routeIs('admin.medicos') ? 'active' : '' }}">
                    <i class="bi bi-person-badge"></i>
                    <span class="link-text">Médicos</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="{{ route('admin.pacientes') }}" class="nav-link {{ request()->routeIs('admin.pacientes') ? 'active' : '' }}">
                    <i class="bi bi-people"></i>
                    <span class="link-text">Pacientes</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="{{ route('admin.centros-medicos') }}" class="nav-link {{ request()->routeIs('admin.centros-medicos') ? 'active' : '' }}">
                    <i class="bi bi-hospital"></i>
                    <span class="link-text">Centros médicos</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="{{ route('admin.medico-centro-medico') }}" class="nav-link {{ request()->routeIs('admin.medico-centro-medico') ? 'active' : '' }}">
                    <i class="bi bi-link-45deg"></i>
                    <span class="link-text">Médico por centro</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="{{ route('admin.historias') }}" class="nav-link {{ request()->routeIs('admin.historias') ? 'active' : '' }}">
                    <i class="bi bi-journal-medical"></i>
                    <span class="link-text">Historias médicas</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="{{ route('admin.cargar-sql') }}" class="nav-link {{ request()->routeIs('admin.cargar-sql') ? 'active' : '' }}">
                    <i class="bi bi-filetype-sql"></i>
                    <span class="link-text">Cargar SQL</span>
                </a>
            </li>
        @endif

        @if ($esAdmin)
            <li class="app-sidebar-titulo link-text">Administración</li>

            <li class="nav-item">
                <a href="{{ route('admin.cuentas') }}" class="nav-link {{ request()->routeIs('admin.cuentas') ? 'active' : '' }}">
                    <i class="bi bi-people-fill"></i>
                    <span class="link-text">Usuarios</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('admin.api-keys') }}" class="nav-link {{ request()->routeIs('admin.api-keys') ? 'active' : '' }}">
                    <i class="bi bi-key-fill"></i>
                    <span class="link-text">API Keys</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('admin.servicios') }}" class="nav-link {{ request()->routeIs('admin.servicios') ? 'active' : '' }}">
                    <i class="bi bi-card-checklist"></i>
                    <span class="link-text">Planes y servicios</span>
                </a>
            </li>
        @endif
    </ul>

    <div class="app-sidebar-pie mt-2">
        <a href="{{ route('logout') }}" class="nav-link"
           onclick="return confirmarCierreSesion(event, document.getElementById('logout-form'));">
            <i class="bi bi-box-arrow-left"></i>
            <span class="link-text">Cerrar sesión</span>
        </a>

        {{-- Formulario oculto necesario para procesar la petición POST con CSRF --}}
        <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
            @csrf
        </form>

        <div class="text-center mt-1 link-text">
            <small class="text-muted">© {{ date('Y') }} Doctorísimo</small>
        </div>
    </div>
  </div>
</div>
