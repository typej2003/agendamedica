@extends('clinica.imprimir.layout')

@section('titulo', $vm->titulo())

@section('estilos')
    {{-- El @page de la hoja del récipe: A4 horizontal, sin márgenes (los pone cada mitad). --}}
    <link rel="stylesheet" href="{{ asset('css/clinica-recipe.css') }}">
@endsection

@section('barra')
    {{-- Esta barra no se imprime. Es la vista previa: lo que se ve debajo es lo que sale en la impresora. --}}
    <div class="barra-impresion-texto">
        <a class="barra-impresion-volver" href="{{ $vm->urlVolver() }}">&larr; {{ $vm->textoVolver() }}</a>
        <h1 class="barra-impresion-titulo">{{ $vm->titulo() }}</h1>
        <p class="barra-impresion-detalle">{{ $vm->resumenDeLaImpresion() }} · {{ $vm->resumenDeLasOpciones() }}</p>
    </div>

    <div class="barra-impresion-acciones">
        <button type="button" class="boton-impresion boton-impresion-secundario" data-abrir-opciones>Opciones</button>
        <button type="button" class="boton-impresion" data-imprimir>Imprimir</button>
    </div>
@endsection

@section('contenido')
    <main class="recipe-hojas" id="recipe-hojas" data-recipe-hojas>
        @foreach ($vm->hojas() as $hoja)
            {{-- Una hoja = una página A4 horizontal con dos mitades. En "solo indicaciones" la mitad derecha queda
                 en blanco (media hoja), como en el móvil. --}}
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

@section('dialogos')
    {{-- Las opciones, sin salir de la vista previa: el mismo formulario del diálogo "Imprimir" de la consulta. --}}
    <dialog id="dialogo-opciones" class="dialogo-impresion" aria-labelledby="dialogo-opciones-titulo">
        <div class="dialogo-impresion-caja">
            <h2 class="dialogo-impresion-titulo" id="dialogo-opciones-titulo">Opciones de la hoja</h2>
            @include('clinica.imprimir._opciones', ['dialogo' => $vm->dialogo()])
        </div>
    </dialog>
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/imprimir-recipe.js') }}"></script>
@endsection
