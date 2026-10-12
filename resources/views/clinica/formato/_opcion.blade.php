{{-- Una casilla de las opciones de un bloque (negrita, cursiva, borde, se imprime…). El `hidden` con `0` va antes para que una casilla
     sin marcar llegue como falso: el formulario siempre manda el estado completo. Recibe `$nombre` (el name), `$id`, `$texto`
     y `$marcada`. --}}
<div class="form-check form-check-inline">
    <input type="hidden" name="{{ $nombre }}" value="0">
    <input class="form-check-input" type="checkbox" name="{{ $nombre }}" id="{{ $id }}" value="1" {{ $marcada ? 'checked' : '' }}>
    <label class="form-check-label" for="{{ $id }}">{{ $texto }}</label>
</div>
