{{-- Los catálogos iniciales, un chip por catálogo (WEB-12.8 / 7.4.3e). `$activo` es el nombre de la ruta de la pantalla actual y, en la de un catálogo, `$activoSlug` su slug. --}}
@php
    $chips = [['texto' => 'Vacunas', 'url' => route('admin.catalogo-inicial.vacunas'), 'activo' => $activo === 'admin.catalogo-inicial.vacunas']];
    foreach (\App\Clinica\Catalogos\Iniciales\CatalogosIniciales::todos() as $slugDelChip => $catalogoDelChip) {
        $chips[] = [
            'texto' => $catalogoDelChip['titulo'],
            'url' => route('admin.catalogo-inicial.catalogo', $slugDelChip),
            'activo' => $activo === 'admin.catalogo-inicial.catalogo' && ($activoSlug ?? null) === $slugDelChip,
        ];
    }
    $chips[] = ['texto' => 'Referencia de crecimiento', 'url' => route('admin.catalogo-inicial.percentiles'), 'activo' => $activo === 'admin.catalogo-inicial.percentiles'];
@endphp
<nav class="d-flex flex-wrap gap-2 mb-3" aria-label="Catálogos iniciales">
    @foreach ($chips as $chip)
        <a href="{{ $chip['url'] }}" class="btn btn-sm {{ $chip['activo'] ? 'btn-primary' : 'btn-outline-secondary' }}"
           @if ($chip['activo']) aria-current="page" @endif>{{ $chip['texto'] }}</a>
    @endforeach
</nav>
