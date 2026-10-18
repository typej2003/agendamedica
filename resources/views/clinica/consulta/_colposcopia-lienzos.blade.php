{{-- La colposcopía con imagen editable (A-13): los **dos lienzos** del cuello uterino —nulípara y multípara— sobre su
     imagen base, con las herramientas de color y grosor. Cada lienzo elige su base (vectorial o la del escritorio) y la
     recuerda; cambiarla no borra el trazo. El trazo se guarda solo (800 ms después de soltar) y se imprime con el
     informe cuando se marca «Incluir imágenes». Recibe `$seccion` (`DibujosDeColposcopiaVista`).

     La vista no lleva estilos ni JS: las clases están en `doctorisimo-ui.css` (las genéricas `.lienzo-*`, compartidas
     con las mamas) y los `data-*` los resuelve `public/js/clinica/lienzo.js` con `colposcopia-lienzo.js`. --}}
<x-ui.tarjeta titulo="Dibujo del cuello uterino" icono="bi-brush">
    <p class="texto-tenue small mb-3">{{ $seccion->ayuda() }}</p>

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

    <div class="row g-3">
        @foreach ($seccion->lienzos() as $lienzo)
            <div class="col-12 col-xl-6" data-lienzo data-variante="{{ $lienzo['variante'] }}"
                 data-url-guardar="{{ $lienzo['guardar'] }}" data-url-dibujo="{{ $lienzo['dibujo'] }}">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                    <h3 class="paso-titulo mb-0">
                        {{ $lienzo['titulo'] }}
                        <span class="texto-tenue fw-normal small">· {{ mb_strtolower($lienzo['descripcion']) }}</span>
                    </h3>
                    <div class="d-flex align-items-center gap-2">
                        <label class="etiqueta mb-0" for="colposcopia-base-{{ $lienzo['variante'] }}">Imagen base</label>
                        <select id="colposcopia-base-{{ $lienzo['variante'] }}" class="form-select form-select-sm w-auto"
                                data-lienzo-base>
                            @foreach ($lienzo['bases'] as $base)
                                <option value="{{ $base['valor'] }}" data-imagen="{{ $base['imagen'] }}"
                                        {{ $base['elegida'] ? 'selected' : '' }}>{{ $base['texto'] }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="lienzo-marco">
                    <img src="{{ $lienzo['imagen'] }}" class="lienzo-base" width="520" height="400"
                         alt="Imagen base del {{ mb_strtolower($lienzo['titulo']) }} ({{ mb_strtolower($lienzo['descripcion']) }})"
                         data-lienzo-imagen>
                    <canvas class="lienzo" width="520" height="400" data-lienzo-canvas
                            aria-label="Lienzo para marcar el {{ mb_strtolower($lienzo['titulo']) }}"></canvas>
                </div>

                <div class="d-flex gap-2 mt-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-lienzo-deshacer>
                        <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Deshacer
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-lienzo-borrar>
                        <i class="bi bi-eraser" aria-hidden="true"></i> Borrar todo
                    </button>
                </div>
            </div>
        @endforeach
    </div>
</x-ui.tarjeta>
