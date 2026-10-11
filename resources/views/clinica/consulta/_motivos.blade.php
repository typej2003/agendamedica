{{-- Los motivos de consulta de esta consulta (WEB-4.2, ítem 1 del panel del legado): lo que ya tiene
     cargado, el buscador de su catálogo y el alta del motivo que falta.

     El buscador es en vivo (`consulta.js` pide el catálogo y pinta los resultados), pero **los resultados
     también se pintan acá**: sin JavaScript la lista del catálogo se ve igual —un formulario por fila y
     el botón Agregar— y la pantalla sigue sirviendo. Lo que sólo hace el script es filtrar sin recargar,
     ofrecer "crear el que falta" y pedir confirmación al quitar. --}}
<div class="consulta-seccion" id="consulta-motivos"
     data-url-buscar="{{ $seccion->urlBuscar() }}"
     data-url-crear="{{ $seccion->urlCrear() }}"
     data-crear-abierto="{{ $seccion->crearAbierto() ? '1' : '0' }}"
     data-texto-crear="Crear «{texto}» en mi catálogo">

    <x-ui.tarjeta titulo="Motivos de esta consulta" icono="bi-list-check" class="mb-3">
        <x-slot name="acciones">
            <x-ui.estado :tono="$seccion->estaVacio() ? 'neutro' : 'marca'"
                         :icono="$seccion->estaVacio() ? 'bi-dash-circle' : 'bi-list-check'">{{ $seccion->resumen() }}</x-ui.estado>
        </x-slot>

        @if ($seccion->estaVacio())
            <x-ui.vacio icono="bi-list-check" titulo="Todavía no hay motivos en esta consulta">
                Busca en tu catálogo, abajo, y agrega los motivos de esta atención. Puedes cargar más de uno.
            </x-ui.vacio>
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
                            <button class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-trash" aria-hidden="true"></i> Quitar
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.tarjeta>

    <x-ui.tarjeta titulo="Agregar un motivo" icono="bi-search">
        <div class="position-relative" id="motivos-busqueda">
            <label class="form-label" for="motivo-texto">Busca en tu catálogo o escribe uno nuevo</label>

            {{-- El campo del buscador en vivo, **sin** `<x-ui.buscador-vivo>`: ese componente ya trae su
                 propio `#motivo-resultados` y acá la lista se pinta con la plantilla (y el servidor la
                 deja lista para cuando el JS no corre), así que el `id` quedaría repetido en la página. --}}
            <div class="buscador-campo buscador-vivo">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" id="motivo-texto" value="{{ $seccion->buscar() }}"
                       class="form-control form-control-sm" autocomplete="off"
                       placeholder="Dolor, control, sangrado…"
                       aria-label="Buscar un motivo de consulta" aria-controls="motivo-resultados">
            </div>

            {{-- El script clona esta fila por cada resultado (y le pone el formulario de agregar). --}}
            <template id="motivo-plantilla">
                <div class="resultado-paciente resultado-motivo">
                    <span class="resultado-paciente-datos"></span>
                    <form method="POST" action="{{ $seccion->urlAgregar() }}">
                        @csrf
                        <input type="hidden" name="codemotivo" value="">
                        <button class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> Agregar
                        </button>
                    </form>
                </div>
            </template>

            {{-- La fila de "crear el que falta": la muestra el script cuando lo escrito no es un motivo del
                 catálogo, y abre el diálogo de alta. --}}
            <template id="motivo-crear-plantilla">
                <button type="button" class="resultado-paciente resultado-motivo resultado-motivo-crear"
                        id="motivo-crear-boton">
                    <span class="resultado-paciente-datos"></span>
                    <span class="btn btn-sm btn-primary">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> Crear
                    </span>
                </button>
            </template>

            {{-- Los resultados sin JavaScript: las mismas filas, ya pintadas por el servidor. --}}
            <div class="list-group resultados-flotantes" id="motivo-resultados" hidden>
                @foreach ($seccion->resultados() as $resultado)
                    <div class="resultado-paciente resultado-motivo">
                        <span class="resultado-paciente-datos">
                            {{ $resultado['descripcion'] }}
                            @if ($resultado['yaEsta'])
                                <span class="texto-tenue small">· ya está en la consulta</span>
                            @endif
                        </span>
                        @unless ($resultado['yaEsta'])
                            <form method="POST" action="{{ $resultado['urlAgregar'] }}">
                                @csrf
                                <input type="hidden" name="codemotivo" value="{{ $resultado['codigo'] }}">
                                <button class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Agregar
                                </button>
                            </form>
                        @endunless
                    </div>
                @endforeach
            </div>
        </div>

        <p class="form-text mt-2 mb-0" id="motivo-ayuda">{{ $seccion->ayuda() }}</p>

        {{-- El alta del motivo nuevo. El aviso de conexión va acá, donde se decide crearlo, y no en una
             franja que se pierde: es la única parte de la pantalla que no funciona sin red. --}}
        <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="motivo-nuevo-boton">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Crear un motivo nuevo
        </button>
        <p class="form-text mb-0 mt-1" id="motivo-aviso-conexion">
            <i class="bi bi-wifi" aria-hidden="true"></i> {{ $seccion->avisoDeConexion() }}
        </p>
    </x-ui.tarjeta>

    <x-ui.dialogo id="dialogo-motivo" titulo="Crear un motivo en tu catálogo">
        <form method="POST" action="{{ $seccion->urlCrear() }}">
            @csrf
            <div class="dialogo-cuerpo">
                <x-ui.campo etiqueta="Nombre del motivo" for="motivo-descripcion" requerido
                            :ayuda="'Hasta ' . $seccion->largoMaximo() . ' caracteres. Se guarda en mayúsculas, como en tu catálogo.'">
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
