{{-- **Las secciones propias de la consulta del internista** (MI-2 a MI-5, 2026-10-28): evaluación física, plan de diagnóstico,
     observaciones, análisis evolutivo y evaluación pre-operatoria. Los cinco parciales `_<seccion>` incluyen este: tienen la
     misma forma (pestañas y un formulario de campos del `MapaDelInternista`). El aviso de cambios sin guardar lo pone
     `consulta-internista.js` con `data-aviso`. --}}
<div class="consulta-seccion" id="{{ $seccion->id() }}">
    @if ($seccion->pestanas() !== [])
        <x-ui.chips class="mb-3" :opciones="$seccion->pestanas()" :etiqueta="$seccion->titulo()" />
    @endif

    <x-ui.tarjeta :titulo="$seccion->titulo()" :icono="$seccion->icono()">
        @if ($seccion->urlImprimir())
            <x-slot name="acciones">
                <a class="btn btn-sm btn-outline-secondary" href="{{ $seccion->urlImprimir() }}" id="internista-imprimir">
                    <i class="bi bi-printer me-1" aria-hidden="true"></i> Imprimir
                </a>
            </x-slot>
        @endif

        @if ($seccion->esAnalisis())
            @include('clinica.consulta._analisis-evolutivo-tabla')
        @elseif ($seccion->esRiesgo())
            @include('clinica.consulta._quirurgico-riesgo', ['seccion' => $seccion])
        @elseif ($seccion->tieneFormulario())
            <form method="POST" action="{{ $seccion->urlGuardar() }}" id="internista-form"
                  data-consulta-internista data-aviso="{{ $seccion->avisoSinGuardar() }}">
                @csrf
                <input type="hidden" name="pestana" value="{{ $seccion->pestana() }}">

                <div class="row g-3">
                    @foreach ($seccion->campos() as $campo)
                        <div class="{{ $campo['columna'] }}">
                            @if ($campo['tipo'] === 'si_no')
                                <input type="hidden" name="{{ $campo['clave'] }}" value="N">
                                <div class="form-check mt-md-4">
                                    <input class="form-check-input" type="checkbox" value="S"
                                           id="{{ $campo['id'] }}" name="{{ $campo['clave'] }}" @checked($campo['marcado'])>
                                    <label class="form-check-label" for="{{ $campo['id'] }}">{{ $campo['etiqueta'] }}</label>
                                </div>
                            @else
                                <label class="form-label" for="{{ $campo['id'] }}">
                                    {{ $campo['etiqueta'] }}
                                    @if ($campo['unidad'])<span class="texto-tenue small">({{ $campo['unidad'] }})</span>@endif
                                </label>

                                @if ($campo['tipo'] === 'parrafo')
                                    <textarea class="form-control @error($campo['clave']) is-invalid @enderror" rows="3"
                                              id="{{ $campo['id'] }}" name="{{ $campo['clave'] }}"
                                              @if ($campo['max']) maxlength="{{ $campo['max'] }}" @endif>{{ $campo['valor'] }}</textarea>
                                @elseif ($campo['tipo'] === 'opcion')
                                    <select class="form-select @error($campo['clave']) is-invalid @enderror"
                                            id="{{ $campo['id'] }}" name="{{ $campo['clave'] }}">
                                        <option value="">— Sin dato —</option>
                                        @foreach ($campo['opciones'] as $valor => $texto)
                                            <option value="{{ $valor }}" @selected($campo['valor'] === (string) $valor)>{{ $texto }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <input type="text" class="form-control @error($campo['clave']) is-invalid @enderror"
                                           id="{{ $campo['id'] }}" name="{{ $campo['clave'] }}" value="{{ $campo['valor'] }}"
                                           autocomplete="off"
                                           @if ($campo['tipo'] === 'numero') inputmode="decimal" @endif
                                           @if ($campo['max']) maxlength="{{ $campo['max'] }}" @endif>
                                @endif
                                @error($campo['clave'])<div class="invalid-feedback">{{ $message }}</div>@enderror
                            @endif
                        </div>
                    @endforeach
                </div>

                <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3" id="internista-form-sin-guardar" hidden>
                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                    <span>Hay datos sin guardar.</span>
                </p>

                <div class="d-flex justify-content-end gap-2 mt-3">
                    <a class="btn btn-outline-secondary" href="{{ $seccion->urlVolver() }}">Cancelar</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1" aria-hidden="true"></i> {{ $seccion->textoGuardar() }}
                    </button>
                </div>
            </form>
        @endif
    </x-ui.tarjeta>
</div>
