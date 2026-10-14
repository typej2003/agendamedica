{{-- La colposcopía de la consulta (WEB-6.5): el texto del informe del examen colposcópico, con las
     plantillas del escritorio (`formato_print` tipo `CO`) al lado y guardado explícito.

     Es la solapa `tabpage_31` del escritorio (`w_consulta_principal.srw`), que escribe el mismo texto en
     `consultas.observaciones` —la columna que imprime la hoja «INFORME DE COLPOSCOPÍA»— y en su copia
     `examen_fisico_nuevo.colposcopia`. Acá el texto es uno: el mapa del dato está en
     `App\Clinica\Consulta\ColposcopiaDeLaConsulta`.

     El aviso de cambios sin guardar y las plantillas los pone `consulta.js` desde los `data-*` del
     formulario (el aviso es el id del formulario más `-sin-guardar`); la vista no lleva JS ni estilos. --}}
@php
    $estado = $seccion->estado();
@endphp

<x-ui.tarjeta :titulo="$seccion->titulo()" :icono="$seccion->icono()">
    <x-slot name="acciones">
        <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']"
                     :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
    </x-slot>

    <p class="texto-tenue small mb-3">{{ $seccion->ayuda() }}</p>

    <form method="POST" action="{{ $seccion->urlGuardar() }}" id="colposcopia-form"
          data-consulta-plantillas data-aviso="{{ $seccion->avisoSinGuardar() }}">
        @csrf

        {{-- Las plantillas del escritorio, como fichas en una fila encima del campo: no le quitan ancho al
             texto. Van **dentro del formulario** (como en la evolución): `consulta.js` las busca ahí.
             Sin plantillas no se pinta nada aquí; el aviso de abajo lo dice en una línea. --}}
        @if ($seccion->hayPlantillas())
            <p class="etiqueta mb-2">Plantillas <span class="texto-tenue small fw-normal">· al elegir una se copia en el campo y la puedes editar antes de guardar</span></p>
            <ul class="evolucion-plantillas">
                @foreach ($seccion->plantillas() as $plantilla)
                    <li>
                        <button type="button" class="evolucion-plantilla"
                                data-plantilla-texto="{{ $plantilla['texto'] }}"
                                title="Copiar «{{ $plantilla['titulo'] }}» en el campo">
                            <span class="evolucion-plantilla-titulo">{{ $plantilla['titulo'] }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif

        <label class="etiqueta" for="{{ $seccion->campo() }}">Informe de colposcopía</label>
        <textarea name="{{ $seccion->campo() }}" id="{{ $seccion->campo() }}" class="form-control" rows="12"
                  data-plantilla-destino placeholder="{{ $seccion->marcador() }}">{{ $seccion->valor() }}</textarea>

        <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-0" id="colposcopia-form-sin-guardar" hidden>
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <span>Hay cambios sin guardar.</span>
        </p>

        <div class="d-flex justify-content-end gap-2 mt-3">
            <a class="btn btn-outline-secondary" href="{{ $seccion->urlVolver() }}">Cancelar</a>
            @if ($seccion->esUnDocumento() && $seccion->urlImprimir() !== '')
                <a class="btn btn-outline-primary" href="{{ $seccion->urlImprimir() }}" target="_blank" rel="noopener">
                    <i class="bi bi-printer" aria-hidden="true"></i> Imprimir el informe
                </a>
            @endif
            <button class="btn btn-primary">
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar la colposcopía
            </button>
        </div>
    </form>

    @unless ($seccion->hayPlantillas())
        <p class="texto-tenue small mt-3 mb-0">
            No tenés plantillas de colposcopía cargadas: el escrito va tal cual en el informe.
        </p>
    @endunless
</x-ui.tarjeta>
