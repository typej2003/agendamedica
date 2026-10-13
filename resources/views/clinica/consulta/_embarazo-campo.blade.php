{{-- Un campo de la gesta (WEB-6.6). Variables: `$campo` (columna, etiqueta, ayuda, tipo, valor) y
     `$columnas` (cuántas ocupa).

     `tipo` sale del mapa del dato (`App\Clinica\Embarazo\Gesta::campos()`) y dice con qué control se pinta:
     `fecha` (la FUR), `numero` (el campo *Gestas*), `texto` (menarquia, parto y los dos tipiajes) y `area`
     (las observaciones). La vista no decide: sólo obedece. --}}
<x-ui.campo :etiqueta="$campo['etiqueta']" :for="$campo['columna']" :ayuda="$campo['ayuda']"
            class="{{ $columnas }}">
    @if ($campo['tipo'] === 'fecha')
        <input type="date" class="form-control" id="{{ $campo['columna'] }}" name="{{ $campo['columna'] }}"
               value="{{ $campo['valor'] }}">
    @elseif ($campo['tipo'] === 'opciones')
        {{-- Un desplegable con la opción vacía: en el escritorio son casillas (Final de Embarazo, Embarazo
             multiple) y la columna guarda "S" o "N"; sin marcar queda vacía. --}}
        <select class="form-select" id="{{ $campo['columna'] }}" name="{{ $campo['columna'] }}">
            <option value="">—</option>
            @foreach ($campo['opciones'] as $opcion)
                <option value="{{ $opcion['valor'] }}" @if ((string) $campo['valor'] === (string) $opcion['valor']) selected @endif>{{ $opcion['texto'] }}</option>
            @endforeach
        </select>
    @elseif ($campo['tipo'] === 'numero')
        <input type="number" step="1" min="0" class="form-control" id="{{ $campo['columna'] }}"
               name="{{ $campo['columna'] }}" value="{{ $campo['valor'] }}">
    @elseif ($campo['tipo'] === 'area')
        <textarea class="form-control" rows="4" id="{{ $campo['columna'] }}"
                  name="{{ $campo['columna'] }}">{{ $campo['valor'] }}</textarea>
    @else
        <input type="text" class="form-control" maxlength="{{ $campo['max'] ?? 10 }}" id="{{ $campo['columna'] }}"
               name="{{ $campo['columna'] }}" value="{{ $campo['valor'] }}">
    @endif
</x-ui.campo>
