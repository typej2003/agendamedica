{{-- El alta del medicamento que falta (el botón *Agregar* del escritorio, `w_nuevo_vademecum`): un diálogo con su
     formulario y el aviso de conexión, porque es lo único del editor que necesita red.

     Es el mismo diálogo en los tres editores que usan `consulta-recipes.js` —el récipe de la consulta (A-04), el de
     la pareja y el del catálogo de tratamientos (WEB-8.1a)—, que lo abren desde su propio botón. El script lo manda
     por `fetch` para no perder lo que hay en el editor. Recibe `$seccion` (`ComparteElEditorDeRecipes`). --}}
@if ($seccion->ofreceCrearMedicamento())
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
@endif
