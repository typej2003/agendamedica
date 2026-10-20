@extends('clinica.layout')

@section('titulo', $vm->titulo())

@section('contenido')
    {{-- El editor de un tratamiento del catálogo (WEB-8.1a). Es **el mismo editor del récipe** (WEB-5.1) con la misma
         grilla de dos columnas (`<x-ui.dos-listas>`, A-04): a la izquierda el tratamiento —el nombre, sus medicamentos con
         cantidad, indicaciones y orden, y el guardado— y a la derecha **el vademécum a la vista** (`_recipes-buscador`):
         el buscador filtra la lista, y «Nuevo medicamento» abre el alta en un diálogo lateral con el nombre ya escrito.
         Las filas (`_recipes-fila`) y el JS (`consulta-recipes.js`) son los de la consulta. El diálogo del alta va **fuera**
         del formulario (un formulario no se anida en otro). El guardado es del tratamiento entero y explícito. --}}
    <x-ui.page-head :titulo="$vm->titulo()" :subtitulo="$vm->subtitulo()" :volver="$vm->urlLista()" volver-texto="Volver a los tratamientos" />

    <x-ui.errores />

    @php
        $editor = $vm->editor();
        $contador = $vm->estadoDelEditor();
    @endphp

    <div class="consulta-seccion" id="consulta-recipes"
         data-url-medicamentos="{{ $vm->urlMedicamentos() }}"
         data-url-tratamientos=""
         data-recipe-lista-en-linea="1">

        <x-ui.dos-listas :titulo-izquierda="$editor['titulo']" titulo-derecha="Tu vademécum">
            <x-slot name="izquierda">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                    <x-ui.estado id="recipe-contador" :tono="$contador['tono']" :icono="$contador['icono']">{{ $contador['texto'] }}</x-ui.estado>
                </div>

                <form method="POST" action="{{ $editor['urlGuardar'] }}" id="recipe-form" data-consulta-recipe data-cosa="tratamiento"
                      data-aviso="{{ $vm->avisoSinGuardar() }}" data-siguiente-clave="{{ $vm->siguienteClave() }}">
                    @csrf
                    {{-- Marca de que el formulario es el del editor: si el POST falla, el editor vuelve con lo que había
                         (incluso sin medicamentos) en vez de releer la base. --}}
                    <input type="hidden" name="editor" value="1">

                    <x-ui.campo etiqueta="Nombre del tratamiento" for="tratamiento-nombre" error="tratamiento" requerido class="mb-3">
                        <input type="text" id="tratamiento-nombre" name="tratamiento" class="form-control" data-recipe-extra
                               maxlength="{{ $vm->largoDelNombre() }}" value="{{ $editor['nombre'] }}" autocomplete="off"
                               placeholder="Ej.: Infección urinaria">
                    </x-ui.campo>

                    <ul class="recipe-editor" id="recipe-items">
                        @foreach ($editor['filas'] as $fila)
                            @include('clinica.consulta._recipes-fila', ['fila' => $fila, 'seccion' => $vm])
                        @endforeach
                    </ul>

                    <div id="recipe-vacio" @if ($editor['filas'] !== []) hidden @endif>
                        <x-ui.vacio icono="bi-capsule" titulo="Todavía no hay medicamentos en este tratamiento">
                            Agrégalos desde tu vademécum, a la derecha.
                        </x-ui.vacio>
                    </div>

                    {{-- El aviso de "hay cambios sin guardar": `consulta-recipes.js` lo muestra cuando algo del
                         formulario cambió respecto de lo que el servidor pintó. --}}
                    <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-0" id="recipe-sin-guardar" hidden>
                        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                        <span>Hay cambios sin guardar.</span>
                    </p>

                    <div class="d-flex justify-content-end gap-2 mt-3">
                        {{-- Cancelar es el primer enlace del formulario: `consulta-recipes.js` le pide confirmación si hay
                             cambios sin guardar. --}}
                        <a class="btn btn-outline-secondary" href="{{ $vm->urlLista() }}">Cancelar</a>
                        {{-- La única acción primaria del editor: guardar el tratamiento entero. --}}
                        <button class="btn btn-primary">
                            <i class="bi bi-check2-circle" aria-hidden="true"></i> {{ $vm->botonGuardar() }}
                        </button>
                    </div>
                </form>
            </x-slot>

            <x-slot name="derecha">
                @include('clinica.consulta._recipes-buscador', ['seccion' => $vm])
            </x-slot>
        </x-ui.dos-listas>

        @include('clinica.consulta._recipes-fila-plantilla', ['seccion' => $vm])
        @include('clinica.consulta._recipes-alta-medicamento', ['seccion' => $vm])
    </div>
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/consulta-recipes.js') }}"></script>
@endsection
