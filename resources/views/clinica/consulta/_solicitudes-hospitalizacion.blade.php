{{-- La orden de hospitalización (WEB-5.3b): el lugar a hospitalizar, el diagnóstico provisional y las recomendaciones. Recibe `$vm`
     (HospitalizacionVista). Una por consulta; se guarda con el botón. Copiar los diagnósticos de la consulta **escribe en el campo y
     no guarda**. --}}
@php $estado = $vm->estado(); @endphp

<x-ui.tarjeta titulo="Orden de hospitalización" icono="bi-hospital">
    <x-slot name="acciones">
        <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']" :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
        @if ($vm->esReal())
            <x-ui.imprimir-guardando :url="$vm->urlImprimir()" form="hospitalizacion-form" documento="hospitalizacion" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
            </x-ui.imprimir-guardando>
        @endif
    </x-slot>

    <form method="POST" action="{{ $vm->urlGuardar() }}" id="hospitalizacion-form" data-solicitud-form data-aviso="{{ $vm->avisoSinGuardar() }}">
        @csrf

        <x-ui.campo etiqueta="Lugar a hospitalizar" for="hospitalizacion-hospital" error="hospital" class="mb-3"
                    ayuda="La clínica u hospital, como quieres que salga en la orden.">
            <input type="text" name="hospital" id="hospitalizacion-hospital" class="form-control" maxlength="{{ $vm->largoDelHospital() }}" value="{{ $vm->hospital() }}">
        </x-ui.campo>

        <x-ui.campo etiqueta="Diagnóstico provisional" for="hospitalizacion-diagnostico" error="diagnostico" class="mb-2">
            <textarea name="diagnostico" id="hospitalizacion-diagnostico" class="form-control" rows="5">{{ $vm->diagnostico() }}</textarea>
        </x-ui.campo>
        @if ($vm->hayDiagnosticosDeLaConsulta())
            <p class="mb-3">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-restaurar="#hospitalizacion-diagnostico"
                        data-texto="{{ $vm->diagnosticosDeLaConsulta() }}">
                    <i class="bi bi-clipboard2-check" aria-hidden="true"></i> Usar los diagnósticos de la consulta
                </button>
            </p>
        @endif

        <x-ui.campo etiqueta="Recomendaciones" for="hospitalizacion-recomendaciones" error="recomendaciones" class="mb-3">
            <textarea name="recomendaciones" id="hospitalizacion-recomendaciones" class="form-control" rows="6">{{ $vm->recomendaciones() }}</textarea>
        </x-ui.campo>

        <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mb-3" data-solicitud-aviso hidden>
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span>Hay cambios sin guardar.</span>
        </p>

        <div class="d-flex justify-content-end gap-2">
            <button class="btn btn-primary">
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar la orden
            </button>
        </div>
    </form>

    @if ($vm->puedeEliminar())
        <form method="POST" action="{{ $vm->urlEliminar() }}" class="mt-3" data-confirmar="{{ $vm->avisoEliminar() }}" data-solicitud-salir>
            @csrf
            @method('DELETE')
            <button class="btn btn-sm btn-outline-danger">
                <i class="bi bi-trash" aria-hidden="true"></i> Eliminar la orden
            </button>
        </form>
    @endif
</x-ui.tarjeta>
