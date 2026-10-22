{{-- Un ítem del menú del panel con submenú desplegable (`<details>`, accesible con teclado: Enter/Espacio abren y
     cierran el `<summary>`). Nace abierto cuando la ruta activa es de uno de sus hijos. `$grupo`: texto, icono, clave,
     rutas (las del grupo entero) y hijos (texto, icono, url y rutas, o `slug` para los catálogos iniciales). --}}
@php
    $abierto = request()->routeIs(...$grupo['rutas']);
    $hijos = collect($grupo['hijos'])->map(function ($hijo) {
        $hijo['activo'] = isset($hijo['slug'])
            ? request()->routeIs('admin.catalogo-inicial.catalogo') && request()->route('catalogo') === $hijo['slug']
            : request()->routeIs(...$hijo['rutas']);

        return $hijo;
    });
@endphp
<li class="nav-item">
    <details class="nav-grupo" data-menu="{{ $grupo['clave'] }}" {{ $abierto ? 'open' : '' }}>
        <summary class="nav-link {{ $abierto ? 'activo-padre' : '' }}" title="{{ $grupo['texto'] }}"
                 aria-expanded="{{ $abierto ? 'true' : 'false' }}">
            <i class="bi {{ $grupo['icono'] }}" aria-hidden="true"></i>
            <span class="link-text">{{ $grupo['texto'] }}</span>
            <i class="bi bi-chevron-down nav-grupo-flecha link-text" aria-hidden="true"></i>
        </summary>
        <ul class="nav flex-column nav-submenu">
            @foreach ($hijos as $hijo)
                <li class="nav-item">
                    <a class="nav-link {{ $hijo['activo'] ? 'active' : '' }}" href="{{ $hijo['url'] }}"
                       @if ($hijo['activo']) aria-current="page" @endif title="{{ $grupo['texto'] }} · {{ $hijo['texto'] }}">
                        <i class="bi {{ $hijo['icono'] }}" aria-hidden="true"></i>
                        <span class="link-text">{{ $hijo['texto'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </details>
</li>
