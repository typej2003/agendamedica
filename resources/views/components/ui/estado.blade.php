@props(['tono' => 'neutro', 'icono' => null])

{{-- Pastilla de estado: color + icono + texto (nunca solo color). Tonos: exito aviso peligro info neutro marca. --}}
<span {{ $attributes->merge(['class' => 'estado estado-' . $tono]) }}>
    @if ($icono)<i class="bi {{ $icono }}" aria-hidden="true"></i>@endif
    {{ $slot }}
</span>
