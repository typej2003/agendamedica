{{-- **Los estudios cardiológicos de la consulta** (CAR-2, 2026-11-04): el ecocardiograma transtorácico y las pestañas
     que sumen CAR-3 a CAR-5. El formulario es el de las secciones del internista: pestañas + campos del
     `MapaDelCardiologo`, y el guardado es explícito. --}}
<div class="consulta-seccion" id="{{ $seccion->id() }}">
    @if ($seccion->pestanas() !== [])
        <x-ui.chips class="mb-3" :opciones="$seccion->pestanas()" :etiqueta="$seccion->titulo()" />
    @endif

    <x-ui.tarjeta :titulo="$seccion->titulo()" :icono="$seccion->icono()">
        <form method="POST" action="{{ $seccion->urlGuardar() }}" id="cardiologia-form">
            @csrf
            <input type="hidden" name="pestana" value="{{ $seccion->pestana() }}">

            <div class="row g-3">
                @foreach ($seccion->campos() as $campo)
                    <div class="{{ $campo['columna'] }}">
                        <label class="form-label" for="{{ $campo['id'] }}">
                            {{ $campo['etiqueta'] }}
                            @if ($campo['unidad'])<span class="texto-tenue small">({{ $campo['unidad'] }})</span>@endif
                        </label>

                        @if ($campo['tipo'] === 'si_no')
                            <input type="hidden" name="{{ $campo['clave'] }}" value="N">
                            <div class="form-check mt-md-4">
                                <input class="form-check-input" type="checkbox" value="S"
                                       id="{{ $campo['id'] }}" name="{{ $campo['clave'] }}" @checked($campo['marcado'])>
                                <label class="form-check-label" for="{{ $campo['id'] }}">{{ $campo['etiqueta'] }}</label>
                            </div>
                        @elseif ($campo['tipo'] === 'parrafo')
                            <textarea class="form-control @error($campo['clave']) is-invalid @enderror" rows="4"
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
                                   autocomplete="off" @if ($campo['max']) maxlength="{{ $campo['max'] }}" @endif>
                        @endif

                        @error($campo['clave'])<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                @endforeach
            </div>

            <div class="d-flex justify-content-end gap-2 mt-3">
                <a class="btn btn-outline-secondary" href="{{ $seccion->urlVolver() }}">Cancelar</a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1" aria-hidden="true"></i> {{ $seccion->textoGuardar() }}
                </button>
            </div>
        </form>
    </x-ui.tarjeta>
</div>
