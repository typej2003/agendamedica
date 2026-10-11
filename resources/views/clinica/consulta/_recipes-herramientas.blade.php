{{-- Lo que comparten el editor del récipe del paciente y el de la pareja (WEB-5.1 y WEB-5.1b): la plantilla de
     una fila de medicamento, la tarjeta de herramientas para llenar el récipe (el vademécum del médico, sus
     tratamientos y el alta del medicamento que falta) y el diálogo de ese alta.

     Los dos editores usan los **mismos ids** y el **mismo** `consulta-recipes.js`; cada ViewModel entrega las
     URLs y los textos por el mismo juego de métodos (`ComparteElEditorDeRecipes`). Este parcial se incluye
     **dentro** de `#consulta-recipes`, que lleva los `data-url-*` del buscador. --}}
{{-- La plantilla de una fila: el script la clona al agregar un medicamento (el mismo marcado que las
     filas que pinta el servidor). --}}
<template id="recipe-fila-plantilla">
    @include('clinica.consulta._recipes-fila', ['fila' => ['clave' => '__CLAVE__', 'codigo' => '', 'nombre' => '', 'generico' => null, 'cantidad' => '1', 'indicaciones' => '']])
</template>

{{-- Las herramientas para llenar el récipe: van **fuera** del formulario (no mandan nada por sí solas;
     lo que eligen entra al editor y se guarda con su botón). --}}
<x-ui.tarjeta titulo="Agregar medicamentos" icono="bi-search" class="mt-3" id="recipe-herramientas">
    <div class="btn-group mb-3" role="group" aria-label="Qué buscar">
        <button type="button" class="btn btn-outline-secondary active" data-recipe-modo="medicamentos" aria-pressed="true">
            <i class="bi bi-capsule" aria-hidden="true"></i> Medicamentos
        </button>
        <button type="button" class="btn btn-outline-secondary" data-recipe-modo="tratamientos" aria-pressed="false">
            <i class="bi bi-collection" aria-hidden="true"></i> Tratamientos
        </button>
    </div>

    <div class="position-relative" id="recipe-busqueda">
        <label class="form-label" for="recipe-texto" id="recipe-etiqueta">Busca en tu vademécum</label>

        <div class="buscador-campo buscador-vivo">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" id="recipe-texto" class="form-control form-control-sm" autocomplete="off"
                   placeholder="{{ $seccion->placeholderMedicamentos() }}"
                   data-placeholder-medicamentos="{{ $seccion->placeholderMedicamentos() }}"
                   data-placeholder-tratamientos="{{ $seccion->placeholderTratamientos() }}"
                   aria-controls="recipe-resultados">
        </div>

        <div class="list-group resultados-flotantes" id="recipe-resultados" aria-label="Resultados de la búsqueda" hidden></div>
    </div>

    <p class="form-text mt-2 mb-0" id="recipe-ayuda"
       data-ayuda-medicamentos="{{ $seccion->ayudaDelBuscador() }}"
       data-ayuda-tratamientos="{{ $seccion->ayudaDeTratamientos() }}">{{ $seccion->ayudaDelBuscador() }}</p>

    {{-- Los resultados: el script clona estas filas (el marcado es de la vista, no del script). --}}
    <template id="recipe-resultado-plantilla">
        <button type="button" class="resultado-paciente resultado-recipe">
            <span class="resultado-paciente-datos">
                <strong data-resultado-nombre></strong>
                <span class="texto-tenue small d-block" data-resultado-detalle></span>
            </span>
            <span class="btn btn-sm btn-outline-primary" data-resultado-accion>
                <i class="bi bi-plus-lg" aria-hidden="true"></i> <span data-resultado-texto>Agregar</span>
            </span>
        </button>
    </template>

    {{-- El alta del medicamento que falta: es el botón *Agregar* del escritorio (`w_nuevo_vademecum`). El
         aviso de conexión va acá, donde se decide crearlo: es lo único de la sección que no funciona
         sin red. --}}
    <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="recipe-nuevo-medicamento">
        <i class="bi bi-plus-lg" aria-hidden="true"></i> Crear un medicamento nuevo
    </button>
    <p class="form-text mb-0 mt-1">
        <i class="bi bi-wifi" aria-hidden="true"></i> {{ $seccion->avisoDeConexion() }}
    </p>
</x-ui.tarjeta>

<x-ui.dialogo id="dialogo-medicamento" titulo="Crear un medicamento" lateral>
    <form method="POST" action="{{ $seccion->urlCrearMedicamento() }}" id="medicamento-form">
        @csrf
        <div class="dialogo-cuerpo">
            <p class="alert alert-danger py-2 mb-3" id="medicamento-error" role="alert" hidden></p>

            <x-ui.campo etiqueta="Nombre comercial" for="medicamento-comercial" error="nombrecomercial" requerido
                        :ayuda="'Hasta ' . $seccion->largoNombre() . ' caracteres. Se guarda en mayúsculas.'">
                <input type="text" id="medicamento-comercial" name="nombrecomercial" class="form-control"
                       maxlength="{{ $seccion->largoNombre() }}" value="{{ old('nombrecomercial') }}" autocomplete="off">
            </x-ui.campo>

            <x-ui.campo etiqueta="Principio activo (genérico)" for="medicamento-generico" error="nombregenerico" class="mt-3"
                        :ayuda="'Opcional. Hasta ' . $seccion->largoNombre() . ' caracteres.'">
                <input type="text" id="medicamento-generico" name="nombregenerico" class="form-control"
                       maxlength="{{ $seccion->largoNombre() }}" value="{{ old('nombregenerico') }}" autocomplete="off">
            </x-ui.campo>

            <x-ui.campo etiqueta="Presentación" for="medicamento-presentacion" error="presentacion" class="mt-3"
                        :ayuda="'Opcional. Por ejemplo: Tabletas 500 mg. Hasta ' . $seccion->largoPresentacion() . ' caracteres.'">
                <input type="text" id="medicamento-presentacion" name="presentacion" class="form-control"
                       maxlength="{{ $seccion->largoPresentacion() }}" value="{{ old('presentacion') }}" autocomplete="off">
            </x-ui.campo>

            <x-ui.campo etiqueta="Indicaciones por defecto" for="medicamento-uso" error="uso" class="mt-3"
                        ayuda="Opcional. Es el texto que aparece al agregarlo a un récipe; allí se puede cambiar sin tocar el vademécum.">
                <textarea id="medicamento-uso" name="uso" class="form-control" rows="3"
                          maxlength="{{ $seccion->largoIndicaciones() }}">{{ old('uso') }}</textarea>
            </x-ui.campo>
        </div>

        <div class="dialogo-pie">
            <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cancelar</button>
            <button class="btn btn-primary" id="medicamento-crear">Crear y agregar al récipe</button>
        </div>
    </form>
</x-ui.dialogo>
