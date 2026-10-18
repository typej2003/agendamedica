{{-- La **hoja aparte de las imágenes** de un documento (WEB-11.13 y A-13): las fotos de un eco o los dibujos del
     cuello uterino de la colposcopía. A4 vertical, una hoja por documento, y se oculta con «Incluir imágenes» (el
     `data-hoja-imagenes` lo resuelve `imprimir-recipe.js`).

     Recibe `$documento` (el `DocumentoImpreso`, para el título y el paciente) y `$imagenes` (las de `InformeDeEco` o las
     del propio documento). Hay dos formas de imagen compuesta, y son las dos caras de la misma decisión (O-09):

       - **`base`** — el esquema de la imagen predeterminada: la imagen de fondo va debajo y el trazo transparente encima,
         en el mismo marco de 520×400.
       - **`foto`** — el videocolposcopio: la foto real, **a su tamaño** (no estirada al marco del esquema) y con el trazo
         encima cuando el médico marcó sobre ella.

     La vista no lleva estilos ni JS. --}}
@php
    $persona = $documento->persona;
@endphp

<article class="informe-hoja informe-hoja-imagenes" data-hoja-imagenes aria-label="Imágenes de {{ $documento->nombre }}">
    <h2 class="informe-titulo informe-titulo-imagenes">{{ $documento->titulo }} · IMÁGENES</h2>
    <p class="informe-imagenes-paciente"><span class="recipe-dato-etiqueta">PACIENTE:</span> {{ $persona['nombre'] }}</p>
    <div class="informe-imagenes">
        @foreach ($imagenes as $imagen)
            <figure class="informe-imagen{{ ! empty($imagen['base']) ? ' informe-imagen-dibujo' : '' }}{{ ! empty($imagen['foto']) ? ' informe-imagen-foto' : '' }}">
                @if (! empty($imagen['foto']))
                    {{-- La foto del estudio, a su tamaño, con la marca del médico encima si la tiene. --}}
                    <div class="informe-foto">
                        <img class="informe-foto-base" src="{{ $imagen['src'] }}"
                             alt="Foto del estudio del {{ $imagen['descripcion'] ?? 'examen' }}">
                        @if (! empty($imagen['trazo']))
                            <img class="informe-foto-trazo" src="{{ $imagen['trazo'] }}"
                                 alt="Marcas del médico sobre la foto del estudio">
                        @endif
                    </div>
                @elseif (! empty($imagen['base']))
                    {{-- El dibujo del cuello uterino: la imagen base elegida con el trazo encima. --}}
                    <div class="informe-dibujo">
                        <img class="informe-dibujo-base" src="{{ $imagen['base'] }}"
                             alt="Imagen base del {{ $imagen['descripcion'] ?? 'dibujo' }}">
                        <img class="informe-dibujo-trazo" src="{{ $imagen['src'] }}"
                             alt="{{ $imagen['descripcion'] ?? 'Dibujo del cuello uterino' }}">
                    </div>
                @else
                    <img src="{{ $imagen['src'] }}" alt="{{ $imagen['descripcion'] ?? 'Imagen del estudio' }}">
                @endif
                @if ($imagen['descripcion'])
                    <figcaption>{{ $imagen['descripcion'] }}</figcaption>
                @endif
            </figure>
        @endforeach
    </div>
</article>
