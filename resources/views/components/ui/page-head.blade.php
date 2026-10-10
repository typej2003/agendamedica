@props(['titulo', 'subtitulo' => null, 'volver' => null, 'volverTexto' => 'Volver'])

{{-- Encabezado de página: título, subtítulo opcional, enlace de regreso y las acciones (slot `acciones`).
     Una sola acción primaria por pantalla (guía de UI, §1). --}}
<div {{ $attributes->merge(['class' => 'page-head']) }}>
    <div class="page-head-texto">
        @if ($volver)
            <a class="page-head-volver" href="{{ $volver }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> {{ $volverTexto }}
            </a>
        @endif
        <h1>{{ $titulo }}</h1>
        @if ($subtitulo)
            <p class="page-head-sub">{{ $subtitulo }}</p>
        @endif
    </div>

    @isset($acciones)
        <div class="page-head-acciones">{{ $acciones }}</div>
    @endisset
</div>
