{{-- **La columna derecha del editor de récipes** (A-04, como el escritorio): el buscador del vademécum del médico y
     de sus tratamientos **con su lista siempre a la vista**, y el alta rápida del medicamento que falta.

     Va en el slot `derecha` de `<x-ui.dos-listas>`: la grilla, su modo compacto por `@container` y el apilado del
     celular son los mismos de motivos, diagnósticos, antecedentes (A-06), laboratorio y estudios.

     Los resultados van **en línea**, debajo del buscador (no son un desplegable flotante) y cada fila mide **una
     sola línea**: el nombre —comercial/presentación— con el texto completo en el `title`, el principio activo (o los
     medicamentos del tratamiento) como dato secundario en un chip y «Agregar» a la derecha, que es una sola acción
     directa (la pauta de `.acciones-fila`: sin menú de una opción). `consulta-recipes.js` los pinta clonando
     `#recipe-resultado-plantilla` y deja la lista cargada al abrir el editor.

     Lo que eligen entra al récipe **en edición** y se guarda con su botón: nada de acá escribe en la base, y el
     alta rápida es lo único que necesita conexión. Recibe `$seccion` (`ComparteElEditorDeRecipes`). --}}
<div id="recipe-herramientas" data-recipe-buscador>
    {{-- El modo «Tratamientos» sólo existe donde se arma un récipe: un tratamiento del catálogo (WEB-8.1a) no se
         arma con otros tratamientos. --}}
    @if ($seccion->ofreceTratamientos())
        <div class="btn-group mb-3" role="group" aria-label="Qué buscar">
            <button type="button" class="btn btn-outline-secondary active" data-recipe-modo="medicamentos" aria-pressed="true">
                <i class="bi bi-capsule" aria-hidden="true"></i> Medicamentos
            </button>
            <button type="button" class="btn btn-outline-secondary" data-recipe-modo="tratamientos" aria-pressed="false">
                <i class="bi bi-collection" aria-hidden="true"></i> Tratamientos
            </button>
        </div>
    @endif

    <div id="recipe-busqueda">
        <label class="form-label" for="recipe-texto" id="recipe-etiqueta">Busca en tu vademécum</label>

        <div class="buscador-campo buscador-vivo">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" id="recipe-texto" class="form-control form-control-sm" autocomplete="off"
                   placeholder="{{ $seccion->placeholderMedicamentos() }}"
                   data-placeholder-medicamentos="{{ $seccion->placeholderMedicamentos() }}"
                   data-placeholder-tratamientos="{{ $seccion->placeholderTratamientos() }}"
                   aria-controls="recipe-resultados">
        </div>

        {{-- La lista: el script la llena y la deja a la vista (con la sección angosta scrollea dentro de su alto). --}}
        <div class="orden-resultados" id="recipe-resultados" aria-label="Resultados de la búsqueda" aria-live="polite" hidden></div>
    </div>

    <p class="form-text mt-2 mb-0" id="recipe-ayuda"
       data-ayuda-medicamentos="{{ $seccion->ayudaDelBuscador() }}"
       data-ayuda-tratamientos="{{ $seccion->ayudaDeTratamientos() }}">{{ $seccion->ayudaDelBuscador() }}</p>

    {{-- Los resultados: el script clona estas filas (el marcado es de la vista, no del script). Una sola línea, como
         la fila del catálogo de una orden (WEB-5.4). --}}
    <template id="recipe-resultado-plantilla">
        <button type="button" class="resultado-paciente resultado-recipe dos-listas-fila orden-resultado">
            <span class="orden-resultado-datos">
                <strong class="texto-una-linea" data-resultado-nombre></strong>
                <span class="chip" data-resultado-detalle></span>
            </span>
            <span class="btn btn-sm btn-outline-primary" data-resultado-accion>
                <i class="bi bi-plus-lg" aria-hidden="true"></i> <span data-resultado-texto>Agregar</span>
            </span>
        </button>
    </template>

    {{-- El alta del medicamento que falta: es el botón *Agregar* del escritorio (`w_nuevo_vademecum`). El aviso de
         conexión va acá, donde se decide crearlo: es lo único de la sección que no funciona sin red. --}}
    @if ($seccion->ofreceCrearMedicamento())
        <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="recipe-nuevo-medicamento">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Crear un medicamento nuevo
        </button>
        <p class="form-text mb-0 mt-1">
            <i class="bi bi-wifi" aria-hidden="true"></i> {{ $seccion->avisoDeConexion() }}
        </p>
    @endif
</div>
