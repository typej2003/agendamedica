{{-- La pestaña de una solicitud de traumatología (T3): la solicitud de implante y la orden de ingreso, con sus campos.
     Recibe `$vm` (SolicitudDeTraumatologiaVista) y `$clave` (`implante` o `orden-ingreso`, para los ids y el documento).
     Una por consulta; se guarda con el botón. Las casillas van con un `0` oculto y el `1` de la casilla. --}}
@php $estado = $vm->estado(); $id = 'solicitud-' . $clave; @endphp

<x-ui.tarjeta :titulo="$vm->titulo()" :icono="$vm->icono()">
    <x-slot name="acciones">
        @if ($vm->esReal())
            <x-ui.imprimir-guardando :url="$vm->urlImprimir()" :form="$id . '-form'" :documento="$clave" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
            </x-ui.imprimir-guardando>
        @endif
        <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']" :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
    </x-slot>

    <p class="text-secondary small mb-3">{{ $vm->ayuda() }}</p>

    <form method="POST" action="{{ $vm->urlGuardar() }}" id="{{ $id }}-form" data-solicitud-form data-aviso="{{ $vm->avisoSinGuardar() }}">
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
                                       id="{{ $id }}-{{ $campo['clave'] }}" value="1" @checked($campo['marcado'])>
                                <label class="form-check-label" for="{{ $id }}-{{ $campo['clave'] }}">{{ $campo['etiqueta'] }}</label>
                            </div>
                        </div>
                    @elseif ($campo['tipo'] === 'parrafo')
                        <div class="col-12">
                            <x-ui.campo :etiqueta="$campo['etiqueta']" :for="$id . '-' . $campo['clave']">
                                <textarea name="{{ $campo['clave'] }}" id="{{ $id }}-{{ $campo['clave'] }}"
                                          class="form-control" rows="3">{{ $campo['valor'] }}</textarea>
                            </x-ui.campo>
                        </div>
                    @elseif ($campo['tipo'] === 'fecha')
                        <div class="col-12 col-md-4">
                            <x-ui.campo :etiqueta="$campo['etiqueta']" :for="$id . '-' . $campo['clave']">
                                <input type="date" name="{{ $campo['clave'] }}" id="{{ $id }}-{{ $campo['clave'] }}"
                                       class="form-control" value="{{ $campo['valor'] }}">
                            </x-ui.campo>
                        </div>
                    @else
                        <div class="col-12 col-md-4">
                            <x-ui.campo :etiqueta="$campo['etiqueta']" :for="$id . '-' . $campo['clave']">
                                <input type="text" name="{{ $campo['clave'] }}" id="{{ $id }}-{{ $campo['clave'] }}"
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
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar
            </button>
        </div>
    </form>
</x-ui.tarjeta>
