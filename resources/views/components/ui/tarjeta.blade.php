@props(['titulo' => null, 'icono' => null, 'sinRelleno' => false])

{{-- Tarjeta con encabezado opcional (título + icono + slot `acciones`). `sin-relleno` para tablas y listas
     que van de borde a borde. --}}
<section {{ $attributes->merge(['class' => 'card']) }}>
    @if ($titulo || isset($acciones))
        <header class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
            <h2 class="card-titulo">
                @if ($icono)<i class="bi {{ $icono }}" aria-hidden="true"></i>@endif
                {{ $titulo }}
            </h2>
            @isset($acciones)
                <div class="d-flex flex-wrap align-items-center gap-2">{{ $acciones }}</div>
            @endisset
        </header>
    @endif

    <div class="{{ $sinRelleno ? '' : 'card-body' }}">{{ $slot }}</div>
</section>
