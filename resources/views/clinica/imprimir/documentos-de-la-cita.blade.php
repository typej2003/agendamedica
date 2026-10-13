@extends('clinica.layout')

@section('titulo', $vm->titulo())

@section('contenido')
    <x-ui.page-head :titulo="$vm->titulo()" :subtitulo="$vm->subtitulo()"
                    :volver="$vm->urlVolver()" volver-texto="Volver a la agenda" />

    {{-- Los documentos de una cita atendida (WEB-5.2, ampliado en WEB-5.3a): la secretaría elige qué imprimir sin entrar a la
         consulta, que es sólo del médico. Lleva los récipes y la constancia, el reposo, las referencias y el informe de esa
         consulta. No se listan medicamentos, indicaciones ni textos: eso es lo que sale en la hoja, y se ve en la vista previa.
         Con un solo documento esta pantalla se salta y se va directo a la hoja. --}}
    @if ($vm->estaVacia())
        @php $vacio = $vm->sinDocumentos(); @endphp
        <x-ui.tarjeta>
            <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">
                {{ $vacio['texto'] }}
                <x-slot name="acciones">
                    <a class="btn btn-outline-secondary" href="{{ $vm->urlVolver() }}">
                        <i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a la agenda
                    </a>
                </x-slot>
            </x-ui.vacio>
        </x-ui.tarjeta>
    @else
        <x-ui.tarjeta titulo="Elige qué imprimir" icono="bi-printer">
            @if ($vm->puedeImprimirTodos())
                <x-slot name="acciones">
                    {{-- Con récipes entre los documentos, imprimir pasa antes por las opciones de la hoja. --}}
                    @if ($vm->hayRecipes())
                        <button type="button" class="btn btn-sm btn-primary" data-imprimir-recipe
                                data-siguiente="{{ $vm->urlImprimirTodos() }}" data-titulo="Todos los documentos">
                            <i class="bi bi-printer" aria-hidden="true"></i> Imprimir todos
                        </button>
                    @else
                        <a class="btn btn-sm btn-primary" href="{{ $vm->urlImprimirTodos() }}">
                            <i class="bi bi-printer" aria-hidden="true"></i> Imprimir todos
                        </a>
                    @endif
                </x-slot>
            @endif

            <div class="recipe-lista">
                @foreach ($vm->documentos() as $documento)
                    <article class="recipe-bloque">
                        <header class="recipe-cabecera mb-0">
                            <div class="recipe-cabecera-texto">
                                <h3 class="recipe-titulo">
                                    <i class="bi {{ $documento['icono'] }} texto-tenue" aria-hidden="true"></i> {{ $documento['titulo'] }}
                                </h3>
                                <p class="texto-tenue small mb-0">
                                    {{ $documento['resumen'] }}@if ($documento['fecha']) · {{ $documento['fecha'] }}@endif
                                </p>
                            </div>

                            <div class="recipe-acciones">
                                @if ($documento['conOpciones'])
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-imprimir-recipe
                                            data-siguiente="{{ $documento['urlImprimir'] }}" data-titulo="{{ $documento['titulo'] }}">
                                        <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
                                    </button>
                                @else
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ $documento['urlImprimir'] }}">
                                        <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
                                    </a>
                                @endif
                            </div>
                        </header>
                    </article>
                @endforeach
            </div>
        </x-ui.tarjeta>

        {{-- Un solo diálogo para toda la página: el botón de un récipe le dice a qué vista previa seguir. --}}
        @if ($vm->hayRecipes())
            <x-ui.dialogo id="dialogo-imprimir-recipe" titulo="Imprimir récipe" lateral>
                @include('clinica.imprimir._opciones', ['dialogo' => $vm->dialogo()])
            </x-ui.dialogo>
        @endif
    @endif
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/imprimir-recipe.js') }}"></script>
@endsection
