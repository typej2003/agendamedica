{{-- El pie de cada sección del formato: el aviso de cambios sin guardar (lo alterna `formato-impresion.js` con `hidden`) y el botón
     que guarda esa sección. Recibe `$texto`. --}}
<p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-0" data-formato-aviso hidden>
    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
    <span>{{ $vm->avisoSinGuardar() }}</span>
</p>

<div class="d-flex justify-content-end gap-2 mt-3">
    <button class="btn btn-primary">
        <i class="bi bi-check2-circle" aria-hidden="true"></i> {{ $texto }}
    </button>
</div>
