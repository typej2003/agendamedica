@php
    $usuario = Auth::user();
    $esAdmin = $usuario->esAdministrador();
    // Quién trabaja en el consultorio: su pantalla es `/clinica`, no el panel.
    $trabajaEnConsultorio = $usuario->hasAnyRole(['Medico', 'Secretaria']);
    // El panel (Escritorio) es de administración; el paciente sigue entrando ahí mientras no exista
    // `/consultorio` (decisión del 2026-10-08, ver ROADMAP).
    $veEscritorio = $esAdmin || $usuario->hasRole('Paciente');
@endphp

<div id="sidebarMenu" class="app-sidebar {{ $isMinimized ? 'minimizado' : '' }}">
  <a class="app-sidebar-marca" href="{{ route('dashboard') }}">
      <span class="app-marca-ficha" aria-hidden="true"><i class="bi bi-heart-pulse-fill"></i></span>
      <span class="app-marca-nombre"><strong>Doctorísimo</strong><small>Panel de administración</small></span>
  </a>

  <div class="app-sidebar-interior">
    <ul class="nav flex-column">
        @if ($veEscritorio)
            <li class="nav-item">
                <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}"
                   @if (request()->routeIs('dashboard')) aria-current="page" @endif title="Escritorio">
                    <i class="bi bi-speedometer2" aria-hidden="true"></i>
                    <span class="link-text">Escritorio</span>
                </a>
            </li>
        @endif

        @if ($trabajaEnConsultorio)
            <li class="nav-item">
                <a href="{{ route('clinica.inicio') }}" class="nav-link {{ request()->routeIs('clinica.*') ? 'active' : '' }}"
                   @if (request()->routeIs('clinica.*')) aria-current="page" @endif title="Consultorio">
                    <i class="bi bi-clipboard2-pulse" aria-hidden="true"></i>
                    <span class="link-text">Consultorio</span>
                </a>
            </li>
        @endif

        @if ($esAdmin)
            <li class="app-sidebar-titulo link-text">Administración</li>

            <li class="nav-item">
                <a href="{{ route('admin.cuentas') }}" class="nav-link {{ request()->routeIs('admin.cuentas') ? 'active' : '' }}"
                   @if (request()->routeIs('admin.cuentas')) aria-current="page" @endif title="Usuarios">
                    <i class="bi bi-people-fill" aria-hidden="true"></i>
                    <span class="link-text">Usuarios</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('admin.especialidades') }}" class="nav-link {{ request()->routeIs('admin.especialidades') ? 'active' : '' }}"
                   @if (request()->routeIs('admin.especialidades')) aria-current="page" @endif title="Especialidades">
                    <i class="bi bi-heart-pulse-fill" aria-hidden="true"></i>
                    <span class="link-text">Especialidades</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('admin.catalogo-inicial.vacunas') }}" class="nav-link {{ request()->routeIs('admin.catalogo-inicial.*') ? 'active' : '' }}"
                   @if (request()->routeIs('admin.catalogo-inicial.*')) aria-current="page" @endif title="Catálogo inicial · Vacunas">
                    <i class="bi bi-capsule" aria-hidden="true"></i>
                    <span class="link-text">Catálogo inicial</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('admin.api-keys') }}" class="nav-link {{ request()->routeIs('admin.api-keys') ? 'active' : '' }}"
                   @if (request()->routeIs('admin.api-keys')) aria-current="page" @endif title="API Keys">
                    <i class="bi bi-key-fill" aria-hidden="true"></i>
                    <span class="link-text">API Keys</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('admin.servicios') }}" class="nav-link {{ request()->routeIs('admin.servicios') ? 'active' : '' }}"
                   @if (request()->routeIs('admin.servicios')) aria-current="page" @endif title="Planes y servicios">
                    <i class="bi bi-card-checklist" aria-hidden="true"></i>
                    <span class="link-text">Planes y servicios</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('admin.comentarios') }}" class="nav-link {{ request()->routeIs('admin.comentarios') ? 'active' : '' }}"
                   @if (request()->routeIs('admin.comentarios')) aria-current="page" @endif title="Ayúdanos a mejorar">
                    <i class="bi bi-chat-heart" aria-hidden="true"></i>
                    <span class="link-text">Ayúdanos a mejorar</span>
                </a>
            </li>
        @endif
    </ul>
  </div>

  <div class="app-sidebar-pie">
      <a href="{{ route('logout') }}" class="nav-link" title="Cerrar sesión"
         onclick="return confirmarCierreSesion(event, document.getElementById('logout-form'));">
          <i class="bi bi-box-arrow-left" aria-hidden="true"></i>
          <span class="link-text">Cerrar sesión</span>
      </a>

      {{-- Formulario oculto necesario para procesar la petición POST con CSRF --}}
      <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
          @csrf
      </form>

      <button class="app-sidebar-plegar" type="button" wire:click="toggleMinimize"
              title="{{ $isMinimized ? 'Desplegar el menú' : 'Plegar el menú' }}"
              aria-label="{{ $isMinimized ? 'Desplegar el menú' : 'Plegar el menú' }}">
          <i class="bi bi-layout-sidebar-inset" aria-hidden="true"></i>
      </button>
  </div>
</div>
