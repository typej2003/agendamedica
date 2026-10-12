{{-- Un examen o estudio de la orden que se edita (WEB-5.4): el código viaja en un campo oculto (el nombre es sólo de pantalla: el servidor
     lo vuelve a resolver contra el catálogo y nunca lo toma del formulario). Lo usan dos cosas con **el mismo marcado**: las filas que el
     servidor pinta y la plantilla que el script clona al agregar. --}}
<li class="orden-fila" data-orden-fila data-codigo="{{ $item['codigo'] }}">
    <input type="hidden" name="{{ $campo }}[]" value="{{ $item['codigo'] }}">

    <span class="orden-fila-datos">
        <span class="orden-nombre" data-orden-nombre>{{ $item['nombre'] }}</span>
        <span class="texto-tenue small d-block" data-orden-detalle>{{ $item['aviso'] ?? $item['tipo'] }}</span>
    </span>

    {{-- "Quitar" saca el examen de la orden **en edición**: no borra nada hasta que se guarda, y no toca el catálogo. --}}
    <button type="button" class="btn btn-sm btn-outline-danger orden-quitar" data-orden-quitar
            title="Quitar «{{ $item['nombre'] }}» de la orden" aria-label="Quitar {{ $item['nombre'] }} de la orden">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
    </button>
</li>
