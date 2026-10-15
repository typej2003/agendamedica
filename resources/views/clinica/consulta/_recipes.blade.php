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
<div class="consulta-seccion" id="consulta-recipes"
     data-url-medicamentos="{{ $seccion->urlMedicamentos() }}"
     data-url-tratamientos="{{ $seccion->urlTratamientos() }}">

@if ($seccion->esDeLaPareja())
    {{-- El récipe de la pareja (WEB-5.1b): la segunda pestaña de la sección. --}}
    @include('clinica.consulta._recipes-pareja')
@else
@php
    $editor = $seccion->editor();
    $estado = $seccion->estado();
@endphp

@if ($editor === null)
    {{-- ------------------------------------------------------------------------------------ La lista --}}
    {{-- Las dos pestañas de la sección (WEB-5.1b): los récipes del paciente y el de la pareja. --}}
    <x-ui.chips class="mb-3" :opciones="$seccion->pestanas()" etiqueta="Récipes de la consulta" />

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

        {{-- Lo que sale al pie de la hoja (WEB-5.2): la próxima cita que el paciente ya tiene agendada y el atajo
             para hacerla. «Hacer cita» abre el formulario de la agenda con el paciente elegido y vuelve acá. --}}
        @include('clinica.consulta._recipes-cita', ['seccion' => $seccion])

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
                                {{-- Imprimir (WEB-5.2): abre el diálogo con las opciones de la hoja y sigue a la vista
                                     previa. Un récipe sin medicamentos no se imprime. --}}
                                @if ($seccion->puedeImprimir() && ! $receta['vacio'])
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-imprimir-recipe
                                            data-siguiente="{{ $receta['urlImprimir'] }}" data-titulo="{{ $receta['titulo'] }}">
                                        <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
                                    </button>
                                @endif

                                {{-- El QR de verificación (WEB-5.6): su estado y anularlo o reactivarlo. --}}
                                @if ($receta['verificacion'])
                                    @include('clinica.consulta._recipes-verificacion', ['v' => $receta['verificacion']])
                                @endif

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

    {{-- El diálogo «Imprimir récipe» (WEB-5.2): uno solo para toda la lista; el botón de cada récipe le dice a qué
         vista previa seguir. --}}
    @if ($seccion->puedeImprimir())
        <x-ui.dialogo id="dialogo-imprimir-recipe" titulo="Imprimir récipe" lateral>
            @include('clinica.imprimir._opciones', ['dialogo' => $seccion->dialogoDeImpresion()])
        </x-ui.dialogo>
    @endif
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

    {{-- La plantilla de la fila, las herramientas para llenar el récipe y el alta del medicamento que falta son
         las mismas del récipe de la pareja: viven en un parcial. --}}
    @include('clinica.consulta._recipes-herramientas')
@endif
@endif
</div>
