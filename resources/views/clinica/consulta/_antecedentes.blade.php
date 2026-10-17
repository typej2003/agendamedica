{{-- Los antecedentes del paciente en la consulta (WEB-4.4, ítem 3 del panel del legado), **en dos columnas**
     desde A-06 (revisión de Alexander): a la izquierda los antecedentes **del paciente**, a la derecha el
     catálogo **con su buscador a la vista** y el alta del antecedente que falta.

     **No son de la consulta: son del paciente.** Lo dice la fuente —`antece_paciente` se llavea por
     `numhistoria` y el escritorio los carga y los lee por historia (`w_consulta_principal.srw:7416`,
     `:7636`, `:7754`)— y por eso lo primero que dice la columna es para quién se están cargando: editar
     acá es editar la historia clínica del paciente.

     Los **chips de tipo** de arriba son el filtro de las dos columnas, como la solapa del escritorio:
     siempre hay uno elegido (no hay «todos») y viaja en `?tipo=`. El `x-ui.segmentado` no sirve para esto
     porque tiene que poder desbordar en horizontal; el patrón es `.chips-filtro` (doctorisimo-ui.css).

     El formulario es **uno solo** y hace todo lo que el escritorio hace en su pestaña: escribe el detalle
     y la casilla de Alerta de lo que ya está, agrega lo que se tildó del catálogo y quita lo que el médico
     sacó de la lista. El aviso de "cambios sin guardar" y el "Quitar" de cada fila los resuelve
     `consulta.js` desde los `data-*`: la vista no lleva JS ni estilos. --}}
@php
    $estado = $seccion->estado();
@endphp

