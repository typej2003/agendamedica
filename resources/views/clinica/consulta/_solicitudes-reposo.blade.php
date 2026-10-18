{{-- El reposo (WEB-5.3a): los días, la fecha de inicio y la justificación, con el último día y el reintegro calculados. Recibe
     `$vm` (ReposoVista). Uno por consulta; se guarda con el botón. --}}
@php $estado = $vm->estado(); @endphp

<x-ui.tarjeta titulo="Reposo médico" icono="bi-bandaid">
    <x-slot name="acciones">
        <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']" :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
        @if ($vm->esReal())
            <x-ui.imprimir-guardando :url="$vm->urlImprimir()" form="reposo-form" documento="reposo" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
            </x-ui.imprimir-guardando>
        @endif
    </x-slot>

    <p class="texto-tenue small mb-3">{{ $vm->ayuda() }}</p>

    <form method="POST" action="{{ $vm->urlGuardar() }}" id="reposo-form" data-solicitud-form data-aviso="{{ $vm->avisoSinGuardar() }}">
        @csrf

        <div class="row g-3 mb-3">
            <x-ui.campo etiqueta="Días de reposo" for="reposo-dias" error="dias" requerido class="col-6 col-md-3">
                <input type="number" name="dias" id="reposo-dias" class="form-control" min="1" max="{{ $vm->diasMaximo() }}"
                       value="{{ $vm->dias() }}" data-reposo-dias inputmode="numeric">
            </x-ui.campo>
            <x-ui.campo etiqueta="Fecha de inicio" for="reposo-desde" error="desde" requerido class="col-6 col-md-3">
                <input type="date" name="desde" id="reposo-desde" class="form-control" value="{{ $vm->desde() }}" data-reposo-desde>
            </x-ui.campo>
            <div class="col-6 col-md-3">
                <p class="etiqueta mb-1">Último día de reposo</p>
                <p class="solicitud-calculo mb-0" data-reposo-hasta aria-live="polite">{{ $vm->hasta() }}</p>
            </div>
            <div class="col-6 col-md-3">
                <p class="etiqueta mb-1">Reintegro laboral</p>
                <p class="solicitud-calculo mb-0" data-reposo-reintegro aria-live="polite">{{ $vm->reintegro() }}</p>
            </div>
        </div>

        <x-ui.campo etiqueta="Justificación" for="reposo-observacion" error="observacion" class="mb-3" :ayuda="$vm->ayudaDeLaJustificacion()">
            <textarea name="observacion" id="reposo-observacion" class="form-control" rows="6">{{ $vm->observacion() }}</textarea>
        </x-ui.campo>

        <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mb-3" data-solicitud-aviso hidden>
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span>Hay cambios sin guardar.</span>
        </p>

        <div class="d-flex justify-content-end gap-2">
            <button class="btn btn-primary">
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar el reposo
            </button>
        </div>
    </form>

    @if ($vm->puedeEliminar())
        <form method="POST" action="{{ $vm->urlEliminar() }}" class="mt-3" data-confirmar="{{ $vm->avisoEliminar() }}" data-solicitud-salir>
            @csrf
            @method('DELETE')
            <button class="btn btn-sm btn-outline-danger">
                <i class="bi bi-trash" aria-hidden="true"></i> Eliminar el reposo
            </button>
        </form>
    @endif
</x-ui.tarjeta>
