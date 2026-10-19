{{-- **Una imagen de un informe** (WEB-11.13, A-13): la foto de un eco, la del videocolposcopio o el dibujo del cuello
     uterino con su trazo. La usan las dos hojas que llevan imágenes: la **hoja aparte del eco** (`_hoja-imagenes`) y el
     **informe de la colposcopía y la histeroscopía**, que las imprime en su misma página (`_documento-mitad`).

     Recibe `$imagen`. Hay dos formas de imagen compuesta, y son las dos caras de la misma decisión (O-09):

       - **`base`** — el esquema de la imagen predeterminada: la imagen de fondo va debajo y el trazo transparente encima,
         en el mismo marco de 520×400.
       - **`foto`** — el videocolposcopio: la foto real, **a su tamaño** (no estirada al marco del esquema) y con el trazo
         encima cuando el médico marcó sobre ella.

     Los tamaños los pone el CSS del contenedor: la hoja de imágenes del eco (`clinica-informe-eco.css`) o el bloque del
     documento (`clinica-documento.css`). La vista no lleva estilos ni JS. --}}
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
