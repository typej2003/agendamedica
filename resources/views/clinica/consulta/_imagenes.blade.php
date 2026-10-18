{{-- Las imágenes de una consulta o de un examen (WEB-4.7 y WEB-6.5): la tarjeta con la lista de las que ya están, su
     vista o descarga y su baja, más el formulario para subir una nueva.

     Es la misma tarjeta en los dos lugares y por eso es un parcial:

       - la **galería de la consulta** (O-22: la solapa *Imágenes* de *Evaluación física*) muestra todas las imágenes de
         la consulta —«Esta consulta / Todas»— y pide una descripción, que la columna `observacion` guarda cuando la
         imagen no es de un examen;
       - la **solapa de un examen** (WEB-6.5) arma su propia tarjeta desde O-09 (`_imagen-del-examen`: el radiobutón, los
         lienzos y, adentro del modo de la foto, esta misma lista) y los estudios ecográficos la usan tal cual.

     Recibe `$imagenes` (el ViewModel), `$titulo` de la tarjeta y `$prefijo` de los ids del formulario. La lista y la
     subida viven en `_imagenes-lista`, que es lo que comparte con la tarjeta de la imagen del examen. La vista no lleva
     lógica, estilos ni JS. --}}
@php
    $estadoImagenes = $imagenes->estado();
@endphp

<x-ui.tarjeta :titulo="$titulo" icono="bi-images" class="{{ $clase ?? '' }}">
    <x-slot name="acciones">
        <x-ui.estado :tono="$estadoImagenes['tono']" :icono="$estadoImagenes['icono']"
                     :title="$estadoImagenes['titulo']">{{ $estadoImagenes['texto'] }}</x-ui.estado>
    </x-slot>

    @if ($imagenes->examen() !== null)
        <p class="texto-tenue small mb-3">{{ $imagenes->ayudaDelExamen() }}</p>
    @endif

    {{-- «Esta consulta | Todas» (WEB-11.22): sólo en la galería genérica de la consulta. --}}
    @if ($imagenes->alcances() !== [])
        <x-ui.chips class="mb-3" :opciones="$imagenes->alcances()" etiqueta="Qué imágenes ver" />
    @endif

    @include('clinica.consulta._imagenes-lista', ['imagenes' => $imagenes, 'prefijo' => $prefijo])
</x-ui.tarjeta>
