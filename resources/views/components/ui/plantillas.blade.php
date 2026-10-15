@props(['plantillas', 'destino', 'tipo', 'nombre' => 'texto', 'tituloDestino' => null])

{{-- Panel lateral de plantillas con buscador (WEB-11.2). Una lista de títulos (las plantillas `formato_print` de un tipo),
     un «Buscar:» y, al elegir una, su texto **reemplaza** el del campo `destino` (con aviso si el campo ya tiene texto).
     «Guardar como plantilla» guarda el texto del campo con el título que se escriba, en este mismo tipo.
     Cada plantilla: ['titulo' => …, 'texto' => …]. `tituloDestino` (opcional) recibe también el título (la dieta).
     El JS está en `public/js/clinica/plantillas-panel.js`. --}}
@php($id = 'plantillas-' . strtolower($tipo))
<aside {{ $attributes->merge(['class' => 'plantillas-panel']) }} id="{{ $id }}" data-plantillas-panel
       data-destino="{{ $destino }}" data-tipo="{{ $tipo }}" data-url-guardar="{{ route('clinica.plantillas.guardar') }}"
       @if ($tituloDestino) data-titulo-destino="{{ $tituloDestino }}" @endif aria-label="Plantillas de {{ $nombre }}">
    <p class="etiqueta mb-2">Plantillas</p>

    <label class="visually-hidden" for="{{ $id }}-buscar">Buscar plantilla</label>
    <input type="search" id="{{ $id }}-buscar" class="form-control form-control-sm mb-2" placeholder="Buscar:" autocomplete="off"
           data-plantillas-buscar>

    <ul class="plantillas-lista" data-plantillas-lista>
        @foreach ($plantillas as $plantilla)
            <li data-plantilla-fila>
                <button type="button" class="plantillas-item" data-plantilla-titulo="{{ $plantilla['titulo'] }}"
                        data-plantilla-texto="{{ $plantilla['texto'] }}" title="Usar «{{ $plantilla['titulo'] }}» en el campo">
                    <i class="bi bi-files" aria-hidden="true"></i>
                    <span>{{ $plantilla['titulo'] }}</span>
                </button>
            </li>
        @endforeach
    </ul>
    <p class="texto-tenue small mb-2" data-plantillas-vacio @if (count($plantillas)) hidden @endif>
        <span data-plantillas-sin-plantillas>Todavía no tienes plantillas de {{ $nombre }}.</span>
        <span data-plantillas-sin-resultados hidden>Ninguna plantilla coincide con la búsqueda.</span>
    </p>

    <details class="plantillas-guardar" data-plantillas-guardar>
        <summary class="btn btn-outline-secondary btn-sm"><i class="bi bi-save" aria-hidden="true"></i> Guardar como plantilla</summary>
        <div class="mt-2">
            <label class="etiqueta" for="{{ $id }}-titulo">Título de la plantilla</label>
            <input type="text" id="{{ $id }}-titulo" class="form-control form-control-sm mb-2" maxlength="200" data-plantillas-titulo>
            <button type="button" class="btn btn-primary btn-sm" data-plantillas-confirmar>Guardar</button>
            <p class="small mt-2 mb-0" data-plantillas-mensaje role="status" hidden></p>
        </div>
    </details>
</aside>
