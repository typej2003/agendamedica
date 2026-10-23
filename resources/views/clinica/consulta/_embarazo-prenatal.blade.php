{{-- La pestaña *Prenatal* del control de embarazo (WEB-6.6): las gestas de la historia, con la FUR, la
     menarquia, el tipiaje y las dos cuentas que el escritorio muestra calculadas —la edad gestacional y la
     FPP—, con **guardado explícito**.

     **El orden y el agrupamiento son los del escritorio** (O-21, captura
     `24-control-de-embarazo-prenatal-con-gesta.png`): primer renglón `FUR · FPP · EG · Menarquia · Gestas`;
     segundo `Partos · Césareas · Abortos · Otros · Tipiaje (madre) · Tipiaje (padre)`; abajo el **memo**
     grande de observaciones; y al pie, **juntos y discretos**, los tres controles que el escritorio tiene en
     su barra lateral: `Embarazo múltiple` (casilla), `Cantidad` (un número corto) y `Final de embarazo`
     (casilla). En la web no hay columna derecha: no se copia la barra lateral, se agrupan al final.

     Los campos salen del mapa del dato (`App\Clinica\Embarazo\Gesta::campos()`), que es el mismo que usan la
     Action y el resto de la sección, y cada bloque se pinta con su `bloque`. Las dos cuentas van **fuera del
     formulario** como `output`: se calculan de la FUR —el `compute` del DataWindow— y no se guardan en
     ninguna columna; el DataWindow las pone en el primer renglón, entre la FPP y la menarquia.

     **Una línea de ayuda como máximo** (O-23): el párrafo y los tres pasos que abrían la pestaña están en el
     diálogo del «¿Cómo funciona?» del encabezado. No se pierde texto y los campos suben media pantalla.

     El `gesta` oculto dice **qué** gesta se guarda: vacío significa la siguiente (*Nueva Gesta*), y el número
     lo asigna el servidor. El aviso de cambios sin guardar lo pone `consulta.js` desde los `data-*`. --}}
@php
    $estado = $vm->estado();
    $observaciones = $vm->observaciones();
@endphp

