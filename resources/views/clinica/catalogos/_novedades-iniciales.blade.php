{{-- Las novedades del catálogo inicial (WEB-12.8): lo que el catálogo inicial tiene y el médico todavía no. Nunca se agregan solas: las acepta con el botón. --}}
@if (isset($novedadesIniciales) && $novedadesIniciales && $novedadesIniciales->hay())
    <x-ui.tarjeta id="catalogo-novedades" class="mb-3">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <i class="bi bi-gift fs-4" aria-hidden="true"></i>
            <p class="mb-0 flex-grow-1">{{ $novedadesIniciales->texto() }}</p>
            <form method="POST" action="{{ $novedadesIniciales->urlCargar() }}">
                @csrf
                <button class="btn btn-outline-primary">
                    <i class="bi bi-download" aria-hidden="true"></i> Cargar el catálogo inicial
                </button>
            </form>
        </div>
    </x-ui.tarjeta>
@endif
