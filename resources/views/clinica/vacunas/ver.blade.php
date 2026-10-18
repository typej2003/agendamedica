@extends('clinica.layout')

@section('titulo', 'Vacunas')

@section('contenido')
    <x-ui.page-head :titulo="'Vacunas · ' . $vm->nombre()" :subtitulo="$vm->subtitulo()" :volver="$vm->urlFicha()" volver-texto="Ficha del paciente" />

    @if ($vm->sinCatalogo())
        @php $vacio = $vm->vacioSinCatalogo(); @endphp
        <x-ui.tarjeta id="vacunas-sin-catalogo">
            <x-ui.vacio icono="bi-capsule" :titulo="$vacio['titulo']">{{ $vacio['texto'] }}</x-ui.vacio>
        </x-ui.tarjeta>
    @else
        @if ($vm->sinFechaDeNacimiento())
            <x-ui.estado tono="aviso" icono="bi-info-circle" class="mb-3" id="vacunas-sin-nacimiento">
                Falta la fecha de nacimiento en la ficha: sin ella no se puede calcular cuándo le toca cada dosis.
            </x-ui.estado>
        @endif

        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div class="d-flex flex-wrap gap-2" id="vacunas-resumen">
                @foreach ($vm->resumen() as $r)
                    <x-ui.estado :tono="$r['tono']" :icono="$r['icono']">{{ $r['cantidad'] }} {{ mb_strtolower($r['texto']) }}{{ $r['cantidad'] === 1 ? '' : 's' }}</x-ui.estado>
                @endforeach
            </div>
            <x-ui.segmentado :opciones="$vm->filtros()" etiqueta="Dosis" />
        </div>

        @if (! $vm->hayAlgoQueMostrar())
            @php $vacio = $vm->vacioDelFiltro(); @endphp
            <x-ui.tarjeta id="vacunas-vacio">
                <x-ui.vacio icono="bi-capsule" :titulo="$vacio['titulo']">{{ $vacio['texto'] }}</x-ui.vacio>
            </x-ui.tarjeta>
        @else
            <x-ui.tarjeta id="vacunas-calendario" titulo="Calendario" icono="bi-calendar-check" class="mb-3">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 vacunas-tabla">
                        <thead>
                            <tr>
                                <th scope="col">Vacuna</th>
                                <th scope="col">Dosis</th>
                                <th scope="col">Le toca</th>
                                <th scope="col">Aplicada</th>
                                <th scope="col">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($vm->vacunas() as $vacuna)
                                @if ($vacuna['dosis'] === [])
                                    <tr>
                                        <th scope="row" class="fw-semibold">{{ $vacuna['vacuna'] }}</th>
                                        <td colspan="4" class="texto-tenue">Sin edad definida en el catálogo.</td>
                                    </tr>
                                @endif
                                @foreach ($vacuna['dosis'] as $i => $d)
                                    <tr>
                                        @if ($i === 0)
                                            <th scope="row" rowspan="{{ count($vacuna['dosis']) }}" class="fw-semibold">
                                                {{ $vacuna['vacuna'] }}
                                                <span class="d-block small texto-tenue fw-normal">{{ $vacuna['tipo'] }}@if ($vacuna['cobertura']) · {{ $vacuna['cobertura'] }}@endif</span>
                                            </th>
                                        @endif
                                        <td>{{ $d['rotulo'] }}</td>
                                        <td>{{ $d['planeada'] ?? '—' }}</td>
                                        <td>
                                            {{ $d['aplicada'] ?? '—' }}
                                            @if ($d['lote'])
                                                <span class="d-block small texto-tenue">Lote {{ $d['lote'] }}</span>
                                            @endif
                                        </td>
                                        <td><x-ui.estado :tono="$d['tono']" :icono="$d['icono']">{{ $d['estado'] }}</x-ui.estado></td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-ui.tarjeta>

            @if ($vm->otras() !== [])
                <x-ui.tarjeta id="vacunas-otras" titulo="Otras vacunas aplicadas" icono="bi-clipboard2-check" class="mb-3">
                    <p class="texto-tenue small">Se le aplicaron, pero ya no están en el catálogo de este consultorio.</p>
                    <ul class="list-unstyled mb-0">
                        @foreach ($vm->otras() as $otra)
                            <li>{{ $otra['vacuna'] }}@if ($otra['rotulo']) · {{ $otra['rotulo'] }}@endif · {{ $otra['aplicada'] ?? '—' }}@if ($otra['lote']) · lote {{ $otra['lote'] }}@endif</li>
                        @endforeach
                    </ul>
                </x-ui.tarjeta>
            @endif
        @endif
    @endif
@endsection
