{{-- Un campo de la evaluación física (WEB-4.3). Existe como parcial para no repetir el mismo bloque
     seis veces (tres listas × dos tipos de control) en `_evaluacion.blade.php`.

     Variables: `$campo` (columna, etiqueta, unidad, ayuda, tipo, valor) y `$columnas` (cuántas ocupa).

     `tipo` sale del mapa del dato (`App\Clinica\Consulta\ExamenFisicoDeLaConsulta`) y dice con qué control
     se pinta: `numero` para las columnas `double` de `consultas` y el entero del examen, `texto` para
     `tension` (el `char(8)` con máscara `###/###` del escritorio) y `area` para el texto libre de cada
     aparato. La vista no decide: sólo obedece. --}}
<x-ui.campo :etiqueta="$campo['etiqueta']" :for="$campo['columna']" :ayuda="$campo['ayuda']"
            class="{{ $columnas }}">
    @if ($campo['tipo'] === 'numero')
        <div class="examen-unidad">
            <input type="number" step="any" inputmode="decimal" class="form-control"
                   id="{{ $campo['columna'] }}" name="{{ $campo['columna'] }}"
                   value="{{ $campo['valor'] }}">
            @if ($campo['unidad'])
                <span>{{ $campo['unidad'] }}</span>
            @endif
        </div>
    @elseif ($campo['tipo'] === 'texto')
        <div class="examen-unidad">
            <input type="text" class="form-control" maxlength="12" placeholder="120/80"
                   id="{{ $campo['columna'] }}" name="{{ $campo['columna'] }}"
                   value="{{ $campo['valor'] }}">
            @if ($campo['unidad'])
                <span>{{ $campo['unidad'] }}</span>
            @endif
        </div>
    @else
        <textarea class="form-control" rows="4" id="{{ $campo['columna'] }}"
                  name="{{ $campo['columna'] }}">{{ $campo['valor'] }}</textarea>
    @endif
</x-ui.campo>
