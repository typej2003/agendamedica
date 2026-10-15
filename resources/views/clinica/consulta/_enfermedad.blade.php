{{-- La enfermedad actual de la consulta (WEB-4.2, ítem 2 del panel del legado): el texto libre de lo que
     el paciente tiene hoy, editable y con **guardado explícito** (pedido de Alexander, 2026-10-09).

     El aviso de "cambios sin guardar" y el bloqueo del navegador al salir los pone `consulta.js` a partir
     de los `data-*` de este formulario: la vista no lleva JS ni estilos. --}}
@php
    $estado = $seccion->estado();
@endphp

<div class="consulta-seccion" id="consulta-enfermedad">
    <x-ui.tarjeta titulo="Enfermedad actual" icono="bi-file-text">
        <x-slot name="acciones">
            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']"
                         :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
        </x-slot>

        <form method="POST" action="{{ $seccion->urlGuardar() }}" id="enfermedad-form"
              data-consulta-enfermedad data-autoguardar data-aviso="Hay cambios sin guardar. Si sales ahora, se pierden.">
            @csrf

            <x-ui.campo etiqueta="Lo que el paciente tiene hoy" for="enfermedadactual"
                        :ayuda="$seccion->ayuda()">
                <textarea name="enfermedadactual" id="enfermedadactual" class="form-control" rows="10"
                          placeholder="{{ $seccion->marcador() }}">{{ $seccion->valor(old('enfermedadactual', '')) }}</textarea>
            </x-ui.campo>

            {{-- El aviso de "hay cambios sin guardar" se muestra recién cuando el texto cambió: se
                 controla por `hidden`, que es lo que el script alterna. --}}
            <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-2 mb-0" id="enfermedad-sin-guardar" hidden>
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span>Hay cambios sin guardar.</span>
            </p>

            <div class="d-flex justify-content-end gap-2 mt-3">
                <a class="btn btn-outline-secondary" href="{{ $seccion->urlVolver() }}">Cancelar</a>
                {{-- La única acción primaria de la sección: guardar lo escrito. --}}
                <button class="btn btn-primary">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar la enfermedad actual
                </button>
            </div>
        </form>
    </x-ui.tarjeta>
</div>