<div class="consulta-seccion" id="consulta-antecedentes">
    {{-- Los chips de tipo: siete (los del catálogo, en el orden de las solapas) más «Sin clasificar» si el
         paciente tiene antecedentes sin tipo. Con scroll horizontal cuando no entran: no se envuelven a
         varias líneas (lo pidió Alexander). --}}
    @if ($seccion->chips() !== [])
        <div class="chips-filtro" role="group" aria-label="Tipo de antecedente">
            @foreach ($seccion->chips() as $chip)
                <a class="chip-filtro {{ $chip['activa'] ? 'activo' : '' }}" href="{{ $chip['url'] }}"
                   @if ($chip['activa']) aria-current="true" @endif>{{ $chip['nombre'] }}</a>
            @endforeach
        </div>
    @endif

    <x-ui.dos-listas titulo-izquierda="Antecedentes del paciente" titulo-derecha="Tu catálogo de antecedentes"
                     :resumen="$seccion->resumen()">
        <x-slot name="izquierda">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']"
                             :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
                @if ($seccion->nombreDelTipo())
                    <span class="texto-tenue small">{{ $seccion->nombreDelTipo() }}</span>
                @endif
            </div>

            <p class="texto-tenue small mb-3">{{ $seccion->ayuda() }}</p>

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

                @if ($seccion->estaVacio())
                    <x-ui.vacio icono="bi-clipboard2-pulse" titulo="Este paciente no tiene antecedentes cargados">
                        Búscalos en el catálogo de la derecha y agrégalos. Quedan en su historia clínica, no en esta
                        consulta.
                    </x-ui.vacio>
                @elseif ($seccion->sinAntecedentesDeEsteTipo())
                    {{-- El estado vacío **por tipo**: decir «no tiene antecedentes cargados» sería falso (tiene
                         {{ $seccion->total() }} en otros tipos) y el médico concluiría que la historia está vacía. --}}
                    <x-ui.vacio icono="bi-clipboard2-pulse" titulo="Sin antecedentes de este tipo">
                        Este paciente tiene {{ $seccion->total() }}
                        {{ $seccion->total() === 1 ? 'antecedente cargado' : 'antecedentes cargados' }} en otros tipos:
                        elige su chip arriba para verlos, o agrega uno de este tipo desde el catálogo de la derecha.
                    </x-ui.vacio>
                @else
                    <ul class="antecedentes-lista">
                        @foreach ($seccion->filas() as $fila)
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
            </form>
        </x-slot>

        <x-slot name="derecha">
            @if ($catalogo)
                <p class="texto-tenue small mb-3">{{ $catalogo->ayuda() }}</p>

                @if ($catalogo->pendientesDelBuscador() !== [])
                    <p class="alert alert-info py-2 d-flex align-items-center gap-2" id="antecedentes-pendientes">
                        <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                        <span>{{ $catalogo->avisoDePendientes() }}</span>
                    </p>
                @endif

                {{-- El buscador del catálogo, **siempre a la vista** (A-06: antes estaba detrás de un botón):
                     es un `GET` a esta misma sección. El tipo viaja oculto para que buscar no cambie el chip, y
                     los pendientes también, para no perder lo tildado al buscar otra cosa. --}}
                <form method="GET" action="{{ $seccion->urlVolver() }}" class="dos-listas-buscar" role="search" id="antecedente-buscar-form">
                    <input type="hidden" name="seccion" value="antecedentes">
                    <input type="hidden" name="tipo" value="{{ $catalogo->tipo() }}">

                    @foreach ($catalogo->pendientesDelBuscador() as $codigo)
                        <input type="hidden" name="agregar[]" value="{{ $codigo }}">
                    @endforeach

                    <label class="visually-hidden" for="antecedente-buscar">Buscar un antecedente en tu catálogo</label>
                    <input type="search" id="antecedente-buscar" name="buscar" value="{{ $catalogo->buscar() }}"
                           class="form-control form-control-sm" autocomplete="off"
                           placeholder="Hipertensión, cesárea, tabaco…">

                    <button class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-search" aria-hidden="true"></i> Buscar
                    </button>
                    @if ($catalogo->buscar() !== '')
                        <a class="btn btn-sm btn-link" href="{{ $catalogo->urlLimpiar() }}">Limpiar</a>
                    @endif
                </form>

                @if ($catalogo->resultados() === [])
                    @php $vacio = $catalogo->sinResultados(); @endphp
                    <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">{{ $vacio['texto'] }}</x-ui.vacio>
                @else
                    <ul class="dos-listas-catalogo">
                        @foreach ($catalogo->resultados() as $resultado)
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
                                    <span class="texto-tenue small">Ya está en el paciente</span>
                                @else
                                    {{-- El buscador es un `GET`: "Agregar" vuelve a la sección con ese
                                         antecedente tildado como pendiente, y el botón de la columna izquierda
                                         lo guarda. Sin JS la pantalla sigue sirviendo. --}}
                                    <a class="btn btn-sm btn-outline-primary" data-pasar-boton
                                       href="{{ $catalogo->urlAgregar($resultado['codigo']) }}"
                                       title="Agregar a los antecedentes del paciente"
                                       aria-label="Agregar {{ $resultado['descripcion'] }}">
                                        <i class="bi bi-plus-lg" aria-hidden="true"></i>
                                        <span class="dos-listas-boton-texto">Agregar</span>
                                    </a>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    <p class="texto-tenue small mt-2 mb-0">Tip: doble clic en un antecedente para agregarlo.</p>
                @endif

                {{-- El alta del antecedente que falta: es el botón *Agregar* del escritorio
                     (`w_nuevo_antecedente`). El código lo da el servidor y el tipo viene elegido con el chip
                     activo, para que lo que se cree caiga en el tipo que se está mirando. --}}
                <hr class="my-3">

                <p class="texto-tenue small mb-2">{{ $catalogo->ayudaDelAlta() }}</p>

                <form method="POST" action="{{ $catalogo->urlCrear() }}" class="row g-2 align-items-end" id="antecedente-crear-form">
                    @csrf
                    <x-ui.campo etiqueta="Nombre del antecedente" for="antecedente-descripcion" class="col-12 col-md-8"
                                :ayuda="'Hasta ' . $catalogo->limiteDescripcion() . ' caracteres. Se guarda en mayúsculas.'">
                        <input type="text" id="antecedente-descripcion" name="descripcion" class="form-control"
                               maxlength="{{ $catalogo->limiteDescripcion() }}" value="{{ old('descripcion') }}" autocomplete="off">
                    </x-ui.campo>

                    <x-ui.campo etiqueta="Tipo" for="antecedente-tipo-nuevo" class="col-12 col-md-4" requerido>
                        <select id="antecedente-tipo-nuevo" name="tipo" class="form-select">
                            <option value="">Elige el tipo…</option>
                            @foreach ($catalogo->tipos() as $tipo)
                                <option value="{{ $tipo['codigo'] }}"
                                        @if ($catalogo->tipoDelAlta() === $tipo['codigo']) selected @endif>{{ $tipo['nombre'] }}</option>
                            @endforeach
                        </select>
                    </x-ui.campo>

                    <div class="col-12 d-flex justify-content-end">
                        <button class="btn btn-outline-secondary">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i> Crear y agregar al paciente
                        </button>
                    </div>
                </form>
            @endif
        </x-slot>
    </x-ui.dos-listas>
</div>
