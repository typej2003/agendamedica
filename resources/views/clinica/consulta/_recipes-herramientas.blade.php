{{-- El buscador del editor de **tratamientos del catálogo** (WEB-8.1a): **una sola línea** dentro de la tarjeta del
     tratamiento, entre el nombre y la lista de medicamentos. A la derecha de la barra va «Nuevo medicamento», que abre
     el alta del que falta en un diálogo lateral (`_recipes-alta-medicamento`) con lo escrito en la barra ya puesto
     como nombre. Los resultados son un desplegable que flota bajo la barra; elegir uno lo agrega a la lista.

     Es el mismo editor del récipe (WEB-5.1): los mismos ids y el mismo `consulta-recipes.js`; lo que cambia es que el
     catálogo no se arma con otros tratamientos (sin modo «Tratamientos») y las filas se ordenan.

     Los récipes de la consulta **no** usan este parcial desde A-04 (su buscador es `_recipes-buscador`). Recibe
     `$seccion` (`ComparteElEditorDeRecipes`) y va **dentro** de `#consulta-recipes`, que lleva los `data-url-*`. El
     buscador no manda nada por sí solo: lo que se elige entra a la lista y se guarda con el botón del tratamiento. --}}
<div class="mb-3">
    <label class="form-label" for="recipe-texto" id="recipe-etiqueta">Agregar medicamentos</label>

    <div class="d-flex gap-2 align-items-start">
        <div class="position-relative flex-grow-1" id="recipe-busqueda">
            <div class="buscador-campo buscador-vivo">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" id="recipe-texto" class="form-control" autocomplete="off"
                       placeholder="{{ $seccion->placeholderMedicamentos() }}"
                       data-placeholder-medicamentos="{{ $seccion->placeholderMedicamentos() }}"
                       data-placeholder-tratamientos="{{ $seccion->placeholderTratamientos() }}"
                       aria-controls="recipe-resultados">
            </div>

            <div class="list-group resultados-flotantes" id="recipe-resultados" aria-label="Resultados de la búsqueda" aria-live="polite" hidden></div>
        </div>

        <button type="button" class="btn btn-outline-primary flex-shrink-0" id="recipe-nuevo-medicamento">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo medicamento
        </button>
    </div>

    <p class="form-text mt-1 mb-0" id="recipe-ayuda"
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
</div>
