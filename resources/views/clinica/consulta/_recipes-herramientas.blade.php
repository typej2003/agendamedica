{{-- La caja de herramientas del editor de **tratamientos del catálogo** (WEB-8.1a): el buscador del vademécum y el
     alta del medicamento que falta. Es el mismo editor del récipe (WEB-5.1), con los mismos ids y el mismo
     `consulta-recipes.js`; lo que cambia es que el catálogo no se arma con otros tratamientos (sin modo
     «Tratamientos»), el medicamento que falta se crea en su propia pantalla y las filas se ordenan.

     Los récipes de la consulta **no** usan este parcial desde A-04: su buscador es la columna derecha de
     `<x-ui.dos-listas>` (`_recipes-buscador`), siempre a la vista. Acá queda el buscador flotante de una sola
     columna, que es el del catálogo.

     Recibe `$seccion` (`ComparteElEditorDeRecipes`). Se incluye **dentro** de `#consulta-recipes`, que lleva los
     `data-url-*` del buscador. --}}
@include('clinica.consulta._recipes-fila-plantilla')

{{-- Las herramientas para llenar el tratamiento: van **fuera** del formulario (no mandan nada por sí solas; lo que
     eligen entra al editor y se guarda con su botón). --}}
<x-ui.tarjeta titulo="Agregar medicamentos" icono="bi-search" class="mt-3" id="recipe-herramientas">
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

    @if ($seccion->enlaceDeMedicamentos())
        {{-- En el catálogo el medicamento que falta se crea en su pantalla (el vademécum). --}}
        <p class="form-text mb-0 mt-2">
            ¿No está el medicamento? Créalo primero en <a href="{{ $seccion->enlaceDeMedicamentos() }}" target="_blank" rel="noopener">Medicamentos</a>
            (se abre en otra pestaña, así no pierdes lo que llevas).
        </p>
    @endif
</x-ui.tarjeta>

@include('clinica.consulta._recipes-alta-medicamento')
