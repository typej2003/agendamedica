@props(['id', 'resultados', 'placeholder' => 'Buscar', 'etiqueta' => 'Buscar', 'valor' => '', 'modo' => 'seleccion'])

{{-- Campo de búsqueda con resultados en vivo: el script de la pantalla escucha el `input`, pide al servidor
     y pinta los resultados dentro de `#{{ $resultados }}` (que flota debajo del campo). Va dentro de un
     contenedor `position-relative`. --}}
{{-- `modo`: **`seleccion`** (por defecto) es el patrón "combobox": se escribe, aparece una lista y se elige una
     opción que se queda en el campo (el alta de cita). **`lista`** es una lista de resultados con su propia
     acción en cada fila (el buscador del día, con "Hacer cita"): no son opciones de un combobox, son filas
     con un botón, así que solo se anuncia como lista. En los dos, `aria-controls` apunta a la lista; el script
     de cada pantalla mantiene `aria-expanded` (modo `seleccion`) y lo que se anuncia. --}}
<div class="buscador-campo buscador-vivo">
    <i class="bi bi-search" aria-hidden="true"></i>
    <input type="search" id="{{ $id }}" value="{{ $valor }}" class="form-control form-control-sm" autocomplete="off"
           placeholder="{{ $placeholder }}" aria-label="{{ $etiqueta }}" aria-controls="{{ $resultados }}"
           @if ($modo === 'seleccion') role="combobox" aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false" @endif
           {{ $attributes }}>
</div>
<div class="list-group resultados-flotantes" id="{{ $resultados }}" role="{{ $modo === 'seleccion' ? 'listbox' : 'list' }}"
     aria-label="Resultados: {{ $etiqueta }}" hidden></div>
