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

     El formulario es **uno solo** y hace lo que el escritorio hace al guardar su pestaña: escribe el detalle
     y la casilla de Alerta de lo que ya está. **Agregar y Quitar son inmediatos** (O-07, 2026-10-17): cada
     uno es su propio `POST` —una escritura por clic, como «Pasar» en motivos y diagnósticos— y la fila
     aparece o desaparece de la lista del paciente sin apretar el botón de guardar. El aviso de "cambios sin
     guardar" del detalle y la alerta lo resuelve `consulta.js` desde los `data-*`: la vista no lleva JS ni
     estilos. --}}
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

            {{-- El `action` es el del guardado del detalle y la alerta. El agregado y la baja ya no vienen en
                 este formulario: cada uno tiene su propio `POST` inmediato (O-07). --}}
            <form method="POST" action="{{ $seccion->urlGuardar() }}" id="antecedentes-form"
                  data-consulta-antecedentes data-aviso="{{ $seccion->avisoSinGuardar() }}">
                @csrf

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

                                    {{-- "Quitar" es **inmediato** (O-07): su propio `POST` saca el antecedente
                                         **del paciente** en el acto y no toca el catálogo (el `DELETE` del
                                         escritorio, `w_consulta_principal.srw:7636`). El formulario de la baja
                                         vive al final de esta columna (no se pueden anidar formularios) y el
                                         botón lo referencia con `form=`. --}}
                                    <div class="antecedentes-acciones">
                                        <button type="submit" class="btn btn-sm btn-outline-danger antecedentes-quitar"
                                                form="antecedente-quitar-{{ $fila['id'] }}"
                                                data-antecedente-quitar="{{ $fila['codigo'] }}"
                                                data-antecedente-nombre="{{ $fila['nombre'] }}"
                                                title="Quitar «{{ $fila['nombre'] }}» de los antecedentes del paciente"
                                                aria-label="Quitar {{ $fila['nombre'] }}">
                                            <i class="bi bi-trash" aria-hidden="true"></i>
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

            {{-- Los formularios del "Quitar" inmediato (O-07): uno por fila, fuera del formulario de guardado
                 —los formularios no se anidan— y referenciados por el botón de su fila con `form=`. Conservan
                 el chip y la búsqueda para que la vuelta deje la pantalla donde estaba. --}}
            @foreach ($seccion->filas() as $fila)
                <form method="POST" action="{{ $seccion->urlQuitar() }}" id="antecedente-quitar-{{ $fila['id'] }}">
                    @csrf
                    <input type="hidden" name="codigo" value="{{ $fila['codigo'] }}">
                    <input type="hidden" name="tipo" value="{{ $seccion->tipo() }}">
                    @if ($catalogo && $catalogo->buscar() !== '')
                        <input type="hidden" name="buscar" value="{{ $catalogo->buscar() }}">
                    @endif
                </form>
            @endforeach
        </x-slot>

        <x-slot name="derecha">
            @if ($catalogo)
                {{-- El buscador del catálogo, **siempre a la vista** (A-06: antes estaba detrás de un botón):
                     es un `GET` a esta misma sección. El tipo viaja oculto para que buscar no cambie el chip. --}}
                <form method="GET" action="{{ $seccion->urlVolver() }}" class="dos-listas-buscar" role="search" id="antecedente-buscar-form"
                      data-buscar-auto data-buscar-filas=".dos-listas-fila-texto">
                    <input type="hidden" name="seccion" value="antecedentes">
                    <input type="hidden" name="tipo" value="{{ $catalogo->tipo() }}">

                    <label class="visually-hidden" for="antecedente-buscar">Buscar un antecedente en tu catálogo</label>
                    <input type="search" id="antecedente-buscar" name="buscar" value="{{ $catalogo->buscar() }}"
                           class="form-control form-control-sm" autocomplete="off"
                           placeholder="Hipertensión, cesárea, tabaco…">

                    <button class="btn btn-sm btn-outline-secondary" data-buscar-enviar>
                        <i class="bi bi-search" aria-hidden="true"></i> Buscar
                    </button>
                    <a class="btn btn-sm btn-link" href="{{ $catalogo->urlLimpiar() }}" data-buscar-limpiar @if ($catalogo->buscar() === '') hidden @endif>Limpiar</a>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-buscar-agregar hidden data-agregar-campo="antecedente-descripcion">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> Agregar «<span data-buscar-agregar-texto></span>»
                    </button>
                </form>

                <div data-buscar-resultados>

                @if ($catalogo->resultados() === [])
                    @php $vacio = $catalogo->sinResultados(); @endphp
                    <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">{{ $vacio['texto'] }}</x-ui.vacio>
                @else
                    <ul class="dos-listas-catalogo">
                        @foreach ($catalogo->resultados() as $resultado)
                            <li class="dos-listas-fila" @if (! $resultado['yaEsta']) data-pasar @endif>
                                <span class="dos-listas-fila-texto">
                                    {{ $resultado['descripcion'] }}
                                    <span class="chip">{{ $resultado['codigo'] }}</span>
                                </span>

                                @if ($resultado['yaEsta'])
                                    <span class="texto-tenue small">Ya está en el paciente</span>
                                @else
                                    {{-- «Agregar» es un `POST` **inmediato** (O-07): el antecedente se escribe en
                                         el paciente en el acto y la fila aparece en la columna izquierda. Sin JS
                                         la pantalla sigue sirviendo: es un formulario común. --}}
                                    <form method="POST" action="{{ $catalogo->urlAgregar() }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="codigo" value="{{ $resultado['codigo'] }}">
                                        <input type="hidden" name="tipo" value="{{ $catalogo->tipo() }}">
                                        @if ($catalogo->buscar() !== '')
                                            <input type="hidden" name="buscar" value="{{ $catalogo->buscar() }}">
                                        @endif
                                        <button class="btn btn-sm btn-outline-primary" data-pasar-boton
                                                title="Agregar a los antecedentes del paciente"
                                                aria-label="Agregar {{ $resultado['descripcion'] }}">
                                            <i class="bi bi-plus-lg" aria-hidden="true"></i>
                                            <span class="dos-listas-boton-texto">Agregar</span>
                                        </button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    <p class="texto-tenue small mt-2 mb-0">Tip: doble clic en un antecedente para agregarlo.</p>
                @endif
                </div>

                {{-- El alta del antecedente que falta: es el botón *Agregar* del escritorio
                     (`w_nuevo_antecedente`). El código lo da el servidor y el tipo viene elegido con el chip
                     activo, para que lo que se cree caiga en el tipo que se está mirando. --}}
                <hr class="my-3">

                <form method="POST" action="{{ $catalogo->urlCrear() }}" class="row g-2 align-items-end" id="antecedente-crear-form">
                    @csrf
                    <x-ui.campo etiqueta="Nombre del antecedente" for="antecedente-descripcion" class="col-12 col-md-8">
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
