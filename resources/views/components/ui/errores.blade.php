{{-- El primer error de validación de la pantalla, arriba, como aviso. Los errores por campo los muestra
     `<x-ui.campo>` debajo de cada control. --}}
@if ($errors->any())
    <div {{ $attributes->merge(['class' => 'alert alert-warning py-2 d-flex align-items-center gap-2']) }} role="alert">
        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> {{ $errors->first() }}
    </div>
@endif
