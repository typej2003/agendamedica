{{-- Un campo de la evaluación física (WEB-4.3). Existe como parcial para no repetir el mismo bloque
     seis veces (tres listas × dos tipos de control) en `_evaluacion.blade.php`.

     Variables: `$campo` (columna, etiqueta, unidad, ayuda, tipo, mascara, enteros, decimales, valor) y
     `$columnas` (cuántas ocupa).

     `tipo` sale del mapa del dato (`App\Clinica\Consulta\ExamenFisicoDeLaConsulta`) y dice con qué control
     se pinta: `numero` para las medidas que se teclean (las columnas `double` de `consultas`) y `texto` para
     `tension` (el `char(8)` con máscara `###/###` del escritorio); `area` es el texto libre de cada aparato.
     La vista no decide: sólo obedece. La `ayuda` del mapa es el **hint dentro del campo** (`placeholder`): no hay texto
     de ayuda debajo, para ahorrar alto (pedido del usuario, 2026-10-20).

     **La máscara (O-08)** es de la pantalla y la pone `public/js/clinica/mascara-numero.js` a partir de
     `data-mascara` + `data-enteros`/`data-decimales`: el control es `type="text"` —un `type="number"` no
     puede mostrar `1,62`— con el `inputmode` que corresponde para que el teléfono abra el teclado numérico.
     El dato que viaja lo devuelve al formato de la base el servidor (`App\Support\NumeroDeFormulario`):
     `1,62` en la pantalla, `1.62` en la columna. --}}
<x-ui.campo :etiqueta="$campo['etiqueta']" :for="$campo['columna']"
            class="{{ $columnas }}">
    @if ($campo['tipo'] === 'numero')
        <div class="examen-unidad">
            <input type="text" class="form-control" autocomplete="off" placeholder="{{ $campo['ayuda'] }}"
                   inputmode="{{ $campo['mascara'] === 'entero' ? 'numeric' : 'decimal' }}"
                   id="{{ $campo['columna'] }}" name="{{ $campo['columna'] }}"
                   value="{{ $campo['valor'] }}"
                   data-mascara="{{ $campo['mascara'] }}"
                   data-enteros="{{ $campo['enteros'] }}"
                   data-decimales="{{ $campo['decimales'] }}">
            @if ($campo['unidad'])
                <span>{{ $campo['unidad'] }}</span>
            @endif
        </div>
    @elseif ($campo['tipo'] === 'texto')
        <div class="examen-unidad">
            <input type="text" class="form-control" maxlength="12" placeholder="120/80"
                   autocomplete="off" inputmode="numeric"
                   id="{{ $campo['columna'] }}" name="{{ $campo['columna'] }}"
                   value="{{ $campo['valor'] }}"
                   data-mascara="{{ $campo['mascara'] }}"
                   data-enteros="{{ $campo['enteros'] }}">
            @if ($campo['unidad'])
                <span>{{ $campo['unidad'] }}</span>
            @endif
        </div>
    @else
        <textarea class="form-control" rows="4" id="{{ $campo['columna'] }}" placeholder="{{ $campo['ayuda'] }}"
                  name="{{ $campo['columna'] }}">{{ $campo['valor'] }}</textarea>
    @endif
</x-ui.campo>
