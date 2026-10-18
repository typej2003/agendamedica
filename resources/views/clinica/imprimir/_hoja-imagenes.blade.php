{{-- La **hoja aparte de las imágenes** de un documento (WEB-11.13 y A-13): las fotos de un eco o los dibujos del
     cuello uterino de la colposcopía. A4 vertical, una hoja por documento, y se oculta con «Incluir imágenes» (el
     `data-hoja-imagenes` lo resuelve `imprimir-recipe.js`).

     Recibe `$documento` (el `DocumentoImpreso`, para el título y el paciente) y `$imagenes` (las de `InformeDeEco` o las
     del propio documento). Una imagen **compuesta** —el dibujo del cuello uterino— trae `base`: la imagen de fondo va
     debajo y el trazo transparente encima, en el mismo marco. La vista no lleva estilos ni JS. --}}
@php
    $persona = $documento->persona;
@endphp

<article class="informe-hoja informe-hoja-imagenes" data-hoja-imagenes aria-label="Imágenes de {{ $documento->nombre }}">
    <h2 class="informe-titulo informe-titulo-imagenes">{{ $documento->titulo }} · IMÁGENES</h2>
    <p class="informe-imagenes-paciente"><span class="recipe-dato-etiqueta">PACIENTE:</span> {{ $persona['nombre'] }}</p>
    <div class="informe-imagenes">
        @foreach ($imagenes as $imagen)
            <figure class="informe-imagen{{ ! empty($imagen['base']) ? ' informe-imagen-dibujo' : '' }}">
                @if (! empty($imagen['base']))
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
