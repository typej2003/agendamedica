@extends('clinica.layout')

@section('titulo', $vm->titulo())

@section('contenido')
    {{-- El editor de un tratamiento del catálogo (WEB-8.1a). Es **el mismo editor del récipe** (WEB-5.1): las filas de
         medicamento (`_recipes-fila`) y la caja de herramientas (`_recipes-herramientas`) son los parciales de la
         consulta y el JS es `consulta-recipes.js`; se declara con los mismos `id` y `data-*`. Lo que suma el
         tratamiento es el nombre y el orden de sus medicamentos (subir y bajar). El guardado es del tratamiento
         entero y explícito, con el aviso de «cambios sin guardar». --}}
    <x-ui.page-head :titulo="$vm->titulo()" :subtitulo="$vm->subtitulo()" :volver="$vm->urlLista()" volver-texto="Volver a los tratamientos" />

    <x-ui.errores />

    @php
        $editor = $vm->editor();
        $contador = $vm->estadoDelEditor();
    @endphp

    <div class="consulta-seccion" id="consulta-recipes"
         data-url-medicamentos="{{ $vm->urlMedicamentos() }}"
         data-url-tratamientos="">

        <form method="POST" action="{{ $editor['urlGuardar'] }}" id="recipe-form" data-consulta-recipe data-cosa="tratamiento"
              data-aviso="{{ $vm->avisoSinGuardar() }}" data-siguiente-clave="{{ $vm->siguienteClave() }}">
            @csrf
            {{-- Marca de que el formulario es el del editor: si el POST falla, el editor vuelve con lo que había
                 (incluso sin medicamentos) en vez de releer la base. --}}
            <input type="hidden" name="editor" value="1">

            <x-ui.tarjeta :titulo="$editor['titulo']" icono="bi-collection">
                <x-slot name="acciones">
                    <x-ui.estado id="recipe-contador" :tono="$contador['tono']" :icono="$contador['icono']">{{ $contador['texto'] }}</x-ui.estado>
                </x-slot>

                <div class="row g-3 mb-3">
                    <x-ui.campo etiqueta="Nombre del tratamiento" for="tratamiento-nombre" error="tratamiento" requerido class="col-12 col-md-8"
                                :ayuda="'Hasta ' . $vm->largoDelNombre() . ' caracteres. Se guarda en mayúsculas.'">
                        <input type="text" id="tratamiento-nombre" name="tratamiento" class="form-control" data-recipe-extra
                               maxlength="{{ $vm->largoDelNombre() }}" value="{{ $editor['nombre'] }}" autocomplete="off">
                    </x-ui.campo>
                </div>

                <ul class="recipe-editor" id="recipe-items">
                    @foreach ($editor['filas'] as $fila)
                        @include('clinica.consulta._recipes-fila', ['fila' => $fila, 'seccion' => $vm])
                    @endforeach
                </ul>

                <div id="recipe-vacio" @if ($editor['filas'] !== []) hidden @endif>
                    <x-ui.vacio icono="bi-capsule" titulo="Todavía no hay medicamentos en este tratamiento">
                        Búscalos abajo en tu vademécum y agrégalos. Puedes ponerles cantidad, indicaciones y orden.
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
            </x-ui.tarjeta>
        </form>

        @include('clinica.consulta._recipes-herramientas', ['seccion' => $vm])
    </div>
@endsection

@section('scripts')
    <script src="{{ asset('js/clinica/consulta-recipes.js') }}"></script>
@endsection
