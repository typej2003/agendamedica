@props(['plantillas', 'destino', 'tipo', 'nombre' => 'texto', 'tituloDestino' => null])

{{-- Plantillas de texto de una sección: un **botón** de una línea arriba del campo («Plantillas (3)») y, al
     abrirlo, un **cajón lateral** con el buscador, la lista de las plantillas `formato_print` de ese tipo y
     «Guardar como plantilla» (revisión de Alexander, 2026-10-17).

     Antes el panel era un `<aside>` pintado arriba del campo y se comía una franja entera; ahora el campo y su
     texto quedan visibles desde el principio. Al elegir una plantilla, su texto **reemplaza** el del campo
     `destino` (con aviso si el campo ya tiene texto) y el cajón se cierra.
     Cada plantilla: ['titulo' => …, 'texto' => …]. `tituloDestino` (opcional) recibe también el título (la dieta).
     El disparador y el cierre los resuelve `shell.js` (`data-abrir-dialogo` / `data-cerrar-dialogo`) y el resto
     `public/js/clinica/plantillas-panel.js`; la vista no lleva JS.

     El cajón es un `<dialog>` nativo (`x-ui.dialogo` con `lateral`): Esc y el foco atrapado los pone el
     navegador, y al cerrar el foco vuelve solo al botón que lo abrió. --}}
@php($id = 'plantillas-' . strtolower($tipo))
@php($cuenta = count($plantillas))

<div {{ $attributes->merge(['class' => 'plantillas-disparador']) }}>
    <button type="button" class="btn btn-outline-secondary" data-abrir-dialogo="{{ $id }}"
            aria-haspopup="dialog" aria-controls="{{ $id }}" id="{{ $id }}-disparador">
        <i class="bi bi-files" aria-hidden="true"></i>
        <span>Plantillas <span data-plantillas-cuenta>@if ($cuenta > 0)({{ $cuenta }})@endif</span></span>
    </button>
</div>

<x-ui.dialogo :id="$id" :titulo="'Plantillas de ' . $nombre" lateral>
    <div class="plantillas-cajon" data-plantillas-panel
         data-destino="{{ $destino }}" data-tipo="{{ $tipo }}" data-url-guardar="{{ route('clinica.plantillas.guardar') }}"
         data-boton="{{ $id }}-disparador"
         @if ($tituloDestino) data-titulo-destino="{{ $tituloDestino }}" @endif>

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
                <label class="etiqueta" for="{{ $id }}-titulo-plantilla">Título de la plantilla</label>
                <input type="text" id="{{ $id }}-titulo-plantilla" class="form-control form-control-sm mb-2" maxlength="200" data-plantillas-titulo>
                <button type="button" class="btn btn-primary btn-sm" data-plantillas-confirmar>Guardar</button>
                <p class="small mt-2 mb-0" data-plantillas-mensaje role="status" hidden></p>
            </div>
        </details>

        <div class="dialogo-pie">
            <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cerrar</button>
        </div>
    </div>
</x-ui.dialogo>
