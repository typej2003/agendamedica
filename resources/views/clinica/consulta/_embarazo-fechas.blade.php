{{-- El formulario de una pestaña del embarazo cuyas filas son **fechas** (WEB-6.6): *Laboratorio* y
     *Controles*. Es el mismo para las dos —la clave es `historia` + `gesta_clave` + `fecha`— y lo que cambia
     entra por el ViewModel (`App\Clinica\Embarazo\Vistas\FechasDelEmbarazoVista`), que a su vez recibe el
     mapa del dato de cada tabla (`PrenaExamenB::campos()` y `PreNatalDesarrolloFino::campos()`).

     `$claveTab` es la clave de la pestaña (`laboratorio` o `controles`) y se usa para el id del formulario:
     el aviso de cambios sin guardar de `consulta.js` se ata al formulario por `data-consulta-fechas` y al
     cartel por `<id del formulario>-sin-guardar`. --}}
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

        <p class="texto-tenue small mb-3">{{ $vm->ayuda() }}</p>

        {{-- Las fechas cargadas de esta gesta: la que se mira se elige con un enlace. --}}
        @if (! $vm->sinFilas())
            <x-ui.segmentado class="recipe-pestanas mb-3" :opciones="$vm->fechas()" etiqueta="Fechas" />
        @else
            <p class="texto-tenue small mb-3">
                Esta gesta todavía no tiene nada cargado en esta pestaña. Cargá la primera fecha y guardala.
            </p>
        @endif

        <form method="POST" action="{{ $vm->urlGuardar() }}" id="{{ $idFormulario }}"
              data-consulta-fechas data-aviso="{{ $vm->avisoSinGuardar() }}">
            @csrf
            {{-- La gesta a la que pertenece la fila: la clave es `historia` + `gesta_clave` + `fecha`. --}}
            <input type="hidden" name="gesta" value="{{ $vm->gestaClave() }}">

            <div class="row g-3 mb-3">
                <x-ui.campo etiqueta="Fecha" for="fecha" class="col-6 col-md-4"
                            ayuda="La fecha del examen: con ella se identifica la fila de la gesta.">
                    <input type="date" class="form-control" id="fecha" name="fecha" value="{{ $vm->fecha() }}">
                </x-ui.campo>

                @foreach ($vm->campos() as $campo)
                    @include('clinica.consulta._embarazo-campo', [
                        'campo' => $campo,
                        'columnas' => ($campo['tipo'] ?? 'texto') === 'area' ? 'col-12' : 'col-6 col-md-4',
                    ])
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
