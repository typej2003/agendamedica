@extends('clinica.imprimir.layout')

@section('titulo', $vm->titulo())

@section('estilos')
    {{-- Los formatos en blanco (WEB-5.5b): la misma hoja de la impresión real (récipe y documentos) con el bloque del paciente y los datos
         cortos vacíos y renglones en lugar del texto. Sólo se agrega `clinica-en-blanco.css`. --}}
    <link rel="stylesheet" href="{{ asset('css/clinica-recipe.css') }}">
    <link rel="stylesheet" href="{{ asset('css/clinica-documento.css') }}">
    <link rel="stylesheet" href="{{ asset('css/clinica-en-blanco.css') }}">
@endsection

@section('barra')
    {{-- Esta barra no se imprime. --}}
    <div class="barra-impresion-texto">
        <a class="barra-impresion-volver" href="{{ $vm->urlVolver() }}">&larr; {{ $vm->textoVolver() }}</a>
        <h1 class="barra-impresion-titulo">{{ $vm->titulo() }}</h1>
        <p class="barra-impresion-detalle">{{ $vm->resumen() }}</p>

        {{-- El modo del récipe se puede cambiar acá sin guardarlo: son enlaces GET a esta misma hoja. --}}
        @if ($vm->esRecipe())
            <div class="barra-impresion-modos" role="group" aria-label="Modo del récipe">
                @foreach ($vm->modos() as $modo)
                    <a class="barra-impresion-modo" href="{{ $modo['url'] }}" @if ($modo['activa']) aria-current="true" @endif>{{ $modo['texto'] }}</a>
                @endforeach
            </div>
        @endif
    </div>

    <div class="barra-impresion-acciones">
        <button type="button" class="boton-impresion" data-imprimir>Imprimir</button>
    </div>
@endsection

@section('contenido')
    <main class="recipe-hojas" id="recipe-hojas" data-recipe-hojas>
        @foreach ($vm->hojas() as $hoja)
            <article class="recipe-hoja tamano-{{ $hoja['tamano'] }}">
                @foreach ($hoja['mitades'] as $mitad)
                    @if ($hoja['tipo'] === 'documento')
                        @include('clinica.imprimir._documento-mitad', ['mitad' => $mitad, 'membrete' => $vm->membrete()])
                    @else
                        @include('clinica.imprimir._recipe-mitad', ['mitad' => $mitad, 'membrete' => $vm->membrete()])
                    @endif
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
