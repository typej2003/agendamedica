{{-- Un campo de la gesta (WEB-6.6). Variable: `$campo` (columna, etiqueta, tipo, ancho, valor y, si es un
     desplegable, `opciones` ya resueltas).

     `tipo` sale del mapa del dato (`App\Clinica\Embarazo\Gesta::campos()`, `PrenaExamenB::campos()` o
     `PreNatalDesarrolloFino::campos()`) y dice con qué control se pinta: `fecha` (la FUR), `numero` (los
     conteos), `texto`, `area` (el memo y los campos largos) y `opciones` (los desplegables). `ancho` es la
     columna de la grilla: la decide el mapa del dato, no la vista, y es lo que hace que el renglón se lea
     como el del escritorio (O-21).

     En un desplegable, las opciones llegan **resueltas** por el ViewModel con `OpcionesDelDesplegable`: la
     vacía adelante y el valor que no está en la lista del escritorio al final. La vista sólo las recorre. --}}
<x-ui.campo :etiqueta="$campo['etiqueta']" :for="$campo['columna']" :info="$campo['info'] ?? null"
            class="{{ $campo['ancho'] ?? 'col-6 col-md-3' }}">
    @if ($campo['tipo'] === 'calculo')
        {{-- Una de las dos cuentas del escritorio (la EG y la FPP del control prenatal): se muestran
             calculadas y **no viajan en el formulario** —no tienen `name`—. --}}
        <output class="form-control-plaintext fw-semibold" id="{{ $campo['columna'] }}">{{ $campo['valor'] }}</output>
    @elseif ($campo['tipo'] === 'fecha')
        <input type="date" @if ($campo['columna'] === 'fur') max="{{ \App\Clinica\FechaDelConsultorio::hoyComoTexto() }}" @endif class="form-control" id="{{ $campo['columna'] }}" name="{{ $campo['columna'] }}"
               value="{{ $campo['valor'] }}">
    @elseif ($campo['tipo'] === 'opciones')
        <select class="form-select" id="{{ $campo['columna'] }}" name="{{ $campo['columna'] }}">
            @foreach ($campo['opciones'] as $opcion)
                <option value="{{ $opcion['valor'] }}" @if ((string) $campo['valor'] === (string) $opcion['valor']) selected @endif>{{ $opcion['texto'] }}</option>
            @endforeach
        </select>
    @elseif ($campo['tipo'] === 'numero')
        <input type="number" step="1" min="0" class="form-control" id="{{ $campo['columna'] }}"
               name="{{ $campo['columna'] }}" value="{{ $campo['valor'] }}">
    @elseif ($campo['tipo'] === 'area')
        <textarea class="form-control" rows="{{ $campo['filas'] ?? 4 }}" id="{{ $campo['columna'] }}"
                  name="{{ $campo['columna'] }}">{{ $campo['valor'] }}</textarea>
    @else
        <input type="text" class="form-control" maxlength="{{ $campo['max'] ?? 10 }}" id="{{ $campo['columna'] }}"
               name="{{ $campo['columna'] }}" value="{{ $campo['valor'] }}">
    @endif
</x-ui.campo>
