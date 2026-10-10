@props(['nombre', 'tamano' => 'md'])

{{-- Círculo con las iniciales de la persona. El color sale del nombre (estable), de la paleta suave. --}}
<span {{ $attributes->merge(['class' => 'avatar avatar-' . $tamano . ' ' . \App\Support\Iniciales::tono($nombre)]) }}
      aria-hidden="true">{{ \App\Support\Iniciales::de($nombre) }}</span>
