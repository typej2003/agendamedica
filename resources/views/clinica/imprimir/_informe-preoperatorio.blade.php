{{-- El **informe de evaluación pre-operatoria** en dos páginas (O-47, 2026-11-02). Recibe `$hoja` (HojasImpresasVista::hojas(),
     con `documento`) y `$membrete`. Los datos ya vienen resueltos en `$documento->preoperatorio` (`InformePreoperatorio`): la vista no
     decide qué se imprime, sólo pinta los bloques que tienen dato. Reusa la hoja A4 vertical de los informes de los ecos
     (`clinica-informe-eco.css`) y suma unas pocas clases propias (`clinica-informe-preoperatorio.css`). --}}
@php
    $documento = $hoja['documento'];
    $informe = $documento->preoperatorio;
    $persona = $documento->persona;
@endphp

<article class="informe-hoja preop-hoja" aria-label="{{ $documento->titulo }}">
    <x-impresion.membrete :membrete="$membrete" />
    <div class="recipe-linea linea-{{ $membrete->colorLinea }}"></div>

    <div class="recipe-datos informe-datos">
        <p class="recipe-dato"><span class="recipe-dato-etiqueta">PACIENTE:</span> {{ $persona['nombre'] }}</p>
        <p class="recipe-datos-fila">
            <span class="recipe-dato"><span class="recipe-dato-etiqueta">C.I.:</span> {{ $persona['cedula'] ?? '' }}</span>
            @if (($persona['edad'] ?? null) !== null)
                <span class="recipe-dato"><span class="recipe-dato-etiqueta">EDAD:</span> {{ $persona['edad'] }} años</span>
            @endif
            <span class="recipe-dato"><span class="recipe-dato-etiqueta">FECHA:</span> {{ \App\Support\FechaClinica::formato($documento->fecha) }}</span>
        </p>
    </div>

    <h2 class="informe-titulo">{{ $documento->titulo }}</h2>

    @foreach ($informe->encabezado as $dato)
        <p class="preop-dato"><span class="preop-dato-etiqueta">{{ $dato['etiqueta'] }}:</span> {{ $dato['valor'] }}</p>
    @endforeach

    @foreach ($informe->bloques as $bloque)
        <section class="informe-bloque">
            <h3 class="informe-bloque-titulo">{{ $bloque['titulo'] }}</h3>

            @if (($bloque['tipo'] ?? 'texto') === 'lista')
                <table class="preop-lista">
                    <tbody>
                        @foreach ($bloque['items'] as $item)
                            <tr>
                                <th scope="row">{{ $item['nombre'] }}</th>
                                <td>{{ $item['resultado'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="informe-texto">{{ $bloque['texto'] }}</p>
            @endif
        </section>
    @endforeach

    <div class="informe-pie">
        <x-impresion.firma :membrete="$membrete" />
        <x-impresion.pie :membrete="$membrete" />
    </div>
</article>

<article class="informe-hoja preop-hoja preop-hoja-2" aria-label="{{ $documento->titulo }} (continuación)">
    {{-- La segunda página no repite el membrete: lleva una línea con el paciente para que la hoja no salga suelta. --}}
    <p class="preop-continuacion">
        <span class="preop-dato-etiqueta">{{ $documento->titulo }}</span> ·
        {{ $persona['nombre'] }}@if (($persona['cedula'] ?? null) !== null) · C.I. {{ $persona['cedula'] }}@endif
        · {{ \App\Support\FechaClinica::formato($documento->fecha) }}
    </p>

    @foreach ($informe->escalas as $escala)
        <section class="informe-bloque preop-escala">
            <h3 class="informe-bloque-titulo">{{ $escala['titulo'] }}</h3>

            @if ($escala['etiqueta'] !== null)
                <p class="preop-escala-etiqueta">{{ $escala['etiqueta'] }}</p>
            @endif

            @if ($escala['texto'] !== null)
                <p class="preop-escala-texto">{{ $escala['texto'] }}</p>
            @endif
        </section>
    @endforeach

    @if ($informe->recomendaciones !== null)
        <section class="informe-bloque">
            <h3 class="informe-bloque-titulo">RECOMENDACIONES</h3>
            <p class="informe-texto">{{ $informe->recomendaciones }}</p>
        </section>
    @endif

    @if ($informe->riesgo !== null)
        <p class="preop-riesgo">RIESGO: {{ $informe->riesgo }}</p>
    @endif

    <div class="informe-pie">
        <x-impresion.firma :membrete="$membrete" />
        <x-impresion.pie :membrete="$membrete" />
    </div>
</article>
