{{-- Los iconos de la página pública de verificación (WEB-5.6): trazos propios en SVG, sin librería ni CDN (la página es pública y
     liviana). Recibe `$nombre` (el `icono` de `VerificacionPublicaVista::resultado()`). Decorativos: el estado lo dice el texto. --}}
<svg class="verif-icono" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    <circle cx="12" cy="12" r="10"/>
    @switch($nombre)
        @case('ok')
            <path d="M7 12.5l3.5 3.5L17 9"/>
            @break
        @case('reloj')
            <path d="M12 7v5l3 2"/>
            @break
        @case('anulado')
            <path d="M5 5l14 14"/>
            @break
        @case('eliminado')
            <path d="M8.5 8.5l7 7M15.5 8.5l-7 7"/>
            @break
        @case('info')
            <path d="M12 11v6M12 7.5h.01"/>
            @break
        @default
            <path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 .9-1 1.7M12 17h.01"/>
    @endswitch
</svg>
