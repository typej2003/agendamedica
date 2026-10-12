{{-- Una mitad de la hoja del récipe (WEB-5.2): un récipe completo con su membrete, los datos del paciente, el
     cuerpo ("Rp." con cantidad y medicamento, o "Indicaciones" con medicamento e indicación), la próxima cita, la
     firma con su sello y el pie. Recibe `$mitad` (HojasDeRecipeVista::hojas()) y `$membrete`. Cada hoja repite todo
     esto, así que cada hoja es un récipe válido por sí sola. --}}
<section class="recipe-mitad recipe-mitad-{{ $mitad['clase'] }}" aria-label="{{ $mitad['titulo'] }}">
    <x-impresion.membrete :membrete="$membrete" />

    <div class="recipe-linea linea-{{ $membrete->colorLinea }}"></div>

    @if ($mitad['enBlanco'] ?? false)
        @include('clinica.imprimir._datos-en-blanco')
    @else
        <div class="recipe-datos">
            <p class="recipe-dato recipe-dato-paciente"><span class="recipe-dato-etiqueta">PACIENTE:</span> {{ $mitad['paciente'] }}</p>
            <p class="recipe-datos-fila">
                <span class="recipe-dato"><span class="recipe-dato-etiqueta">C.I.:</span> {{ $mitad['cedula'] }}</span>
                @if ($mitad['edad'] !== null)
                    <span class="recipe-dato"><span class="recipe-dato-etiqueta">EDAD:</span> {{ $mitad['edad'] }}</span>
                @endif
                <span class="recipe-dato"><span class="recipe-dato-etiqueta">FECHA:</span> {{ $mitad['fecha'] }}</span>
            </p>
            @if ($mitad['direccion'])
                <p class="recipe-dato recipe-dato-direccion"><span class="recipe-dato-etiqueta">DIRECCIÓN:</span> {{ $mitad['direccion'] }}</p>
            @endif
        </div>
    @endif

    <div class="recipe-titulo-fila">
        <h2 class="recipe-titulo">{{ $mitad['titulo'] }}</h2>
        @if ($mitad['etiqueta'])
            <span class="recipe-hoja-etiqueta">{{ $mitad['etiqueta'] }}</span>
        @endif
    </div>

    <div class="recipe-cuerpo{{ ($mitad['enBlanco'] ?? false) ? ' cuerpo-en-blanco' : '' }}">
        @if ($mitad['enBlanco'] ?? false)
            {{-- Formato en blanco (WEB-5.5b): renglones para escribir a mano. --}}
            @include('clinica.imprimir._seccion-en-blanco', ['seccion' => ['etiqueta' => null, 'peso' => 1]])
        @elseif ($mitad['clase'] === 'rp')
            <ol class="recipe-lista">
                @foreach ($mitad['filas'] as $fila)
                    <li class="recipe-rp-fila">
                        <span class="recipe-cantidad">{{ $fila['cantidad'] }}</span>
                        <span class="recipe-nombre">{{ $fila['nombre'] }}</span>
                    </li>
                @endforeach
            </ol>
        @else
            <ol class="recipe-lista">
                @foreach ($mitad['filas'] as $fila)
                    <li class="recipe-indicacion-fila">
                        <strong class="recipe-nombre">{{ $fila['nombre'] }}</strong>
                        @if ($fila['indicaciones'])
                            <p class="recipe-indicacion">{{ $fila['indicaciones'] }}</p>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </div>

    <x-impresion.firma :membrete="$membrete">
        @if ($mitad['proximaCita'])
            <p class="recipe-proxima-cita">{{ $mitad['proximaCita'] }}</p>
        @endif
        {{-- El QR de verificación del récipe (WEB-5.6): 16 × 16 mm en el lugar que la hoja le reservaba, con la dirección
             y el código corto al lado para teclearlos si el QR no se lee. El mismo QR va en las dos mitades. --}}
        @if ($mitad['qr'])
            @include('clinica.imprimir._recipe-qr', ['qr' => $mitad['qr']])
        @else
            <div class="recipe-qr-reservado" aria-hidden="true"></div>
        @endif
    </x-impresion.firma>

    <x-impresion.pie :membrete="$membrete" />
</section>
