{{-- **La orden de laboratorio o la solicitud de estudios** (WEB-5.4) pintadas como *Motivos de consulta* (O-29): a la
     izquierda lo que la consulta ya pidió —cada fila con su «Quitar», que **escribe al instante**— y a la derecha **el
     catálogo del médico siempre a la vista**, con su buscador, su filtro por tipo y su «Pasar» por fila, que también
     escribe al instante (rastro `source = web` y last-write-wins, por la Action de la orden).

     Lo único que se sigue guardando con el botón es el **resumen clínico** (y el botón de eliminar la orden entera). Eso
     es lo que dice la ayuda de la sección, porque es lo que cambió: antes la orden entera se guardaba con el botón.

     La usan `_solicitudes-laboratorio` y `_solicitudes-estudios` (es la misma pantalla con otros textos). No lleva JS ni
     estilos: todo son formularios, así que funciona sin JavaScript. --}}
@php
    $estado = $vm->estado();
    $vacio = $vm->vacio();
    $sinCatalogo = $vm->sinCatalogo();
@endphp

<div id="orden-catalogo">
    @if ($vm->urlPareja() !== '')
        <p class="mb-3">
            <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlPareja() }}">
                <i class="bi bi-people" aria-hidden="true"></i> Pareja
            </a>
            <span class="texto-tenue small ms-2">Pedirle exámenes a la pareja del paciente.</span>
        </p>
    @endif

    <x-ui.dos-listas :titulo-izquierda="$vm->titulo()" titulo-derecha="Tu catálogo" :resumen="$vm->cuenta()">
        <x-slot name="izquierda">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']" :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
                @if ($vm->esReal())
                    {{-- Imprimir guarda el resumen y después imprime la hoja (O-16). --}}
                    <x-ui.imprimir-guardando :url="$vm->urlImprimir()" form="orden-resumen" documento="{{ $vm->documento() }}" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
                    </x-ui.imprimir-guardando>
                @endif
            </div>

            {{-- Una línea de ayuda como máximo (§10.1). --}}
            <p class="texto-tenue small mb-3">
                Pasa {{ $vm->cosas() }} de tu catálogo y quítalos cuando quieras: cada clic se guarda. El resumen clínico, con su botón.
            </p>

            @if ($vm->total() === 0)
                <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">{{ $vacio['texto'] }}</x-ui.vacio>
            @else
                <ul class="orden-lista {{ $vm->numerada() ? 'orden-lista-numerada' : '' }}" id="orden-items" aria-label="{{ ucfirst($vm->cosas()) }} de la orden">
                    @foreach ($vm->items() as $item)
                        @include('clinica.consulta._orden-catalogo-fila', ['item' => $item])
                    @endforeach
                </ul>
            @endif

            {{-- El resumen clínico: un formulario, con su botón. Es lo único que queda por guardar acá. --}}
            <form method="POST" action="{{ $vm->urlResumen() }}" id="orden-resumen" data-orden-resumen
                  data-aviso="{{ $vm->avisoSinGuardar() }}">
                @csrf

                <x-ui.campo :etiqueta="$vm->etiquetaDelResumen()" for="orden-observacion" error="observacion" class="mt-3 mb-3"
                            :ayuda="$vm->ayudaDelResumen()">
                    <textarea name="observacion" id="orden-observacion" class="form-control" rows="3"
                              maxlength="{{ $vm->largoDeLaObservacion() }}">{{ $vm->observacion() }}</textarea>
                </x-ui.campo>

                <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mb-3" data-orden-aviso hidden>
                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                    <span>Hay cambios sin guardar en el resumen.</span>
                </p>

                <div class="d-flex justify-content-end gap-2">
                    <button class="btn btn-primary">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i> {{ $vm->textoDeGuardar() }}
                    </button>
                </div>
            </form>

            @if ($vm->puedeEliminar())
                <form method="POST" action="{{ $vm->urlEliminar() }}" class="mt-3" data-confirmar="{{ $vm->avisoEliminar() }}">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-sm btn-outline-danger">
                        <i class="bi bi-trash" aria-hidden="true"></i> {{ $vm->textoDeEliminar() }}
                    </button>
                </form>
            @endif
        </x-slot>

        <x-slot name="derecha">
            @if (! $vm->hayCatalogo())
                <x-ui.vacio :icono="$sinCatalogo['icono']" :titulo="$sinCatalogo['titulo']">
                    {{ $sinCatalogo['texto'] }}
                    <x-slot name="acciones">
                        <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlAdministrar() }}" target="_blank" rel="noopener">
                            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Abrir el catálogo de exámenes
                        </a>
                    </x-slot>
                </x-ui.vacio>
            @else
                {{-- El buscador y el filtro del catálogo: un `GET` a esta misma sección. --}}
                <form method="GET" action="{{ $vm->urlDelFiltro() }}" class="dos-listas-buscar" role="search">
                    <input type="hidden" name="seccion" value="solicitudes">
                    <input type="hidden" name="solicitud" value="{{ $vm->esLaboratorio() ? 'laboratorio' : 'estudios' }}">
                    <label class="visually-hidden" for="orden-texto">{{ $vm->etiquetaDelBuscador() }}</label>
                    <input type="search" id="orden-texto" name="buscar" value="{{ $vm->buscar() }}"
                           class="form-control form-control-sm" autocomplete="off" placeholder="{{ $vm->placeholderDelBuscador() }}">

                    @if ($vm->esLaboratorio() && $vm->tipos() !== [])
                        <label class="visually-hidden" for="orden-tipo">Filtrar por tipo de examen</label>
                        <select id="orden-tipo" name="tipo" class="form-select form-select-sm">
                            <option value="">Todos los tipos</option>
                            @foreach ($vm->tipos() as $codigo => $nombre)
                                <option value="{{ $codigo }}" {{ $vm->tipoElegido() === (string) $codigo ? 'selected' : '' }}>{{ $nombre }}</option>
                            @endforeach
                        </select>
                    @endif

                    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search" aria-hidden="true"></i> Buscar</button>
                    @if ($vm->buscar() !== '' || $vm->tipoElegido() !== '')
                        <a class="btn btn-sm btn-link" href="{{ $vm->urlSeccion() }}">Limpiar</a>
                    @endif
                </form>

                @if ($vm->hayResultados())
                    <ul class="dos-listas-catalogo">
                        @foreach ($vm->filasDelCatalogo() as $examen)
                            <li class="dos-listas-fila" @unless ($examen['yaEsta']) data-pasar @endunless>
                                <span class="dos-listas-fila-texto">
                                    {{ $examen['nombre'] }}
                                    {{-- El tipo se muestra sólo en laboratorio: los estudios son todos del mismo tipo. --}}
                                    @if ($examen['tipo'] && $vm->esLaboratorio())
                                        <span class="chip">{{ $examen['tipo'] }}</span>
                                    @endif
                                </span>
                                @if ($examen['yaEsta'])
                                    <span class="texto-tenue small"><i class="bi bi-check2-circle" aria-hidden="true"></i> ya está en la {{ $vm->esLaboratorio() ? 'orden' : 'solicitud' }}</span>
                                @else
                                    <form method="POST" action="{{ $examen['urlAgregar'] }}">
                                        @csrf
                                        <input type="hidden" name="codigo" value="{{ $examen['codigo'] }}">
                                        <button class="btn btn-sm btn-outline-primary" data-pasar-boton
                                                title="Pasar a la {{ $vm->esLaboratorio() ? 'orden' : 'solicitud' }}"
                                                aria-label="Pasar a la {{ $vm->esLaboratorio() ? 'orden' : 'solicitud' }}">
                                            <i class="bi bi-arrow-left" aria-hidden="true"></i>
                                            <span class="dos-listas-boton-texto">Pasar</span>
                                        </button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    @if ($vm->paginador() && $vm->paginador()->hasPages())
                        <div class="mt-2">{{ $vm->paginador()->links() }}</div>
                    @endif
                @endif

                {{-- La ayuda del catálogo: qué escribe cada clic y qué queda para el botón. --}}
                <p class="texto-tenue small mt-2 mb-2">{{ $vm->ayudaDelCatalogo() }}</p>

                @if ($vm->ofreceRutinas())
                    <h3 class="orden-subtitulo mt-3">Rutinas</h3>
                    <p class="form-text mt-0 mb-2">Una rutina pasa de una vez todos los exámenes de un tipo (los que ya están no se repiten).</p>
                    <div class="orden-rutinas">
                        @foreach ($vm->rutinas() as $tipo)
                            <form method="POST" action="{{ $vm->urlDeLaRutina($tipo['codigo']) }}">
                                @csrf
                                <button class="btn btn-sm btn-outline-secondary"
                                        title="Pasar los {{ $tipo['cantidad'] }} exámenes de {{ $tipo['nombre'] }}">
                                    <i class="bi bi-collection" aria-hidden="true"></i> {{ $tipo['nombre'] }}
                                    <span class="texto-tenue">({{ $tipo['cantidad'] }})</span>
                                </button>
                            </form>
                        @endforeach
                    </div>
                @endif

                <p class="form-text mb-0 mt-3">
                    <a href="{{ $vm->urlAdministrar() }}" target="_blank" rel="noopener">{{ $vm->textoDelEnlaceAlCatalogo() }}</a>
                    (se abre en otra pestaña, así no pierdes lo que llevas).
                </p>
            @endif
        </x-slot>
    </x-ui.dos-listas>
</div>
