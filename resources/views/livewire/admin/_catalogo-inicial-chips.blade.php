{{-- Los catálogos iniciales, un chip por catálogo (WEB-12.8 / 7.4.3e). `$activo` es el nombre de la ruta de la pantalla actual. --}}
<nav class="d-flex flex-wrap gap-2 mb-3" aria-label="Catálogos iniciales">
    @foreach ([
        'admin.catalogo-inicial.vacunas' => 'Vacunas',
        'admin.catalogo-inicial.percentiles' => 'Referencia de crecimiento',
    ] as $ruta => $texto)
        <a href="{{ route($ruta) }}" class="btn btn-sm {{ $activo === $ruta ? 'btn-primary' : 'btn-outline-secondary' }}"
           @if ($activo === $ruta) aria-current="page" @endif>{{ $texto }}</a>
    @endforeach
</nav>
