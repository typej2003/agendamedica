{{-- La **hoja aparte de las imágenes** de un informe de eco (WEB-11.13). A4 vertical, una hoja por documento, y se oculta con
     «Incluir imágenes» (el `data-hoja-imagenes` lo resuelve `imprimir-recipe.js`).

     ⚠️ Es sólo para los **ecos**. La colposcopía y la histeroscopía **no** usan esta hoja: su imagen sale en la misma página
     del informe, debajo del texto (`_documento-mitad`), porque así la imprime el escritorio —el subreporte va en la banda
     *detail* de `d_colposcopia_1` y `d_histeroscopia`— y es lo que pidió Alexander (2026-10-18).

     Recibe `$documento` (el `DocumentoImpreso`, para el título y el paciente) y `$imagenes` (las de `InformeDeEco`).

     La vista no lleva estilos ni JS. --}}
@php
    $persona = $documento->persona;
@endphp

<article class="informe-hoja informe-hoja-imagenes" data-hoja-imagenes aria-label="Imágenes de {{ $documento->nombre }}">
    <h2 class="informe-titulo informe-titulo-imagenes">{{ $documento->titulo }} · IMÁGENES</h2>
    <p class="informe-imagenes-paciente"><span class="recipe-dato-etiqueta">PACIENTE:</span> {{ $persona['nombre'] }}</p>
    <div class="informe-imagenes">
        @foreach ($imagenes as $imagen)
            @include('clinica.imprimir._informe-imagen', ['imagen' => $imagen])
        @endforeach
    </div>
</article>
