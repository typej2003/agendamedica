{{-- El editor de una orden de varias filas (WEB-5.4): la orden de exámenes de laboratorio y la solicitud de estudios. Recibe `$vm`
     (OrdenVista). Lo incluyen `_solicitudes-laboratorio` y `_solicitudes-estudios`: son la misma pantalla con otros textos.

     La orden se arma en el navegador (se busca en el catálogo, se agrega, se quita) y **se guarda entera con el botón**: lo que queda en
     la lista es lo que queda guardado. El buscador y las rutinas van **fuera** del formulario (no mandan nada por sí solos; lo que eligen
     entra a la lista). `consulta-ordenes.js` hace todo eso desde los `data-*`; esta vista no lleva JS ni estilos. --}}
@php
    $estado = $vm->estado();
    $vacio = $vm->vacio();
    $sinCatalogo = $vm->sinCatalogo();
@endphp

<div id="orden-editor" data-campo="{{ $vm->campo() }}" data-url-catalogo="{{ $vm->urlCatalogo() }}"
     data-url-rutina="{{ $vm->urlRutina() }}" data-umbral="{{ $vm->umbralDeRutina() }}"
     data-agrupa="{{ $vm->esLaboratorio() ? '1' : '0' }}" data-cosas="{{ $vm->cosas() }}">
    <x-ui.tarjeta :titulo="$vm->titulo()" :icono="$vm->icono()">
        <x-slot name="acciones">
            <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']" :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
            @if ($vm->esReal())
                <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlImprimir() }}" target="_blank" rel="noopener">
                    <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
                </a>
            @endif
        </x-slot>

        <p class="texto-tenue small mb-3">{{ $vm->ayuda() }}</p>

        <form method="POST" action="{{ $vm->urlGuardar() }}" id="orden-form" data-orden-form data-aviso="{{ $vm->avisoSinGuardar() }}">
            @csrf

            <p class="texto-tenue small mb-2" id="orden-cuenta" aria-live="polite">{{ $vm->cuenta() }}</p>

            <ul class="orden-lista {{ $vm->numerada() ? 'orden-lista-numerada' : '' }}" id="orden-items" aria-label="{{ ucfirst($vm->cosas()) }} de la orden">
                @foreach ($vm->items() as $item)
                    @include('clinica.consulta._orden-fila', ['item' => $item, 'campo' => $vm->campo()])
                @endforeach
            </ul>

            <div id="orden-vacio" @if ($vm->total() > 0) hidden @endif>
                <x-ui.vacio :icono="$vacio['icono']" :titulo="$vacio['titulo']">{{ $vacio['texto'] }}</x-ui.vacio>
            </div>

            <x-ui.campo :etiqueta="$vm->etiquetaDelResumen()" for="orden-observacion" error="observacion" class="mb-3" :ayuda="$vm->ayudaDelResumen()">
                <textarea name="observacion" id="orden-observacion" class="form-control" rows="3"
                          maxlength="{{ $vm->largoDeLaObservacion() }}">{{ $vm->observacion() }}</textarea>
            </x-ui.campo>

            <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mb-3" data-orden-aviso hidden>
                <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                <span>Hay cambios sin guardar.</span>
            </p>

            <div class="d-flex justify-content-end gap-2">
                <button class="btn btn-primary">
                    <i class="bi bi-check2-circle" aria-hidden="true"></i> {{ $vm->textoDeGuardar() }}
                </button>
            </div>
        </form>

        @if ($vm->puedeEliminar())
            <form method="POST" action="{{ $vm->urlEliminar() }}" class="mt-3" data-confirmar="{{ $vm->avisoEliminar() }}" data-orden-salir>
                @csrf
                @method('DELETE')
                <button class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-trash" aria-hidden="true"></i> {{ $vm->textoDeEliminar() }}
                </button>
            </form>
        @endif
    </x-ui.tarjeta>

    {{-- Las herramientas para llenar la orden: el catálogo del médico y, en laboratorio, las rutinas (un tipo de examen completo). --}}
    <x-ui.tarjeta :titulo="'Agregar ' . $vm->cosas()" icono="bi-search" class="mt-3" id="orden-herramientas">
        @if (! $vm->hayCatalogo())
            <x-ui.vacio :icono="$sinCatalogo['icono']" :titulo="$sinCatalogo['titulo']">
                {{ $sinCatalogo['texto'] }}
                <x-slot name="acciones">
                    <a class="btn btn-sm btn-outline-secondary" href="{{ $vm->urlAdministrar() }}" target="_blank" rel="noopener">
                        <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Abrir el catálogo de exámenes
                    </a>
                </x-slot>
            </x-ui.vacio>
        @else
            {{-- «Exámenes» y «Rutinas» como dos chips (WEB-11.20): en el escritorio son dos pestañas, una para pasar exámenes sueltos y otra para pasar
                 una rutina completa. Sin JavaScript se ven las dos cosas, una debajo de la otra. --}}
            @if ($vm->ofreceRutinas())
                <div class="btn-group mb-3" role="group" aria-label="Qué agregar" data-orden-modos hidden>
                    <button type="button" class="btn btn-outline-secondary active" data-orden-modo="sueltos" aria-pressed="true">
                        <i class="bi bi-droplet" aria-hidden="true"></i> Exámenes
                    </button>
                    <button type="button" class="btn btn-outline-secondary" data-orden-modo="rutinas" aria-pressed="false">
                        <i class="bi bi-collection" aria-hidden="true"></i> Rutinas
                    </button>
                </div>
            @endif

            <div id="orden-modo-sueltos">
            <div class="position-relative" id="orden-busqueda">
                <label class="form-label" for="orden-texto">{{ $vm->etiquetaDelBuscador() }}</label>

                <div class="orden-buscador">
                    <div class="buscador-campo buscador-vivo">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input type="search" id="orden-texto" class="form-control form-control-sm" autocomplete="off"
                               placeholder="{{ $vm->placeholderDelBuscador() }}" aria-controls="orden-resultados">
                    </div>

                    @if ($vm->esLaboratorio())
                        <select id="orden-tipo" class="form-select form-select-sm" aria-label="Filtrar por tipo de examen">
                            <option value="">Todos los tipos</option>
                            @foreach ($vm->tipos() as $tipo)
                                <option value="{{ $tipo['codigo'] }}">{{ $tipo['nombre'] }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>

                <div class="list-group resultados-flotantes" id="orden-resultados" aria-label="Resultados de la búsqueda" hidden></div>
            </div>

            <p class="form-text mt-2 mb-0" id="orden-ayuda" data-ayuda="{{ $vm->ayudaDelBuscador() }}">{{ $vm->ayudaDelBuscador() }}</p>
            </div>

            @if ($vm->ofreceRutinas())
                <div class="mt-3" id="orden-rutinas">
                    <h3 class="orden-subtitulo">Rutinas</h3>
                    <p class="form-text mt-0 mb-2">Una rutina agrega de una vez todos los exámenes de un tipo (los que ya están en la orden no se repiten).</p>
                    <div class="orden-rutinas">
                        @foreach ($vm->tipos() as $tipo)
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-orden-rutina="{{ $tipo['codigo'] }}"
                                    data-nombre="{{ $tipo['nombre'] }}" data-cantidad="{{ $tipo['cantidad'] }}"
                                    title="Agregar los {{ $tipo['cantidad'] }} exámenes de {{ $tipo['nombre'] }}">
                                <i class="bi bi-collection" aria-hidden="true"></i> {{ $tipo['nombre'] }}
                                <span class="texto-tenue">({{ $tipo['cantidad'] }})</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            <p class="form-text mb-0 mt-3">
                <a href="{{ $vm->urlAdministrar() }}" target="_blank" rel="noopener">{{ $vm->textoDelEnlaceAlCatalogo() }}</a>
                (se abre en otra pestaña, así no pierdes lo que llevas).
            </p>

            {{-- Los resultados: el script clona estas filas (el marcado es de la vista, no del script). --}}
            <template id="orden-resultado-plantilla">
                <button type="button" class="resultado-paciente resultado-recipe">
                    <span class="resultado-paciente-datos">
                        <strong data-resultado-nombre></strong>
                        <span class="texto-tenue small d-block" data-resultado-detalle></span>
                    </span>
                    <span class="btn btn-sm btn-outline-primary" data-resultado-accion>
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> <span data-resultado-texto>Agregar</span>
                    </span>
                </button>
            </template>
        @endif
    </x-ui.tarjeta>

    {{-- La plantilla de una fila: el script la clona al agregar (el mismo marcado que las filas que pinta el servidor). --}}
    <template id="orden-fila-plantilla">
        @include('clinica.consulta._orden-fila', ['item' => ['codigo' => '', 'nombre' => '', 'tipo' => null, 'aviso' => null], 'campo' => $vm->campo()])
    </template>
</div>
