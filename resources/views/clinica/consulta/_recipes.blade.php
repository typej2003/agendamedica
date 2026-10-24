{{-- Los récipes de la consulta (WEB-5.1, ítem 10 del panel del legado).

     Dos modos en la misma sección:

       - la **lista** de los récipes de esta consulta (la consulta puede tener varios, agrupados por
         `nrohistoria` + `nroconsulta` + `recipe`), con «Editar» y «Eliminar»;
       - el **editor** de uno (`?recipe=nuevo` o `?recipe=2`): a la izquierda el récipe (la fecha de emisión, los
         medicamentos con su cantidad e indicaciones y el botón de guardar) y a la derecha el buscador del vademécum
         y de los tratamientos del médico **con su lista siempre a la vista**, más el alta del medicamento que falta
         (A-04, la misma grilla de `<x-ui.dos-listas>` que las otras secciones de dos columnas).

     El editor es de **guardado explícito** y trabaja sobre el récipe entero: lo que se agrega o se quita
     viaja en un solo `POST`. Quien arma las filas, busca y avisa de «cambios sin guardar» es
     `consulta-recipes.js`, desde los `data-*` de acá; la vista no lleva JS ni estilos. --}}
{{-- El buscador del editor es la columna derecha (una lista **en línea**, no un desplegable): con
     `data-recipe-lista-en-linea` `consulta-recipes.js` la deja cargada al abrir el editor. El catálogo de
     tratamientos usa el mismo script sin este atributo, con su buscador flotante. --}}
<div class="consulta-seccion" id="consulta-recipes"
     data-url-medicamentos="{{ $seccion->urlMedicamentos() }}"
     data-url-tratamientos="{{ $seccion->urlTratamientos() }}"
     data-recipe-lista-en-linea="1">

@if ($seccion->esDeLaPareja())
    {{-- El récipe de la pareja (WEB-5.1b): la segunda pestaña de la sección. --}}
    @include('clinica.consulta._recipes-pareja')
@else
@php
    $editor = $seccion->editor();
    $estado = $seccion->estado();
@endphp

{{-- Las dos pestañas de la sección (WEB-5.1b): los récipes del paciente y el de la pareja. Se ven en los dos
     modos, porque el editor es la puerta de entrada (O-28). --}}
@if (count($seccion->pestanas()) > 1)
    <x-ui.chips class="mb-3" :opciones="$seccion->pestanas()" etiqueta="Récipes de la consulta" />
@endif

