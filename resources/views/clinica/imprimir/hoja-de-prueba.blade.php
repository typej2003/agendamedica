@extends('clinica.imprimir.layout')

@section('titulo', $vm->titulo())

@section('estilos')
    {{-- La misma hoja que imprime el récipe (WEB-5.2): el mismo CSS y el mismo parcial de cada mitad. Lo único propio de esta
         página es el récipe de ejemplo y que, incrustada en la pantalla del formato, no lleva barra. --}}
    <link rel="stylesheet" href="{{ asset('css/clinica-recipe.css') }}">
@endsection

@unless ($vm->incrustada())
    @section('barra')
        {{-- Esta barra no se imprime. --}}
        <div class="barra-impresion-texto">
            <a class="barra-impresion-volver" href="{{ $vm->urlVolver() }}">&larr; {{ $vm->textoVolver() }}</a>
            <h1 class="barra-impresion-titulo">{{ $vm->titulo() }}</h1>
            <p class="barra-impresion-detalle">{{ $vm->resumen() }}</p>
        </div>

        <div class="barra-impresion-acciones">
            <button type="button" class="boton-impresion" data-imprimir>Imprimir</button>
        </div>
    @endsection
@endunless

@section('contenido')
    <main class="recipe-hojas" id="recipe-hojas" data-recipe-hojas>
        @foreach ($vm->hojas() as $hoja)
            <article class="recipe-hoja tamano-{{ $hoja['tamano'] }}">
                @foreach ($hoja['mitades'] as $mitad)
                    @include('clinica.imprimir._recipe-mitad', ['mitad' => $mitad, 'membrete' => $vm->membrete()])
                @endforeach

                @if (count($hoja['mitades']) === 1)
                    <div class="recipe-mitad recipe-mitad-vacia" aria-hidden="true"></div>
                @endif
            </article>
        @endforeach
    </main>
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/imprimir-recipe.js') }}"></script>
@endsection
