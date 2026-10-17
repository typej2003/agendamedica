{{-- La pestaña *Prenatal* del control de embarazo (WEB-6.6): las gestas de la historia, con la FUR, la
     menarquia, el tipiaje y las dos cuentas que el escritorio muestra calculadas —la edad gestacional y la
     FPP—, con **guardado explícito**.

     Los campos salen del mapa del dato (`App\Clinica\Embarazo\Gesta::campos()`), que es el mismo que usan la
     Action y el resto de la sección. Las dos cuentas van **fuera del formulario** como `output`: se calculan
     de la FUR —el `compute` del DataWindow— y no se guardan en ninguna columna.

     El `gesta` oculto dice **qué** gesta se guarda: vacío significa la siguiente (*Nueva Gesta*), y el número
     lo asigna el servidor. El aviso de cambios sin guardar lo pone `consulta.js` desde los `data-*`. --}}
@php
    $estado = $vm->estado();
@endphp

<div class="consulta-seccion" id="consulta-embarazo-prenatal">
    <x-ui.tarjeta titulo="{{ $vm->titulo() }}" icono="bi-calendar-heart">
        <x-slot name="acciones">
            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']"
                         :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
        </x-slot>

        <p class="texto-tenue small mb-3">{{ $vm->ayuda() }}</p>

        {{-- El orden del flujo (WEB-11.15): en el escritorio los botones de laboratorio y controles aparecían recién después de crear la gesta y
             nadie lo entendía a la primera. Acá se explica. --}}
        <ol class="pasos-gesta">
            <li><strong>Crea la gesta</strong> (con «Nueva gesta», que sólo pregunta cuántos bebés son) y completa su FUR.</li>
            <li><strong>Registra sus laboratorios y controles</strong> en las otras pestañas del control de embarazo.</li>
            <li><strong>Al terminar el embarazo, marca «Final de embarazo»</strong>: sin eso no se puede crear la gesta siguiente.</li>
        </ol>

        {{-- Las gestas de la historia: la que se está mirando se elige con un enlace, como las pestañas. --}}
        @if (! $vm->sinGestas())
            <x-ui.chips class="mb-3" :opciones="$vm->gestas()" etiqueta="Gestas" />
        @else
            <p class="texto-tenue small mb-3">
                Esta historia todavía no tiene ninguna gesta. Cargá la primera con su FUR y guardala.
            </p>
        @endif

        <form method="POST" action="{{ $vm->urlGuardar() }}" id="prenatal-form"
              data-consulta-prenatal data-aviso="{{ $vm->avisoSinGuardar() }}">
            @csrf
            {{-- La gesta que se guarda: el número de la que se está mirando, o vacío para crear la siguiente. --}}
            <input type="hidden" name="gesta" value="{{ $vm->clave() ?? '' }}">

            <div class="row g-3 mb-3">
                @foreach ($vm->campos() as $campo)
                    @include('clinica.consulta._embarazo-campo', [
                        'campo' => $campo,
                        'columnas' => $campo['tipo'] === 'area' ? 'col-12' : 'col-6 col-md-4',
                    ])
                @endforeach

                {{-- Las dos cuentas del escritorio, calculadas de la FUR (no se guardan). --}}
                <x-ui.campo etiqueta="Edad gestacional" for="prenatal-eg" class="col-6 col-md-4"
                            ayuda="Calculada de la FUR. No se guarda.">
                    <output class="form-control-plaintext fw-semibold" id="prenatal-eg">{{ $vm->edadGestacional() ?? '—' }}</output>
                </x-ui.campo>

                <x-ui.campo etiqueta="FPP" for="prenatal-fpp" class="col-6 col-md-4"
                            ayuda="La FUR más 280 días (40 semanas). No se guarda.">
                    <output class="form-control-plaintext fw-semibold" id="prenatal-fpp">
                        {{ $vm->fpp() ? \App\Support\FechaClinica::formato($vm->fpp()) : '—' }}
                    </output>
                </x-ui.campo>
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

{{-- «Nueva gesta» (WEB-11.15): sólo pregunta cuántos bebés son, como la ventana del escritorio. --}}
<x-ui.dialogo id="dialogo-gesta" titulo="Nueva gesta">
    <form method="POST" action="{{ $vm->urlCrearGesta() }}">
        @csrf
        <div class="dialogo-cuerpo">
            <x-ui.campo etiqueta="¿Cuántos bebés son?" for="gesta-bebes" requerido
                        ayuda="1 si es un solo bebé; con 2 o más el embarazo queda como múltiple. La FUR y lo demás se completan después.">
                <input type="number" id="gesta-bebes" name="bebes" class="form-control" min="1" max="6" value="1">
            </x-ui.campo>
        </div>
        <div class="dialogo-pie">
            <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cancelar</button>
            <button class="btn btn-primary">Crear la gesta</button>
        </div>
    </form>
</x-ui.dialogo>
