{{-- Un campo del estudio ecográfico (WEB-6.1). Existe como parcial para no repetir el mismo bloque cinco
     veces (los nueve grupos × cinco tipos de control) en `_estudio-ginecologico.blade.php`.

     Variables: `$campo` (columna, etiqueta, unidad, ayuda, tipo, valor, opciones) y `$columnas` (cuántas
     ocupa). `tipo` sale del mapa del dato (`App\Clinica\Consulta\EcoGinecologico`) y dice con qué control se
     pinta: `opciones` (un `<select>` con los códigos del legado), `numero`, `texto`, `area`, `fecha` y
     `fecha-tecleada`. La vista no decide: sólo obedece. --}}
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
        {{-- O-15: el campo arranca **vacío** cuando el legado dejó su marcador `-1` (el mapa ya lo lee como
             «sin dato») y el incremento **empieza en 0**: `min="0"` es el mismo piso que valida el servidor, así
             que las flechas del control no pueden llevar a un negativo que después se rechace. --}}
        <div class="examen-unidad">
            <input type="number" step="any" min="0" inputmode="decimal" class="form-control"
                   id="{{ $campo['columna'] }}" name="{{ $campo['columna'] }}"
                   value="{{ $campo['valor'] }}">
            @if (($campo['unidad'] ?? null))
                <span>{{ ($campo['unidad'] ?? null) }}</span>
            @endif
        </div>
    @elseif ($campo['tipo'] === 'fecha')
        <input type="date" class="form-control" id="{{ $campo['columna'] }}" name="{{ $campo['columna'] }}"
               value="{{ $campo['valor'] }}">
    @elseif ($campo['tipo'] === 'fecha-tecleada')
        {{-- Una fecha que se **teclea** `dd/mm/aaaa` (O-18: la FUR, como en el escritorio): la máscara
             pone las barras y no deja escribir un mes 13 ni un día 32 (`public/js/clinica/mascara-fecha.js`).
             El servidor la pasa a `AAAA-MM-DD` antes de escribirla en su columna `date`. --}}
        <input type="text" class="form-control" inputmode="numeric" autocomplete="off" maxlength="10"
               placeholder="dd/mm/aaaa" data-mascara="fecha"
               id="{{ $campo['columna'] }}" name="{{ $campo['columna'] }}"
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
