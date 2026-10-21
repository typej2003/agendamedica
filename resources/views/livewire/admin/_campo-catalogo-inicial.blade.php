{{-- Un campo del formulario del catálogo inicial: sale de `CatalogosIniciales` (`columna => [rotulo, tipo, max, requerido, mayusculas, grupo?, ayuda?]`). --}}
@php([$rotulo, $tipo, $max, $requerido] = $campo)
@php($ayuda = $campo[6] ?? null)
<div class="{{ in_array($tipo, ['area', 'porkilo'], true) ? 'col-12' : ($tipo === 'decimal' ? 'col-6 col-md-4' : 'col-md-6') }}">
    @if ($tipo === 'porkilo')
        <div class="form-check form-switch">
            <input class="form-check-input dosis-por-kilo__casilla" type="checkbox" role="switch" id="ini-{{ $columna }}" wire:model.defer="f.{{ $columna }}">
            <label class="form-check-label" for="ini-{{ $columna }}">{{ $rotulo }}</label>
        </div>
    @elseif ($tipo === 'marca')
        <div class="form-check mt-4">
            <input class="form-check-input" type="checkbox" id="ini-{{ $columna }}" wire:model.defer="f.{{ $columna }}">
            <label class="form-check-label" for="ini-{{ $columna }}">{{ $rotulo }}</label>
        </div>
    @else
        <label class="form-label" for="ini-{{ $columna }}">{{ $rotulo }}@if ($requerido) <span class="text-danger" aria-hidden="true">*</span>@endif</label>
        @if ($tipo === 'area')
            <textarea id="ini-{{ $columna }}" rows="{{ $max > 5000 ? 8 : 3 }}" class="form-control" wire:model.defer="f.{{ $columna }}"
                      @if ($ayuda) aria-describedby="ini-{{ $columna }}-ayuda" @endif></textarea>
        @elseif ($tipo === 'ref' || $tipo === 'tipo')
            <select id="ini-{{ $columna }}" class="form-select" wire:model.defer="f.{{ $columna }}">
                <option value="">Elige…</option>
                @foreach ($opciones[$columna] ?? [] as $valor => $texto)
                    <option value="{{ $valor }}">{{ $texto }}</option>
                @endforeach
            </select>
        @elseif ($tipo === 'decimal')
            <input type="number" step="any" min="0" id="ini-{{ $columna }}" class="form-control" wire:model.defer="f.{{ $columna }}"
                   @if ($ayuda) aria-describedby="ini-{{ $columna }}-ayuda" @endif>
        @else
            <input type="text" id="ini-{{ $columna }}" class="form-control" @if ($tipo === 'numero') inputmode="decimal" @endif
                   @if ($max > 0) maxlength="{{ $max }}" @endif wire:model.defer="f.{{ $columna }}">
        @endif
        @if ($ayuda)
            <div class="form-text" id="ini-{{ $columna }}-ayuda">{{ $ayuda }}</div>
        @endif
    @endif
</div>
