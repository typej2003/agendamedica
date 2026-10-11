{{-- El récipe de la pareja de la consulta (WEB-5.1b): la segunda pestaña de la sección de récipes.

     La pareja es **una por consulta** (`recipes_pareja` no tiene cabecera ni número de récipe) y se corrige en el
     lugar, así que la pantalla es **siempre el editor**: vacío si todavía no hay, con lo guardado si ya hay. La
     cédula y el nombre de la pareja son texto libre (no se enlazan con ningún paciente) y viajan con todos los
     medicamentos en un solo `POST`, como el escritorio los repite en cada fila.

     Es el **mismo** editor del récipe del paciente —las mismas filas, el mismo buscador de vademécum y de
     tratamientos y el mismo alta del medicamento que falta, con el mismo `consulta-recipes.js`—; lo que cambia
     es la tarjeta de datos de la pareja (en lugar de la fecha de emisión) y que se puede eliminar entera. --}}
@php
    $editor = $seccion->editor();
    $estado = $seccion->estado();
    $contador = $seccion->estadoDelEditor();
@endphp

{{-- Las dos pestañas y, a la derecha, lo destructivo aparte: eliminar el récipe de la pareja entero. Es un
     formulario propio (no puede ir dentro del editor) y pide confirmación que nombra lo que se borra. --}}
<div class="recipe-pestanas-barra mb-3">
    <x-ui.segmentado class="recipe-pestanas" :opciones="$seccion->pestanas()" etiqueta="Récipes de la consulta" />

    @if ($seccion->existe())
        <div class="recipe-acciones">
            {{-- Imprimir (WEB-5.2): imprime lo que está **guardado**. Si el editor tiene cambios sin guardar, el
                 navegador avisa antes de salir (`consulta-recipes.js`). --}}
            @if ($seccion->puedeImprimir())
                <button type="button" class="btn btn-sm btn-outline-secondary" data-imprimir-recipe
                        data-siguiente="{{ $seccion->urlImprimirPareja() }}" data-titulo="Récipe de la pareja">
                    <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
                </button>
            @endif

            <form method="POST" action="{{ $seccion->urlEliminar() }}" data-confirmar="{{ $seccion->avisoDeEliminar() }}" data-recipe-salir>
                @csrf
                @method('DELETE')
                <x-ui.menu etiqueta="Más acciones del récipe de la pareja">
                    <button class="menu-item menu-item-peligro">
                        <i class="bi bi-trash" aria-hidden="true"></i> Eliminar el récipe de la pareja
                    </button>
                </x-ui.menu>
            </form>
        </div>
    @endif
</div>

{{-- La próxima cita que sale al pie de la hoja y el atajo para hacerla (WEB-5.2). --}}
@include('clinica.consulta._recipes-cita', ['seccion' => $seccion])

@if ($seccion->puedeImprimir())
    <x-ui.dialogo id="dialogo-imprimir-recipe" titulo="Imprimir récipe" lateral>
        @include('clinica.imprimir._opciones', ['dialogo' => $seccion->dialogoDeImpresion()])
    </x-ui.dialogo>
@endif

<form method="POST" action="{{ $editor['urlGuardar'] }}" id="recipe-form" data-consulta-recipe
      data-aviso="{{ $seccion->avisoSinGuardar() }}" data-siguiente-clave="{{ $seccion->siguienteClave() }}">
    @csrf
    {{-- Marca de que el formulario es el del editor: si el POST falla, el editor vuelve con lo que había
         (incluso sin medicamentos) en vez de releer la base. --}}
    <input type="hidden" name="editor" value="1">

    <x-ui.tarjeta :titulo="$editor['titulo']" icono="bi-prescription2">
        <x-slot name="acciones">
            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']" :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
        </x-slot>

        <p class="texto-tenue small mb-3">{{ $seccion->ayuda() }}</p>

        {{-- Los datos de la pareja: obligatorios (el escritorio no deja agregar un medicamento sin ellos). La
             cédula y el nombre cuentan para el aviso de «cambios sin guardar» (`data-recipe-extra`). --}}
        <div class="row g-3 mb-3">
            <x-ui.campo etiqueta="Cédula de la pareja" for="pareja-cedula" error="cedula" requerido class="col-12 col-sm-5 col-md-4">
                <input type="text" id="pareja-cedula" name="cedula" class="form-control" data-recipe-extra
                       maxlength="{{ $seccion->largoCedula() }}" value="{{ $editor['cedula'] }}" autocomplete="off">
            </x-ui.campo>

            <x-ui.campo etiqueta="Nombre de la pareja" for="pareja-nombre" error="nombre" requerido class="col-12 col-sm-7 col-md-8"
                        :ayuda="$seccion->ayudaDeLaPareja()">
                <input type="text" id="pareja-nombre" name="nombre" class="form-control" data-recipe-extra
                       maxlength="{{ $seccion->largoNombreDeLaPareja() }}" value="{{ $editor['nombre'] }}" autocomplete="off">
            </x-ui.campo>
        </div>

        <ul class="recipe-editor" id="recipe-items">
            @foreach ($editor['filas'] as $fila)
                @include('clinica.consulta._recipes-fila', ['fila' => $fila])
            @endforeach
        </ul>

        <div id="recipe-vacio" @if ($editor['filas'] !== []) hidden @endif>
            <x-ui.vacio icono="bi-capsule" titulo="La pareja todavía no tiene medicamentos">
                Búscalos abajo en tu vademécum o carga un tratamiento. Puedes agregar los que necesites.
            </x-ui.vacio>
        </div>

        {{-- El aviso de "hay cambios sin guardar": `consulta-recipes.js` lo muestra cuando algo del
             formulario cambió respecto de lo que el servidor pintó. --}}
        <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-0" id="recipe-sin-guardar" hidden>
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span>Hay cambios sin guardar.</span>
        </p>

        <div class="d-flex justify-content-between align-items-center gap-2 mt-3">
            {{-- El contador vivo de medicamentos: `consulta-recipes.js` lo mantiene al agregar y quitar. --}}
            <x-ui.estado id="recipe-contador" :tono="$contador['tono']" :icono="$contador['icono']">{{ $contador['texto'] }}</x-ui.estado>

            {{-- La única acción primaria del editor: guardar el récipe de la pareja entero. --}}
            <button class="btn btn-primary">
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar el récipe de la pareja
            </button>
        </div>
    </x-ui.tarjeta>
</form>

@include('clinica.consulta._recipes-herramientas')
