@props(['opciones', 'etiqueta' => 'Filtro'])

{{-- Control segmentado de enlaces (Activas / Todas, Día / Semana / Mes). Cada opción:
     ['texto' => …, 'url' => …, 'activa' => bool]. --}}
<div {{ $attributes->merge(['class' => 'btn-group']) }} role="group" aria-label="{{ $etiqueta }}">
    @foreach ($opciones as $opcion)
        <a class="btn btn-outline-secondary {{ $opcion['activa'] ? 'active' : '' }}" href="{{ $opcion['url'] }}"
           @if ($opcion['activa']) aria-current="true" @endif>{{ $opcion['texto'] }}</a>
    @endforeach
</div>
