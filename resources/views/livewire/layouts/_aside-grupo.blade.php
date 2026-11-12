{{-- Un ítem del menú del panel con submenú desplegable (`<details>`, accesible con teclado: Enter/Espacio abren y
     cierran el `<summary>`). Nace abierto cuando la ruta activa es de uno de sus hijos. `$grupo`: texto, icono, clave,
     rutas (las del grupo entero), `aviso` opcional (`total` y `que`) y hijos (texto, icono, url y rutas —o `slug` para
     los catálogos iniciales—, más `aviso` opcional).
     El menú tiene **dos niveles**: un grupo cuelga de la lista y sus hijos son enlaces; acá no se anidan grupos. --}}
@php
    /**
     * El número de pendientes de una bandeja para el `title` (`Pagos (2 sin confirmar)`), que es lo que se lee con la
     * barra plegada. Devuelve '' cuando no hay nada que contar.
     */
    $cuenta = function (?array $aviso) {
        return $aviso && $aviso['total'] > 0 ? ' (' . $aviso['total'] . ' ' . $aviso['que'] . ')' : '';
    };

    $abierto = request()->routeIs(...$grupo['rutas']);
    $avisoDelGrupo = $grupo['aviso'] ?? null;
    $hijos = collect($grupo['hijos'])->map(function ($hijo) use ($cuenta, $grupo) {
        $hijo['activo'] = isset($hijo['slug'])
            ? request()->routeIs('admin.catalogo-inicial.catalogo') && request()->route('catalogo') === $hijo['slug']
            : request()->routeIs(...$hijo['rutas']);
        $hijo['aviso'] = $hijo['aviso'] ?? null;
        $hijo['titulo'] = $grupo['texto'] . ' · ' . $hijo['texto'] . $cuenta($hijo['aviso']);

        return $hijo;
    });
@endphp
<li class="nav-item">
    <details class="nav-grupo" data-menu="{{ $grupo['clave'] }}" {{ $abierto ? 'open' : '' }}>
        <summary class="nav-link {{ $abierto ? 'activo-padre' : '' }}" title="{{ $grupo['texto'] . $cuenta($avisoDelGrupo) }}"
                 aria-expanded="{{ $abierto ? 'true' : 'false' }}">
            <i class="bi {{ $grupo['icono'] }}" aria-hidden="true"></i>
            <span class="link-text">{{ $grupo['texto'] }}</span>
            {{-- El número de sus hijos, para que se vea con el grupo cerrado; abierto se esconde (CSS) porque
                 entonces está el del propio hijo. --}}
            @if ($avisoDelGrupo)
                @include('livewire.layouts.aviso', $avisoDelGrupo)
            @endif
            <i class="bi bi-chevron-down nav-grupo-flecha link-text" aria-hidden="true"></i>
        </summary>
        <ul class="nav flex-column nav-submenu">
            @foreach ($hijos as $hijo)
                <li class="nav-item">
                    <a class="nav-link {{ $hijo['activo'] ? 'active' : '' }}" href="{{ $hijo['url'] }}"
                       @if ($hijo['activo']) aria-current="page" @endif title="{{ $hijo['titulo'] }}">
                        <i class="bi {{ $hijo['icono'] }}" aria-hidden="true"></i>
                        <span class="link-text">{{ $hijo['texto'] }}</span>
                        @if ($hijo['aviso'])
                            @include('livewire.layouts.aviso', $hijo['aviso'])
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    </details>
</li>
