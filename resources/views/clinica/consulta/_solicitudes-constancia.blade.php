{{-- La constancia (WEB-5.3a): el texto base fijo, los diagnósticos de la consulta (editables) y la observación. Recibe `$vm`
     (ConstanciaVista). Una por consulta; se guarda con el botón. --}}
@php $estado = $vm->estado(); @endphp

<x-ui.tarjeta titulo="Constancia de asistencia" icono="bi-file-earmark-medical">
    <x-slot name="acciones">
        <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']" :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
        <x-ui.imprimir-guardando :url="$vm->urlImprimir()" form="constancia-form" documento="constancia" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
        </x-ui.imprimir-guardando>
    </x-slot>

    <form method="POST" action="{{ $vm->urlGuardar() }}" id="constancia-form" data-solicitud-form data-aviso="{{ $vm->avisoSinGuardar() }}">
        @csrf

        <p class="solicitud-texto-base" id="constancia-texto-base">{{ $vm->textoBase() }}</p>

        <x-ui.campo etiqueta="Diagnósticos (IDX)" for="constancia-diagnosticos" error="diagnosticos" class="mb-3"
                    :ayuda="$vm->siguenALaConsulta() ? 'Son los de esta consulta: si agregas uno en el ítem Diagnóstico, la constancia lo trae sola. Si los editas aquí, dejan de seguirla.' : 'Editaste los diagnósticos: la constancia ya no sigue a los de la consulta.'">
            <textarea name="diagnosticos" id="constancia-diagnosticos" class="form-control" rows="3"
                      placeholder="{{ $vm->sinDiagnosticos() ? 'Esta consulta todavía no tiene diagnósticos: escríbelos o cárgalos en el ítem Diagnóstico.' : '' }}">{{ $vm->diagnosticos() }}</textarea>
        </x-ui.campo>
        @unless ($vm->siguenALaConsulta())
            <p class="mb-3">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-restaurar="#constancia-diagnosticos"
                        data-texto="{{ $vm->automatico() }}">
                    <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Usar los diagnósticos de la consulta
                </button>
            </p>
        @endunless

        <x-ui.campo etiqueta="Observación" for="constancia-observacion" error="observacion" class="mb-3" ayuda="Va al final de la constancia.">
            <textarea name="observacion" id="constancia-observacion" class="form-control" rows="5">{{ $vm->observacion() }}</textarea>
        </x-ui.campo>

        <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mb-3" data-solicitud-aviso hidden>
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span>Hay cambios sin guardar.</span>
        </p>

        <div class="d-flex justify-content-end gap-2">
            <button class="btn btn-primary">
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar la constancia
            </button>
        </div>
    </form>

    @if ($vm->puedeQuitar())
        <form method="POST" action="{{ $vm->urlEliminar() }}" class="mt-3" data-confirmar="{{ $vm->avisoQuitar() }}" data-solicitud-salir>
            @csrf
            @method('DELETE')
            <button class="btn btn-sm btn-outline-danger">
                <i class="bi bi-trash" aria-hidden="true"></i> Quitar mi observación y mis diagnósticos
            </button>
        </form>
    @endif
</x-ui.tarjeta>
