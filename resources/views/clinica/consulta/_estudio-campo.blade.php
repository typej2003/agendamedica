{{-- Un campo del estudio ecográfico (WEB-6.1). Existe como parcial para no repetir el mismo bloque cinco
     veces (los nueve grupos × cinco tipos de control) en `_estudio-ginecologico.blade.php`.

     Variables: `$campo` (columna, etiqueta, unidad, ayuda, tipo, valor, opciones) y `$columnas` (cuántas
     ocupa). `tipo` sale del mapa del dato (`App\Clinica\Consulta\EcoGinecologico`) y dice con qué control se
     pinta: `opciones` (un `<select>` con los códigos del legado), `numero`, `texto`, `area` y `fecha`. La
     vista no decide: sólo obedece. --}}
<x-ui.campo :etiqueta="$campo['etiqueta']" :for="$campo['columna']" :ayuda="$campo['ayuda']"
            class="{{ $columnas }}">
    @if ($campo['tipo'] === 'opciones')
        <select class="form-select" id="{{ $campo['columna'] }}" name="{{ $campo['columna'] }}">
            @foreach ($campo['opciones'] as $opcion)
                {{-- `@selected` es de Blade 9 (como `@disabled`, ver `agenda/formulario.blade.php`): en
                     Laravel 8 el atributo se escribe con un `@if`. --}}
                <option value="{{ $opcion['valor'] }}" @if ($opcion['valor'] === $campo['valor']) selected @endif>{{ $opcion['texto'] }}</option>
            @endforeach
        </select>
    @elseif ($campo['tipo'] === 'numero')
        <div class="examen-unidad">
            <input type="number" step="any" inputmode="decimal" class="form-control"
                   id="{{ $campo['columna'] }}" name="{{ $campo['columna'] }}"
                   value="{{ $campo['valor'] }}">
            @if (($campo['unidad'] ?? null))
                <span>{{ ($campo['unidad'] ?? null) }}</span>
            @endif
        </div>
    @elseif ($campo['tipo'] === 'fecha')
        <input type="date" class="form-control" id="{{ $campo['columna'] }}" name="{{ $campo['columna'] }}"
               value="{{ $campo['valor'] }}">
    @elseif ($campo['tipo'] === 'area')
        <textarea class="form-control" rows="4" id="{{ $campo['columna'] }}"
                  name="{{ $campo['columna'] }}">{{ $campo['valor'] }}</textarea>
    @else
        <div class="examen-unidad">
            <input type="text" class="form-control" id="{{ $campo['columna'] }}" name="{{ $campo['columna'] }}"
                   value="{{ $campo['valor'] }}">
            @if (($campo['unidad'] ?? null))
                <span>{{ ($campo['unidad'] ?? null) }}</span>
            @endif
        </div>
    @endif
</x-ui.campo>
