{{-- **Indicadores incidentes** (nefrología pediátrica, 2026-10-19): el ítem 5 del árbol de PediaReport
     (`tabpage_35` del escritorio). Se teclean los valores de laboratorio y el resumen se **compone** con las
     etiquetas y el orden del escritorio; al guardar, ese texto queda en `consultas.resultadoexamencomp`, que es lo
     que imprimen el resumen médico y la evaluación pre-operatoria.

     Los números **no se guardan** (el escritorio tampoco los guarda): el panel abre vacío en cada consulta y lo que
     queda es el resumen. Por eso se muestra siempre el resumen ya guardado, de sólo lectura.

     El aviso de cambios sin guardar lo pone `consulta.js` a partir de `data-aviso`, como en las otras secciones. --}}
@php
    $estado = $seccion->estado();
@endphp

<div class="consulta-seccion" id="consulta-indicadores">
    <x-ui.tarjeta titulo="Indicadores incidentes (nefrología pediátrica)" icono="bi-droplet-half">
        <x-slot name="acciones">
            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']"
                         :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
        </x-slot>

        <p class="text-muted small mb-3">{{ $seccion->ayuda() }}</p>

        <form method="POST" action="{{ $seccion->urlGuardar() }}" id="indicadores-form"
              data-consulta-indicadores data-aviso="{{ $seccion->avisoSinGuardar() }}">
            @csrf

            @foreach ($seccion->grupos() as $grupo)
                <fieldset class="mb-4">
                    <legend class="h6 text-uppercase text-muted mb-2">{{ $grupo['titulo'] }}</legend>

                    <div class="row g-3">
                        @foreach ($grupo['campos'] as $campo)
                            <div class="col-6 col-md-3">
                                <label class="form-label" for="indicador-{{ $campo['clave'] }}">{{ $campo['etiqueta'] }}</label>
                                <input type="text" class="form-control @error($campo['clave']) is-invalid @enderror"
                                       id="indicador-{{ $campo['clave'] }}" name="{{ $campo['clave'] }}"
                                       value="{{ $campo['valor'] }}" inputmode="decimal" autocomplete="off"
                                       placeholder="{{ $seccion->marcador() }}">
                                @error($campo['clave'])<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach

            <p class="alert alert-warning py-2 d-flex align-items-center gap-2" id="indicadores-form-sin-guardar" hidden>
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span>Hay valores sin guardar.</span>
            </p>

            <div class="d-flex justify-content-end gap-2">
                <a class="btn btn-outline-secondary" href="{{ $seccion->urlVolver() }}">Cancelar</a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1" aria-hidden="true"></i> Guardar los indicadores
                </button>
            </div>
        </form>
    </x-ui.tarjeta>

    <x-ui.tarjeta titulo="Resumen guardado en la consulta" icono="bi-file-text">
        @if ($seccion->tieneGuardado())
            <p class="text-muted small mb-2">
                Es lo que se imprime en el resumen médico y en la evaluación pre-operatoria. Guardar otra vez
                reemplaza el resumen completo; no se puede editar por partes.
            </p>
            <pre class="mb-0 text-wrap" id="indicadores-guardado">{{ $seccion->guardado() }}</pre>
        @else
            <p class="text-muted mb-0">
                Esta consulta todavía no tiene un resumen de laboratorio. Escribe los valores y guárdalos: el texto
                se arma solo.
            </p>
        @endif
    </x-ui.tarjeta>
</div>
