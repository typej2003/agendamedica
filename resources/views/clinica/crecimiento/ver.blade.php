@extends('clinica.layout')

@section('titulo', 'Crecimiento')

@section('estilos')
    <link rel="stylesheet" href="{{ asset('css/clinica-crecimiento.css') }}">
@endsection

@section('contenido')
    <x-ui.page-head :titulo="'Crecimiento · ' . $vm->nombre()" :subtitulo="$vm->subtitulo()" :volver="$vm->urlFicha()" volver-texto="Ficha del paciente">
        <x-slot name="acciones">
            {{-- Imprimir es lo único que sale de acá: la hoja es otra página, sin el armazón de la web. Sin mediciones no hay qué imprimir. --}}
            @if ($vm->hayMediciones())
                <a class="btn btn-primary" href="{{ $vm->urlImprimir() }}" target="_blank" rel="noopener">
                    <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
                </a>
            @else
                <button type="button" class="btn btn-primary" disabled title="Todavía no hay mediciones que imprimir">
                    <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
                </button>
            @endif
        </x-slot>
    </x-ui.page-head>

    @if (! $vm->hayMediciones())
        @php $vacio = $vm->sinMediciones(); @endphp
        <x-ui.tarjeta id="crecimiento-vacio">
            <x-ui.vacio icono="bi-graph-up" :titulo="$vacio['titulo']">{{ $vacio['texto'] }}</x-ui.vacio>
        </x-ui.tarjeta>
    @else
        <x-ui.chips class="mb-3" :opciones="$vm->chips()" etiqueta="Gráfica" />

        @if ($vm->avisoDeReferencia())
            @php $aviso = $vm->avisoDeReferencia(); @endphp
            <x-ui.estado tono="aviso" icono="bi-info-circle" class="mb-3" id="crecimiento-sin-referencia">{{ $aviso['titulo'] }}. {{ $aviso['texto'] }}</x-ui.estado>
        @endif

        {{-- Los dos juegos de edades a la vez: lado a lado en pantalla ancha, uno sobre otro en la angosta. --}}
        <div class="row g-3 mb-3" id="crecimiento-graficas">
            @foreach ($vm->graficasPorJuego() as $juego => $g)
                <div class="col-12 col-xl-6">
                    <x-ui.tarjeta :id="'crecimiento-grafica-' . $juego" :titulo="$g['grafica']->titulo()" icono="bi-graph-up" class="h-100">
                        @unless ($g['grafica']->conPuntos())
                            <x-ui.estado tono="neutro" icono="bi-info-circle" class="mb-2">El niño no tiene mediciones de esta gráfica entre los {{ $g['texto'] }}.</x-ui.estado>
                        @endunless

                        @include('clinica.crecimiento._grafica', ['grafica' => $g['grafica'], 'id' => 'grafica-' . $juego])
                    </x-ui.tarjeta>
                </div>
            @endforeach
        </div>

        <x-ui.tarjeta id="crecimiento-tabla" titulo="Mediciones" icono="bi-table" class="mb-3">
            @include('clinica.crecimiento._tabla')
        </x-ui.tarjeta>
    @endif
@endsection
