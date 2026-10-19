{{-- **Eco renal** (nefrología pediátrica, 2026-10-19): el formulario del eco que el escritorio abre desde el panel de
     Indicadores incidentes (`w_cargar_visualizar_renal`). Los grupos salen del mapa `EcoRenal`. --}}
@php
    $vm = $seccion->ecoRenal();
@endphp

@if ($vm === null)
    <p class="text-muted mb-0">El eco renal no está disponible.</p>
@else
    <p class="text-muted small mb-3">{{ $vm->ayuda() }}</p>

    <form method="POST" action="{{ $vm->urlGuardar() }}" id="eco-renal-form"
          data-consulta-eco-renal data-aviso="{{ $vm->avisoSinGuardar() }}">
        @csrf

        @foreach ($vm->grupos() as $grupo)
            <fieldset class="mb-4">
                <legend class="h6 text-uppercase text-muted mb-2">{{ $grupo['titulo'] }}</legend>

                <div class="row g-3">
                    @foreach ($grupo['campos'] as $campo)
                        <div class="{{ $campo['clase'] === 'parrafo' ? 'col-12' : 'col-12 col-md-4' }}">
                            <label class="form-label" for="renal-{{ $campo['clave'] }}">{{ $campo['etiqueta'] }}</label>

                            @if ($campo['clase'] === 'fecha')
                                <input type="date" class="form-control" id="renal-{{ $campo['clave'] }}"
                                       name="{{ $campo['clave'] }}" value="{{ $campo['valor'] }}">
                            @elseif ($campo['clase'] === 'parrafo')
                                <textarea class="form-control" rows="3" id="renal-{{ $campo['clave'] }}"
                                          name="{{ $campo['clave'] }}">{{ $campo['valor'] }}</textarea>
                            @else
                                <input type="text" class="form-control" id="renal-{{ $campo['clave'] }}"
                                       name="{{ $campo['clave'] }}" value="{{ $campo['valor'] }}">
                            @endif
                        </div>
                    @endforeach
                </div>
            </fieldset>
        @endforeach

        <p class="alert alert-warning py-2 d-flex align-items-center gap-2" id="eco-renal-form-sin-guardar" hidden>
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span>Hay datos sin guardar.</span>
        </p>

        <div class="d-flex justify-content-end gap-2">
            <a class="btn btn-outline-secondary" href="{{ $vm->urlVolver() }}">Cancelar</a>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save me-1" aria-hidden="true"></i> Guardar el eco renal
            </button>
        </div>
    </form>
@endif