<div class="consulta-seccion" id="consulta-embarazo-prenatal">
    <x-ui.tarjeta titulo="{{ $vm->titulo() }}" icono="bi-calendar-heart">
        <x-slot name="acciones">
            {{-- El «¿Cómo funciona?» va al lado del título: abre un diálogo nativo, el mismo patrón del tour y
                 de los diálogos de la consulta (`data-abrir-dialogo` lo resuelve `shell.js`). --}}
            <button type="button" class="btn btn-sm btn-outline-secondary" data-abrir-dialogo="dialogo-prenatal">
                <i class="bi bi-question-circle" aria-hidden="true"></i> ¿Cómo funciona?
            </button>
            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']"
                         :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
        </x-slot>

        {{-- Las gestas de la historia: la que se está mirando se elige con un enlace, como las pestañas. --}}
        @if (! $vm->sinGestas())
            <x-ui.chips class="mb-3" :opciones="$vm->gestas()" etiqueta="Gestas" />
        @else
            <p class="texto-tenue small mb-3">
                Esta historia todavía no tiene ninguna gesta.
            </p>
        @endif

        <form method="POST" action="{{ $vm->urlGuardar() }}" id="prenatal-form"
              data-consulta-prenatal data-hoy="{{ $vm->hoyIso() }}" data-dias-fpp="{{ \App\Clinica\Embarazo\EdadGestacional::DIAS_DE_LA_FPP }}" data-aviso="{{ $vm->avisoSinGuardar() }}">
            @csrf
            {{-- La gesta que se guarda: el número de la que se está mirando, o vacío para crear la siguiente. --}}
            <input type="hidden" name="gesta" value="{{ $vm->clave() ?? '' }}">

            {{-- Los dos renglones del escritorio: `FUR · FPP · EG · Menarquia · Gestas` y
                 `Partos · Césareas · Abortos · Otros · Tipiaje (madre y padre)`. --}}
            @foreach ($vm->renglones() as $renglon)
                <div class="row g-2 mb-2">
                    @foreach ($renglon as $campo)
                        @include('clinica.consulta._embarazo-campo', ['campo' => $campo])
                    @endforeach
                </div>
            @endforeach

            {{-- El memo grande del escritorio: las observaciones, a todo el ancho. --}}
            <div class="row g-2 mt-1 mb-2">
                @include('clinica.consulta._embarazo-campo', ['campo' => $observaciones])
            </div>

            {{-- El estado del embarazo: los tres controles de la barra lateral del escritorio, juntos y
                 discretos al pie del bloque (O-21). La casilla guarda `S`/`N` como la escribe el escritorio;
                 el `hidden` que va antes hace que el formulario mande siempre el estado completo. --}}
            <div class="estado-embarazo">
                @foreach ($vm->estadoDelEmbarazo() as $campo)
                    @if ($campo['tipo'] === 'casilla')
                        <div class="form-check mb-0">
                            <input type="hidden" name="{{ $campo['columna'] }}" value="N">
                            <input class="form-check-input" type="checkbox" name="{{ $campo['columna'] }}"
                                   id="{{ $campo['columna'] }}" value="S" @if ($campo['marcada']) checked @endif>
                            <label class="form-check-label" for="{{ $campo['columna'] }}">{{ $campo['etiqueta'] }}</label>
                        </div>
                    @else
                        <div class="estado-embarazo-numero">
                            <label class="form-label mb-0" for="{{ $campo['columna'] }}">{{ $campo['etiqueta'] }}</label>
                            <input type="number" step="1" min="1" max="6" class="form-control form-control-sm"
                                   id="{{ $campo['columna'] }}" name="{{ $campo['columna'] }}"
                                   value="{{ $campo['valor'] }}">
                        </div>
                    @endif
                @endforeach
            </div>

            <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-0" id="prenatal-sin-guardar" hidden>
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span>Hay cambios sin guardar.</span>
            </p>

            <div class="d-flex justify-content-end gap-2 mt-3">
                {{-- "Nueva gesta" es un enlace que abre el formulario en blanco: la gesta se crea al guardar. --}}
                @if (! $vm->sinGestas() && ! $vm->esNueva())
                    <button type="button" class="btn btn-outline-secondary" data-abrir-dialogo="dialogo-gesta">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> Nueva gesta
                    </button>
                @endif
                <button class="btn btn-primary">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar la gesta
                </button>
            </div>
        </form>
    </x-ui.tarjeta>
</div>

{{-- «¿Cómo funciona?» (O-23): lo que antes era el párrafo de apertura y la lista de tres pasos, detrás de un
     botón al lado del título. Es un diálogo nativo, el mismo patrón del tour y de los diálogos de alertas. --}}
<x-ui.dialogo id="dialogo-prenatal" titulo="¿Cómo funciona el control prenatal?">
    <div class="dialogo-cuerpo">
        <p>{{ $vm->comoFunciona() }}</p>

        <ol class="pasos-gesta">
            @foreach ($vm->pasosDelUso() as $paso)
                <li><strong>{{ $paso['titulo'] }}</strong> {{ $paso['texto'] }}</li>
            @endforeach
        </ol>

        <p class="texto-tenue small mb-0">{{ $vm->comoSeGuarda() }}</p>
    </div>
    <div class="dialogo-pie">
        <button type="button" class="btn btn-primary" data-cerrar-dialogo>Entendido</button>
    </div>
</x-ui.dialogo>

{{-- «Nueva gesta» (WEB-11.15): sólo pregunta cuántos bebés son, como la ventana del escritorio. --}}
<x-ui.dialogo id="dialogo-gesta" titulo="Nueva gesta" lateral>
    <form method="POST" action="{{ $vm->urlCrearGesta() }}">
        @csrf
        <div class="dialogo-cuerpo">
            <x-ui.campo etiqueta="¿Cuántos bebés son?" for="gesta-bebes" requerido
                        ayuda="Con 2 o más, el embarazo queda como múltiple.">
                <input type="number" id="gesta-bebes" name="bebes" class="form-control" min="1" max="6" value="1">
            </x-ui.campo>
        </div>
        <div class="dialogo-pie">
            <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cancelar</button>
            <button class="btn btn-primary">Crear la gesta</button>
        </div>
    </form>
</x-ui.dialogo>
