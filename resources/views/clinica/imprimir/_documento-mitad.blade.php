{{-- Una mitad de la hoja de un documento de la consulta (WEB-5.3a): constancia, reposo, referencia o informe. Recibe
     `$mitad` (HojasDeDocumentoVista::hojas()) y `$membrete`.

     Hay dos clases de mitad. La **primera** lleva el membrete, los datos del paciente y el título, como la del récipe. Las de
     **continuación** —cuando el texto no entra— van sin membrete: un encabezado corto (PACIENTE y FECHA), el título «… (continuación)»
     y el resto del texto. Al pie, **una sola lleva la firma** (la última); las demás, la línea «Continúa →». El pie de página va
     en todas. Las medidas tienen su gemela en `App\Clinica\Impresion\MedidasDeLaMitad` y `DistribuidorDeDocumento`. --}}
<section class="recipe-mitad recipe-mitad-documento" aria-label="{{ $mitad['titulo'] }}">
    @if ($mitad['primera'])
        <x-impresion.membrete :membrete="$membrete" />

        <div class="recipe-linea linea-{{ $membrete->colorLinea }}"></div>

        @if ($mitad['enBlanco'] ?? false)
            @include('clinica.imprimir._datos-en-blanco')
        @else
            <div class="recipe-datos">
                <p class="recipe-dato recipe-dato-paciente"><span class="recipe-dato-etiqueta">PACIENTE:</span> {{ $mitad['paciente'] }}</p>
                <p class="recipe-datos-fila">
                    <span class="recipe-dato"><span class="recipe-dato-etiqueta">C.I.:</span> {{ $mitad['cedula'] }}</span>
                    @if ($mitad['edad'] !== '')
                        <span class="recipe-dato"><span class="recipe-dato-etiqueta">EDAD:</span> {{ $mitad['edad'] }}</span>
                    @endif
                    <span class="recipe-dato"><span class="recipe-dato-etiqueta">FECHA:</span> {{ $mitad['fecha'] }}</span>
                </p>
            </div>
        @endif

        @if (($mitad['para'] ?? null) !== null)
            {{-- El destinatario del resumen médico (WEB-5.3c, `informe.para`): en la cabecera y en una caja con borde,
                 como el `border="4"` del DataWindow. Su franja la mide `MedidasDeLaMitad::PARA_MM`. --}}
            <p class="documento-para">{{ $mitad['para'] }}</p>
        @endif

        <div class="recipe-titulo-fila documento-titulo-fila">
            <h2 class="recipe-titulo documento-titulo">{{ $mitad['titulo'] }}</h2>
        </div>
    @else
        <div class="documento-continuacion-datos">
            <p class="recipe-dato documento-continuacion-paciente"><span class="recipe-dato-etiqueta">PACIENTE:</span> {{ $mitad['paciente'] }}</p>
            <p class="recipe-dato documento-continuacion-fecha"><span class="recipe-dato-etiqueta">FECHA:</span> {{ $mitad['fecha'] }}</p>
        </div>

        <div class="recipe-linea linea-{{ $membrete->colorLinea }}"></div>

        <div class="recipe-titulo-fila documento-titulo-fila">
            <h2 class="documento-titulo-continuacion">{{ $mitad['titulo'] }}</h2>
        </div>
    @endif

    <div class="recipe-cuerpo documento-cuerpo{{ ($mitad['enBlanco'] ?? false) ? ' cuerpo-en-blanco' : '' }}">
        {{-- Los datos fijos (sólo en la primera mitad): rótulo en negrita y su valor, una por línea. --}}
        @if ($mitad['fijos'] !== [])
            <div class="documento-fijos">
                @foreach ($mitad['fijos'] as $fijo)
                    {{-- Un dato sin valor (formato en blanco, WEB-5.5b) deja su línea para escribirlo a mano. --}}
                    @if ($fijo['valor'] === '')
                        <p class="documento-fijo documento-fijo-en-blanco"><strong class="documento-rotulo">{{ $fijo['etiqueta'] }}</strong> <span class="linea-en-blanco"></span></p>
                    @else
                        <p class="documento-fijo"><strong class="documento-rotulo">{{ $fijo['etiqueta'] }}</strong> {{ $fijo['valor'] }}</p>
                    @endif
                @endforeach
            </div>
        @endif

        @foreach ($mitad['parrafos'] as $parrafo)
            @if ($parrafo['etiqueta'] !== null && $parrafo['aparte'])
                <p class="documento-parrafo"><strong class="documento-rotulo documento-rotulo-aparte">{{ $parrafo['etiqueta'] }}</strong>{{ $parrafo['texto'] }}</p>
            @elseif ($parrafo['etiqueta'] !== null)
                <p class="documento-parrafo"><strong class="documento-rotulo">{{ $parrafo['etiqueta'] }}</strong> {{ $parrafo['texto'] }}</p>
            @else
                <p class="documento-parrafo">{{ $parrafo['texto'] }}</p>
            @endif
        @endforeach
        {{-- Formato en blanco (WEB-5.5b): bloques de renglones para escribir, cada uno con su rótulo. --}}
        @foreach ($mitad['secciones'] ?? [] as $seccion)
            @include('clinica.imprimir._seccion-en-blanco', ['seccion' => $seccion])
        @endforeach

        {{-- **Las imágenes del informe, en la misma página y debajo del texto** (la colposcopía y la histeroscopía,
             2026-10-18): el escritorio las imprime así —el subreporte va en la banda *detail*, debajo de la columna del
             texto— y Alexander lo reportó: la imagen no va en una hoja aparte. Van en la última mitad, antes de la firma,
             y su alto lo descuenta el reparto del texto (`MedidasDeLaMitad::altoDeLasImagenes()`); la casilla
             «Incluir imágenes» de la vista previa las oculta por `data-hoja-imagenes`. --}}
        @if (($mitad['imagenes'] ?? []) !== [])
            <div class="documento-imagenes{{ count($mitad['imagenes']) > 1 ? ' documento-imagenes-dos' : '' }}" data-hoja-imagenes>
                @foreach ($mitad['imagenes'] as $imagen)
                    @include('clinica.imprimir._informe-imagen', ['imagen' => $imagen])
                @endforeach
            </div>
        @endif
    </div>

    @if ($mitad['sigue'])
        <p class="documento-continua">Continúa &rarr;</p>
    @endif

    @if ($mitad['firma'])
        <x-impresion.firma :membrete="$membrete" />
    @endif

    <x-impresion.pie :membrete="$membrete" />
</section>
