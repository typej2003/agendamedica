@props(['icono' => 'bi-inbox', 'titulo'])

{{-- Estado vacío: icono suave, frase humana, texto de apoyo (slot) y la acción que lo resuelve (slot `acciones`). --}}
<div {{ $attributes->merge(['class' => 'empty-state']) }}>
    <i class="bi {{ $icono }}" aria-hidden="true"></i>
    <p class="empty-state-titulo">{{ $titulo }}</p>
    @if (trim((string) $slot) !== '')
        <p class="empty-state-texto">{{ $slot }}</p>
    @endif
    @isset($acciones)
        <div class="empty-state-acciones">{{ $acciones }}</div>
    @endisset
</div>
