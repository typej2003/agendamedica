{{-- **Riesgo quirúrgico** (cirugía pediátrica, 2026-10-19): la tercera solapa del ítem «I.Q.». El catálogo de
     factores del médico (`riesgo`) con lo marcado al paciente (`riesgo_paciente`) y el resumen de la consulta
     (`riesgo_paciente_opera`: puntos, clase, conclusión e intervención). --}}
@php
    $vm = $seccion->riesgo();
@endphp

@if ($vm === null)
    <p class="text-muted mb-0">El riesgo quirúrgico no está disponible.</p>
@else
    <p class="text-muted small mb-3">{{ $vm->ayuda() }}</p>

    @if (! $vm->tieneCatalogo())
        <div class="alert alert-info py-2 mb-3">
            <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
            Todavía no hay factores de riesgo en el catálogo del médico. Se cargan desde
            <a href="{{ route('clinica.catalogos') }}">Catálogos</a>.
        </div>
    @endif

    <form method="POST" action="{{ $vm->urlGuardar() }}" id="riesgo-form"
          data-consulta-quirurgico data-aviso="{{ $vm->avisoSinGuardar() }}">
        @csrf

        @foreach ($vm->grupos() as $grupo)
            <fieldset class="mb-3">
                <legend class="h6 text-uppercase text-muted mb-2">{{ $grupo['titulo'] }}</legend>

                <div class="row g-2">
                    @foreach ($grupo['factores'] as $factor)
                        <div class="col-12 col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="marcadas[]"
                                       id="riesgo-{{ \Illuminate\Support\Str::slug($factor['descripcion']) }}"
                                       value="{{ $factor['descripcion'] }}"
                                       @if ($factor['marcado']) checked @endif>
                                <label class="form-check-label" for="riesgo-{{ \Illuminate\Support\Str::slug($factor['descripcion']) }}">
                                    {{ $factor['descripcion'] }}
                                    <span class="texto-tenue small">({{ $factor['puntos'] }} pts)</span>
                                </label>
                            </div>
                        </div>
                    @endforeach
                </div>
            </fieldset>
        @endforeach

        <p class="mb-3"><strong>Puntos totales:</strong> {{ $vm->puntos() }}</p>

        <div class="row g-3">
            @foreach ($vm->campos() as $campo)
                <div class="{{ $campo['clase'] === 'parrafo' ? 'col-12' : 'col-12 col-md-4' }}">
                    <label class="form-label" for="riesgo-{{ $campo['clave'] }}">{{ $campo['etiqueta'] }}</label>

                    @if ($campo['clase'] === 'parrafo')
                        <textarea class="form-control" rows="3" id="riesgo-{{ $campo['clave'] }}"
                                  name="{{ $campo['clave'] }}">{{ $campo['valor'] }}</textarea>
                    @else
                        <input type="text" class="form-control" id="riesgo-{{ $campo['clave'] }}"
                               name="{{ $campo['clave'] }}" value="{{ $campo['valor'] }}">
                    @endif
                </div>
            @endforeach
        </div>

        <div class="d-flex justify-content-end gap-2 mt-3">
            <a class="btn btn-outline-secondary" href="{{ $vm->urlVolver() }}">Cancelar</a>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-1" aria-hidden="true"></i> Guardar el riesgo
            </button>
        </div>
    </form>
@endif
