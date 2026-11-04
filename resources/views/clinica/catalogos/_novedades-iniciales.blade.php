{{-- Las novedades del catálogo inicial (WEB-12.8): lo que el catálogo inicial tiene y el médico todavía no. Nunca se
     agregan solas: el botón abre el diálogo para **elegir cuáles** y el médico las acepta ahí (2026-11-03). --}}
@if (isset($novedadesIniciales) && $novedadesIniciales && $novedadesIniciales->hay())
    <x-ui.tarjeta id="catalogo-novedades" class="mb-3">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <i class="bi bi-gift fs-4" aria-hidden="true"></i>
            <p class="mb-0 flex-grow-1">{{ $novedadesIniciales->texto() }}</p>
            <button type="button" class="btn btn-outline-primary" data-abrir-dialogo="dialogo-catalogo-inicial">
                <i class="bi bi-download" aria-hidden="true"></i> Cargar el catálogo inicial
            </button>
        </div>
    </x-ui.tarjeta>

    {{-- El diálogo lateral con lo que falta, para marcar qué se agrega. Todo sale marcado: lo común es querer todo y
         desmarcar lo que no. El `data-catalogo-inicial` es el gancho del JS (`catalogos.js`) para el «todo» y el conteo. --}}
    <x-ui.dialogo id="dialogo-catalogo-inicial" :titulo="$novedadesIniciales->tituloDelDialogo()" lateral data-catalogo-inicial>
        <form method="POST" action="{{ $novedadesIniciales->urlCargar() }}">
            @csrf

            <div class="dialogo-cuerpo">
                <p class="texto-tenue small mb-3">
                    Elige lo que quieras agregar. Lo que ya tienes no se toca.
                </p>

                {{-- Con un catálogo inicial grande (el de diagnósticos trae más de mil) buscar es la única forma de
                     llegar a una fila: filtra la lista y no cambia lo marcado. --}}
                <label class="visually-hidden" for="catalogo-inicial-buscar">Buscar en el catálogo inicial</label>
                <input type="search" class="form-control form-control-sm mb-2" id="catalogo-inicial-buscar"
                       placeholder="Buscar en la lista" autocomplete="off" data-catalogo-inicial-buscar>

                <div class="form-check border-bottom pb-2 mb-2">
                    <input class="form-check-input" type="checkbox" id="catalogo-inicial-todos" data-catalogo-inicial-todos checked>
                    <label class="form-check-label fw-semibold" for="catalogo-inicial-todos">
                        Todos <span class="texto-tenue fw-normal" data-catalogo-inicial-cuantos>{{ $novedadesIniciales->cuantos() }}</span>
                    </label>
                </div>

                <div class="catalogo-inicial-lista">
                    @foreach ($novedadesIniciales->items() as $item)
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="ids[]" value="{{ $item['id'] }}"
                                   id="catalogo-inicial-{{ $item['id'] }}" data-catalogo-inicial-item checked>
                            <label class="form-check-label" for="catalogo-inicial-{{ $item['id'] }}">{{ $item['texto'] }}</label>
                        </div>
                    @endforeach
                </div>

                <p class="texto-tenue small mb-0 mt-2 d-none" data-catalogo-inicial-sin-coincidencias>Ninguna fila coincide con la búsqueda.</p>
            </div>

            <div class="dialogo-pie">
                <button type="button" class="btn btn-outline-secondary" data-cerrar-dialogo>Cancelar</button>
                <button class="btn btn-primary" data-catalogo-inicial-agregar>Agregar</button>
            </div>
        </form>
    </x-ui.dialogo>
@endif
