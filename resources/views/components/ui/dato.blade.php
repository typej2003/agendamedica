@props(['etiqueta', 'valor' => null])

{{-- Par etiqueta/valor de una ficha. Si no hay valor muestra "—" (nunca vacío ni "N/A"). El valor va por
     el atributo `valor` o, si lleva marcado, por el slot. --}}
@php
    $texto = trim((string) ($valor ?? ''));
    $contenido = $texto !== '' ? e($texto) : trim((string) $slot);
@endphp
<div {{ $attributes->merge(['class' => 'dato']) }}>
    <dt class="etiqueta">{{ $etiqueta }}</dt>
    <dd>{!! $contenido !== '' ? $contenido : '<span class="dato-vacio">—</span>' !!}</dd>
</div>
