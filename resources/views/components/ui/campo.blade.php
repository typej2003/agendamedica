@props(['etiqueta', 'for', 'error' => null, 'ayuda' => null, 'info' => null, 'requerido' => false])

{{-- Un campo de formulario: etiqueta + control (slot) + error del campo + ayuda. El control lleva
     `id="{{ $for }}"` y `name` igual. La clase de columna (`col-md-4`) va en el propio componente.

     `error` es la clave con la que se busca el error de validación y por defecto es la misma que `for`.
     Se separa para los formularios donde el `id` del control tiene que ser único en la página pero el
     `name` que valida el servidor es otro (p. ej. dos diálogos que mandan `cedula` en la misma pantalla:
     el del representante usa `id="representante-cedula"` y `name="cedula"`). --}}
@php $claveDelError = $error ?? $for; @endphp
<div {{ $attributes->merge(['class' => 'campo']) }}>
    <label class="form-label" for="{{ $for }}">{{ $etiqueta }}@if ($requerido) <span aria-hidden="true">*</span>@endif</label>@if ($info) <x-ui.info>{{ $info }}</x-ui.info>@endif
    {{ $slot }}
    @error($claveDelError)
        <span class="campo-error d-block">{{ $message }}</span>
    @enderror
    @if ($ayuda)
        <span class="form-text d-block">{{ $ayuda }}</span>
    @endif
</div>
