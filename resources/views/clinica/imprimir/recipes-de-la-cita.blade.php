@extends('clinica.layout')

@section('titulo', $vm->titulo())

@section('contenido')
    <x-ui.page-head :titulo="$vm->titulo()" :subtitulo="$vm->subtitulo()"
                    :volver="$vm->urlVolver()" volver-texto="Volver a la agenda" />

    {{-- Los récipes de una cita atendida (WEB-5.2): la secretaría elige cuál imprimir sin entrar a la consulta, que
         es sólo del médico. No se listan medicamentos ni indicaciones: eso es lo que sale en la hoja, y se ve en
         la vista previa. Con un solo récipe esta pantalla se salta y se va directo a la hoja. --}}
    @if ($vm->estaVacia())
        @php $vacio = $vm->sinRecipes(); @endphp
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
                    <button type="button" class="btn btn-sm btn-primary" data-imprimir-recipe
                            data-siguiente="{{ $vm->urlImprimirTodos() }}" data-titulo="Todos los récipes">
                        <i class="bi bi-printer" aria-hidden="true"></i> Imprimir todos
                    </button>
                </x-slot>
            @endif

            <div class="recipe-lista">
                @foreach ($vm->recipes() as $recipe)
                    <article class="recipe-bloque">
                        <header class="recipe-cabecera mb-0">
                            <div class="recipe-cabecera-texto">
                                <h3 class="recipe-titulo">
                                    <i class="bi {{ $recipe['icono'] }} texto-tenue" aria-hidden="true"></i> {{ $recipe['titulo'] }}
                                </h3>
                                <p class="texto-tenue small mb-0">
                                    {{ $recipe['resumen'] }}@if ($recipe['fecha']) · emitido el {{ $recipe['fecha'] }}@endif
                                </p>
                            </div>

                            <div class="recipe-acciones">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-imprimir-recipe
                                        data-siguiente="{{ $recipe['urlImprimir'] }}" data-titulo="{{ $recipe['titulo'] }}">
                                    <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
                                </button>
                            </div>
                        </header>
                    </article>
                @endforeach
            </div>
        </x-ui.tarjeta>

        {{-- Un solo diálogo para toda la página: el botón que lo abre le dice a qué vista previa sigue. --}}
        <x-ui.dialogo id="dialogo-imprimir-recipe" titulo="Imprimir récipe" lateral>
            @include('clinica.imprimir._opciones', ['dialogo' => $vm->dialogo()])
        </x-ui.dialogo>
    @endif
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/imprimir-recipe.js') }}"></script>
@endsection
