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
      <span class="app-marca-ficha" aria-hidden="true"><x-marca-corazon /></span>
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

        {{-- Ocho áreas y **dos niveles**: grupo → enlace. «Facturación y pagos» junta lo que antes eran los grupos
             «Planes y servicios» y «Métodos de pago» más «Pagos»; «Crecimiento y fidelización» junta «Referidos» y
             «Latidos». Un grupo no se anida dentro de otro (decisión del 2026-11-12, tarjeta #111). --}}
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
                <a href="{{ route('admin.solicitudes-medicos') }}" class="nav-link {{ request()->routeIs('admin.solicitudes-medicos') ? 'active' : '' }}"
                   @if (request()->routeIs('admin.solicitudes-medicos')) aria-current="page" @endif
                   title="Solicitudes de médicos{{ $avisos['solicitudes'] ? ' (' . $avisos['solicitudes'] . ' sin responder)' : '' }}">
                    <i class="bi bi-person-badge" aria-hidden="true"></i>
                    <span class="link-text">Solicitudes de médicos</span>
                    @include('livewire.layouts.aviso', ['total' => $avisos['solicitudes'], 'que' => 'sin responder'])
                </a>
            </li>
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
            @include('livewire.layouts._aside-grupo', ['grupo' => [
                'texto' => 'Facturación y pagos', 'icono' => 'bi-receipt', 'clave' => 'facturacion-y-pagos',
                'rutas' => ['admin.servicios*', 'admin.metodos-pago*', 'admin.pagos'],
                // El número de los pagos sin confirmar también en el grupo cerrado (el hijo lo repite).
                'aviso' => ['total' => $avisos['pagos'], 'que' => 'sin confirmar'],
                'hijos' => [
                    ['texto' => 'Servicios por médico', 'icono' => 'bi-people', 'url' => route('admin.servicios'), 'rutas' => ['admin.servicios']],
                    ['texto' => 'Planes', 'icono' => 'bi-card-checklist', 'url' => route('admin.servicios.planes'), 'rutas' => ['admin.servicios.planes']],
                    ['texto' => 'Métodos de pago', 'icono' => 'bi-credit-card', 'url' => route('admin.metodos-pago'), 'rutas' => ['admin.metodos-pago']],
                    // Hoy sólo existe VES; cuando haya más monedas, esta pantalla se parte en una pestaña por moneda.
                    ['texto' => 'Tasas de cambio', 'icono' => 'bi-currency-exchange', 'url' => route('admin.metodos-pago.tasas'), 'rutas' => ['admin.metodos-pago.tasas']],
                    ['texto' => 'Pagos', 'icono' => 'bi-cash-stack', 'url' => route('admin.pagos'), 'rutas' => ['admin.pagos'], 'aviso' => ['total' => $avisos['pagos'], 'que' => 'sin confirmar']],
                ],
            ]])
            @include('livewire.layouts._aside-grupo', ['grupo' => [
                'texto' => 'Crecimiento y fidelización', 'icono' => 'bi-graph-up-arrow', 'clave' => 'crecimiento-y-fidelizacion',
                'rutas' => ['admin.referidos', 'admin.latidos'],
                'hijos' => [
                    ['texto' => 'Referidos', 'icono' => 'bi-gift', 'url' => route('admin.referidos'), 'rutas' => ['admin.referidos']],
                    ['texto' => 'Latidos', 'icono' => 'bi-stars', 'url' => route('admin.latidos'), 'rutas' => ['admin.latidos']],
                ],
            ]])
            <li class="nav-item">
                <a href="{{ route('admin.api-keys') }}" class="nav-link {{ request()->routeIs('admin.api-keys') ? 'active' : '' }}"
                   @if (request()->routeIs('admin.api-keys')) aria-current="page" @endif title="API Keys">
                    <i class="bi bi-key-fill" aria-hidden="true"></i>
                    <span class="link-text">API Keys</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="{{ route('admin.comentarios') }}" class="nav-link {{ request()->routeIs('admin.comentarios') ? 'active' : '' }}"
                   @if (request()->routeIs('admin.comentarios')) aria-current="page" @endif
                   title="Soporte y sugerencias{{ $avisos['comentarios'] ? ' (' . $avisos['comentarios'] . ' sin responder)' : '' }}">
                    <i class="bi bi-chat-heart" aria-hidden="true"></i>
                    <span class="link-text">Soporte y sugerencias</span>
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
