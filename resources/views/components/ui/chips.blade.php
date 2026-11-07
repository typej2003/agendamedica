@props(['opciones', 'etiqueta' => 'Secciones'])

{{-- Chips de navegación (WEB-11.1): reemplazan a las pestañas que se envolvían en varias filas. Una sola fila con
     desplazamiento horizontal si no entran; el elegido va resaltado (`aria-current`). Cada opción:
     ['texto' => …, 'url' => …, 'activa' => bool], y opcionalmente `cargada` (lleva el tilde de «con datos») y
     `pendiente` (todavía no tiene pantalla: se pinta como texto, sin enlace). El JS (`chips.js`) deja el elegido a
     la vista al cargar. --}}
<nav {{ $attributes->merge(['class' => 'chips']) }} aria-label="{{ $etiqueta }}" data-chips>
    <ul class="chips-lista">
        @foreach ($opciones as $opcion)
            <li>
                @if (! empty($opcion['pendiente']))
                    <span class="chip-opcion chip-opcion-pendiente" title="Llega en la próxima entrega">
                        {{ $opcion['texto'] }} <small>(próximamente)</small>
                    </span>
                @else
                    <a class="chip-opcion {{ $opcion['activa'] ? 'activo' : '' }}" href="{{ $opcion['url'] }}"
                       @if ($opcion['activa']) aria-current="page" @endif>{{ $opcion['texto'] }}@if (! empty($opcion['cargada']))<i class="bi bi-check2-circle ms-1" title="Con datos" aria-hidden="true"></i>@endif</a>
                @endif
            </li>
        @endforeach
    </ul>
</nav>
