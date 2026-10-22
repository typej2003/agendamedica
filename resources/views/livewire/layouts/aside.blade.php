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

            @include('livewire.layouts._aside-grupo', ['grupo' => [
                'texto' => 'Usuarios', 'icono' => 'bi-people-fill', 'clave' => 'usuarios',
                'rutas' => ['admin.cuentas*', 'admin.medicos.sedes*'],
                'hijos' => [
                    ['texto' => 'Médicos', 'icono' => 'bi-person-badge', 'url' => route('admin.cuentas'), 'rutas' => ['admin.cuentas', 'admin.cuentas.medico', 'admin.medicos.sedes*']],
                    ['texto' => 'Administradores', 'icono' => 'bi-shield-lock', 'url' => route('admin.cuentas.administradores'), 'rutas' => ['admin.cuentas.administradores']],
                    ['texto' => 'Pacientes', 'icono' => 'bi-person-heart', 'url' => route('admin.cuentas.pacientes'), 'rutas' => ['admin.cuentas.pacientes']],
                ],
            ]])
            <li class="nav-item">
                <a href="{{ route('admin.especialidades') }}" class="nav-link {{ request()->routeIs('admin.especialidades') ? 'active' : '' }}"
                   @if (request()->routeIs('admin.especialidades')) aria-current="page" @endif title="Especialidades">
                    <i class="bi bi-heart-pulse-fill" aria-hidden="true"></i>
                    <span class="link-text">Especialidades</span>
                </a>
            </li>
            @php
                $hijosCatalogo = [['texto' => 'Vacunas', 'icono' => 'bi-dot', 'url' => route('admin.catalogo-inicial.vacunas'), 'rutas' => ['admin.catalogo-inicial.vacunas']]];
                foreach (\App\Clinica\Catalogos\Iniciales\CatalogosIniciales::todos() as $slugCatalogo => $datosCatalogo) {
                    $hijosCatalogo[] = ['texto' => $datosCatalogo['titulo'], 'icono' => 'bi-dot', 'url' => route('admin.catalogo-inicial.catalogo', $slugCatalogo), 'slug' => $slugCatalogo];
                }
                $hijosCatalogo[] = ['texto' => 'Referencia de crecimiento', 'icono' => 'bi-dot', 'url' => route('admin.catalogo-inicial.percentiles'), 'rutas' => ['admin.catalogo-inicial.percentiles']];
            @endphp
            @include('livewire.layouts._aside-grupo', ['grupo' => [
                'texto' => 'Catálogo inicial', 'icono' => 'bi-capsule', 'clave' => 'catalogo-inicial',
                'rutas' => ['admin.catalogo-inicial.*'], 'hijos' => $hijosCatalogo,
            ]])
            <li class="nav-item">
                <a href="{{ route('admin.api-keys') }}" class="nav-link {{ request()->routeIs('admin.api-keys') ? 'active' : '' }}"
                   @if (request()->routeIs('admin.api-keys')) aria-current="page" @endif title="API Keys">
                    <i class="bi bi-key-fill" aria-hidden="true"></i>
                    <span class="link-text">API Keys</span>
                </a>
            </li>
            @include('livewire.layouts._aside-grupo', ['grupo' => [
                'texto' => 'Planes y servicios', 'icono' => 'bi-card-checklist', 'clave' => 'planes-y-servicios',
                'rutas' => ['admin.servicios*'],
                'hijos' => [
                    ['texto' => 'Servicios por médico', 'icono' => 'bi-people', 'url' => route('admin.servicios'), 'rutas' => ['admin.servicios']],
                    ['texto' => 'Planes', 'icono' => 'bi-card-checklist', 'url' => route('admin.servicios.planes'), 'rutas' => ['admin.servicios.planes']],
                ],
            ]])
            @include('livewire.layouts._aside-grupo', ['grupo' => [
                'texto' => 'Métodos de pago', 'icono' => 'bi-credit-card', 'clave' => 'metodos-de-pago',
                'rutas' => ['admin.metodos-pago*'],
                'hijos' => [
                    ['texto' => 'Métodos de pago', 'icono' => 'bi-credit-card', 'url' => route('admin.metodos-pago'), 'rutas' => ['admin.metodos-pago']],
                    // Hoy sólo existe VES; cuando haya más monedas, esta pantalla se parte en una pestaña por moneda.
                    ['texto' => 'Tasas de cambio', 'icono' => 'bi-currency-exchange', 'url' => route('admin.metodos-pago.tasas'), 'rutas' => ['admin.metodos-pago.tasas']],
                ],
            ]])
            <li class="nav-item">
                <a href="{{ route('admin.pagos') }}" class="nav-link {{ request()->routeIs('admin.pagos') ? 'active' : '' }}"
                   @if (request()->routeIs('admin.pagos')) aria-current="page" @endif
                   title="Pagos{{ $avisos['pagos'] ? ' (' . $avisos['pagos'] . ' sin confirmar)' : '' }}">
                    <i class="bi bi-cash-stack" aria-hidden="true"></i>
                    <span class="link-text">Pagos</span>
                    @include('livewire.layouts.aviso', ['total' => $avisos['pagos'], 'que' => 'sin confirmar'])
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('admin.solicitudes-medicos') }}" class="nav-link {{ request()->routeIs('admin.solicitudes-medicos') ? 'active' : '' }}"
                   @if (request()->routeIs('admin.solicitudes-medicos')) aria-current="page" @endif
                   title="Solicitudes de médicos{{ $avisos['solicitudes'] ? ' (' . $avisos['solicitudes'] . ' sin responder)' : '' }}">
                    <i class="bi bi-person-badge" aria-hidden="true"></i>
                    <span class="link-text">Solicitudes de médicos</span>
                    @include('livewire.layouts.aviso', ['total' => $avisos['solicitudes'], 'que' => 'sin responder'])
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('admin.comentarios') }}" class="nav-link {{ request()->routeIs('admin.comentarios') ? 'active' : '' }}"
                   @if (request()->routeIs('admin.comentarios')) aria-current="page" @endif
                   title="Ayúdanos a mejorar{{ $avisos['comentarios'] ? ' (' . $avisos['comentarios'] . ' sin responder)' : '' }}">
                    <i class="bi bi-chat-heart" aria-hidden="true"></i>
                    <span class="link-text">Ayúdanos a mejorar</span>
                    @include('livewire.layouts.aviso', ['total' => $avisos['comentarios'], 'que' => 'sin responder'])
                </a>
            </li>
        @endif
    </ul>
  </div>

  <div class="app-sidebar-pie">
      {{-- Cerrar sesión vive en el menú del usuario (barra superior); acá sólo la versión. --}}
      <span class="app-sidebar-version" title="Versión {{ config('app.version') }}">v{{ config('app.version') }}</span>

      <button class="app-sidebar-plegar" type="button" wire:click="toggleMinimize"
              title="{{ $isMinimized ? 'Desplegar el menú' : 'Plegar el menú' }}"
              aria-label="{{ $isMinimized ? 'Desplegar el menú' : 'Plegar el menú' }}">
          <i class="bi bi-layout-sidebar-inset" aria-hidden="true"></i>
      </button>
  </div>
</div>
