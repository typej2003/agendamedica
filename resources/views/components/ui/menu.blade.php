@props(['etiqueta' => 'Más acciones', 'icono' => 'bi-three-dots', 'texto' => null])

{{-- Menú de acciones secundarias, sin librería: `<details>` + `shell.js` (cierra al hacer clic afuera).
     El contenido son enlaces o botones con `class="menu-item"`; los peligrosos, `menu-item menu-item-peligro`. --}}
<details {{ $attributes->merge(['class' => 'menu-acciones']) }}>
    <summary class="btn btn-sm btn-outline-secondary" aria-label="{{ $etiqueta }}" title="{{ $etiqueta }}">
        <i class="bi {{ $icono }}" aria-hidden="true"></i>@if ($texto) <span class="ms-1">{{ $texto }}</span>@endif
    </summary>
    <div class="menu-lista">{{ $slot }}</div>
</details>
