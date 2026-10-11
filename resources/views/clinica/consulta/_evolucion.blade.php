{{-- La evolución de la consulta (WEB-4.6, ítem 9 del panel del legado): el texto de seguimiento de la
     atención (`consultas.evolucion`) y, al lado, las **plantillas** que el escritorio ofrece para copiar al
     campo (la lista «Evolución» de `formato_print`, `w_consulta_principal.srw:12002`).

     Es la misma decisión de producto que la enfermedad actual (WEB-4.2): es texto clínico, se guarda con un
     **botón** y la pantalla avisa cuando hay algo escrito sin guardar. Elegir una plantilla **escribe** en el
     campo (no guarda nada): el aviso aparece igual y el guardado sigue siendo el botón.

     El aviso de "cambios sin guardar" y el del navegador al salir los pone `consulta.js` a partir de los
     `data-*` de este formulario: la vista no lleva JS ni estilos. --}}
@php
    $estado = $seccion->estado();
    $vacio = $seccion->sinPlantillas();
@endphp

<div class="consulta-seccion" id="consulta-evolucion">
    <x-ui.tarjeta titulo="Evolución de esta consulta" icono="bi-activity">
        <x-slot name="acciones">
            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']"
                         :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
        </x-slot>

        <form method="POST" action="{{ $seccion->urlGuardar() }}" id="evolucion-form"
              data-consulta-evolucion data-aviso="{{ $seccion->avisoSinGuardar() }}">
            @csrf

            <div class="row g-3">
                <div class="col-12 col-xl-8">
                    <x-ui.campo etiqueta="Cómo sigue el paciente" for="evolucion" :ayuda="$seccion->ayuda()">
                        <textarea name="evolucion" id="evolucion" class="form-control" rows="12"
                                  placeholder="{{ $seccion->marcador() }}">{{ $seccion->valor(old('evolucion', '')) }}</textarea>
                    </x-ui.campo>
                </div>

                {{-- Las plantillas del escritorio. La columna no se pinta cuando el médico no tiene ninguna:
                     el estado vacío de abajo lo dice de una, sin dejar un hueco al lado del campo. --}}
                @if ($seccion->hayPlantillas())
                    <div class="col-12 col-xl-4">
                        <p class="etiqueta mb-2">Plantillas</p>
                        <ul class="evolucion-plantillas">
                            @foreach ($seccion->plantillas() as $plantilla)
                                <li>
                                    <button type="button" class="evolucion-plantilla"
                                            data-evolucion-plantilla="{{ $plantilla['texto'] }}"
                                            title="Copiar «{{ $plantilla['titulo'] }}» en el campo">
                                        <span class="evolucion-plantilla-titulo">{{ $plantilla['titulo'] }}</span>
                                        <span class="texto-tenue small">Copiar en el campo</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                        <p class="form-text mb-0">
                            Son las plantillas de evolución de tu escritorio. Al elegir una se copia en el
                            campo y la puedes editar antes de guardar.
                        </p>
                    </div>
                @endif
            </div>

            {{-- El aviso de "hay cambios sin guardar" se muestra recién cuando el texto cambió: se
                 controla por `hidden`, que es lo que el script alterna. --}}
            <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-0" id="evolucion-sin-guardar" hidden>
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span>Hay cambios sin guardar.</span>
            </p>

            <div class="d-flex justify-content-end gap-2 mt-3">
                <a class="btn btn-outline-secondary" href="{{ $seccion->urlVolver() }}">Cancelar</a>
                {{-- La única acción primaria de la sección: guardar lo escrito. --}}
                <button class="btn btn-primary">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar la evolución
                </button>
            </div>
        </form>

        @unless ($seccion->hayPlantillas())
            <div class="mt-3">
                <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">{{ $vacio['texto'] }}</x-ui.vacio>
            </div>
        @endunless
    </x-ui.tarjeta>
</div>
