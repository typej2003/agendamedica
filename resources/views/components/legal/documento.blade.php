@props(['tipo'])

{{-- El documento legal, una sola vez: lo usan las páginas públicas (`/terminos`, `/privacidad`) y el
     diálogo de aceptación de los registros. El texto vive en `legal/_<tipo>.blade.php`, así que no se
     copia en ningún otro lado. --}}
<article {{ $attributes->merge(['class' => 'legal-documento']) }}>
    @include('legal._' . $tipo)
</article>
