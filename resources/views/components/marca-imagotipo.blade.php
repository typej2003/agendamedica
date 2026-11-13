{{--
    El imagotipo de la identidad Doctorísimo: la «D» de los dos médicos. Reemplaza al corazón de la marca
    anterior en las fichas y encabezados. Va como imagen (no como SVG) porque el diseñador todavía no
    entregó el vector; el PNG de `public/img/marca/doctorisimo-imagotipo.png` sale de su recorte corregido.
--}}
<img src="{{ asset('img/marca/doctorisimo-imagotipo.png') }}"
     {{ $attributes->merge(['class' => 'marca-imagotipo', 'alt' => '', 'aria-hidden' => 'true']) }}>
