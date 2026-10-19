{{-- Una gráfica de crecimiento (WEB-7.4.3c) en SVG propio, sin librería. Variables: `$grafica` (GraficaDeCrecimiento) y `$id` (único en la
     página). Las clases de color están en `clinica-crecimiento.css`; esta vista sólo pone la geometría que calculó `GraficaDeCrecimiento`. --}}
@php $marco = $grafica->marco(); @endphp
<figure class="grafica-crecimiento m-0">
    <svg class="grafica-svg" viewBox="{{ $grafica->viewBox() }}" role="img" aria-labelledby="{{ $id }}-titulo {{ $id }}-descripcion">
        <title id="{{ $id }}-titulo">{{ $grafica->titulo() }}</title>
        <desc id="{{ $id }}-descripcion">
            {{ $grafica->conPuntos() ? count($grafica->puntosDelNino()) . ' mediciones del niño' : 'Sin mediciones del niño en este rango de edades' }}{{ $grafica->conCurvas() ? ' sobre las curvas baja, media y alta de la referencia (' . $grafica->fuente() . ').' : '.' }}
        </desc>

        {{-- Marco y cuadrícula --}}
        <rect class="grafica-marco" x="{{ $marco['x'] }}" y="{{ $marco['y'] }}" width="{{ $marco['ancho'] }}" height="{{ $marco['alto'] }}" />
        @foreach ($grafica->marcasDelEjeY() as $marca)
            <line class="grafica-rejilla" x1="{{ $marco['x'] }}" x2="{{ $marco['x'] + $marco['ancho'] }}" y1="{{ $marca['pos'] }}" y2="{{ $marca['pos'] }}" />
            <text class="grafica-marca" x="{{ $marco['x'] - 6 }}" y="{{ $marca['pos'] + 4 }}" text-anchor="end">{{ $marca['texto'] }}</text>
        @endforeach
        @foreach ($grafica->marcasDelEjeX() as $marca)
            <line class="grafica-rejilla" x1="{{ $marca['pos'] }}" x2="{{ $marca['pos'] }}" y1="{{ $marco['y'] }}" y2="{{ $marco['y'] + $marco['alto'] }}" />
            <text class="grafica-marca" x="{{ $marca['pos'] }}" y="{{ $marco['y'] + $marco['alto'] + 16 }}" text-anchor="middle">{{ $marca['texto'] }}</text>
        @endforeach

        {{-- Las tres curvas de la referencia y la franja entre la baja y la alta --}}
        @if ($grafica->conCurvas())
            <polygon class="grafica-franja" points="{{ $grafica->franja() }}" />
            @foreach (['b' => 'baja', 'm' => 'media', 'a' => 'alta'] as $curva => $nombre)
                <polyline class="grafica-curva grafica-curva-{{ $nombre }}" points="{{ $grafica->polilinea($curva) }}" />
                @php $fin = $grafica->finDeLaCurva($curva); @endphp
                @if ($fin)
                    <text class="grafica-rotulo" x="{{ min($fin['x'] + 4, $marco['x'] + $marco['ancho'] - 2) }}" y="{{ $fin['y'] - 4 }}" text-anchor="end">{{ ucfirst($nombre) }}</text>
                @endif
            @endforeach
        @endif

        {{-- El niño --}}
        @if ($grafica->conPuntos())
            <polyline class="grafica-nino-linea" points="{{ $grafica->lineaDelNino() }}" />
            @foreach ($grafica->puntosDelNino() as $punto)
                <circle class="grafica-nino-punto" cx="{{ $punto['cx'] }}" cy="{{ $punto['cy'] }}" r="4.5"><title>{{ $punto['etiqueta'] }}</title></circle>
            @endforeach
        @endif

        <text class="grafica-eje" x="{{ $marco['x'] + $marco['ancho'] / 2 }}" y="{{ $marco['y'] + $marco['alto'] + 38 }}" text-anchor="middle">{{ $grafica->ejeX() }}</text>
        <text class="grafica-eje" x="14" y="{{ $marco['y'] + $marco['alto'] / 2 }}" text-anchor="middle" transform="rotate(-90 14 {{ $marco['y'] + $marco['alto'] / 2 }})">{{ $grafica->ejeY() }}</text>
    </svg>
    <figcaption class="grafica-leyenda">
        <span class="grafica-leyenda-item"><i class="grafica-muestra grafica-muestra-nino" aria-hidden="true"></i> El niño</span>
        @if ($grafica->conCurvas())
            <span class="grafica-leyenda-item"><i class="grafica-muestra grafica-muestra-baja" aria-hidden="true"></i> Curva baja</span>
            <span class="grafica-leyenda-item"><i class="grafica-muestra grafica-muestra-media" aria-hidden="true"></i> Curva media</span>
            <span class="grafica-leyenda-item"><i class="grafica-muestra grafica-muestra-alta" aria-hidden="true"></i> Curva alta</span>
            {{-- **La fuente de las curvas** (O-37, Alexander): la que se está dibujando, no un texto fijo. Va al final de la
                 leyenda y en las dos hojas —la pantalla y la impresa—, que comparten este parcial. --}}
            @if ($grafica->fuente() !== '')
                <span class="grafica-leyenda-fuente">Fuente: {{ $grafica->fuente() }}</span>
            @endif
        @endif
    </figcaption>
</figure>
