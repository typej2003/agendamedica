{{--
    El icono de una hoja con la parte que se imprime sombreada (2026-11-05). Lo usan el botón de configuración de la
    vista previa (con la hoja actual) y las tres opciones del diálogo.

    `$opcion`: `completa` (toda la hoja), `media-vertical` (la mitad de arriba) o `media-horizontal` (la mitad izquierda).
    La hoja es la misma en los tres casos —un A4 vertical con su esquina doblada, como la referencia que pasó el
    usuario— y lo que cambia es la zona sombreada y la línea punteada de dónde se corta.

    El trazo y el relleno van con `currentColor`: el botón los pinta del color del texto y las opciones, del suyo.
--}}
@php
    $opcion = $opcion ?? 'completa';
    $clase = $clase ?? '';

    // La hoja: el contorno con el chaflán de la esquina superior derecha y el doblez.
    $hoja = 'M5 2.5 H30.8 L43.5 15.2 V59.5 H5 Z';
    $solapa = 'M30.8 2.5 V13.2 A2 2 0 0 0 32.8 15.2 H43.5';

    // La zona imprimible: toda la hoja, su mitad de arriba o su mitad izquierda.
    $zonas = [
        'completa' => 'M5 2.5 H30.8 V15.2 H43.5 V59.5 H5 Z',
        'media-vertical' => 'M5 2.5 H30.8 V15.2 H43.5 V31 H5 Z',
        'media-horizontal' => 'M5 2.5 H24 V59.5 H5 Z',
    ];

    // La línea del corte: horizontal en la media vertical y vertical en la media horizontal.
    $corte = [
        'media-vertical' => ['x1' => 5, 'y1' => 31, 'x2' => 43.5, 'y2' => 31],
        'media-horizontal' => ['x1' => 24, 'y1' => 15.2, 'x2' => 24, 'y2' => 59.5],
    ];

    // Las líneas que hacen de texto: horizontales en las dos hojas verticales y en vertical en la media horizontal.
    if ($opcion === 'media-horizontal') {
        $lineas = [];
        foreach ([10.5, 16, 21.5] as $x) {
            $lineas[] = ['x1' => $x, 'y1' => 21, 'x2' => $x, 'y2' => 53, 'dentro' => true];
        }
        foreach ([29.5, 35, 40.5] as $x) {
            $lineas[] = ['x1' => $x, 'y1' => 21, 'x2' => $x, 'y2' => 53, 'dentro' => false];
        }
    } else {
        $lineas = [];
        foreach ([20, 25.5, 31, 36.5, 42, 47.5, 53] as $y) {
            $lineas[] = ['x1' => 10, 'y1' => $y, 'x2' => 38.5, 'y2' => $y, 'dentro' => $y < 31];
        }
    }
@endphp

<svg class="icono-hoja {{ $clase }}" viewBox="0 0 48 62" width="24" height="31" aria-hidden="true" focusable="false">
    <path d="{{ $zonas[$opcion] ?? $zonas['completa'] }}" fill="currentColor" fill-opacity=".2"/>

    @foreach ($lineas as $linea)
        <line x1="{{ $linea['x1'] }}" y1="{{ $linea['y1'] }}" x2="{{ $linea['x2'] }}" y2="{{ $linea['y2'] }}"
              stroke="currentColor" stroke-width="1.6" stroke-linecap="round"
              @if ($linea['dentro']) stroke-opacity=".55" @else stroke-opacity=".22" @endif/>
    @endforeach

    <path d="{{ $hoja }}" fill="#fff" fill-opacity="0" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round"/>
    <path d="{{ $solapa }}" fill="#fff" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round" stroke-linecap="round"/>

    @isset($corte[$opcion])
        <line x1="{{ $corte[$opcion]['x1'] }}" y1="{{ $corte[$opcion]['y1'] }}"
              x2="{{ $corte[$opcion]['x2'] }}" y2="{{ $corte[$opcion]['y2'] }}"
              stroke="currentColor" stroke-width="1.4" stroke-dasharray="2.5 2.5" stroke-opacity=".7"/>
    @endisset
</svg>
