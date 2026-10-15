{{-- Los diagnósticos de la consulta (WEB-4.5, ítem 8 del panel del legado).

     **Son de esta consulta**: la fila de `diagnostico_paciente` se llavea por `nrohistoria` + `nroconsulta`
     + `codediagnostico` y la pestaña del escritorio la lee con `dw_8.retrieve(historia, consulta)`
     (`w_consulta_principal.srw:3007`); por eso la tarjeta lo dice.

     El formulario es **uno solo** y hace lo que el escritorio hace en su pestaña: escribe el detalle de lo
     que ya está, agrega lo que se tildó del catálogo y quita lo que el médico sacó de la lista. El
     escritorio también guarda el detalle con el *Guardar* de la ventana (`dw_8.update()`, `:2709`), así que
     la sección es de **guardado explícito**. El aviso de "cambios sin guardar" y el "Quitar" de cada fila
     los resuelve `consulta.js` desde los `data-*`: la vista no lleva JS ni estilos. --}}
@php
    $estado = $seccion->estado();
@endphp

<div class="consulta-seccion" id="consulta-diagnostico">
    {{-- **Dos listas** (WEB-11.3): a la izquierda los diagnósticos de la consulta (con su detalle y el guardado explícito de siempre) y a la
         derecha **todo el catálogo a la vista**, por páginas, donde cada uno se pasa con «Pasar» o con doble clic. «Pasar» deja el
         diagnóstico tildado y se agrega al guardar. --}}
    <x-ui.dos-listas titulo-izquierda="Diagnósticos de esta consulta" titulo-derecha="Tu catálogo de diagnósticos">
    <x-slot name="izquierda">
    {{-- El `action` sale del ViewModel cuando hay pendientes: le agrega `?agregar[]=…` para que el POST no
         los pierda si la página se recargó (los `hidden` de abajo también los mandan, pero una recarga se
         los lleva y la query no). Sin pendientes es el `action` normal. --}}
    <form method="POST" action="{{ $seccion->urlGuardar() }}" id="diagnosticos-form"
          data-consulta-diagnosticos data-aviso="{{ $seccion->avisoSinGuardar() }}">
        @csrf

        {{-- Los diagnósticos que el buscador dejó tildados y todavía no se guardaron: entran al `POST` del
             botón de guardar como `agregar[código]`. El buscador es un `GET` y no puede mandarlos. Lo que ya
             tenía la consulta **no** se manda como `agregar`: se quita con el botón de la fila, que agrega
             su id a `quitar[]` (`consulta.js`). --}}
        @foreach ($seccion->pendientesDelBuscador() as $codigo)
            <input type="hidden" name="agregar[{{ $codigo }}]" value="{{ $codigo }}">
        @endforeach

        <div class="mb-2">
            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']"
                         :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
        </div>
        <div>

            <p class="texto-tenue small mb-3">{{ $seccion->ayuda() }}</p>

            @if ($seccion->estaVacio())
                <x-ui.vacio icono="bi-clipboard2-check" titulo="Esta consulta todavía no tiene diagnósticos">
                    Búscalos en tu catálogo, abajo, y agrégalos. Puedes cargar más de uno y anotarles un detalle.
                </x-ui.vacio>
            @else
                <ul class="diagnosticos-lista">
                    @foreach ($seccion->filas() as $fila)
                        <li class="diagnosticos-item" data-diagnostico-fila="{{ $fila['id'] }}">
                            <div class="diagnosticos-item-datos">
                                <span class="diagnosticos-nombre">{{ $fila['nombre'] }}</span>
                                <span class="texto-tenue small">
                                    <span class="chip">{{ $fila['codigo'] }}</span>
                                    @if ($fila['avisoCatalogo'])
                                        <span class="texto-tenue small"> · {{ $fila['avisoCatalogo'] }}</span>
                                    @endif
                                </span>

                                {{-- El detalle es la única columna editable de la fila (el DataWindow del
                                     escritorio la deja con `edit.limit=100`) y es el texto que el escritorio
                                     muestra en su pestaña: por eso se puede ajustar acá. --}}
                                <label class="form-label visually-hidden" for="diagnostico-detalle-{{ $fila['id'] }}">
                                    Detalle de {{ $fila['nombre'] }}
                                </label>
                                <input type="text" class="form-control form-control-sm"
                                       id="diagnostico-detalle-{{ $fila['id'] }}"
                                       name="filas[{{ $fila['id'] }}][detalle]"
                                       value="{{ $fila['detalle'] }}"
                                       maxlength="{{ $seccion->limiteDetalle() }}"
                                       placeholder="Detalle (lado, grado, cuándo…)">
                            </div>

                            {{-- "Quitar" deja la fila marcada como "se quita al guardar": saca el diagnóstico
                                 **de la consulta** y no toca el catálogo (el `DELETE` del escritorio,
                                 `w_consulta_principal.srw:11785`). Sin guardar no se borra nada: el `hidden`
                                 lo inserta `consulta.js` y lo manda el mismo botón de siempre. --}}
                            <div class="diagnosticos-acciones">
                                <button type="button" class="btn btn-sm btn-outline-danger diagnosticos-quitar"
                                        data-diagnostico-quitar="{{ $fila['id'] }}"
                                        title="Quitar «{{ $fila['nombre'] }}» de esta consulta"
                                        aria-label="Quitar {{ $fila['nombre'] }}">
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                </button>
                                <span class="texto-tenue small diagnosticos-aviso-quitar" hidden>
                                    Se quita al guardar
                                </span>
                                <button type="button" class="btn btn-sm btn-link diagnosticos-restaurar" hidden
                                        data-diagnostico-restaurar>
                                    Volver a dejarlo
                                </button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            {{-- El aviso de "hay cambios sin guardar": `consulta.js` lo muestra cuando algún control del
                 formulario cambió respecto de lo que el servidor pintó. --}}
            <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-0" id="diagnosticos-sin-guardar" hidden>
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span>Hay cambios sin guardar.</span>
            </p>

            <div class="d-flex justify-content-end gap-2 mt-3">
                <a class="btn btn-outline-secondary" href="{{ $seccion->urlVolver() }}">Cancelar</a>
                {{-- La única acción primaria de la sección: guardar lo cargado. --}}
                <button class="btn btn-primary">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar los diagnósticos
                </button>
            </div>
        </div>
    </form>
    </x-slot>

    <x-slot name="derecha">
        <p class="texto-tenue small mb-3">{{ $seccion->ayudaDelBuscador() }}</p>

        @if ($seccion->pendientesDelBuscador() !== [])
            <p class="alert alert-info py-2 d-flex align-items-center gap-2" id="diagnosticos-pendientes">
                <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                <span>{{ $seccion->avisoDePendientes() }}</span>
            </p>
        @endif

        {{-- El filtro del catálogo: un `GET` a esta misma sección (no hay `fetch`: el buscador se recarga
             con el filtro y "Agregar" es un enlace, así la sección entera funciona sin JavaScript). --}}
        <form method="GET" action="{{ $seccion->urlVolver() }}" class="row g-2 align-items-end mb-3" id="diagnostico-buscar-form">
            <input type="hidden" name="seccion" value="diagnostico">

            {{-- Lo que el buscador dejó tildado viaja **también** acá: buscar otra cosa es un `GET` a esta
                 misma sección y sin estos `hidden` se perdería lo pendiente. --}}
            @foreach ($seccion->pendientesDelBuscador() as $codigo)
                <input type="hidden" name="agregar[]" value="{{ $codigo }}">
            @endforeach

            <x-ui.campo etiqueta="Buscar en tu catálogo" for="diagnostico-buscar" class="col-12 col-md-9">
                <input type="text" id="diagnostico-buscar" name="buscar" value="{{ $seccion->buscar() }}"
                       class="form-control" autocomplete="off"
                       placeholder="Hipertensión, amenaza de aborto, control…">
            </x-ui.campo>

            <div class="col-12 col-md-3 d-grid">
                <button class="btn btn-outline-secondary">
                    <i class="bi bi-search" aria-hidden="true"></i> Buscar
                </button>
            </div>
        </form>

        @if ($seccion->resultados() === [])
            @php $vacio = $seccion->sinResultados(); @endphp
            <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">{{ $vacio['texto'] }}</x-ui.vacio>
        @else
            <ul class="dos-listas-catalogo">
                @foreach ($seccion->resultados() as $resultado)
                    <li class="dos-listas-fila" @if (! $resultado['pendiente'] && ! $resultado['yaEsta']) data-pasar @endif>
                        <span class="dos-listas-fila-texto">
                            {{ $resultado['descripcion'] }}
                            <span class="chip">{{ $resultado['codigo'] }}</span>
                        </span>
                        @if ($resultado['pendiente'])
                            <span class="texto-tenue small">
                                <i class="bi bi-check2-circle" aria-hidden="true"></i> Se agrega al guardar
                            </span>
                        @elseif ($resultado['yaEsta'])
                            <span class="texto-tenue small"><i class="bi bi-check2-circle" aria-hidden="true"></i> Ya está en la consulta</span>
                        @else
                            <a class="btn btn-sm btn-outline-primary" data-pasar-boton href="{{ $seccion->urlAgregar($resultado['codigo']) }}">
                                <i class="bi bi-arrow-left" aria-hidden="true"></i> Pasar
                            </a>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($seccion->paginador() && $seccion->paginador()->hasPages())
                <div class="mt-2">{{ $seccion->paginador()->links() }}</div>
            @endif

            <p class="texto-tenue small mt-2 mb-0">Tip: doble clic en un diagnóstico para pasarlo a la consulta.</p>
        @endif

        {{-- El alta del diagnóstico que falta: es el botón *Agregar* del escritorio
             (`w_nuevo_diagnostico`). El código lo da el servidor (el correlativo de cuatro dígitos) y el
             nombre se guarda en mayúsculas; el nuevo queda tildado para el botón de guardar. --}}
        <hr class="my-3">

        <p class="texto-tenue small mb-2">{{ $seccion->ayudaDelAlta() }}</p>

        <form method="POST" action="{{ $seccion->urlCrear() }}" class="row g-2 align-items-end" id="diagnostico-crear-form">
            @csrf

            {{-- Lo que el buscador ya dejó tildado viaja con el alta: crear un diagnóstico nuevo navega a la
                 sección y, sin estos `hidden`, lo tildado antes se perdería (los pendientes no viven en la
                 base: son de la pantalla hasta que se guarda). --}}
            @foreach ($seccion->pendientesDelBuscador() as $codigo)
                <input type="hidden" name="agregar[{{ $codigo }}]" value="{{ $codigo }}">
            @endforeach

            <x-ui.campo etiqueta="Nombre del diagnóstico" for="diagnostico-descripcion" class="col-12 col-md-9"
                        :ayuda="'Hasta ' . $seccion->limiteDescripcion() . ' caracteres. Se guarda en mayúsculas.'">
                <input type="text" id="diagnostico-descripcion" name="descripcion" class="form-control"
                       maxlength="{{ $seccion->limiteDescripcion() }}" value="{{ old('descripcion') }}" autocomplete="off">
            </x-ui.campo>

            <div class="col-12 col-md-3 d-grid">
                <button class="btn btn-outline-secondary">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Crear
                </button>
            </div>
        </form>
    </x-slot>
    </x-ui.dos-listas>
</div>
