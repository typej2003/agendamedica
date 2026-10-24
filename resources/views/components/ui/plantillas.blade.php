@props(['plantillas', 'destino', 'tipo' => null, 'tipos' => null, 'nombre' => 'texto', 'tituloDestino' => null, 'repartir' => null])

{{-- Plantillas de texto de una sección: un **botón** de una línea arriba del campo («Plantillas (3)») y, al
     abrirlo, un **cajón lateral** con el buscador, la lista de las plantillas `formato_print` de ese tipo y
     «Guardar como plantilla» (revisión de Alexander, 2026-10-17).

     Antes el panel era un `<aside>` pintado arriba del campo y se comía una franja entera; ahora el campo y su
     texto quedan visibles desde el principio. Al elegir una plantilla, su texto **reemplaza** el del campo
     `destino` (con aviso si el campo ya tiene texto) y el cajón se cierra.
     Cada plantilla: ['titulo' => …, 'texto' => …]. `tituloDestino` (opcional) recibe también el título (la dieta).
     El disparador y el cierre los resuelve `shell.js` (`data-abrir-dialogo` / `data-cerrar-dialogo`) y el resto
     `public/js/clinica/plantillas-panel.js`; la vista no lleva JS.

     **Los tipos.** Las secciones de siempre pasan uno suelto (`tipo="EV"`). Un estudio ecográfico puede tener
     más de uno (`:tipos="['O2' => 'Obstétrico 2', 'O3' => 'Obstétrico 3']"`): el cajón lista los de todos y,
     como el servidor necesita **un** tipo para guardar la plantilla nueva, aparece un desplegable chico con
     esos tipos, con el primero elegido. Hoy cada estudio de la web tiene un solo tipo, así que el desplegable
     no se ve; queda para el estudio que en el escritorio comparta plantillas entre dos tipos.
     Se acepta `código => etiqueta` (y `código => código` si no hay etiqueta).

     **`repartir`** (2026-10-23, Alexander: la plantilla «NORMAL» del escritorio llena todo a la vez): un selector del
     formulario cuyos cuadros de texto se rellenan con lo que la plantilla trae por rótulo («GENERAL: …. TIROIDES: ….», el
     mismo formato del resumen). Los rótulos que la plantilla no trae no se tocan.

     **«Administrar plantillas»** (lo que la médica no encontraba): al pie del cajón, un enlace al catálogo
     **filtrado por el tipo** de la sección (`/clinica/catalogos/plantillas-texto?filtro=<TIPO>`), para verlas,
     corregirlas y crear las que falten sin salir de la consulta (abre en otra pestaña: así no se pierde lo
     escrito). Va en **todas** las secciones que usan este componente.

     El cajón es un `<dialog>` nativo (`x-ui.dialogo` con `lateral`): Esc y el foco atrapado los pone el
     navegador, y al cerrar el foco vuelve solo al botón que lo abrió. --}}
@php
    $tipos = $tipos ?? ($tipo !== null ? [$tipo => $tipo] : []);
    $tipoPrincipal = (string) (array_key_first($tipos) ?? '');
    $id = 'plantillas-' . strtolower(implode('-', array_keys($tipos)));
    $cuenta = count($plantillas);
    $urlAdministrar = route('clinica.catalogos.plantillas-texto', ['filtro' => $tipoPrincipal]);
@endphp

<div {{ $attributes->merge(['class' => 'plantillas-disparador']) }} data-tour="consulta-plantillas">
    <button type="button" class="btn btn-outline-secondary" data-abrir-dialogo="{{ $id }}"
            aria-haspopup="dialog" aria-controls="{{ $id }}" id="{{ $id }}-disparador">
        <i class="bi bi-files" aria-hidden="true"></i>
        <span>Plantillas <span data-plantillas-cuenta>@if ($cuenta > 0)({{ $cuenta }})@endif</span></span>
    </button>
</div>

<x-ui.dialogo :id="$id" :titulo="'Plantillas de ' . $nombre" lateral>
    <div class="plantillas-cajon" data-plantillas-panel
         data-destino="{{ $destino }}" data-tipo="{{ $tipoPrincipal }}"
         data-url-guardar="{{ route('clinica.plantillas.guardar') }}"
         data-url-administrar="{{ $urlAdministrar }}"
         data-boton="{{ $id }}-disparador"
         @if ($tituloDestino) data-titulo-destino="{{ $tituloDestino }}" @endif
         @if ($repartir) data-repartir="{{ $repartir }}" @endif>

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
                @if (count($tipos) > 1)
                    {{-- Con más de un tipo, el médico elige con cuál se guarda la plantilla nueva. --}}
                    <label class="etiqueta" for="{{ $id }}-tipo">Tipo de la plantilla</label>
                    <select id="{{ $id }}-tipo" class="form-select form-select-sm mb-2" data-plantillas-tipo>
                        @foreach ($tipos as $codigo => $etiqueta)
                            <option value="{{ $codigo }}">{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                @endif
                <label class="etiqueta" for="{{ $id }}-titulo-plantilla">Título de la plantilla</label>
                <input type="text" id="{{ $id }}-titulo-plantilla" class="form-control form-control-sm mb-2" maxlength="200" data-plantillas-titulo>
                <button type="button" class="btn btn-primary btn-sm" data-plantillas-confirmar>Guardar</button>
                <p class="small mt-2 mb-0" data-plantillas-mensaje role="status" hidden></p>
            </div>
        </details>

        <div class="dialogo-pie">
            <a class="btn btn-sm btn-outline-secondary me-auto" href="{{ $urlAdministrar }}" target="_blank" rel="noopener"
               data-plantillas-administrar>
                <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Administrar plantillas
            </a>
            <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cerrar</button>
        </div>
    </div>
</x-ui.dialogo>
