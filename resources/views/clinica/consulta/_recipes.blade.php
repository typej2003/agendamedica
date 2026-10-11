{{-- Los récipes de la consulta (WEB-5.1, ítem 10 del panel del legado).

     Dos modos en la misma sección:

       - la **lista** de los récipes de esta consulta (la consulta puede tener varios, agrupados por
         `nrohistoria` + `nroconsulta` + `recipe`), con «Editar» y «Eliminar»;
       - el **editor** de uno (`?recipe=nuevo` o `?recipe=2`): la fecha de emisión, los medicamentos con su
         cantidad e indicaciones y, debajo, las herramientas para llenarlo (el vademécum del médico, sus
         tratamientos y el alta del medicamento que falta).

     El editor es de **guardado explícito** y trabaja sobre el récipe entero: lo que se agrega o se quita
     viaja en un solo `POST`. Quien arma las filas, busca y avisa de «cambios sin guardar» es
     `consulta-recipes.js`, desde los `data-*` de acá; la vista no lleva JS ni estilos. --}}
@php
    $editor = $seccion->editor();
    $estado = $seccion->estado();
@endphp

<div class="consulta-seccion" id="consulta-recipes"
     data-url-medicamentos="{{ $seccion->urlMedicamentos() }}"
     data-url-tratamientos="{{ $seccion->urlTratamientos() }}">

@if ($editor === null)
    {{-- ------------------------------------------------------------------------------------ La lista --}}
    <x-ui.tarjeta titulo="Récipes de esta consulta" icono="bi-prescription2">
        <x-slot name="acciones">
            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']" :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
            @unless ($seccion->estaVacia())
                {{-- La acción primaria de la sección. Con la lista vacía es la del estado vacío (una sola). --}}
                <a class="btn btn-sm btn-primary" href="{{ $seccion->urlNuevo() }}">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Nuevo récipe
                </a>
            @endunless
        </x-slot>

        <p class="texto-tenue small mb-3">{{ $seccion->ayuda() }}</p>

        @if ($seccion->estaVacia())
            <x-ui.vacio icono="bi-prescription2" titulo="Esta consulta todavía no tiene récipes">
                Crea el primero: busca los medicamentos en tu vademécum o carga un tratamiento completo.
                <x-slot name="acciones">
                    <a class="btn btn-primary" href="{{ $seccion->urlNuevo() }}">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> Crear el primer récipe
                    </a>
                </x-slot>
            </x-ui.vacio>
        @else
            <div class="recipe-lista">
                @foreach ($seccion->recetas() as $receta)
                    <article class="recipe-bloque" id="recipe-{{ $receta['numero'] }}">
                        <header class="recipe-cabecera">
                            <div class="recipe-cabecera-texto">
                                <h3 class="recipe-titulo">{{ $receta['titulo'] }}</h3>
                                <p class="texto-tenue small mb-0">
                                    Emitido el {{ $receta['emision'] }} · vigencia de {{ $receta['vigencia'] }} · {{ $receta['resumen'] }}
                                </p>
                            </div>

                            <div class="recipe-acciones">
                                <a class="btn btn-sm btn-outline-secondary" href="{{ $receta['urlEditar'] }}">
                                    <i class="bi bi-pencil" aria-hidden="true"></i> Editar
                                </a>

                                {{-- Eliminar es lo destructivo y va aparte, en el menú, con confirmación que
                                     nombra lo que se borra. Siempre se puede: no hay plazo para corregir. --}}
                                <form method="POST" action="{{ $receta['urlEliminar'] }}" data-confirmar="{{ $receta['avisoEliminar'] }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.menu :etiqueta="'Más acciones del ' . mb_strtolower($receta['titulo'])">
                                        <button class="menu-item menu-item-peligro">
                                            <i class="bi bi-trash" aria-hidden="true"></i> Eliminar el récipe
                                        </button>
                                    </x-ui.menu>
                                </form>
                            </div>
                        </header>

                        @if ($receta['vacio'])
                            <p class="texto-tenue mb-0">Este récipe no tiene medicamentos. Ábrelo para agregarlos o elimínalo.</p>
                        @else
                            <ol class="recipe-medicamentos">
                                @foreach ($receta['items'] as $item)
                                    <li class="recipe-medicamento">
                                        <span class="recipe-medicamento-cantidad" title="Cantidad">{{ $item['cantidad'] }}</span>
                                        <div class="recipe-medicamento-texto">
                                            <span class="recipe-nombre">{{ $item['nombre'] }}</span>
                                            @if ($item['generico'])
                                                <span class="texto-tenue small"> · {{ $item['generico'] }}</span>
                                            @endif
                                            @if ($item['avisoVademecum'])
                                                <span class="texto-tenue small"> · {{ $item['avisoVademecum'] }}</span>
                                            @endif
                                            <span class="recipe-indicaciones-texto">{{ $item['indicaciones'] ?? '—' }}</span>
                                        </div>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </x-ui.tarjeta>
@else
    {{-- ---------------------------------------------------------------------------------- El editor --}}
    @php $contador = $seccion->estadoDelEditor(); @endphp

    <form method="POST" action="{{ $editor['urlGuardar'] }}" id="recipe-form" data-consulta-recipe
          data-aviso="{{ $seccion->avisoSinGuardar() }}" data-siguiente-clave="{{ $seccion->siguienteClave() }}">
        @csrf
        {{-- Marca de que el formulario es el del editor: si el POST falla, el editor vuelve con lo que había
             (incluso sin medicamentos) en vez de releer la base. --}}
        <input type="hidden" name="editor" value="1">

        <x-ui.tarjeta :titulo="$editor['titulo']" icono="bi-prescription2">
            <x-slot name="acciones">
                <x-ui.estado id="recipe-contador" :tono="$contador['tono']" :icono="$contador['icono']">{{ $contador['texto'] }}</x-ui.estado>
            </x-slot>

            <div class="row g-3 mb-3">
                <x-ui.campo etiqueta="Fecha de emisión" for="recipe-fecha" class="col-12 col-sm-6 col-md-4"
                            :ayuda="'El récipe vence a los ' . $editor['vigencia'] . ' de la emisión.'">
                    <input type="date" id="recipe-fecha" name="fecha" class="form-control" value="{{ $editor['fecha'] }}">
                </x-ui.campo>
            </div>

            <ul class="recipe-editor" id="recipe-items">
                @foreach ($editor['filas'] as $fila)
                    @include('clinica.consulta._recipes-fila', ['fila' => $fila])
                @endforeach
            </ul>

            <div id="recipe-vacio" @if ($editor['filas'] !== []) hidden @endif>
                <x-ui.vacio icono="bi-capsule" titulo="Todavía no hay medicamentos en este récipe">
                    Búscalos abajo en tu vademécum o carga un tratamiento. Puedes agregar los que necesites.
                </x-ui.vacio>
            </div>

            {{-- El aviso de "hay cambios sin guardar": `consulta-recipes.js` lo muestra cuando algo del
                 formulario cambió respecto de lo que el servidor pintó. --}}
            <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-0" id="recipe-sin-guardar" hidden>
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span>Hay cambios sin guardar.</span>
            </p>

            <div class="d-flex justify-content-end gap-2 mt-3">
                <a class="btn btn-outline-secondary" href="{{ $seccion->urlLista() }}">Cancelar</a>
                {{-- La única acción primaria del editor: guardar el récipe entero. --}}
                <button class="btn btn-primary">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar el récipe
                </button>
            </div>
        </x-ui.tarjeta>
    </form>

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
@endif
</div>
