{{-- **La imagen del examen, con sus dos modos** (O-09, antes A-13): una sola tarjeta con un radiobutón entre la *imagen
     predeterminada* (el esquema sobre el que se dibuja) y el *videocolposcopio* (la foto real del estudio). Se ve **sólo
     el modo elegido** y la elección **decide qué se imprime**: lo que no se eligió no sale en el informe aunque exista.

     Los dos modos se marcan con el mismo motor (`lienzo.js`, `imagen-del-examen.js`): color, grosor, deshacer, borrar
     todo y autoguardado al soltar el trazo. El esquema guarda su base (vectorial o del escritorio, en la colposcopía); la
     foto se marca **relativa a ella** (el trazo se va con la foto si se quita, y el aviso lo dice antes de borrar).

     Recibe `$seccion` (`ImagenDelExamenVista`). La vista no lleva estilos ni JS: las clases están en `doctorisimo-ui.css`
     (las `.lienzo-*`, compartidas con las mamas) y los `data-*` los resuelve `imagen-del-examen.js`. --}}
@php
    $estado = $seccion->estado();
    $fotos = $seccion->fotos();
@endphp

<x-ui.tarjeta :titulo="$seccion->titulo()" :icono="$seccion->icono()">
    <x-slot name="acciones">
        <x-ui.estado :tono="$estado['tono']" :icono="$estado['icono']" :title="$estado['titulo']">{{ $estado['texto'] }}</x-ui.estado>
    </x-slot>

    <p class="texto-tenue small mb-3">{{ $seccion->ayuda() }}</p>

    {{-- **El radiobutón** (O-09): los dos modos son excluyentes. Lo guarda `imagen-del-examen.js` sin recargar la
         pantalla (cambiar de modo no puede perder el texto del informe que no se guardó); sin JS, el botón «Ver» envía
         el mismo formulario y el servidor vuelve a pintar la tarjeta en el modo elegido. --}}
    <form method="POST" action="{{ $seccion->urlElegir() }}" id="imagen-examen-modo" data-imagen-modo>
        @csrf

        <fieldset class="mb-3">
            <legend class="etiqueta">Qué imagen se ve y se imprime</legend>
            <div class="d-flex flex-wrap gap-4">
                @foreach ($seccion->modos() as $modo)
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="modo" value="{{ $modo['valor'] }}"
                               id="imagen-modo-{{ $modo['valor'] }}" data-imagen-modo-opcion
                               {{ $modo['activa'] ? 'checked' : '' }}>
                        <label class="form-check-label" for="imagen-modo-{{ $modo['valor'] }}">
                            {{ $modo['texto'] }}
                            <span class="texto-tenue small d-block">{{ $modo['descripcion'] }}</span>
                        </label>
                    </div>
                @endforeach
            </div>
        </fieldset>

        <input type="hidden" name="imagen_id" value="{{ $seccion->fotoElegida() }}" data-imagen-elegida>
        <div class="d-flex align-items-center justify-content-end gap-2">
            <span class="texto-tenue small" data-imagen-modo-estado role="status" aria-live="polite"></span>
            <button class="btn btn-outline-secondary btn-sm" data-imagen-modo-ver>Ver</button>
        </div>
    </form>

    {{-- Las herramientas del lienzo: las mismas de las mamas y del cuello uterino (A-13). Una sola barra manda a todos los
         lienzos de la tarjeta. --}}
    <div class="lienzos-herramientas d-flex flex-wrap align-items-center gap-3 mb-3" data-lienzo-herramientas>
        <div class="d-flex align-items-center gap-2">
            <label class="etiqueta mb-0" for="lienzo-color">Color</label>
            <input type="color" id="lienzo-color" class="form-control form-control-color" value="#d62828" data-lienzo-color>
            @foreach ($seccion->colores() as $hex => $nombre)
                <button type="button" class="lienzo-muestra" data-lienzo-muestra="{{ $hex }}" title="{{ $nombre }}"
                        aria-label="{{ $nombre }}"></button>
            @endforeach
        </div>
        <div class="d-flex align-items-center gap-2">
            <label class="etiqueta mb-0" for="lienzo-grosor">Grosor</label>
            <input type="range" id="lienzo-grosor" min="1" max="20" value="4" data-lienzo-grosor>
            <output data-lienzo-grosor-valor>4</output>
        </div>
        <span class="texto-tenue small ms-auto" data-lienzo-estado role="status">Sin cambios</span>
    </div>

    {{-- =================================== MODO ESQUEMA (imagen predeterminada) =================================== --}}
    <div data-imagen-panel="esquema" @if (! $seccion->esEsquema()) hidden @endif>
        <div class="row g-3">
            @foreach ($seccion->esquema() as $lienzo)
                <div class="{{ $seccion->columnas() }}" data-lienzo data-variante="{{ $lienzo['variante'] }}"
                     data-url-guardar="{{ $lienzo['guardar'] }}" data-url-dibujo="{{ $lienzo['dibujo'] }}">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                        <h3 class="paso-titulo mb-0">
                            {{ $lienzo['titulo'] }}
                            <span class="texto-tenue fw-normal small">· {{ mb_strtolower($lienzo['descripcion']) }}</span>
                        </h3>
                        @if ($lienzo['bases'] !== [])
                            <div class="d-flex align-items-center gap-2">
                                <label class="etiqueta mb-0" for="imagen-base-{{ $lienzo['variante'] }}">Imagen base</label>
                                <select id="imagen-base-{{ $lienzo['variante'] }}" class="form-select form-select-sm w-auto"
                                        data-lienzo-base>
                                    @foreach ($lienzo['bases'] as $base)
                                        <option value="{{ $base['valor'] }}" data-imagen="{{ $base['imagen'] }}"
                                                {{ $base['elegida'] ? 'selected' : '' }}>{{ $base['texto'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    </div>

                    <div class="lienzo-marco">
                        <img src="{{ $lienzo['imagen'] }}" class="lienzo-base" width="{{ $lienzo['ancho'] }}"
                             height="{{ $lienzo['alto'] }}" alt="Esquema del {{ mb_strtolower($lienzo['titulo']) }}"
                             data-lienzo-imagen>
                        <canvas class="lienzo" width="{{ $lienzo['ancho'] }}" height="{{ $lienzo['alto'] }}" data-lienzo-canvas
                                aria-label="Lienzo para marcar el {{ mb_strtolower($lienzo['titulo']) }}"></canvas>
                    </div>

                    <div class="d-flex gap-2 mt-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-lienzo-deshacer>
                            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Deshacer
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" data-lienzo-borrar
                                data-aviso="{{ $seccion->avisoBorrar() }}">
                            <i class="bi bi-eraser" aria-hidden="true"></i> Borrar todo
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- =================================== MODO FOTO (videocolposcopio) =================================== --}}
    <div data-imagen-panel="foto" @if (! $seccion->esFoto()) hidden @endif>
        @if ($seccion->hayQueElegirLaFoto())
            {{-- Con varias fotos se trabaja sobre una: el selector dice cuál se está marcando y cuál se imprime. --}}
            <div class="d-flex align-items-center gap-2 mb-3">
                <label class="etiqueta mb-0" for="imagen-foto-elegida">Foto a marcar</label>
                <select id="imagen-foto-elegida" class="form-select form-select-sm w-auto" data-imagen-foto>
                    @foreach ($fotos as $lienzo)
                        <option value="{{ $lienzo['id'] }}" {{ $lienzo['activa'] ? 'selected' : '' }}>
                            #{{ $lienzo['id'] }} · {{ $lienzo['nombre'] ?: 'Foto del estudio' }}
                        </option>
                    @endforeach
                </select>
            </div>
        @endif

        @if ($fotos === [])
            <x-ui.vacio :icono="$seccion->sinFotos()['icono']"
                        :titulo="$seccion->sinFotos()['titulo']">{{ $seccion->sinFotos()['texto'] }}</x-ui.vacio>
        @else
            @foreach ($fotos as $lienzo)
                <div data-lienzo data-foto="{{ $lienzo['id'] }}" data-url-guardar="{{ $lienzo['guardar'] }}"
                     data-url-dibujo="{{ $lienzo['dibujo'] }}" @if (! $lienzo['activa']) hidden @endif>
                    <div class="lienzo-marco lienzo-marco-foto">
                        <img src="{{ $lienzo['imagen'] }}" class="lienzo-base" width="{{ $lienzo['ancho'] }}"
                             height="{{ $lienzo['alto'] }}" alt="Foto del estudio ({{ $lienzo['nombre'] ?: 'imagen ' . $lienzo['id'] }})"
                             data-lienzo-imagen>
                        <canvas class="lienzo" width="{{ $lienzo['ancho'] }}" height="{{ $lienzo['alto'] }}" data-lienzo-canvas
                                aria-label="Lienzo para marcar sobre la foto del estudio"></canvas>
                    </div>

                    <div class="d-flex gap-2 mt-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-lienzo-deshacer>
                            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Deshacer
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" data-lienzo-borrar
                                data-aviso="{{ $seccion->avisoBorrarFoto() }}">
                            <i class="bi bi-eraser" aria-hidden="true"></i> Borrar todo
                        </button>
                    </div>
                </div>
            @endforeach
        @endif

        {{-- Las fotos del estudio: la lista, la baja y la subida, que son las mismas de siempre (WEB-6.5). Sólo se ven
             en este modo: es lo que pidió Alexander (O-09), condensar las dos tarjetas en una. --}}
        <div class="mt-3">
            @include('clinica.consulta._imagenes-lista', ['imagenes' => $seccion->imagenes(), 'prefijo' => $seccion->examen()])
        </div>
    </div>
</x-ui.tarjeta>
