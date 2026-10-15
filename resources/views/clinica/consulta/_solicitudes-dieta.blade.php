{{-- La dieta / recomendaciones (WEB-5.3b): el título (opcional) y el texto, libres o a partir de una plantilla del médico. Recibe `$vm`
     (DietaVista). Una por consulta; se guarda con el botón. Elegir una plantilla **escribe en el editor y no guarda**. --}}
@php $estado = $vm->estado(); @endphp

<x-ui.tarjeta titulo="Dieta / Recomendaciones" icono="bi-egg-fried">
    <x-slot name="acciones">
        <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']" :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
        @if ($vm->esReal())
            <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlImprimir() }}" target="_blank" rel="noopener">
                <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
            </a>
        @endif
    </x-slot>

    <p class="texto-tenue small mb-3">{{ $vm->ayuda() }}</p>

    <form method="POST" action="{{ $vm->urlGuardar() }}" id="dieta-form" data-solicitud-form data-aviso="{{ $vm->avisoSinGuardar() }}">
        @csrf

        <div class="row g-3">
            <div class="col-12 col-xl-8">
                <x-ui.campo etiqueta="Título" for="dieta-titulo" error="titulo" class="mb-3" ayuda="Opcional. Sale en negrita sobre el texto.">
                    <input type="text" name="titulo" id="dieta-titulo" class="form-control" maxlength="{{ $vm->largoDelTitulo() }}" value="{{ $vm->titulo() }}">
                </x-ui.campo>

                <x-ui.campo etiqueta="Texto de la dieta o de las recomendaciones" for="dieta-descripcion" error="descripcion" requerido>
                    <textarea name="descripcion" id="dieta-descripcion" class="form-control" rows="14">{{ $vm->texto() }}</textarea>
                </x-ui.campo>
            </div>

            <div class="col-12 col-xl-4">
                <x-ui.plantillas :plantillas="$vm->plantillas()" destino="#dieta-descripcion" tipo="DI"
                                 nombre="dieta" titulo-destino="#dieta-titulo" />
            </div>
        </div>

        <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-3" data-solicitud-aviso hidden>
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span>Hay cambios sin guardar.</span>
        </p>

        <div class="d-flex justify-content-end gap-2 mt-3">
            <button class="btn btn-primary">
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar la dieta
            </button>
        </div>
    </form>

    @if ($vm->puedeEliminar())
        <form method="POST" action="{{ $vm->urlEliminar() }}" class="mt-3" data-confirmar="{{ $vm->avisoEliminar() }}" data-solicitud-salir>
            @csrf
            @method('DELETE')
            <button class="btn btn-sm btn-outline-danger">
                <i class="bi bi-trash" aria-hidden="true"></i> Eliminar la dieta
            </button>
        </form>
    @endif
</x-ui.tarjeta>
