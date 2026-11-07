@extends('clinica.imprimir.layout')

@section('titulo', $vm->titulo())

@section('estilos')
    {{-- El @page de la hoja: Carta horizontal, sin márgenes (los pone cada mitad). `clinica-recipe.css` trae la hoja, la mitad y
         los datos del paciente (de los récipes y de los documentos); `clinica-documento.css` suma el cuerpo de un documento
         de la consulta (constancia, reposo, referencia, informe: WEB-5.3a). --}}
    {{-- El `?v=` con la fecha del archivo: sin él el navegador sirve la hoja y el JS viejos tras un despliegue, y un
         botón nuevo parece no hacer nada (2026-11-05). --}}
    <link rel="stylesheet" href="{{ asset('css/clinica-recipe.css') }}?v={{ @filemtime(public_path('css/clinica-recipe.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/clinica-documento.css') }}?v={{ @filemtime(public_path('css/clinica-documento.css')) }}">
    {{-- El informe de una eco a hoja completa, Carta vertical (WEB-11.13). --}}
    <link rel="stylesheet" href="{{ asset('css/clinica-informe-eco.css') }}?v={{ @filemtime(public_path('css/clinica-informe-eco.css')) }}">
    {{-- El informe pre-operatorio, también Carta vertical, en dos páginas (O-47). --}}
    <link rel="stylesheet" href="{{ asset('css/clinica-informe-preoperatorio.css') }}?v={{ @filemtime(public_path('css/clinica-informe-preoperatorio.css')) }}">
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
        {{-- **La hoja de este imprimible** (2026-11-05): el botón de configuración, con el icono que muestra qué parte de
             la hoja se imprime. Al lado de «Imprimir», porque es lo que decide cómo sale. --}}
        @if ($vm->hojaDelDocumento() !== null)
            @php
                $hoja = $vm->hojaDelDocumento();
            @endphp
            <button type="button" class="boton-impresion boton-impresion-secundario boton-hoja"
                    data-abrir-hoja aria-haspopup="dialog" aria-controls="dialogo-hoja"
                    aria-label="Formato de la hoja: {{ $hoja['actualTitulo'] }}"
                    title="Formato de la hoja: {{ $hoja['actualTitulo'] }}">
                @include('clinica.imprimir._icono-hoja', ['opcion' => $hoja['actual'], 'clase' => 'boton-hoja-icono'])
                <svg class="boton-hoja-engranaje" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false">
                    <circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="2"/>
                    <path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                          d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                </svg>
            </button>
        @endif
        <button type="button" class="boton-impresion" data-imprimir>Imprimir</button>
    </div>
@endsection

@section('contenido')
    <main class="recipe-hojas" id="recipe-hojas" data-recipe-hojas>
        @foreach ($vm->hojas() as $hoja)
            @if (($hoja['tipo'] ?? '') === 'preoperatorio')
                @include('clinica.imprimir._informe-preoperatorio', ['hoja' => $hoja, 'membrete' => $vm->membrete()])
                @continue
            @endif

            @if (($hoja['tipo'] ?? '') === 'informe')
                @include('clinica.imprimir._informe-eco', ['hoja' => $hoja, 'membrete' => $vm->membrete()])
                @continue
            @endif

            {{-- Una hoja = una página Carta con dos mitades (horizontal) **o una página Carta vertical** cuando el documento declara
                 hoja vertical (O-26: el control de embarazo y los ecos, con la página entera; 2026-10-18: la colposcopía y la
                 histeroscopía, que usan sólo la mitad de arriba y dejan el resto en blanco —`ModoDeLaHoja::clasesDeLaHoja()`).
                 En "solo indicaciones" de un récipe, y en cualquier documento de una sola mitad, la mitad derecha queda en
                 blanco (media hoja), como en el móvil. --}}
            @php
                $modo = $hoja['modo'] ?? \App\Clinica\Impresion\ModoDeLaHoja::MITAD;
            @endphp
            {{-- La letra va en la hoja y también en cada mitad de documento: dos documentos de una misma hoja pueden tener
                 tamaños distintos y gana la regla más específica de `clinica-recipe.css`. --}}
            <article class="recipe-hoja{{ \App\Clinica\Impresion\ModoDeLaHoja::clasesDeLaHoja($modo) }} tamano-{{ $hoja['tamano'] }}">
                @foreach ($hoja['mitades'] as $mitad)
                    @if (($hoja['tipo'] ?? 'recipe') === 'documento')
                        @include('clinica.imprimir._documento-mitad', ['mitad' => $mitad, 'membrete' => $vm->membrete()])
                    @else
                        @include('clinica.imprimir._recipe-mitad', ['mitad' => $mitad, 'membrete' => $vm->membrete()])
                    @endif
                @endforeach

                {{-- La otra mitad en blanco **sólo** en la hoja acostada compartida (`mitad`, dos documentos por página): la
                     completa, la media vertical y la media horizontal ya miden lo que se imprime y no llevan nada vacío. --}}
                @if ($modo === \App\Clinica\Impresion\ModoDeLaHoja::MITAD && count($hoja['mitades']) === 1)
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

    {{-- **La hoja de este imprimible** (2026-11-05): tres opciones, la actual marcada, y un clic guarda y recarga. No hay
         botón de guardar: elegir es guardar, y el recuerdo es lo que pide el usuario («configurar y recordar»). --}}
    @if ($vm->hojaDelDocumento() !== null)
        @php
            $hoja = $vm->hojaDelDocumento();
        @endphp
        <dialog id="dialogo-hoja" class="dialogo-impresion dialogo-hoja" aria-labelledby="dialogo-hoja-titulo">
            <div class="dialogo-impresion-caja">
                <h2 class="dialogo-impresion-titulo" id="dialogo-hoja-titulo">Formato de la hoja</h2>
                <p class="dialogo-hoja-ayuda">
                    Cómo sale <strong>{{ $hoja['nombre'] }}</strong> al imprimir. La elección se recuerda para las próximas.
                </p>

                <form method="POST" action="{{ $hoja['urlGuardar'] }}">
                    @csrf
                    <input type="hidden" name="documento" value="{{ $hoja['documento'] }}">

                    <div class="hojas-opciones">
                        @foreach ($hoja['opciones'] as $opcion)
                            <button type="submit" name="hoja" value="{{ $opcion['valor'] }}"
                                    class="opcion-hoja @if ($opcion['activa']) opcion-hoja-activa @endif"
                                    @if ($opcion['activa']) aria-current="true" @endif>
                                @include('clinica.imprimir._icono-hoja', ['opcion' => $opcion['valor'], 'clase' => 'opcion-hoja-icono'])
                                <span class="opcion-hoja-texto">
                                    <strong>{{ $opcion['titulo'] }}</strong>
                                    <small>{{ $opcion['descripcion'] }}</small>
                                </span>
                                @if ($opcion['activa'])
                                    <span class="opcion-hoja-ahora">Ahora</span>
                                @endif
                            </button>
                        @endforeach
                    </div>

                    <div class="dialogo-pie">
                        <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cerrar</button>
                    </div>
                </form>
            </div>
        </dialog>
    @endif
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/imprimir-recipe.js') }}?v={{ @filemtime(public_path('js/clinica/imprimir-recipe.js')) }}"></script>
@endsection
