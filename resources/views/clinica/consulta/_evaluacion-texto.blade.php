{{-- La solapa de **un texto del examen** (WEB-6.5): la colposcopía (`tabpage_31`), la citología (`tabpage_33`),
     la histeroscopía (`tabpage_49`) o el resultado de exámenes (`tabpage_48`).

     Las cuatro son la misma pantalla —un texto con las plantillas del escritorio al lado, el aviso de cambios
     sin guardar y, si la solapa tiene, sus fotos y su botón de imprimir—, así que hay **un solo** parcial y el
     dato sale de `App\Clinica\Consulta\TextosDelExamen` (la columna de `consultas`, el `tipo` de plantillas y la
     hoja de cada una). El campo y los ids se llaman como la **clave de la solapa** (`colposcopia`, `citologia`,
     …): el aviso de cambios sin guardar y las plantillas los ata `consulta.js` desde los `data-*` del formulario
     (el aviso es el id del formulario más `-sin-guardar`). La vista no lleva JS ni estilos. --}}
@php
    $estado = $seccion->estado();
    $clave = $seccion->clave();
@endphp

<x-ui.tarjeta :titulo="$seccion->titulo()" :icono="$seccion->icono()">
    <x-slot name="acciones">
        <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']"
                     :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
    </x-slot>

    <p class="texto-tenue small mb-3">{{ $seccion->ayuda() }}</p>

    <form method="POST" action="{{ $seccion->urlGuardar() }}" id="{{ $clave }}-form"
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

        <label class="etiqueta" for="{{ $seccion->campo() }}">{{ $seccion->titulo() }}</label>
        <textarea name="{{ $seccion->campo() }}" id="{{ $seccion->campo() }}" class="form-control" rows="12"
                  data-plantilla-destino placeholder="{{ $seccion->marcador() }}">{{ $seccion->valor() }}</textarea>

        <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-0" id="{{ $clave }}-form-sin-guardar" hidden>
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
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar {{ $clave === 'resultados' ? 'el resultado' : 'la ' . mb_strtolower($seccion->titulo()) }}
            </button>
        </div>
    </form>

    @if ($seccion->tienePlantillas())
        @unless ($seccion->hayPlantillas())
            <p class="texto-tenue small mt-3 mb-0">
                No tenés plantillas de {{ mb_strtolower($seccion->titulo()) }} cargadas: el escrito va tal cual en el informe.
            </p>
        @endunless
    @endif

    {{-- Las imágenes del examen (WEB-6.5), si la solapa las tiene (la colposcopía y la histeroscopía): son las
         suyas, etiquetadas para ella. Es el parcial que comparte con la sección genérica de exámenes, con la
         solapa adentro del formulario. --}}
    @if ($imagenes !== null)
        <div class="mt-3">
            @include('clinica.consulta._imagenes', [
                'imagenes' => $imagenes,
                'titulo' => 'Imágenes de ' . mb_strtolower($seccion->titulo()),
                'prefijo' => $clave,
            ])
        </div>
    @endif
</x-ui.tarjeta>