@if ($editor === null)
    {{-- ------------------------------------------------------------------------------------ La lista --}}
    {{-- La lista de los récipes de la consulta (`?seccion=recipes&lista=1`): sirve para ver qué récipes tiene,
         imprimir cada uno y borrar. Ya no es la puerta de entrada (O-28): se llega desde el editor. --}}

    <x-ui.tarjeta titulo="Récipes de esta consulta" icono="bi-prescription2">
        <x-slot name="acciones">
            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']" :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
            <a class="btn btn-sm btn-outline-secondary" href="{{ $seccion->urlEntrada() }}">
                <i class="bi bi-pencil-square" aria-hidden="true"></i> Volver al editor
            </a>
        </x-slot>

        {{-- Lo que sale al pie de la hoja (WEB-5.2): la próxima cita que el paciente ya tiene agendada y el atajo
             para hacerla. «Hacer cita» abre el formulario de la agenda con el paciente elegido y vuelve acá. --}}
        @include('clinica.consulta._recipes-cita', ['seccion' => $seccion])

        @if ($seccion->estaVacia())
            <x-ui.vacio icono="bi-prescription2" titulo="Esta consulta todavía no tiene récipes">
                <x-slot name="acciones">
                    <a class="btn btn-primary" href="{{ $seccion->urlEntrada() }}">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> Abrir el editor
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
    <button type="button" class="btn btn-sm btn-outline-secondary" data-visualizar="{{ $receta['urlImprimir'] }}" data-titulo="Vista previa del récipe">
        <i class="bi bi-eye" aria-hidden="true"></i> Visualizar
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
    {{-- **Dos columnas** (A-04, como el escritorio): a la izquierda el récipe —la fecha de emisión, los
         medicamentos con su cantidad e indicaciones y el botón de guardar— y a la derecha el buscador con su lista
         (el vademécum y los tratamientos del médico) **siempre a la vista**, con el alta rápida ahí mismo. Es la
         misma grilla de `<x-ui.dos-listas>` que motivos, diagnósticos, antecedentes (A-06), laboratorio y estudios:
         dos columnas a 1366, 1024 y 768, apiladas recién a 375 (lo decide el ancho de la sección, no el de la
         pantalla). --}}
    @php $contador = $seccion->estadoDelEditor(); @endphp

    <x-ui.dos-listas :titulo-izquierda="$editor['titulo']" titulo-derecha="Tu vademécum y tus tratamientos">
        <x-slot name="izquierda">
            {{-- **El selector de récipes** (O-28): las pestañas de los que la consulta ya tiene y «+ Nuevo», el
                 mismo patrón de las pestañas de la sección. «+ Nuevo» abre el editor vacío y **no crea la fila**
                 hasta que se guarda; a la lista (imprimir y eliminar) se llega por su enlace. --}}
            <div class="recipe-pestanas-barra mb-3">
                <x-ui.chips :opciones="$seccion->pestanasDeLosRecipes()" etiqueta="Récipes de esta consulta" />
                <a class="btn btn-sm btn-link" href="{{ $seccion->urlLista() }}">
                    <i class="bi bi-list-ul" aria-hidden="true"></i> Ver todos
                </a>
            </div>

            {{-- El contador vivo de medicamentos: `consulta-recipes.js` lo mantiene al agregar y quitar. --}}
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <x-ui.estado id="recipe-contador" :tono="$contador['tono']" :icono="$contador['icono']">{{ $contador['texto'] }}</x-ui.estado>
                {{-- «Anterior» (WEB-11.4): pone en este récipe los medicamentos del último récipe de la consulta anterior. --}}
                <x-ui.anterior :anterior="$vm->anterior()" />
            </div>

            <form method="POST" action="{{ $editor['urlGuardar'] }}" id="recipe-form" data-consulta-recipe
                  data-aviso="{{ $seccion->avisoSinGuardar() }}" data-siguiente-clave="{{ $seccion->siguienteClave() }}">
                @csrf
                {{-- Marca de que el formulario es el del editor: si el POST falla, el editor vuelve con lo que había
                     (incluso sin medicamentos) en vez de releer la base. --}}
                <input type="hidden" name="editor" value="1">

                <x-ui.campo etiqueta="Fecha de emisión" for="recipe-fecha" class="mb-3"
                            :ayuda="'El récipe vence a los ' . $editor['vigencia'] . ' de la emisión.'">
                    <input type="date" id="recipe-fecha" name="fecha" class="form-control" value="{{ $editor['fecha'] }}">
                </x-ui.campo>

                <ul class="recipe-editor" id="recipe-items">
                    @foreach ($editor['filas'] as $fila)
                        @include('clinica.consulta._recipes-fila', ['fila' => $fila])
                    @endforeach
                </ul>

                <div id="recipe-vacio" @if ($editor['filas'] !== []) hidden @endif>
                    <x-ui.vacio icono="bi-capsule" titulo="Todavía no hay medicamentos en este récipe"></x-ui.vacio>
                </div>

                {{-- El aviso de "hay cambios sin guardar": `consulta-recipes.js` lo muestra cuando algo del
                     formulario cambió respecto de lo que el servidor pintó. --}}
                <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-0" id="recipe-sin-guardar" hidden>
                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                    <span>Hay cambios sin guardar.</span>
                </p>

                <div class="d-flex flex-wrap justify-content-end gap-2 mt-3">
                    {{-- La lista de la consulta (imprimir cada récipe y eliminar), que ya no es la entrada (O-28). --}}
                    <a class="btn btn-outline-secondary" href="{{ $seccion->urlLista() }}">Ir a la lista</a>
                    {{-- Imprimir, a la vista (2026-10-23): abre el mismo diálogo de opciones que la lista. Imprime lo guardado. --}}
                    @if ((($editor['urlImprimir'] ?? '')) !== '')
                        <button type="button" class="btn btn-outline-primary" data-imprimir-recipe
                                data-siguiente="{{ $editor['urlImprimir'] }}" data-titulo="{{ $editor['titulo'] }}">
                            <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
                        </button>
                    @endif
                    {{-- La única acción primaria del editor: guardar el récipe entero. --}}
                    <button class="btn btn-primary">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar el récipe
                    </button>
                </div>
            </form>
        </x-slot>

        <x-slot name="derecha">
            @include('clinica.consulta._recipes-buscador', ['seccion' => $seccion])
        </x-slot>
    </x-ui.dos-listas>

    {{-- La plantilla de la fila y el alta del medicamento que falta son los mismos del récipe de la pareja y del
         catálogo de tratamientos: viven en sus parciales. --}}
    @include('clinica.consulta._recipes-fila-plantilla')
    @include('clinica.consulta._recipes-alta-medicamento')

    {{-- El diálogo «Imprimir récipe» también en el editor (2026-10-23): el botón «Imprimir» de aquí lo abre. Sin él el botón no hacía nada. --}}
    @if ($seccion->puedeImprimir())
        <x-ui.dialogo id="dialogo-imprimir-recipe" titulo="Imprimir récipe" lateral>
            @include('clinica.imprimir._opciones', ['dialogo' => $seccion->dialogoDeImpresion()])
        </x-ui.dialogo>
    @endif
@endif
@endif
</div>
