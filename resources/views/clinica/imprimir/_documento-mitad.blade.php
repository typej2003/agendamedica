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
    </div>

    @if ($mitad['sigue'])
        <p class="documento-continua">Continúa &rarr;</p>
    @endif

    @if ($mitad['firma'])
        <x-impresion.firma :membrete="$membrete" />
    @endif

    <x-impresion.pie :membrete="$membrete" />
</section>
