{{-- Un campo del estudio ecográfico (WEB-6.1). Existe como parcial para no repetir el mismo bloque cinco
     veces (los nueve grupos × cinco tipos de control) en `_estudio-ginecologico.blade.php`.

     Variables: `$campo` (columna, etiqueta, unidad, ayuda, tipo, valor, opciones) y `$columnas` (cuántas
     ocupa). `tipo` sale del mapa del dato (`App\Clinica\Consulta\EcoGinecologico`) y dice con qué control se
     pinta: `opciones` (un `<select>` con los códigos del legado), `numero`, `texto`, `area`, `fecha` y
     `fecha-tecleada`. La vista no decide: sólo obedece. --}}
{{-- `$omitirEtiqueta` (2026-10-23): el campo único de un grupo que se llama igual que el grupo («Fondo de saco de Douglas» bajo el
     título «Fondo de saco de Douglas») no repite el rótulo a la vista; queda oculto para los lectores de pantalla. --}}
<x-ui.campo :etiqueta="$campo['etiqueta']" :for="$campo['columna']"
            class="{{ $columnas }}{{ ($omitirEtiqueta ?? false) ? ' etiqueta-oculta' : '' }}">
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
             que las flechas del control no pueden llevar a un negativo que después se rechace.

             El paso lo decide el mapa: `1` en los enteros del escritorio (máscara `###`: el navegador no deja
             escribir decimales) y `0.01`/`any` en los decimales (máscara `###.00`). --}}
        <div class="examen-unidad">
            <input type="number" step="{{ ($campo['entero'] ?? false) ? '1' : ($campo['paso'] ?? 'any') }}"
                   min="0" inputmode="{{ ($campo['entero'] ?? false) ? 'numeric' : 'decimal' }}" class="form-control"
                   id="{{ $campo['columna'] }}" name="{{ $campo['columna'] }}"
                   value="{{ $campo['valor'] }}">
            @if (($campo['unidad'] ?? null))
                <span>{{ ($campo['unidad'] ?? null) }}</span>
            @endif
        </div>
    @elseif ($campo['tipo'] === 'fecha')
        <input type="date" @if ($campo['columna'] === 'fur') max="{{ \App\Clinica\FechaDelConsultorio::hoyComoTexto() }}" @endif class="form-control" id="{{ $campo['columna'] }}" name="{{ $campo['columna'] }}"
               value="{{ $campo['valor'] }}">
    @elseif ($campo['tipo'] === 'fecha-tecleada')
        {{-- Una fecha que se **teclea** `dd/mm/aaaa` —o `mm/dd/aaaa`, según el dispositivo (WEB-13.5)— (O-18: la FUR, como en el escritorio): la máscara
             pone las barras y no deja escribir un mes 13 ni un día 32 (`public/js/clinica/mascara-fecha.js`).
             El servidor la pasa a `AAAA-MM-DD` antes de escribirla en su columna `date`. --}}
        <input type="text" class="form-control" inputmode="numeric" autocomplete="off" maxlength="10"
               placeholder="{{ \App\Support\Regionalizacion::actual()->patronDeTecleo() }}" data-mascara="fecha" @if ($campo['columna'] === 'fur') data-max="{{ \App\Clinica\FechaDelConsultorio::hoyComoTexto() }}" @endif
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
