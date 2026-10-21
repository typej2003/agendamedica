{{-- Los motivos de consulta de esta consulta (WEB-4.2, ítem 1 del panel del legado) como **dos listas** (WEB-11.3): a la izquierda lo que la
     consulta ya tiene (con «Eliminar») y a la derecha **todo el catálogo del médico a la vista**, por páginas y con buscador, donde cada
     motivo se pasa con «Pasar» o con doble clic (el `dw` del escritorio). Y el alta del motivo que falta.

     Todo son formularios y enlaces: la pantalla sirve sin JavaScript. `consulta.js` sólo abre el diálogo de «Crear» y `dos-listas.js`
     agrega el doble clic. --}}
<div class="consulta-seccion" id="consulta-motivos"
     data-crear-abierto="{{ $seccion->crearAbierto() ? '1' : '0' }}">

    <x-ui.dos-listas titulo-izquierda="Motivos de esta consulta" titulo-derecha="Tu catálogo de motivos" :resumen="$seccion->resumen()">
        @if ($vm->anterior())
            <x-slot name="accionesIzquierda">
                <x-ui.anterior :anterior="$vm->anterior()" />
            </x-slot>
        @endif
        <x-slot name="izquierda">
            @if ($seccion->estaVacio())
                <x-ui.vacio icono="bi-list-check" titulo="Todavía no hay motivos en esta consulta"></x-ui.vacio>
            @else
                <ul class="motivos-lista">
                    @foreach ($seccion->cargados() as $motivo)
                        <li class="motivos-item">
                            <div class="motivos-item-texto">
                                <span class="motivos-item-nombre">{{ $motivo['descripcion'] }}</span>
                                <span class="texto-tenue small">
                                    <span class="chip">{{ $motivo['codigo'] }}</span>
                                    @if ($motivo['avisoCatalogo'])
                                        <span class="texto-tenue small"> · {{ $motivo['avisoCatalogo'] }}</span>
                                    @endif
                                </span>
                            </div>

                            <form method="POST" action="{{ $motivo['urlQuitar'] }}"
                                  data-confirmar="¿Quitar «{{ $motivo['descripcion'] }}» de esta consulta? El motivo sigue en tu catálogo.">
                                @csrf
                                @method('DELETE')
                                {{-- El rótulo vive en `.dos-listas-boton-texto`: en el modo compacto (por el ancho de la sección) queda sólo el
                                     icono, con `title` y `aria-label` (doctorisimo-ui.css). El nombre del motivo está en la
                                     fila, al lado del botón: el rótulo del botón no lo repite. --}}
                                <button class="btn btn-sm btn-outline-danger"
                                        title="Quitar de esta consulta" aria-label="Quitar de esta consulta">
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                    <span class="dos-listas-boton-texto">Eliminar</span>
                                </button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-slot>

        <x-slot name="derecha">
            {{-- El filtro del catálogo: un `GET` a esta misma sección. --}}
            <form method="GET" action="{{ $seccion->urlDelFiltro() }}" class="dos-listas-buscar" role="search" data-buscar-auto>
                <input type="hidden" name="seccion" value="motivos">
                <label class="visually-hidden" for="motivo-texto">Buscar un motivo en tu catálogo</label>
                <input type="search" id="motivo-texto" name="buscar" value="{{ $seccion->buscar() }}"
                       class="form-control form-control-sm" autocomplete="off" placeholder="Buscar:">
                <button class="btn btn-sm btn-outline-secondary" data-buscar-enviar><i class="bi bi-search" aria-hidden="true"></i> Buscar</button>
                <a class="btn btn-sm btn-link" href="{{ $seccion->urlSeccion() }}" data-buscar-limpiar @if ($seccion->buscar() === '') hidden @endif>Limpiar</a>
            </form>

            {{-- Lo que cambia al buscar solo (`buscar-auto.js`): el «Crear «texto»» (el servidor decide si lo escrito ya existe), la lista y su
                 paginación. --}}
            <div data-buscar-resultados>
            @if ($seccion->ofreceCrearLoBuscado())
                <button type="button" class="btn btn-sm btn-outline-primary mb-2" data-motivo-crear="{{ $seccion->buscar() }}">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Crear «{{ $seccion->buscar() }}» en mi catálogo
                </button>
            @endif

            @if ($seccion->hayResultados())
                <ul class="dos-listas-catalogo">
                    @foreach ($seccion->resultados() as $resultado)
                        <li class="dos-listas-fila" @unless ($resultado['yaEsta']) data-pasar @endunless>
                            <span class="dos-listas-fila-texto">
                                {{ $resultado['descripcion'] }}
                                <span class="chip">{{ $resultado['codigo'] }}</span>
                            </span>
                            @if ($resultado['yaEsta'])
                                <span class="texto-tenue small"><i class="bi bi-check2-circle" aria-hidden="true"></i> ya está en la consulta</span>
                            @else
                                <form method="POST" action="{{ $resultado['urlAgregar'] }}">
                                    @csrf
                                    <input type="hidden" name="codemotivo" value="{{ $resultado['codigo'] }}">
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
            @endif

            <p class="texto-tenue small mt-2 mb-2">Tip: doble clic en un motivo para pasarlo a la consulta.</p>
            </div>

            {{-- El alta del motivo nuevo. El aviso de conexión va acá, donde se decide crearlo, y no en una
                 franja que se pierde: es la única parte de la pantalla que no funciona sin red. --}}
            <button type="button" class="btn btn-outline-secondary btn-sm" id="motivo-nuevo-boton">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Agregar un motivo nuevo
            </button>
            <p class="form-text mb-0 mt-1" id="motivo-aviso-conexion">
                <i class="bi bi-wifi" aria-hidden="true"></i> {{ $seccion->avisoDeConexion() }}
            </p>
        </x-slot>
    </x-ui.dos-listas>

    <x-ui.dialogo id="dialogo-motivo" titulo="Crear un motivo en tu catálogo">
        <form method="POST" action="{{ $seccion->urlCrear() }}">
            @csrf
            <div class="dialogo-cuerpo">
                <x-ui.campo etiqueta="Nombre del motivo" for="motivo-descripcion" requerido
                            :ayuda="'Hasta ' . $seccion->largoMaximo() . ' caracteres. Se guarda en mayúsculas.'">
                    <input type="text" name="descripcion" id="motivo-descripcion" class="form-control"
                           maxlength="{{ $seccion->largoMaximo() }}" value="{{ old('descripcion') }}" autocomplete="off">
                </x-ui.campo>

                <p class="form-text mb-0">{{ $seccion->avisoDeConexion() }}</p>
            </div>

            <div class="dialogo-pie">
                <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cancelar</button>
                <button class="btn btn-primary">Crear y agregar</button>
            </div>
        </form>
    </x-ui.dialogo>
</div>
