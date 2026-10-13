{{-- Los antecedentes del paciente en la consulta (WEB-4.4, ítem 3 del panel del legado).

     **No son de la consulta: son del paciente.** Lo dice la fuente —`antece_paciente` se llavea por
     `numhistoria` y el escritorio los carga y los lee por historia (`w_consulta_principal.srw:7416`,
     `:7636`, `:7754`)— y por eso lo primero que dice la tarjeta es para quién se están cargando: editar
     acá es editar la historia clínica del paciente.

     El formulario es **uno solo** y hace todo lo que el escritorio hace en su pestaña: escribe el detalle
     y la casilla de Alerta de lo que ya está, agrega lo que se tildó del catálogo y quita lo que el médico
     sacó de la lista. El aviso de "cambios sin guardar" y el "Quitar" de cada fila los resuelve
     `consulta.js` desde los `data-*`: la vista no lleva JS ni estilos. --}}
@php
    $estado = $seccion->estado();
@endphp

<div class="consulta-seccion" id="consulta-antecedentes">
    {{-- El `action` sale del buscador cuando hay pendientes: le agrega `?agregar=…` para que el POST no los
         pierda si la página se recargó (los `hidden` de abajo también los mandan, pero una recarga se los
         lleva y la query no). Sin pendientes es el `action` normal. --}}
    <form method="POST" action="{{ $catalogo ? $catalogo->urlGuardar() : $seccion->urlGuardar() }}" id="antecedentes-form"
          data-consulta-antecedentes data-aviso="{{ $seccion->avisoSinGuardar() }}">
        @csrf

        {{-- Los antecedentes que el buscador dejó tildados y todavía no se guardaron: entran al `POST` del
             botón de guardar como `agregar[código]`. El buscador es un `GET` y no puede mandarlos.
             Lo que ya tenía el paciente **no** se manda como `agregar`: se quita con el botón de la fila,
             que agrega su código a `quitar[]` (`consulta.js`). --}}
        @foreach ($catalogo ? $catalogo->pendientesDelBuscador() : [] as $codigo)
            <input type="hidden" name="agregar[{{ $codigo }}]" value="{{ $codigo }}">
        @endforeach

        <x-ui.tarjeta titulo="Antecedentes del paciente" icono="bi-clipboard2-pulse">
            <x-slot name="acciones">
                <span class="texto-tenue small me-2">{{ $seccion->total() }} {{ $seccion->total() === 1 ? 'antecedente' : 'antecedentes' }}</span>
                <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']"
                             :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
            </x-slot>

            <p class="texto-tenue small mb-3">{{ $seccion->ayuda() }}</p>

            @if ($seccion->estaVacio())
                <x-ui.vacio icono="bi-clipboard2-pulse" titulo="Este paciente no tiene antecedentes cargados">
                    Búscalos en el catálogo de abajo y agrégalos. Quedan en su historia clínica, no en esta consulta.
                </x-ui.vacio>
            @else
                @foreach ($seccion->grupos() as $grupo)
                    <div class="antecedentes-grupo">
                        <h3 class="antecedentes-grupo-titulo">{{ $grupo['titulo'] }}</h3>

                        <ul class="antecedentes-lista">
                            @foreach ($grupo['filas'] as $fila)
                                <li class="antecedentes-item {{ $fila['alerta'] ? 'antecedentes-item-alerta' : '' }}"
                                    data-antecedente-fila="{{ $fila['id'] }}">
                                    <div class="antecedentes-item-texto">
                                        <div class="antecedentes-item-datos">
                                            <span class="antecedentes-nombre">{{ $fila['nombre'] }}</span>
                                            <span class="texto-tenue small">
                                                <span class="chip">{{ $fila['codigo'] }}</span>
                                                @if ($fila['avisoCatalogo'])
                                                    <span class="texto-tenue small"> · {{ $fila['avisoCatalogo'] }}</span>
                                                @endif
                                            </span>

                                            <label class="form-label visually-hidden" for="antecedente-detalle-{{ $fila['id'] }}">
                                                Detalle de {{ $fila['nombre'] }}
                                            </label>
                                            <input type="text" class="form-control form-control-sm"
                                                   id="antecedente-detalle-{{ $fila['id'] }}"
                                                   name="filas[{{ $fila['id'] }}][detalle]"
                                                   value="{{ $fila['detalle'] }}"
                                                   maxlength="{{ $catalogo->limiteDetalle() }}"
                                                   placeholder="Detalle (cuándo, cuántas, cómo…)">
                                        </div>

                                        <label class="form-check antecedentes-alerta" for="antecedente-alerta-{{ $fila['id'] }}"
                                               title="Marcada, esta fila se resalta como alerta en las consultas de este paciente">
                                            {{-- El `hidden` con `B` y el control con `A`: es la casilla del
                                                 DataWindow del escritorio (`checkbox.on="A"`,
                                                 `checkbox.off="B"`, `d_antecedentes_consulta_bueno.srd`) y,
                                                 cuando el control viaja, gana su valor. --}}
                                            <input type="hidden" name="filas[{{ $fila['id'] }}][alerta]" value="B">
                                            <input class="form-check-input" type="checkbox" value="A"
                                                   name="filas[{{ $fila['id'] }}][alerta]"
                                                   id="antecedente-alerta-{{ $fila['id'] }}"
                                                   @if ($fila['alerta']) checked @endif>
                                            <span class="form-check-label">Alerta</span>
                                        </label>

                                        {{-- "Quitar" deja la fila marcada como "se quita al guardar": saca el
                                             antecedente **del paciente** y no toca el catálogo (el `DELETE`
                                             del escritorio, `w_consulta_principal.srw:7636`). Sin guardar,
                                             no se borra nada: el `hidden` lo inserta `consulta.js` y lo manda
                                             el mismo botón de siempre. --}}
                                        <div class="antecedentes-acciones">
                                            <button type="button" class="btn btn-sm btn-outline-danger antecedentes-quitar"
                                                    data-antecedente-quitar="{{ $fila['codigo'] }}"
                                                    data-antecedente-nombre="{{ $fila['nombre'] }}"
                                                    title="Quitar «{{ $fila['nombre'] }}» de los antecedentes del paciente"
                                                    aria-label="Quitar {{ $fila['nombre'] }}">
                                                <i class="bi bi-trash" aria-hidden="true"></i>
                                            </button>
                                            <span class="texto-tenue small antecedentes-aviso-quitar" hidden>
                                                Se quita al guardar
                                            </span>
                                            <button type="button" class="btn btn-sm btn-link antecedentes-restaurar" hidden
                                                    data-antecedente-restaurar>
                                                Volver a dejarlo
                                            </button>
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            @endif

            {{-- El aviso de "hay cambios sin guardar": `consulta.js` lo muestra cuando algún control del
                 formulario cambió respecto de lo que el servidor pintó. --}}
            <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-0" id="antecedentes-sin-guardar" hidden>
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span>Hay cambios sin guardar.</span>
            </p>

            <div class="d-flex justify-content-end gap-2 mt-3">
                <a class="btn btn-outline-secondary" href="{{ $seccion->urlVolver() }}">Cancelar</a>
                {{-- La única acción primaria de la sección: guardar lo cargado. --}}
                <button class="btn btn-primary">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar los antecedentes
                </button>
            </div>
        </x-ui.tarjeta>
    </form>

    @if ($catalogo)
        <x-ui.tarjeta titulo="Agregar un antecedente" icono="bi-search" class="mt-3">
            <p class="texto-tenue small mb-3">{{ $catalogo->ayuda() }}</p>

            @if ($catalogo->pendientesDelBuscador() !== [])
                <p class="alert alert-info py-2 d-flex align-items-center gap-2" id="antecedentes-pendientes">
                    <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                    <span>{{ $catalogo->avisoDePendientes() }}</span>
                </p>
            @endif

            {{-- El filtro del catálogo: un `GET` a esta misma sección. Va **antes** de la lista y sin
                 `buscar` en la URL para que el buscador se pueda limpiar. --}}
            <form method="GET" action="{{ $seccion->urlVolver() }}" class="row g-2 align-items-end mb-3" id="antecedente-buscar-form">
                <input type="hidden" name="seccion" value="antecedentes">

                {{-- Lo que el buscador dejó tildado viaja **también** acá: buscar otra cosa es un `GET` a esta
                     misma sección y sin estos `hidden` se perdería lo pendiente (la query vieja no se
                     arrastra al enviar un formulario). --}}
                @foreach ($catalogo->pendientesDelBuscador() as $codigo)
                    <input type="hidden" name="agregar[]" value="{{ $codigo }}">
                @endforeach
                <x-ui.campo etiqueta="Buscar en tu catálogo" for="antecedente-buscar" class="col-12 col-md-7">
                    <input type="text" id="antecedente-buscar" name="buscar" value="{{ $catalogo->buscar() }}"
                           class="form-control" autocomplete="off"
                           placeholder="Hipertensión, cesárea, tabaco…">
                </x-ui.campo>

                <x-ui.campo etiqueta="Tipo" for="antecedente-tipo" class="col-8 col-md-3">
                    <select id="antecedente-tipo" name="tipo" class="form-select">
                        <option value="">Todos los tipos</option>
                        @foreach ($catalogo->tipos() as $tipo)
                            <option value="{{ $tipo['codigo'] }}"
                                    @if ($catalogo->tipo() === $tipo['codigo']) selected @endif>{{ $tipo['nombre'] }}</option>
                        @endforeach
                    </select>
                </x-ui.campo>

                <div class="col-4 col-md-2 d-grid">
                    <button class="btn btn-outline-secondary">
                        <i class="bi bi-search" aria-hidden="true"></i> Buscar
                    </button>
                </div>
            </form>

            @if ($catalogo->resultados() === [])
                @php $vacio = $catalogo->sinResultados(); @endphp
                <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">{{ $vacio['texto'] }}</x-ui.vacio>
            @else
                <x-ui.tabla>
                    <thead>
                        <tr>
                            <th scope="col">Antecedente</th>
                            <th scope="col">Tipo</th>
                            <th scope="col" class="text-end">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($catalogo->resultados() as $resultado)
                            <tr>
                                <td data-etiqueta="Antecedente" class="celda-principal">
                                    {{ $resultado['descripcion'] }}
                                    <span class="chip">{{ $resultado['codigo'] }}</span>
                                </td>
                                <td data-etiqueta="Tipo">{{ $resultado['tipo'] ?? '—' }}</td>
                                <td data-etiqueta="Acción" class="text-end">
                                    @if ($resultado['pendiente'])
                                        <span class="texto-tenue small">
                                            <i class="bi bi-check2-circle" aria-hidden="true"></i> Se agrega al guardar
                                        </span>
                                    @elseif ($resultado['yaEsta'])
                                        <span class="texto-tenue small">Ya está en el paciente</span>
                                    @else
                                        {{-- El buscador es un `GET`: "Agregar" vuelve a la sección con ese
                                             antecedente tildado como pendiente, y el botón de la tarjeta de
                                             arriba lo guarda. Sin JS la pantalla sigue sirviendo. --}}
                                        <a class="btn btn-sm btn-outline-primary"
                                           href="{{ $catalogo->urlAgregar($resultado['codigo']) }}#consulta-antecedentes">
                                            <i class="bi bi-plus-lg" aria-hidden="true"></i> Agregar
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.tabla>
            @endif

            {{-- El alta del antecedente que falta: es el botón *Agregar* del escritorio
                 (`w_nuevo_antecedente`). El código lo da el servidor y el tipo se elige del catálogo. --}}
            <hr class="my-3">

            <p class="texto-tenue small mb-2">{{ $catalogo->ayudaDelAlta() }}</p>

            <form method="POST" action="{{ $catalogo->urlCrear() }}" class="row g-2 align-items-end" id="antecedente-crear-form">
                @csrf
                <x-ui.campo etiqueta="Nombre del antecedente" for="antecedente-descripcion" class="col-12 col-md-6"
                            :ayuda="'Hasta ' . $catalogo->limiteDescripcion() . ' caracteres. Se guarda en mayúsculas.'">
                    <input type="text" id="antecedente-descripcion" name="descripcion" class="form-control"
                           maxlength="{{ $catalogo->limiteDescripcion() }}" value="{{ old('descripcion') }}" autocomplete="off">
                </x-ui.campo>

                <x-ui.campo etiqueta="Tipo" for="antecedente-tipo-nuevo" class="col-8 col-md-4" requerido>
                    <select id="antecedente-tipo-nuevo" name="tipo" class="form-select">
                        <option value="">Elige el tipo…</option>
                        @foreach ($catalogo->tipos() as $tipo)
                            <option value="{{ $tipo['codigo'] }}" @if (old('tipo') === $tipo['codigo']) selected @endif>
                                {{ $tipo['nombre'] }}
                            </option>
                        @endforeach
                    </select>
                </x-ui.campo>

                <div class="col-4 col-md-2 d-grid">
                    <button class="btn btn-outline-secondary">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> Crear
                    </button>
                </div>
            </form>
        </x-ui.tarjeta>
    @endif
</div>
