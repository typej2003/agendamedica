{{-- El formulario de una pestaña del embarazo cuyas filas son **fechas** (WEB-6.6): *Laboratorio* y
     *Controles*. Es el mismo para las dos —la clave es `historia` + `gesta_clave` + `fecha`— y lo que cambia
     entra por el ViewModel (`App\Clinica\Embarazo\Vistas\FechasDelEmbarazoVista`), que a su vez recibe el
     mapa del dato de cada tabla (`PrenaExamenB::campos()` y `PreNatalDesarrolloFino::campos()`).

     **Los campos van agrupados como en la grilla del escritorio** (O-21): el laboratorio tiene su panel de
     exámenes y, en un renglón aparte, Urea · Crea. · Orina · Heces · Otros; los controles son un solo
     renglón. El agrupamiento y el ancho de cada campo los decide el mapa del dato (`bloque`, `ancho`), no
     esta vista, que sólo recorre los bloques del ViewModel.

     `$claveTab` es la clave de la pestaña (`laboratorio` o `controles`) y se usa para el id del formulario:
     el aviso de cambios sin guardar de `consulta.js` se ata al formulario por `data-consulta-fechas` y al
     cartel por `<id del formulario>-sin-guardar`.

     **Una fecha nueva es este mismo formulario sin `&fecha=`**: la gesta no cambia («Agregar una fecha» es
     la pestaña con `&gesta=`), así que se pueden cargar las fechas que haga falta dentro de la misma gesta. --}}
@php
    $estado = $vm->estado();
    $idFormulario = $claveTab . '-form';
@endphp

<div class="consulta-seccion" id="consulta-embarazo-{{ $claveTab }}">
    <x-ui.tarjeta :titulo="$vm->titulo()" :icono="$vm->icono()">
        <x-slot name="acciones">
            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']"
                         :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
        </x-slot>

        {{-- Las fechas cargadas de esta gesta: la que se mira se elige con un enlace. --}}
        @if (! $vm->sinFilas())
            <x-ui.chips class="mb-3" :opciones="$vm->fechas()" etiqueta="Fechas" />
        @else
            <p class="texto-tenue small mb-3">
                Esta gesta todavía no tiene nada cargado en esta pestaña.
            </p>
        @endif

        {{-- La fecha nueva es una fila **más** de la gesta: el formulario en blanco no es que no haya nada,
             y sin este aviso parece que «Agregar una fecha» perdió lo que se estaba mirando. --}}
        @if ($avisoDeFechaNueva = $vm->avisoDeFechaNueva())
            <p class="alert alert-info py-2 d-flex align-items-center gap-2">
                <i class="bi bi-plus-circle" aria-hidden="true"></i>
                <span>{{ $avisoDeFechaNueva }}</span>
            </p>
        @endif

        <form method="POST" action="{{ $vm->urlGuardar() }}" id="{{ $idFormulario }}"
              data-consulta-fechas data-aviso="{{ $vm->avisoSinGuardar() }}">
            @csrf
            {{-- La gesta a la que pertenece la fila: la clave es `historia` + `gesta_clave` + `fecha`. --}}
            <input type="hidden" name="gesta" value="{{ $vm->gestaClave() }}">

            <div class="row g-2 mb-3">
                <x-ui.campo etiqueta="Fecha" for="fecha" class="col-6 col-md-3">
                    <input type="date" class="form-control" id="fecha" name="fecha" value="{{ $vm->fecha() }}">
                </x-ui.campo>

                @foreach ($vm->bloques() as $bloque)
                    @if ($bloque['rotulo'])
                        <div class="col-12 pt-1">
                            <p class="etiqueta mb-0">{{ $bloque['rotulo'] }}</p>
                        </div>
                    @endif

                    @foreach ($bloque['campos'] as $campo)
                        @include('clinica.consulta._embarazo-campo', ['campo' => $campo])
                    @endforeach
                @endforeach
            </div>

            <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-0"
               id="{{ $idFormulario }}-sin-guardar" hidden>
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span>Hay cambios sin guardar.</span>
            </p>

            <div class="d-flex justify-content-end gap-2 mt-3">
                @if (! $vm->sinFilas() && ! $vm->esNueva())
                    <a class="btn btn-outline-secondary" href="{{ $vm->urlNueva() }}">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> Agregar una fecha
                    </a>
                @endif
                <button class="btn btn-primary">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar
                </button>
            </div>
        </form>
    </x-ui.tarjeta>
</div>
