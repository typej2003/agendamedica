{{-- La plantilla ortopédica (Traumatología, 2026-11-09): las casillas y los textos de la hoja «Calzados Ortopédicos».
     Recibe `$vm` (OrtopediaVista). Una por consulta; se guarda con el botón. Las casillas van con un `0` oculto y el `1`
     de la casilla, que es como las guarda el escritorio (`char(1)`). --}}
@php $estado = $vm->estado(); @endphp

<x-ui.tarjeta titulo="Ortopedia" icono="bi-person-arms-up">
    <x-slot name="acciones">
        @if ($vm->esReal())
            <x-ui.imprimir-guardando :url="$vm->urlImprimir()" form="ortopedia-form" documento="ortopedia" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
            </x-ui.imprimir-guardando>
        @endif
        <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']" :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
    </x-slot>

    <form method="POST" action="{{ $vm->urlGuardar() }}" id="ortopedia-form" data-solicitud-form data-aviso="{{ $vm->avisoSinGuardar() }}">
        @csrf

        @foreach ($vm->grupos() as $grupo)
            <h3 class="h6 text-uppercase text-secondary mt-3 mb-2">{{ $grupo['titulo'] }}</h3>

            <div class="row g-3">
                @foreach ($grupo['campos'] as $campo)
                    @if ($campo['tipo'] === 'marca')
                        <div class="col-12 col-md-4">
                            <input type="hidden" name="{{ $campo['clave'] }}" value="0">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="{{ $campo['clave'] }}"
                                       id="ortopedia-{{ $campo['clave'] }}" value="1" @checked($campo['marcado'])>
                                <label class="form-check-label" for="ortopedia-{{ $campo['clave'] }}">{{ $campo['etiqueta'] }}</label>
                            </div>
                        </div>
                    @elseif ($campo['tipo'] === 'parrafo')
                        <div class="col-12">
                            <x-ui.campo :etiqueta="$campo['etiqueta']" :for="'ortopedia-' . $campo['clave']">
                                <textarea name="{{ $campo['clave'] }}" id="ortopedia-{{ $campo['clave'] }}"
                                          class="form-control" rows="3">{{ $campo['valor'] }}</textarea>
                            </x-ui.campo>
                        </div>
                    @else
                        <div class="col-12 col-md-4">
                            <x-ui.campo :etiqueta="$campo['etiqueta']" :for="'ortopedia-' . $campo['clave']">
                                <input type="text" name="{{ $campo['clave'] }}" id="ortopedia-{{ $campo['clave'] }}"
                                       class="form-control" maxlength="{{ $campo['max'] ?? 255 }}" value="{{ $campo['valor'] }}">
                            </x-ui.campo>
                        </div>
                    @endif
                @endforeach
            </div>
        @endforeach

        <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-3" data-solicitud-aviso hidden>
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span>Hay cambios sin guardar.</span>
        </p>

        <div class="d-flex justify-content-end gap-2 mt-3">
            <button class="btn btn-primary">
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar la plantilla
            </button>
        </div>
    </form>
</x-ui.tarjeta>
