{{-- Una hoja del informe de una eco, A4 vertical completa (WEB-11.13). Recibe `$hoja` (HojasImpresasVista::hojas(): `documento` e `imagenes`)
     y `$membrete`. Si `imagenes` es verdadero es la hoja aparte con las imágenes del estudio; si no, el formulario lleno:
     membrete, datos de la paciente, título del estudio, la ficha (gesta, FUR, FPP, EG) y los bloques con **todas las opciones y la
     elegida marcada**, con la firma y el pie al final. --}}
@php
    $documento = $hoja['documento'];
    $informe = $documento->informe;
    $persona = $documento->persona;
@endphp

@if ($hoja['imagenes'])
    <article class="informe-hoja informe-hoja-imagenes" data-hoja-imagenes aria-label="Imágenes de {{ $documento->nombre }}">
        <h2 class="informe-titulo informe-titulo-imagenes">{{ $documento->titulo }} · IMÁGENES</h2>
        <p class="informe-imagenes-paciente"><span class="recipe-dato-etiqueta">PACIENTE:</span> {{ $persona['nombre'] }}</p>
        <div class="informe-imagenes">
            @foreach ($informe->imagenes as $imagen)
                <figure class="informe-imagen">
                    <img src="{{ $imagen['src'] }}" alt="{{ $imagen['descripcion'] ?? 'Imagen del estudio' }}">
                    @if ($imagen['descripcion'])
                        <figcaption>{{ $imagen['descripcion'] }}</figcaption>
                    @endif
                </figure>
            @endforeach
        </div>
    </article>
@else
    <article class="informe-hoja" aria-label="{{ $documento->titulo }}">
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

        @if ($informe->encabezado !== [])
            <p class="informe-ficha">
                @foreach ($informe->encabezado as $dato)
                    <span class="recipe-dato"><span class="recipe-dato-etiqueta">{{ $dato['etiqueta'] }}</span> {{ $dato['valor'] }}</span>
                @endforeach
            </p>
        @endif

        @foreach ($informe->bloques as $bloque)
            <section class="informe-bloque">
                <h3 class="informe-bloque-titulo">{{ $bloque['titulo'] }}</h3>

                @if ($bloque['grilla'])
                    <table class="informe-grilla">
                        <thead>
                            <tr>
                                <th></th>
                                @foreach ($bloque['grilla']['ejes'] as $eje)
                                    <th>{{ $eje }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($bloque['grilla']['filas'] as $fila)
                                <tr>
                                    <th scope="row">{{ $fila['etiqueta'] }}</th>
                                    @foreach ($fila['celdas'] as $celda)
                                        <td>{{ $celda }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="informe-campos">
                        @foreach ($bloque['campos'] as $campo)
                            @if ($campo['tipo'] === 'area')
                                <div class="informe-campo informe-campo-area">
                                    @if ($campo['etiqueta'] !== '')
                                        <span class="informe-etiqueta">{{ $campo['etiqueta'] }}:</span>
                                    @endif
                                    <p class="informe-texto">{{ $campo['valor'] }}</p>
                                </div>
                            @elseif ($campo['tipo'] === 'opciones')
                                <div class="informe-campo">
                                    @if ($campo['etiqueta'] !== '')
                                        <span class="informe-etiqueta">{{ $campo['etiqueta'] }}:</span>
                                    @endif
                                    @foreach ($campo['opciones'] as $opcion)
                                        <span class="informe-opcion {{ $opcion['marcada'] ? 'es-marcada' : '' }}">
                                            <span class="informe-circulo" aria-hidden="true">{{ $opcion['marcada'] ? '●' : '○' }}</span>{{ $opcion['texto'] }}
                                        </span>
                                    @endforeach
                                </div>
                            @else
                                <div class="informe-campo">
                                    @if ($campo['etiqueta'] !== '')
                                        <span class="informe-etiqueta">{{ $campo['etiqueta'] }}:</span>
                                    @endif
                                    <span class="informe-valor">{{ $campo['valor'] }}@if ($campo['valor'] !== null && $campo['unidad']) {{ $campo['unidad'] }}@endif</span>
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif
            </section>
        @endforeach

        <div class="informe-pie">
            <x-impresion.firma :membrete="$membrete" />
            <x-impresion.pie :membrete="$membrete" />
        </div>
    </article>
@endif
