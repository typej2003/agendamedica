@props(['nombre', 'detalle' => null, 'href' => null, 'tamano' => 'md'])

{{-- Avatar + nombre + dato clave en gris: la primera columna de un listado de personas. Con `href`, el
     nombre es el enlace. --}}
<div {{ $attributes->merge(['class' => 'identidad']) }}>
    <x-ui.avatar :nombre="$nombre" :tamano="$tamano" />
    <div class="identidad-texto">
        @if ($href)
            <a class="identidad-nombre" href="{{ $href }}">{{ $nombre }}</a>
        @else
            <span class="identidad-nombre">{{ $nombre }}</span>
        @endif
        @if ($detalle)
            <span class="identidad-detalle">{{ $detalle }}</span>
        @endif
    </div>
</div>
