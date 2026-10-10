@props(['id', 'resultados', 'placeholder' => 'Buscar', 'etiqueta' => 'Buscar', 'valor' => ''])

{{-- Campo de búsqueda con resultados en vivo: el script de la pantalla escucha el `input`, pide al servidor
     y pinta los resultados dentro de `#{{ $resultados }}` (que flota debajo del campo). Va dentro de un
     contenedor `position-relative`. --}}
<div class="buscador-campo buscador-vivo">
    <i class="bi bi-search" aria-hidden="true"></i>
    <input type="search" id="{{ $id }}" value="{{ $valor }}" class="form-control form-control-sm" autocomplete="off"
           placeholder="{{ $placeholder }}" aria-label="{{ $etiqueta }}" aria-controls="{{ $resultados }}"
           {{ $attributes }}>
</div>
<div class="list-group resultados-flotantes" id="{{ $resultados }}" hidden></div>
