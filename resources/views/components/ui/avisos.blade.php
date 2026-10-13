{{-- Avisos de una acción del panel: lo que Livewire deja en la sesión (`message` = salió bien, `error` = no
     salió). Un solo diseño para todas las pantallas del admin: icono + texto + cerrar, y el icono distingue
     el éxito del error sin depender sólo del color. En `/clinica` el equivalente vive en el layout
     (`estado`, `error` y `avisos`). --}}
@if (session()->has('message'))
    <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 py-2" role="status">
        <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
        <span>{{ session('message') }}</span>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
@endif

@if (session()->has('error'))
    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 py-2" role="alert">
        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
        <span>{{ session('error') }}</span>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
@endif
