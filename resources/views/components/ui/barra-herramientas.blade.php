@props(['etiqueta' => 'Herramientas de la lista'])

{{-- Barra de herramientas de una pantalla de lista, debajo del encabezado: el buscador (`$slot`) a la izquierda,
     ocupando el ancho útil, y los filtros (slot `filtros`: segmentados, selects) a su derecha; en el celular cada
     grupo baja a su línea. El encabezado (`<x-ui.page-head>`) lleva sólo título, subtítulo y botones: un formulario
     en su slot de acciones lo desalinea (trampa 63 de `TRAMPAS.md`). --}}
<div {{ $attributes->merge(['class' => 'barra-herramientas']) }} role="group" aria-label="{{ $etiqueta }}">
    {{ $slot }}

    @isset($filtros)
        <div class="barra-herramientas-filtros">{{ $filtros }}</div>
    @endisset
</div>
