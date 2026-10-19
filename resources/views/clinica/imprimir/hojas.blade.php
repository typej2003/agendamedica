@extends('clinica.imprimir.layout')

@section('titulo', $vm->titulo())

@section('estilos')
    {{-- El @page de la hoja: A4 horizontal, sin márgenes (los pone cada mitad). `clinica-recipe.css` trae la hoja, la mitad y
         los datos del paciente (de los récipes y de los documentos); `clinica-documento.css` suma el cuerpo de un documento
         de la consulta (constancia, reposo, referencia, informe: WEB-5.3a). --}}
    <link rel="stylesheet" href="{{ asset('css/clinica-recipe.css') }}">
    <link rel="stylesheet" href="{{ asset('css/clinica-documento.css') }}">
    {{-- El informe de una eco a hoja completa, A4 vertical (WEB-11.13). --}}
    <link rel="stylesheet" href="{{ asset('css/clinica-informe-eco.css') }}">
@endsection

@section('barra')
    {{-- Esta barra no se imprime. Es la vista previa: lo que se ve debajo es lo que sale en la impresora. --}}
    <div class="barra-impresion-texto">
        <a class="barra-impresion-volver" href="{{ $vm->urlVolver() }}">&larr; {{ $vm->textoVolver() }}</a>
        <h1 class="barra-impresion-titulo">{{ $vm->titulo() }}</h1>
        <p class="barra-impresion-detalle">{{ $vm->resumenDeLaImpresion() }}@if ($vm->conOpciones()) · {{ $vm->resumenDeLasOpciones() }}@endif</p>
    </div>

    <div class="barra-impresion-acciones">
        {{-- Las opciones de la hoja (modo y nombre del medicamento) son del récipe: sin récipes no hay nada que elegir. --}}
        @if ($vm->conOpciones())
            <button type="button" class="boton-impresion boton-impresion-secundario" data-abrir-opciones>Opciones</button>
        @endif
        {{-- Las hojas con imágenes aparte: un solo «Imprimir» y esta casilla decide si salen. Son las fotos de un eco
             (WEB-11.13) y los dibujos del cuello uterino de la colposcopía (A-13), por el mismo camino. --}}
        @if ($vm->conImagenes())
            <label class="barra-impresion-casilla">
                <input type="checkbox" checked data-incluir-imagenes> Incluir imágenes
            </label>
        @endif
        <button type="button" class="boton-impresion" data-imprimir>Imprimir</button>
    </div>
@endsection

@section('contenido')
    <main class="recipe-hojas" id="recipe-hojas" data-recipe-hojas>
        @foreach ($vm->hojas() as $hoja)
            @if (($hoja['tipo'] ?? '') === 'informe')
                @include('clinica.imprimir._informe-eco', ['hoja' => $hoja, 'membrete' => $vm->membrete()])
                @continue
            @endif

            {{-- Una hoja = una página A4 con dos mitades (horizontal) **o una página A4 vertical** cuando el documento declara
                 hoja vertical (O-26: el control de embarazo y los ecos, con la página entera; 2026-10-18: la colposcopía y la
                 histeroscopía, que usan sólo la mitad de arriba y dejan el resto en blanco —`ModoDeLaHoja::clasesDeLaHoja()`).
                 En "solo indicaciones" de un récipe, y en cualquier documento de una sola mitad, la mitad derecha queda en
                 blanco (media hoja), como en el móvil. --}}
            @php
                $modo = $hoja['modo'] ?? \App\Clinica\Impresion\ModoDeLaHoja::MITAD;
                $vertical = $modo !== \App\Clinica\Impresion\ModoDeLaHoja::MITAD;
            @endphp
            <article class="recipe-hoja{{ \App\Clinica\Impresion\ModoDeLaHoja::clasesDeLaHoja($modo) }} tamano-{{ $hoja['tamano'] }}">
                @foreach ($hoja['mitades'] as $mitad)
                    @if (($hoja['tipo'] ?? 'recipe') === 'documento')
                        @include('clinica.imprimir._documento-mitad', ['mitad' => $mitad, 'membrete' => $vm->membrete()])
                    @else
                        @include('clinica.imprimir._recipe-mitad', ['mitad' => $mitad, 'membrete' => $vm->membrete()])
                    @endif
                @endforeach

                @if (! $vertical && count($hoja['mitades']) === 1)
                    <div class="recipe-mitad recipe-mitad-vacia" aria-hidden="true"></div>
                @endif
            </article>
        @endforeach
    </main>
@endsection

@section('dialogos')
    {{-- Las opciones, sin salir de la vista previa: el mismo formulario del diálogo "Imprimir" de la consulta. --}}
    @if ($vm->conOpciones())
        <dialog id="dialogo-opciones" class="dialogo-impresion" aria-labelledby="dialogo-opciones-titulo">
            <div class="dialogo-impresion-caja">
                <h2 class="dialogo-impresion-titulo" id="dialogo-opciones-titulo">Opciones de la hoja</h2>
                @include('clinica.imprimir._opciones', ['dialogo' => $vm->dialogo()])
            </div>
        </dialog>
    @endif
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/imprimir-recipe.js') }}"></script>
@endsection
