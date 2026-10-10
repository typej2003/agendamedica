@props(['accion', 'valor' => '', 'nombre' => 'buscar', 'placeholder' => 'Buscar', 'etiqueta' => 'Buscar', 'limpiar' => null])

{{-- Formulario de búsqueda por GET. Con un valor activo ofrece "Limpiar", que por defecto vuelve a `accion`
     sin parámetros; `limpiar` cambia ese destino. Existe para los listados con filtro propio: en
     `/clinica/pacientes` (WEB-3.3), "Limpiar" tiene que sacar la búsqueda y **dejar puesto** el segmento
     de historia. El filtro que hay que conservar al buscar se manda como un `<input type="hidden">` del
     slot, que es lo que el componente deja pasar al `<form>` por `$attributes`. --}}
<form {{ $attributes->merge(['class' => 'buscador']) }} method="GET" action="{{ $accion }}" role="search">
    <div class="buscador-campo">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" name="{{ $nombre }}" value="{{ $valor }}" class="form-control"
               placeholder="{{ $placeholder }}" aria-label="{{ $etiqueta }}">
    </div>
    <button class="btn btn-primary">Buscar</button>
    @if ($valor !== '')
        <a class="btn btn-outline-secondary" href="{{ $limpiar ?? $accion }}">Limpiar</a>
    @endif
    {{ $slot }}
</form>
