@props(['accion', 'valor' => '', 'nombre' => 'buscar', 'placeholder' => 'Buscar', 'etiqueta' => 'Buscar'])

{{-- Formulario de búsqueda por GET. Con un valor activo ofrece "Limpiar", que vuelve a la misma ruta sin
     parámetros. --}}
<form {{ $attributes->merge(['class' => 'buscador']) }} method="GET" action="{{ $accion }}" role="search">
    <div class="buscador-campo">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" name="{{ $nombre }}" value="{{ $valor }}" class="form-control"
               placeholder="{{ $placeholder }}" aria-label="{{ $etiqueta }}">
    </div>
    <button class="btn btn-primary">Buscar</button>
    @if ($valor !== '')
        <a class="btn btn-outline-secondary" href="{{ $accion }}">Limpiar</a>
    @endif
</form>
