{{-- Imágenes de mamas (WEB-11.10): un lienzo por mama sobre su imagen base, con selector de color y grosor, por consulta.
     El dibujo se guarda solo (`mamas-lienzo.js`) y no se imprime. Recibe `$seccion` (DibujosDeMamasVista). --}}
<x-ui.tarjeta titulo="Imágenes de mamas" icono="bi-brush">
    <p class="texto-tenue small mb-3">{{ $seccion->ayuda() }}</p>

    <div class="mamas-herramientas d-flex flex-wrap align-items-center gap-3 mb-3" data-mamas-herramientas>
        <div class="d-flex align-items-center gap-2">
            <label class="etiqueta mb-0" for="mamas-color">Color</label>
            <input type="color" id="mamas-color" class="form-control form-control-color" value="#d62828" data-mamas-color>
            @foreach ($seccion->colores() as $hex => $nombre)
                <button type="button" class="mamas-muestra" data-mamas-muestra="{{ $hex }}" title="{{ $nombre }}"
                        aria-label="{{ $nombre }}"></button>
            @endforeach
        </div>
        <div class="d-flex align-items-center gap-2">
            <label class="etiqueta mb-0" for="mamas-grosor">Grosor</label>
            <input type="range" id="mamas-grosor" min="1" max="20" value="4" data-mamas-grosor>
            <output data-mamas-grosor-valor>4</output>
        </div>
        <span class="texto-tenue small ms-auto" data-mamas-estado role="status">Sin cambios</span>
    </div>

    <div class="row g-3">
        @foreach ($seccion->lienzos() as $lienzo)
            <div class="col-12 col-lg-6" data-mama-lienzo="{{ $lienzo['lado'] }}"
                 data-url-guardar="{{ $lienzo['guardar'] }}" data-url-dibujo="{{ $lienzo['dibujo'] }}">
                <h3 class="paso-titulo mb-2">{{ $lienzo['titulo'] }}</h3>
                <div class="mama-lienzo-marco">
                    <img src="{{ $lienzo['base'] }}" alt="Imagen base de la {{ strtolower($lienzo['titulo']) }}" class="mama-lienzo-base" width="400" height="400">
                    <canvas class="mama-lienzo" width="400" height="400" aria-label="Lienzo para marcar la {{ strtolower($lienzo['titulo']) }}"></canvas>
                </div>
                <div class="d-flex gap-2 mt-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-mama-deshacer>
                        <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Deshacer
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-mama-borrar>
                        <i class="bi bi-eraser" aria-hidden="true"></i> Borrar todo
                    </button>
                </div>
            </div>
        @endforeach
    </div>
</x-ui.tarjeta>
