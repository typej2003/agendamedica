{{-- **La lista y la subida de las imágenes de una consulta o de un examen** (WEB-4.7 y WEB-6.5).

     Es la parte de `_imagenes` que no es la tarjeta: la lista de las que ya están —con su vista o descarga y su baja— y el
     formulario para subir una nueva. Se separó en O-09 porque la **imagen del examen** (colposcopía, histeroscopía) vive
     adentro de una sola tarjeta, la del radiobutón, y ahí no puede haber una tarjeta dentro de otra.

     Recibe `$imagenes` (el ViewModel `ImagenesDeLaConsultaVista`) y `$prefijo` (los ids del formulario: el aviso de
     «elegiste una imagen y no la subiste» lo ata `consulta.js` por el id del formulario). La vista no lleva lógica,
     estilos ni JS. --}}
@php
    $vacioImagenes = $imagenes->sinImagenes();
    $avisoEscritorio = $imagenes->avisoDelEscritorio();
@endphp

@if ($avisoEscritorio)
    <p class="alert alert-info py-2 d-flex align-items-start gap-2" id="{{ $prefijo }}-aviso-escritorio">
        <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
        <span>{{ $avisoEscritorio }}</span>
    </p>
@endif

@if ($imagenes->hay())
    <ul class="list-unstyled mb-3" id="{{ $prefijo }}-imagenes">
        @foreach ($imagenes->imagenes() as $imagen)
            <li class="examen-imagen d-flex flex-wrap align-items-start gap-3">
                <span class="examen-imagen-icono" aria-hidden="true"><i class="bi bi-image"></i></span>
                <div class="flex-grow-1">
                    <p class="mb-1">
                        {{-- Una imagen de un examen se anuncia por **su examen**; una de la sección
                             genérica, por la descripción que le puso el médico. --}}
                        @if ($imagen['etiqueta'])
                            Imagen de {{ $imagen['etiqueta'] }}
                        @else
                            {{ $imagen['descripcion'] ?: 'Sin descripción' }}
                        @endif
                    </p>
                    <p class="texto-tenue small mb-0">
                        @if ($imagenes->alcances() !== [])
                            Consulta N.º {{ $imagen['consulta'] }} ·
                        @endif
                        {{ $imagen['nombre'] ?: 'Imagen sin nombre' }} · {{ $imagen['fecha'] }} ·
                        {{ $imagen['origen'] }}
                    </p>
                    <p class="texto-tenue small mb-0">{{ $imagen['detalle'] }}</p>
                </div>
                <div class="acciones-fila">
                    @if ($imagen['puedeVerse'] && $imagen['esDescarga'])
                        <a class="btn btn-outline-secondary btn-sm" href="{{ $imagen['url'] }}" download
                           aria-label="Descargar la imagen {{ $imagen['nombre'] ?: $imagen['id'] }}">
                            <i class="bi bi-download" aria-hidden="true"></i> Descargar
                        </a>
                    @elseif ($imagen['puedeVerse'])
                        <a class="btn btn-outline-secondary btn-sm" href="{{ $imagen['url'] }}"
                           target="_blank" rel="noopener"
                           aria-label="Ver la imagen {{ $imagen['nombre'] ?: $imagen['id'] }}">
                            <i class="bi bi-eye" aria-hidden="true"></i> Ver
                        </a>
                    @endif
                    @unless ($imagen['deOtraConsulta'])
                    <form method="POST" action="{{ $imagen['urlQuitar'] }}" data-confirmar="{{ $imagen['confirmar'] }}">
                        @csrf
                        @method('DELETE')
                        @if ($imagenes->examen() !== null)
                            <input type="hidden" name="examen" value="{{ $imagenes->examen() }}">
                        @endif
                        <button class="btn btn-outline-danger btn-sm">
                            <i class="bi bi-trash" aria-hidden="true"></i> Quitar
                        </button>
                    </form>
                    @endunless
                </div>
            </li>
        @endforeach
    </ul>
@else
    <x-ui.vacio :icono="$vacioImagenes['icono']" :titulo="$vacioImagenes['titulo']">{{ $vacioImagenes['texto'] }}</x-ui.vacio>
@endif

{{-- La subida. `multipart/form-data` es obligatorio para que el archivo viaje; el nombre del
     archivo lo pone el servidor (el del cliente no se usa). --}}
<form method="POST" action="{{ $imagenes->urlSubir() }}" enctype="multipart/form-data"
      id="{{ $prefijo }}-subida" data-consulta-archivo data-aviso-id="{{ $prefijo }}-sin-subir"
      data-aviso="{{ $imagenes->avisoSinGuardar() }}">
    @csrf

    @if ($imagenes->examen() !== null)
        {{-- La solapa del examen: con esto el controlador marca la etiqueta de la fila y vuelve acá. --}}
        <input type="hidden" name="examen" value="{{ $imagenes->examen() }}">
    @endif

    <div class="row g-3">
        <div class="col-12 {{ $imagenes->pideDescripcion() ? 'col-md-6' : '' }}">
            <x-ui.campo etiqueta="Imagen" for="imagen-archivo" error="imagen" requerido
                        :ayuda="$imagenes->ayudaArchivo()">
                <input type="file" name="imagen" id="imagen-archivo" class="form-control"
                       accept="image/jpeg,image/png,image/gif,image/webp">
            </x-ui.campo>
        </div>
        @if ($imagenes->pideDescripcion())
            <div class="col-12 col-md-6">
                <x-ui.campo etiqueta="Descripción" for="imagen-descripcion" error="descripcion"
                            :ayuda="$imagenes->ayudaDescripcion()">
                    <input type="text" name="descripcion" id="imagen-descripcion" class="form-control"
                           maxlength="200" placeholder="Colposcopia: ectopia leve"
                           value="{{ $imagenes->descripcionElegida(old('descripcion', '')) }}">
                </x-ui.campo>
            </div>
        @endif
    </div>

    <p class="alert alert-warning py-2 d-flex align-items-center gap-2 mt-3 mb-0" id="{{ $prefijo }}-sin-subir" hidden>
        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
        <span>Elegiste una imagen y todavía no la subiste.</span>
    </p>

    <div class="d-flex justify-content-end gap-2 mt-3">
        <button class="btn btn-primary">
            <i class="bi bi-upload" aria-hidden="true"></i> Subir la imagen
        </button>
    </div>
</form>
