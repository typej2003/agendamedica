{{-- Izquierda / centro / derecha como un grupo de botones (radios de Bootstrap `btn-check`). Recibe `$nombre` (el name), `$prefijo`
     (para los ids), `$actual` y `$opciones` (valor => [texto, icono]). Los botones con sólo icono llevan el texto para lectores
     de pantalla. --}}
<div class="btn-group formato-alineacion" role="group" aria-label="{{ $etiqueta }}">
    @foreach ($opciones as $valor => $opcion)
        <input type="radio" class="btn-check" name="{{ $nombre }}" id="{{ $prefijo }}-{{ $valor }}" value="{{ $valor }}"
               autocomplete="off" {{ $actual === $valor ? 'checked' : '' }}>
        <label class="btn btn-outline-primary" for="{{ $prefijo }}-{{ $valor }}" title="{{ $opcion['texto'] }}">
            <i class="bi {{ $opcion['icono'] }}" aria-hidden="true"></i>
            <span class="visually-hidden">{{ $opcion['texto'] }}</span>
        </label>
    @endforeach
</div>
