@props(['opciones', 'etiqueta' => 'Secciones'])

{{-- Chips de navegación (WEB-11.1): reemplazan a las pestañas que se envolvían en varias filas. Una sola fila con
     desplazamiento horizontal si no entran; el elegido va resaltado (`aria-current`). Cada opción:
     ['texto' => …, 'url' => …, 'activa' => bool]. El JS (`chips.js`) deja el elegido a la vista al cargar. --}}
<nav {{ $attributes->merge(['class' => 'chips']) }} aria-label="{{ $etiqueta }}" data-chips>
    <ul class="chips-lista">
        @foreach ($opciones as $opcion)
            <li>
                <a class="chip-opcion {{ $opcion['activa'] ? 'activo' : '' }}" href="{{ $opcion['url'] }}"
                   @if ($opcion['activa']) aria-current="page" @endif>{{ $opcion['texto'] }}</a>
            </li>
        @endforeach
    </ul>
</nav>
