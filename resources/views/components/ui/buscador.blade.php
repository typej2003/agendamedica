@props([
    'accion', 'valor' => '', 'nombre' => 'buscar', 'placeholder' => 'Buscar', 'etiqueta' => 'Buscar', 'limpiar' => null,
    // «Agregar» (buscar-auto.js): cuando lo escrito no coincide exacto con ninguna fila. O abre el diálogo de alta
    // (`agregar` = id del diálogo, `campo` = id del campo que se pre-llena) o lleva a la pantalla de alta (`agregarUrl`).
    'agregar' => null, 'campo' => null, 'agregarUrl' => null, 'agregarParametro' => 'nombre', 'agregarTexto' => 'Agregar',
])

{{-- Formulario de búsqueda por GET que **busca solo** (`public/js/clinica/buscar-auto.js`: sin Enter, con debounce, cambiando
     sólo `[data-buscar-resultados]`). Sin JavaScript sigue siendo un formulario con su botón «Buscar». Con un valor activo
     ofrece "Limpiar", que por defecto vuelve a `accion` sin parámetros; `limpiar` cambia ese destino. Existe para los
     listados con filtro propio: en `/clinica/pacientes` (WEB-3.3), "Limpiar" tiene que sacar la búsqueda y **dejar puesto**
     el segmento de historia. El filtro que hay que conservar al buscar se manda como un `<input type="hidden">` del
     slot, que es lo que el componente deja pasar al `<form>` por `$attributes`. --}}
<form {{ $attributes->merge(['class' => 'buscador']) }} method="GET" action="{{ $accion }}" role="search" data-buscar-auto>
    <div class="buscador-campo">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" name="{{ $nombre }}" value="{{ $valor }}" class="form-control"
               placeholder="{{ $placeholder }}" aria-label="{{ $etiqueta }}" autocomplete="off">
    </div>
    <button class="btn btn-primary" data-buscar-enviar>Buscar</button>
    <a class="btn btn-outline-secondary" href="{{ $limpiar ?? $accion }}" data-buscar-limpiar @if ($valor === '') hidden @endif>Limpiar</a>
    @if ($agregar)
        <button type="button" class="btn btn-outline-primary" data-buscar-agregar hidden
                data-agregar-dialogo="{{ $agregar }}" data-prefill-campo="{{ $campo }}">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> {{ $agregarTexto }} «<span data-buscar-agregar-texto></span>»
        </button>
    @elseif ($agregarUrl)
        <a class="btn btn-outline-primary" href="{{ $agregarUrl }}" data-buscar-agregar hidden
           data-agregar-url="{{ $agregarUrl }}" data-agregar-parametro="{{ $agregarParametro }}">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> {{ $agregarTexto }} «<span data-buscar-agregar-texto></span>»
        </a>
    @endif
    {{ $slot }}
</form>
