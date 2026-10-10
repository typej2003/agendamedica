@props(['etiqueta', 'for', 'ayuda' => null, 'requerido' => false])

{{-- Un campo de formulario: etiqueta + control (slot) + error del campo + ayuda. El control lleva
     `id="{{ $for }}"` y `name` igual. La clase de columna (`col-md-4`) va en el propio componente. --}}
<div {{ $attributes->merge(['class' => 'campo']) }}>
    <label class="form-label" for="{{ $for }}">{{ $etiqueta }}@if ($requerido) <span aria-hidden="true">*</span>@endif</label>
    {{ $slot }}
    @error($for)
        <span class="campo-error d-block">{{ $message }}</span>
    @enderror
    @if ($ayuda)
        <span class="form-text d-block">{{ $ayuda }}</span>
    @endif
</div>
