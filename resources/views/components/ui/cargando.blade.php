@props(['texto' => 'Auscultando...', 'tamano' => 'md', 'cubre' => false])

{{-- Indicador de carga: el estetoscopio que ausculta (la campana pasa de un punto a otro y late).
     Los colores son los de la marca (`.cargando` en doctorisimo-ui.css).

     - `tamano`: `sm` (dentro de un botón o una celda), `md` (una tarjeta) o `lg` (la pantalla entera).
     - `cubre`: lo pone como velo sobre el contenedor (que queda `position: relative`), para esconder lo que
       todavía no está listo. Con Livewire: `<x-ui.cargando cubre wire:loading.delay.flex />` — el atributo
       `wire:*` pasa al contenedor y `.delay` evita el parpadeo en las respuestas rápidas. Livewire 2 **no**
       oculta el elemento al iniciar, por eso el componente nace con `display: none` (es lo único que lleva
       en línea): Livewire lo pasa a `flex` mientras hay una petición y lo devuelve a `none` al terminar.
     - `texto`: lo que lee un lector de pantalla (`role="status"`); si lo dejas vacío no se dibuja.

     La animación es SMIL dentro del SVG (no hay JS): la campana va a tres posiciones y el tubo la sigue. --}}
<div {{ $attributes->merge(['class' => 'cargando cargando-' . $tamano . ($cubre ? ' cargando-cubre' : '')]) }}
     @if ($cubre) style="display: none" @endif role="status">
    <svg class="cargando-icono" viewBox="56 12 88 138" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
        {{-- Auriculares --}}
        <circle cx="75" cy="22" r="7" fill="currentColor"/>
        <circle cx="125" cy="22" r="7" fill="currentColor"/>

        {{-- Tubo superior (fijo, en forma de Y) --}}
        <path d="M 75 28 Q 75 58 100 58 Q 125 58 125 28" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round"/>

        {{-- Tubo inferior: se flexiona según la posición de la campana --}}
        <path fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round">
            <animate attributeName="d" dur="5.5s" repeatCount="indefinite"
                     keyTimes="0;0.28;0.38;0.58;0.68;0.88;1"
                     calcMode="spline"
                     keySplines="0.4 0 0.6 1;0.4 0 0.6 1;0.4 0 0.6 1;0.4 0 0.6 1;0.4 0 0.6 1;0.4 0 0.6 1"
                     values="M 100 58 C 100 90 85 100 85 130;
                             M 100 58 C 100 90 85 100 85 130;
                             M 100 58 C 105 90 115 100 115 120;
                             M 100 58 C 105 90 115 100 115 120;
                             M 100 58 C 100 90 80 100 80 110;
                             M 100 58 C 100 90 80 100 80 110;
                             M 100 58 C 100 90 85 100 85 130"/>
        </path>

        {{-- Campana: su posición se anima y late dos veces ("tun-tun") en cada punto --}}
        <g>
            <animateTransform attributeName="transform" type="translate" dur="5.5s" repeatCount="indefinite"
                              keyTimes="0;0.28;0.38;0.58;0.68;0.88;1"
                              calcMode="spline"
                              keySplines="0.4 0 0.6 1;0.4 0 0.6 1;0.4 0 0.6 1;0.4 0 0.6 1;0.4 0 0.6 1;0.4 0 0.6 1"
                              values="85 130; 85 130; 115 120; 115 120; 80 110; 80 110; 85 130"/>
            <g>
                <animateTransform attributeName="transform" type="scale" dur="5.5s" repeatCount="indefinite"
                                  keyTimes="0;0.08;0.12;0.16;0.20;0.24;0.38;0.42;0.46;0.50;0.54;0.68;0.72;0.76;0.80;0.84;1"
                                  values="1;1;1.18;1;1.14;1;1;1.18;1;1.14;1;1;1.18;1;1.14;1;1"/>
                <circle cx="0" cy="0" r="15" class="cargando-campana" stroke="currentColor" stroke-width="5"/>
                <circle cx="0" cy="0" r="5" class="cargando-latido"/>
            </g>
        </g>
    </svg>

    @if ($texto !== '')
        <span class="cargando-texto">{{ $texto }}</span>
    @endif
</div>
