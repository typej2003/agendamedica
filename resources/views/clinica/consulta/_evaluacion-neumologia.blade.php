{{-- **Neumología pediátrica** (postgrado, 2026-10-19): la solapa que el escritorio tiene dentro de *Evaluación
     física* (`tabpage_24` con su `tab_6`). Son los tres informes de texto: R.X. de tórax (`consultas.rayosx`),
     espirometría (`consultas.faringe`, la columna que ginecología usa para la citología) y broncoscopía
     (`consultas.broncoscopia`).

     Como en el escritorio van en el orden de sus solapas y en un solo formulario, con guardado explícito. El aviso de
     cambios sin guardar lo pone `consulta.js` a partir de `data-aviso`. --}}
@php
    $estado = $seccion->estado();
@endphp

<div class="consulta-seccion" id="consulta-neumologia">
    <x-ui.tarjeta titulo="Neumología" icono="bi-lungs">
        <x-slot name="acciones">
            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']"
                         :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
        </x-slot>

        <form method="POST" action="{{ $seccion->urlGuardar() }}" id="neumologia-form"
              data-consulta-neumologia data-aviso="{{ $seccion->avisoSinGuardar() }}">
            @csrf

            @foreach ($seccion->campos() as $campo)
                <x-ui.campo :etiqueta="$campo['etiqueta']" :for="'neumo-' . $campo['clave']" :ayuda="$campo['ayuda']">
                    <textarea name="{{ $campo['clave'] }}" id="neumo-{{ $campo['clave'] }}" class="form-control"
                              rows="{{ $campo['filas'] }}"
                              placeholder="Escribe el informe o déjalo vacío">{{ $campo['valor'] }}</textarea>
                </x-ui.campo>
            @endforeach

            <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-0" id="neumologia-form-sin-guardar" hidden>
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span>Hay cambios sin guardar.</span>
            </p>

            <div class="d-flex justify-content-end gap-2 mt-3">
                <a class="btn btn-outline-secondary" href="{{ $seccion->urlVolver() }}">Cancelar</a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1" aria-hidden="true"></i> Guardar la neumología
                </button>
            </div>
        </form>
    </x-ui.tarjeta>
</div>
