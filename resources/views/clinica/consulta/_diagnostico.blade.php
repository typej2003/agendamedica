{{-- Los diagnósticos de la consulta (WEB-4.5, ítem 8 del panel del legado).

     **Son de esta consulta**: la fila de `diagnostico_paciente` se llavea por `nrohistoria` + `nroconsulta`
     + `codediagnostico` y la pestaña del escritorio la lee con `dw_8.retrieve(historia, consulta)`
     (`w_consulta_principal.srw:3007`); por eso la tarjeta lo dice.

     El formulario es **uno solo** y hace lo que el escritorio hace al guardar su pestaña: escribe el detalle de
     lo que ya está. **«Pasar» y «Quitar» son inmediatos** (O-27, 2026-10-17): cada uno es su propio `POST` y la
     fila aparece o desaparece de la lista sin apretar el botón de guardar. El aviso de "cambios sin guardar"
     del detalle lo resuelve `consulta.js` desde los `data-*`: la vista no lleva JS ni estilos. --}}
@php
    $estado = $seccion->estado();
@endphp

<div class="consulta-seccion" id="consulta-diagnostico">
    {{-- **Dos listas** (WEB-11.3): a la izquierda los diagnósticos de la consulta (con el guardado explícito del detalle) y a la
         derecha **todo el catálogo a la vista**, por páginas, donde cada uno se pasa con «Pasar» o con doble clic. Desde O-27
         «Pasar» escribe en el acto. --}}
    <x-ui.dos-listas titulo-izquierda="Diagnósticos de esta consulta" titulo-derecha="Tu catálogo de diagnósticos">
    <x-slot name="izquierda">
    @if ($vm->tieneAnterior())
        <div class="mb-2"><x-ui.anterior :url="$vm->urlAnterior('diagnosticos')" que="los diagnósticos" /></div>
    @endif
    <form method="POST" action="{{ $seccion->urlGuardar() }}" id="diagnosticos-form"
          data-consulta-diagnosticos data-aviso="{{ $seccion->avisoSinGuardar() }}">
        @csrf

        <div class="mb-2">
            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']"
                         :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
        </div>
        <div>

            <p class="texto-tenue small mb-3">{{ $seccion->ayuda() }}</p>

            @if ($seccion->estaVacio())
                <x-ui.vacio icono="bi-clipboard2-check" titulo="Esta consulta todavía no tiene diagnósticos">
                    Pásalos desde tu catálogo, a la derecha. Puedes cargar más de uno.
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

                                {{-- Sin detalle por diagnóstico (WEB-11.18): como en el escritorio, cada diagnóstico es el nombre del catálogo, sin retocar. --}}
                            </div>

                            {{-- "Quitar" es **inmediato** (O-27): su propio `POST` saca el diagnóstico
                                 **de la consulta** en el acto y no toca el catálogo. El formulario vive al
                                 final de esta columna (no se anidan formularios) y el botón lo referencia
                                 con `form=`. --}}
                            <div class="diagnosticos-acciones">
                                <button type="submit" class="btn btn-sm btn-outline-danger diagnosticos-quitar"
                                        form="diagnostico-quitar-{{ $fila['id'] }}"
                                        data-diagnostico-quitar="{{ $fila['id'] }}"
                                        title="Quitar «{{ $fila['nombre'] }}» de esta consulta"
                                        aria-label="Quitar {{ $fila['nombre'] }}">
                                    <i class="bi bi-trash" aria-hidden="true"></i>
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

    {{-- Los formularios del "Quitar" inmediato (O-27): uno por fila, fuera del formulario de guardado —los
         formularios no se anidan— y referenciados por el botón de su fila con `form=`. Conservan la búsqueda
         para que la vuelta deje el catálogo como estaba. --}}
    @foreach ($seccion->filas() as $fila)
        <form method="POST" action="{{ $seccion->urlQuitar() }}" id="diagnostico-quitar-{{ $fila['id'] }}">
            @csrf
            <input type="hidden" name="id" value="{{ $fila['id'] }}">
            @if ($seccion->buscar() !== '')
                <input type="hidden" name="buscar" value="{{ $seccion->buscar() }}">
            @endif
        </form>
    @endforeach
    </x-slot>

    <x-slot name="derecha">
        <p class="texto-tenue small mb-3">{{ $seccion->ayudaDelBuscador() }}</p>

        {{-- El filtro del catálogo: un `GET` a esta misma sección (no hay `fetch`: el buscador se recarga
             con el filtro y "Pasar" es un `POST`, así la sección entera funciona sin JavaScript). --}}
        <form method="GET" action="{{ $seccion->urlVolver() }}" class="row g-2 align-items-end mb-3" id="diagnostico-buscar-form"
              data-buscar-auto data-buscar-filas=".dos-listas-fila-texto">
            <input type="hidden" name="seccion" value="diagnostico">

            <x-ui.campo etiqueta="Buscar en tu catálogo" for="diagnostico-buscar" class="col-12 col-md-9">
                <input type="search" id="diagnostico-buscar" name="buscar" value="{{ $seccion->buscar() }}"
                       class="form-control" autocomplete="off"
                       placeholder="Hipertensión, amenaza de aborto, control…">
            </x-ui.campo>

            <div class="col-12 col-md-3 d-grid">
                <button class="btn btn-outline-secondary" data-buscar-enviar>
                    <i class="bi bi-search" aria-hidden="true"></i> Buscar
                </button>
            </div>
            <div class="col-12">
                <a class="btn btn-sm btn-link" href="{{ $seccion->urlVolver() }}" data-buscar-limpiar @if ($seccion->buscar() === '') hidden @endif>Limpiar</a>
                <button type="button" class="btn btn-sm btn-outline-primary" data-buscar-agregar hidden data-agregar-campo="diagnostico-descripcion">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Agregar «<span data-buscar-agregar-texto></span>»
                </button>
            </div>
        </form>

        <div data-buscar-resultados>
        @if ($seccion->resultados() === [])
            @php $vacio = $seccion->sinResultados(); @endphp
            <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">{{ $vacio['texto'] }}</x-ui.vacio>
        @else
            <ul class="dos-listas-catalogo">
                @foreach ($seccion->resultados() as $resultado)
                    <li class="dos-listas-fila" @if (! $resultado['yaEsta']) data-pasar @endif>
                        <span class="dos-listas-fila-texto">
                            {{ $resultado['descripcion'] }}
                            <span class="chip">{{ $resultado['codigo'] }}</span>
                        </span>
                        @if ($resultado['yaEsta'])
                            <span class="texto-tenue small"><i class="bi bi-check2-circle" aria-hidden="true"></i> Ya está en la consulta</span>
                        @else
                            {{-- «Pasar» es un `POST` **inmediato** (O-27): el diagnóstico entra a la consulta en
                                 el acto y la fila aparece en la lista de la izquierda. Sin JS sigue
                                 funcionando: es un formulario común. --}}
                            <form method="POST" action="{{ $seccion->urlAgregar() }}" class="d-inline">
                                @csrf
                                <input type="hidden" name="codigo" value="{{ $resultado['codigo'] }}">
                                @if ($seccion->buscar() !== '')
                                    <input type="hidden" name="buscar" value="{{ $seccion->buscar() }}">
                                @endif
                                <button class="btn btn-sm btn-outline-primary" data-pasar-boton
                                        title="Pasar a la consulta" aria-label="Pasar a la consulta">
                                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                                    <span class="dos-listas-boton-texto">Pasar</span>
                                </button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($seccion->paginador() && $seccion->paginador()->hasPages())
                <div class="mt-2">{{ $seccion->paginador()->links() }}</div>
            @endif

            <p class="texto-tenue small mt-2 mb-0">Tip: doble clic en un diagnóstico para pasarlo a la consulta.</p>
        @endif
        </div>

        {{-- El alta del diagnóstico que falta: es el botón *Agregar* del escritorio
             (`w_nuevo_diagnostico`). El código lo da el servidor (el correlativo de cuatro dígitos) y el
             nombre se guarda en mayúsculas; desde O-27 el nuevo queda **agregado a la consulta** en el acto. --}}
        <hr class="my-3">

        <p class="texto-tenue small mb-2">{{ $seccion->ayudaDelAlta() }}</p>

        <form method="POST" action="{{ $seccion->urlCrear() }}" class="row g-2 align-items-end" id="diagnostico-crear-form">
            @csrf

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
